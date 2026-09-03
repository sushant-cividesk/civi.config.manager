<?php
namespace Civi\ConfigManager\Handler;

/**
 * Portable adapter for the Contact Layout Editor extension.
 *
 * ContactLayout stores several references inside serialized arrays. Export
 * converts the known local IDs to semantic references and refuses unknown
 * numeric *_id values rather than leaking database-local identifiers to YAML.
 */
class ContactLayoutHandler extends AbstractHandler implements StreamingHandlerInterface, StreamingImportHandlerInterface {

  public function getType(): string { return 'contact-layouts'; }
  public function getLabel(): string { return 'Contact Layouts'; }
  public function getDirectory(): string { return 'contact-layouts'; }
  public function getWeight(): int { return 145; }

  public function getProviderMetadata(): array {
    return [
      'owner' => 'org.civicrm.contactlayout',
      'api_version' => 'api4',
      'entity' => 'ContactLayout',
      'actions' => ['read' => TRUE, 'create' => TRUE, 'update' => TRUE, 'delete' => FALSE],
      'field_names' => ['label', 'contact_type', 'contact_sub_type', 'groups', 'weight', 'blocks', 'tabs', 'settings'],
      'identity_fields' => ['label'],
      'reference_fields' => ['groups', 'blocks.*.profile_id', 'blocks.*.custom_group_id', 'blocks.*.related_rel'],
      'sensitive_fields' => [],
      'runtime_fields' => ['id'],
      'management_capability' => 'managed_no_delete',
      'identity_evidence' => 'reviewed_adapter',
      'metadata_completeness' => 'declared',
    ];
  }

  public function getRuntimeAvailability(): array {
    $availability = $this->api4ManagementAvailability('ContactLayout', ['get', 'create', 'update']);
    if (empty($availability['available'])) {
      return $availability;
    }
    if (($availability['management_capability'] ?? '') === 'export_only') {
      return $availability;
    }
    return [
      'available' => TRUE,
      'management_capability' => 'managed_no_delete',
      'reason' => 'Contact layouts support reviewed portable create/update management; delete-missing is intentionally disabled.',
      'missing_actions' => [],
    ];
  }

  public function export(): array {
    return iterator_to_array($this->iterateExport(), FALSE);
  }

  public function iterateExport(): iterable {
    $seenLabels = [];
    foreach ($this->api4Iterate('ContactLayout', [], ['id', 'label', 'contact_type', 'contact_sub_type', 'groups', 'weight', 'blocks', 'tabs', 'settings'], ['weight' => 'ASC', 'label' => 'ASC']) as $row) {
      $row = (array) $row;
      $label = trim((string) ($row['label'] ?? ''));
      if ($label === '') {
        throw new \RuntimeException('ContactLayout has no portable label identity.');
      }
      if (isset($seenLabels[$label])) {
        throw new \RuntimeException('ContactLayout label is not unique and cannot be used safely as portable identity: ' . $label);
      }
      $seenLabels[$label] = TRUE;
      $dependencies = [];
      $portable = $this->toPortable($row, $dependencies);
      yield [
        'filename' => $this->safeFilePart($label) . '.yml',
        'source_id' => isset($row['id']) ? (int) $row['id'] : NULL,
        'data' => [
          'schema_version' => 1,
          'type' => 'contact-layouts.item',
          'entity' => 'ContactLayout',
          'key_fields' => ['label'],
          'key' => 'label=' . $label,
          'dependencies' => $this->uniqueDependencies($dependencies),
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
      if (($document['type'] ?? '') !== 'contact-layouts.item' || ($document['entity'] ?? '') !== 'ContactLayout') {
        $errors[] = ['file' => $filename, 'message' => 'Expected a contact-layouts.item ContactLayout document.'];
        continue;
      }
      $row = (array) ($document['item'] ?? []);
      $label = trim((string) ($row['label'] ?? ''));
      if ($label === '') {
        $errors[] = ['file' => $filename, 'message' => 'Contact layout is missing portable label identity.'];
      }
      elseif (isset($seen[$label])) {
        $errors[] = ['file' => $filename, 'message' => 'Duplicate ContactLayout label in YAML: ' . $label . '.'];
      }
      $seen[$label] = TRUE;
      if (array_key_exists('id', $row)) {
        $errors[] = ['file' => $filename, 'message' => 'Local ContactLayout id must not be stored in YAML.'];
      }
      try {
        $this->assertPortableValue($row, 'item');
      }
      catch (\Throwable $e) {
        $errors[] = ['file' => $filename, 'message' => $e->getMessage()];
      }
      if (!array_key_exists('dependencies', $document)) {
        $warnings[] = ['file' => $filename, 'message' => 'Dependency metadata is missing. Re-export this layout before deployment.'];
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
      $row = (array) ($document['item'] ?? []);
      $label = trim((string) ($row['label'] ?? ''));
      if (($document['type'] ?? '') !== 'contact-layouts.item' || $label === '') {
        $summary['errors'][] = ['file' => $filename, 'message' => 'Invalid ContactLayout document or missing label identity.'];
        continue;
      }
      try {
        $this->assertPortableValue($row, 'item');
        $desired = $this->fromPortable($row);
        $matches = $this->api4Get('ContactLayout', [['label', '=', $label]], ['*'], ['id' => 'ASC']);
        if (count($matches) > 1) {
          throw new \RuntimeException('ContactLayout label is not unique on the target site: ' . $label);
        }
        $existing = isset($matches[0]) ? (array) $matches[0] : [];
        if ($existing) {
          $unusedDependencies = [];
          $existingComparable = $this->toPortable($existing, $unusedDependencies);
          if ($this->desiredDiffers($existingComparable, $row)) {
            $summary['update']++;
            if (!$dryRun) {
              if (empty($existing['id'])) {
                throw new \RuntimeException('Existing ContactLayout has no local ID for update: ' . $label);
              }
              $this->api4Update('ContactLayout', [['id', '=', $existing['id']]], $desired);
            }
          }
          else {
            $summary['skip']++;
          }
        }
        else {
          $summary['create']++;
          if (!$dryRun) {
            $this->api4Create('ContactLayout', $desired);
          }
        }
      }
      catch (\Throwable $e) {
        $summary['errors'][] = ['file' => $filename, 'name' => $label, 'message' => $e->getMessage()];
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

  /** @param array<int,array<string,string>> $dependencies */
  private function toPortable(array $row, array &$dependencies): array {
    unset($row['id']);
    $row['groups'] = $this->exportGroupReferences((array) ($row['groups'] ?? []), $dependencies);
    if (isset($row['blocks'])) {
      $row['blocks'] = $this->transformNestedForExport($row['blocks'], $dependencies, 'blocks');
    }
    if (isset($row['settings'])) {
      $row['settings'] = $this->transformNestedForExport($row['settings'], $dependencies, 'settings');
    }
    $this->assertPortableValue($row, 'item');
    return $row;
  }

  private function fromPortable(array $row): array {
    unset($row['id']);
    $row['groups'] = $this->importGroupReferences((array) ($row['groups'] ?? []));
    if (isset($row['blocks'])) {
      $row['blocks'] = $this->transformNestedForImport($row['blocks'], 'blocks');
    }
    if (isset($row['settings'])) {
      $row['settings'] = $this->transformNestedForImport($row['settings'], 'settings');
    }
    return $row;
  }

  private function exportGroupReferences(array $groups, array &$dependencies): array {
    $portable = [];
    foreach ($groups as $groupId) {
      if ($groupId === NULL || $groupId === '') {
        continue;
      }
      $reference = $this->referenceFromId('Group', $groupId, ['name']);
      $portable[] = $reference;
      $dependencies[] = ['type' => 'groups', 'name' => (string) $reference['key']['name'], 'reason' => 'Contact layout visibility references this group.'];
    }
    return $portable;
  }

  private function importGroupReferences(array $groups): array {
    $ids = [];
    foreach ($groups as $reference) {
      $ids[] = $this->idFromReference('Group', $reference, ['name']);
    }
    return $ids;
  }

  private function transformNestedForExport($value, array &$dependencies, string $path) {
    if (!is_array($value)) {
      return $value;
    }
    $result = [];
    foreach ($value as $key => $child) {
      $childPath = $path . '.' . (string) $key;
      if ($key === 'profile_id' && is_scalar($child) && (string) $child !== '') {
        $reference = $this->referenceFromId('UFGroup', $child, ['name']);
        $result[$key] = $reference;
        $dependencies[] = ['type' => 'profiles', 'name' => (string) $reference['key']['name'], 'reason' => 'Contact layout block references this profile.'];
        continue;
      }
      if ($key === 'custom_group_id' && is_scalar($child) && (string) $child !== '') {
        $reference = $this->referenceFromId('CustomGroup', $child, ['name']);
        $result[$key] = $reference;
        $dependencies[] = ['type' => 'custom-data', 'name' => (string) $reference['key']['name'], 'reason' => 'Contact layout block references this custom group.'];
        continue;
      }
      if ($key === 'related_rel' && is_string($child) && preg_match('/^(\d+)_(ab|ba|r)$/', $child, $matches)) {
        $reference = $this->referenceFromId('RelationshipType', (int) $matches[1], ['name_a_b']);
        $result[$key] = ['relationship_type' => $reference, 'direction' => $matches[2]];
        $dependencies[] = ['type' => 'relationship-types', 'name' => (string) $reference['key']['name_a_b'], 'reason' => 'Contact layout related-contact block references this relationship type.'];
        continue;
      }
      $result[$key] = $this->transformNestedForExport($child, $dependencies, $childPath);
    }
    return $result;
  }

  private function transformNestedForImport($value, string $path) {
    if (!is_array($value)) {
      return $value;
    }
    $result = [];
    foreach ($value as $key => $child) {
      $childPath = $path . '.' . (string) $key;
      if ($key === 'profile_id' && is_array($child)) {
        $result[$key] = $this->idFromReference('UFGroup', $child, ['name']);
        continue;
      }
      if ($key === 'custom_group_id' && is_array($child)) {
        $result[$key] = $this->idFromReference('CustomGroup', $child, ['name']);
        continue;
      }
      if ($key === 'related_rel' && is_array($child) && isset($child['relationship_type'], $child['direction'])) {
        $id = $this->idFromReference('RelationshipType', $child['relationship_type'], ['name_a_b']);
        $direction = (string) $child['direction'];
        if (!in_array($direction, ['ab', 'ba', 'r'], TRUE)) {
          throw new \RuntimeException('Invalid ContactLayout related relationship direction at ' . $childPath . '.');
        }
        $result[$key] = $id . '_' . $direction;
        continue;
      }
      $result[$key] = $this->transformNestedForImport($child, $childPath);
    }
    return $result;
  }

  private function referenceFromId(string $entity, $id, array $keyFields): array {
    $target = $this->api4GetFirst($entity, [['id', '=', $id]], array_merge(['id'], $keyFields));
    if (!$target) {
      throw new \RuntimeException('Could not resolve ContactLayout local reference ' . $entity . ' id=' . (string) $id . '.');
    }
    $key = [];
    foreach ($keyFields as $field) {
      if (!isset($target[$field]) || (string) $target[$field] === '') {
        throw new \RuntimeException('ContactLayout reference ' . $entity . ' is missing stable key field ' . $field . '.');
      }
      $key[$field] = $target[$field];
    }
    return ['provider' => 'api4:' . $entity, 'entity' => $entity, 'key' => $key];
  }

  private function idFromReference(string $entity, $reference, array $keyFields) {
    if (!is_array($reference) || ($reference['entity'] ?? $entity) !== $entity || !isset($reference['key']) || !is_array($reference['key'])) {
      throw new \RuntimeException('ContactLayout reference must be semantic for ' . $entity . '.');
    }
    $where = [];
    foreach ($keyFields as $field) {
      $value = $reference['key'][$field] ?? NULL;
      if (!is_scalar($value) || (string) $value === '') {
        throw new \RuntimeException('ContactLayout reference to ' . $entity . ' is missing stable key ' . $field . '.');
      }
      $where[] = [$field, '=', $value];
    }
    $target = $this->api4GetFirst($entity, $where, ['id']);
    if (!$target || !isset($target['id'])) {
      throw new \RuntimeException('Could not resolve ContactLayout semantic reference to ' . $entity . '.');
    }
    return $target['id'];
  }

  private function assertPortableValue($value, string $path): void {
    if (!is_array($value)) {
      return;
    }
    foreach ($value as $key => $child) {
      $childPath = $path . '.' . (string) $key;
      if (is_string($key) && preg_match('/_id$/', $key) && is_scalar($child) && $child !== '' && $child !== NULL) {
        throw new \RuntimeException('ContactLayout contains unresolved local numeric/reference field at ' . $childPath . '.');
      }
      $this->assertPortableValue($child, $childPath);
    }
  }

  private function uniqueDependencies(array $dependencies): array {
    $seen = [];
    $result = [];
    foreach ($dependencies as $dependency) {
      $key = json_encode($dependency);
      if ($key === FALSE || isset($seen[$key])) {
        continue;
      }
      $seen[$key] = TRUE;
      $result[] = $dependency;
    }
    return $result;
  }

  private function safeFilePart(string $name): string {
    $safe = trim((string) preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name), '-');
    return $safe === '' ? sha1($name) : $safe;
  }
}
