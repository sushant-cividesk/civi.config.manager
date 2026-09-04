<?php
namespace Civi\ConfigManager\Handler;

/**
 * Portable adapter for Profile/UF fields.
 *
 * A Profile may contain more than one instance of the same field_name (for
 * example multiple phone/location variants). Matching by profile + field_name
 * alone is therefore unsafe. This handler includes semantic qualifiers in the
 * portable identity and keeps local IDs at the API boundary only.
 */
class ProfileFieldHandler extends AbstractHandler implements StreamingHandlerInterface, StreamingImportHandlerInterface {

  private const FIELDS = [
    'uf_group_id', 'field_name', 'is_active', 'is_view', 'is_required',
    'weight', 'help_post', 'help_pre', 'visibility', 'in_selector',
    'is_searchable', 'location_type_id', 'label', 'field_type', 'is_reserved',
    'is_multi_summary',
  ];

  public function getType(): string { return 'profile-fields'; }
  public function getLabel(): string { return 'Profile Fields'; }
  public function getDirectory(): string { return 'profiles/fields'; }
  public function getWeight(): int { return 41; }

  public function getProviderMetadata(): array {
    return [
      'owner' => 'civicrm-core',
      'api_version' => 'api4',
      'entity' => 'UFField',
      'actions' => ['read' => TRUE, 'create' => TRUE, 'update' => TRUE, 'delete' => FALSE],
      'field_names' => self::FIELDS,
      'identity_fields' => ['uf_group_id.name', 'field_name', 'field_type', 'location_type_id.name', 'label'],
      'reference_fields' => ['uf_group_id', 'location_type_id'],
      'sensitive_fields' => [],
      'runtime_fields' => ['id'],
      'management_capability' => 'managed_no_delete',
      'identity_evidence' => 'reviewed_adapter',
      'metadata_completeness' => 'declared',
    ];
  }

  public function getRuntimeAvailability(): array {
    $availability = $this->api4ManagementAvailability('UFField', ['get', 'create', 'update']);
    if (empty($availability['available']) || ($availability['management_capability'] ?? '') === 'export_only') {
      return $availability;
    }
    return [
      'available' => TRUE,
      'management_capability' => 'managed_no_delete',
      'reason' => 'Profile fields support reviewed semantic create/update management; delete-missing is intentionally disabled.',
      'missing_actions' => [],
    ];
  }

  public function export(): array {
    return iterator_to_array($this->iterateExport(), FALSE);
  }

  public function iterateExport(): iterable {
    $seen = [];
    $select = array_merge(['id'], self::FIELDS);
    foreach ($this->api4Iterate('UFField', [], $select, ['uf_group_id' => 'ASC', 'weight' => 'ASC', 'id' => 'ASC']) as $raw) {
      $raw = (array) $raw;
      $sourceId = isset($raw['id']) && is_scalar($raw['id']) ? (int) $raw['id'] : NULL;
      $portable = $this->toPortable($raw);
      $identity = $this->identity($portable);
      if (isset($seen[$identity])) {
        throw new \RuntimeException(
          'Profile Field portable identity is not unique. Conflicting UFField records use identity ' . $identity
          . '; source IDs ' . (string) $seen[$identity] . ' and ' . (string) ($sourceId ?? 'unknown')
          . '. No live YAML was changed.'
        );
      }
      $seen[$identity] = $sourceId ?? 'unknown';
      yield [
        'filename' => $this->filename($portable),
        'source_id' => $sourceId,
        'data' => [
          'schema_version' => 1,
          'type' => 'profile-fields.item',
          'entity' => 'UFField',
          'key_fields' => ['uf_group_id.name', 'field_name', 'field_type', 'location_type_id.name', 'label'],
          'key' => $identity,
          'dependencies' => $this->dependencies($portable),
          'capabilities' => ['create' => TRUE, 'update' => TRUE, 'delete' => FALSE],
          'item' => $portable,
        ],
      ];
    }
  }

  public function validate(array $items): array {
    $errors = [];
    $warnings = [];
    $seen = [];
    foreach ($items as $filename => $document) {
      if (($document['type'] ?? '') !== 'profile-fields.item' || ($document['entity'] ?? '') !== 'UFField') {
        $errors[] = ['file' => $filename, 'message' => 'Expected a profile-fields.item UFField document.'];
        continue;
      }
      $row = (array) ($document['item'] ?? []);
      try {
        $identity = $this->identity($row);
        if (isset($seen[$identity])) {
          throw new \RuntimeException('Duplicate Profile Field portable identity in YAML: ' . $identity . '.');
        }
        $seen[$identity] = TRUE;
        $this->assertReference($row['uf_group_id'] ?? NULL, 'UFGroup', 'name', 'uf_group_id');
        if (($row['location_type_id'] ?? NULL) !== NULL && ($row['location_type_id'] ?? '') !== '') {
          $this->assertReference($row['location_type_id'], 'LocationType', 'name', 'location_type_id');
        }
        if (array_key_exists('id', $row)) {
          throw new \RuntimeException('Local UFField id must not be stored in YAML.');
        }
      }
      catch (\Throwable $e) {
        $errors[] = ['file' => $filename, 'message' => $e->getMessage()];
      }
      if (!array_key_exists('dependencies', $document)) {
        $warnings[] = ['file' => $filename, 'message' => 'Dependency metadata is missing. Re-export this Profile Field before deployment.'];
      }
    }
    return ['type' => $this->getType(), 'valid' => !$errors, 'warnings' => $warnings, 'errors' => $errors, 'count' => count($items)];
  }

  public function import(array $items, bool $dryRun = TRUE): array {
    return $this->importIterable($items, $dryRun);
  }

  public function importIterable(iterable $items, bool $dryRun = TRUE): array {
    $summary = ['type' => $this->getType(), 'status' => $dryRun ? 'dry_run' : 'applied', 'dry_run' => $dryRun, 'create' => 0, 'update' => 0, 'delete' => 0, 'skip' => 0, 'warnings' => [], 'errors' => []];
    foreach ($items as $filename => $document) {
      $portable = (array) ($document['item'] ?? []);
      try {
        if (($document['type'] ?? '') !== 'profile-fields.item' || ($document['entity'] ?? '') !== 'UFField') {
          throw new \RuntimeException('Invalid Profile Field document.');
        }
        $this->identity($portable);
        $desired = $this->fromPortable($portable);
        $matches = $this->findMatches($portable, $desired);
        if (count($matches) > 1) {
          throw new \RuntimeException('Profile Field portable identity is ambiguous on the target: ' . $this->identity($portable) . '.');
        }
        if ($matches) {
          $existing = (array) $matches[0];
          $existingPortable = $this->toPortable($existing);
          if ($this->desiredDiffers($existingPortable, $portable)) {
            $summary['update']++;
            if (!$dryRun) {
              if (empty($existing['id'])) {
                throw new \RuntimeException('Existing UFField has no local ID for update.');
              }
              $this->api4Update('UFField', [['id', '=', $existing['id']]], $desired);
            }
          }
          else {
            $summary['skip']++;
          }
        }
        else {
          $summary['create']++;
          if (!$dryRun) {
            $this->api4Create('UFField', $desired);
          }
        }
      }
      catch (\Throwable $e) {
        $summary['errors'][] = ['file' => $filename, 'name' => (string) ($portable['label'] ?? $portable['field_name'] ?? ''), 'message' => $e->getMessage()];
      }
    }
    $summary['ok'] = !$summary['errors'];
    return $summary;
  }

  protected function normaliseDataForDiff(array $data): array {
    $data = parent::normaliseDataForDiff($data);
    if (isset($data['item']) && is_array($data['item'])) {
      unset($data['item']['id']);
    }
    return $data;
  }

  private function toPortable(array $row): array {
    unset($row['id']);
    $row = array_intersect_key($row, array_flip(self::FIELDS));
    $row['uf_group_id'] = $this->referenceFromId('UFGroup', $row['uf_group_id'] ?? NULL, 'name');
    if (($row['location_type_id'] ?? NULL) !== NULL && ($row['location_type_id'] ?? '') !== '') {
      $row['location_type_id'] = $this->referenceFromId('LocationType', $row['location_type_id'], 'name');
    }
    else {
      $row['location_type_id'] = NULL;
    }
    ksort($row, SORT_NATURAL | SORT_FLAG_CASE);
    return $row;
  }

  private function fromPortable(array $row): array {
    unset($row['id']);
    $row = array_intersect_key($row, array_flip(self::FIELDS));
    $row['uf_group_id'] = $this->idFromReference('UFGroup', $row['uf_group_id'] ?? NULL, 'name', 'uf_group_id');
    if (($row['location_type_id'] ?? NULL) !== NULL && ($row['location_type_id'] ?? '') !== '') {
      $row['location_type_id'] = $this->idFromReference('LocationType', $row['location_type_id'], 'name', 'location_type_id');
    }
    else {
      $row['location_type_id'] = NULL;
    }
    return $row;
  }

  private function findMatches(array $portable, array $desired): array {
    $where = [
      ['uf_group_id', '=', $desired['uf_group_id']],
      ['field_name', '=', (string) ($desired['field_name'] ?? '')],
    ];
    $candidates = $this->api4Get('UFField', $where, array_merge(['id'], self::FIELDS), ['id' => 'ASC']);
    $baseIdentity = $this->baseIdentity($portable);
    $baseMatches = [];
    foreach ($candidates as $candidate) {
      $candidatePortable = $this->toPortable((array) $candidate);
      if ($this->baseIdentity($candidatePortable) === $baseIdentity) {
        $baseMatches[] = (array) $candidate;
      }
    }
    if (count($baseMatches) <= 1) {
      return $baseMatches;
    }

    // Multiple fields can legitimately share the same base field semantics.
    // In that case the label is a portable tie-breaker, never a local ID.
    $identity = $this->identity($portable);
    $matches = [];
    foreach ($baseMatches as $candidate) {
      if ($this->identity($this->toPortable((array) $candidate)) === $identity) {
        $matches[] = (array) $candidate;
      }
    }
    return $matches;
  }

  private function identity(array $row): string {
    $base = $this->baseIdentity($row);
    $label = trim((string) ($row['label'] ?? ''));
    if ($label === '') {
      throw new \RuntimeException('Profile Field requires a portable label to disambiguate repeated fields safely.');
    }
    return $base . '|label=' . $label;
  }

  private function baseIdentity(array $row): string {
    $profile = $this->referenceKey($row['uf_group_id'] ?? NULL, 'UFGroup', 'name', 'uf_group_id');
    $fieldName = trim((string) ($row['field_name'] ?? ''));
    $fieldType = trim((string) ($row['field_type'] ?? ''));
    if ($profile === '' || $fieldName === '') {
      throw new \RuntimeException('Profile Field requires portable profile name + field_name identity.');
    }
    $location = '~';
    if (($row['location_type_id'] ?? NULL) !== NULL && ($row['location_type_id'] ?? '') !== '') {
      $location = $this->referenceKey($row['location_type_id'], 'LocationType', 'name', 'location_type_id');
    }
    return 'profile=' . $profile . '|field=' . $fieldName . '|field_type=' . ($fieldType === '' ? '~' : $fieldType) . '|location=' . $location;
  }

  private function filename(array $row): string {
    $profile = $this->referenceKey($row['uf_group_id'], 'UFGroup', 'name', 'uf_group_id');
    $field = trim((string) $row['field_name']);
    $label = trim((string) $row['label']);
    $identity = $this->identity($row);
    return $this->safe($profile) . '__' . $this->safe($field) . '__' . $this->safe($label) . '--' . substr(sha1($identity), 0, 10) . '.yml';
  }

  private function dependencies(array $row): array {
    $dependencies = [[
      'type' => 'profiles',
      'name' => $this->referenceKey($row['uf_group_id'], 'UFGroup', 'name', 'uf_group_id'),
      'reason' => 'Profile Field belongs to this Profile/UF Group.',
    ]];
    if (($row['location_type_id'] ?? NULL) !== NULL && ($row['location_type_id'] ?? '') !== '') {
      $dependencies[] = [
        'type' => 'location-types',
        'name' => $this->referenceKey($row['location_type_id'], 'LocationType', 'name', 'location_type_id'),
        'reason' => 'Profile Field references this portable Location Type.',
      ];
    }
    return $dependencies;
  }

  private function referenceFromId(string $entity, $id, string $keyField): array {
    if ($id === NULL || $id === '') {
      throw new \RuntimeException('Cannot resolve empty ' . $entity . ' reference.');
    }
    $target = $this->api4GetFirst($entity, [['id', '=', $id]], ['id', $keyField]);
    if (!$target || !isset($target[$keyField]) || trim((string) $target[$keyField]) === '') {
      throw new \RuntimeException('Could not resolve Profile Field local ' . $entity . ' id=' . (string) $id . ' to portable ' . $keyField . '.');
    }
    return ['provider' => 'api4:' . $entity, 'entity' => $entity, 'key' => [$keyField => $target[$keyField]]];
  }

  private function idFromReference(string $entity, $reference, string $keyField, string $path) {
    $value = $this->referenceKey($reference, $entity, $keyField, $path);
    $target = $this->api4GetFirst($entity, [[$keyField, '=', $value]], ['id', $keyField]);
    if (!$target || !isset($target['id'])) {
      throw new \RuntimeException('Could not resolve Profile Field semantic reference ' . $path . '=' . $value . ' on the target.');
    }
    return $target['id'];
  }

  private function referenceKey($reference, string $entity, string $keyField, string $path): string {
    $this->assertReference($reference, $entity, $keyField, $path);
    return trim((string) $reference['key'][$keyField]);
  }

  private function assertReference($reference, string $entity, string $keyField, string $path): void {
    if (!is_array($reference)
      || ($reference['provider'] ?? '') !== 'api4:' . $entity
      || ($reference['entity'] ?? '') !== $entity
      || !isset($reference['key'])
      || !is_array($reference['key'])
      || array_keys($reference['key']) !== [$keyField]
      || !is_scalar($reference['key'][$keyField] ?? NULL)
      || trim((string) ($reference['key'][$keyField] ?? '')) === '') {
      throw new \RuntimeException('Profile Field ' . $path . ' must use an api4:' . $entity . ' semantic reference keyed exactly by ' . $keyField . '.');
    }
  }

  private function safe(string $value): string {
    $safe = trim((string) preg_replace('/[^A-Za-z0-9_.-]+/', '-', $value), '-');
    return $safe === '' ? sha1($value) : $safe;
  }
}
