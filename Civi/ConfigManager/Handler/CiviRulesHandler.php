<?php
namespace Civi\ConfigManager\Handler;

use Civi\ConfigManager\Service\DiskRowSpool;

/**
 * CiviRules configuration handler.
 *
 * Alpha63 treats an unproven/duplicate identity as a monitor-only snapshot
 * rather than an export blocker. Every occurrence remains visible and
 * deterministic, but automatic create/update/delete is allowed only for a
 * proven unique portable identity.
 */
class CiviRulesHandler extends AbstractHandler implements StreamingHandlerInterface, StreamingImportHandlerInterface, ChunkedStreamingHandlerInterface {
  private bool $importWritesEnabled = TRUE;
  private bool $deleteMissingEnabled = TRUE;

  private array $entities = [
    'rules' => ['entity' => 'CiviRulesRule', 'identity' => 'name', 'order' => ['name' => 'ASC', 'id' => 'ASC']],
    'triggers' => ['entity' => 'CiviRulesTrigger', 'identity' => 'name', 'order' => ['name' => 'ASC', 'id' => 'ASC']],
    'conditions' => ['entity' => 'CiviRulesCondition', 'identity' => 'name', 'order' => ['name' => 'ASC', 'id' => 'ASC']],
    'actions' => ['entity' => 'CiviRulesAction', 'identity' => 'name', 'order' => ['name' => 'ASC', 'id' => 'ASC']],
    // Junction rows expose database-local numeric IDs. They remain useful for
    // same-site monitoring/backups, but those IDs never become portable import
    // identities. Expanded semantic references are used only for display/hash
    // stability where the provider supplies them.
    'rule-conditions' => ['entity' => 'CiviRulesRuleCondition', 'identity' => 'id', 'order' => ['id' => 'ASC'], 'portable' => FALSE],
    'rule-actions' => ['entity' => 'CiviRulesRuleAction', 'identity' => 'id', 'order' => ['id' => 'ASC'], 'portable' => FALSE],
  ];

  public function getType(): string { return 'civirules'; }
  public function getLabel(): string { return 'CiviRules'; }
  public function getDirectory(): string { return 'civirules'; }
  public function getWeight(): int { return 150; }

  public function getRuntimeAvailability(): array {
    $checks = [];
    $missingEntities = [];
    foreach ($this->entities as $def) {
      $entity = (string) $def['entity'];
      $check = $this->api4ManagementAvailability($entity, ['get', 'create', 'update', 'delete']);
      $checks[] = $check;
      if (empty($check['available'])) {
        $missingEntities[] = $entity;
      }
    }

    $availability = $this->combineApi4ManagementAvailability($checks, 'CiviRules');
    if ($missingEntities) {
      $availability['reason'] = 'CiviRules provider coverage is incomplete on this site. Missing/unreadable API4 entities: ' . implode(', ', $missingEntities) . '. Install/enable a supported CiviRules version before managing this type.';
    }
    elseif (($availability['management_capability'] ?? 'full') !== 'full') {
      $availability['reason'] = 'CiviRules is readable, but one or more API4 entities do not expose the complete create/update/delete action surface required for safe managed import. ' . $availability['reason'];
    }
    return $availability;
  }

  public function setImportWriteEnabled(bool $enabled): self { $this->importWritesEnabled = $enabled; return $this; }
  public function setDeleteMissingEnabled(bool $enabled): self { $this->deleteMissingEnabled = $enabled; return $this; }

  /** @return array<int,array{key:string,label:string,path_prefix:string}> */
  public function getExportUnits(): array {
    $units = [];
    foreach ($this->entities as $bucket => $def) {
      $units[] = [
        'key' => $bucket,
        'label' => 'CiviRules ' . $this->bucketLabel($bucket),
        'path_prefix' => $bucket,
      ];
    }
    return $units;
  }

  public function export(): array {
    $files = iterator_to_array($this->iterateExport(), FALSE);
    usort($files, static function(array $a, array $b): int {
      return strnatcasecmp((string) ($a['filename'] ?? ''), (string) ($b['filename'] ?? ''));
    });
    return $files;
  }

  public function iterateExport(): iterable {
    $this->assertRuntimeAvailable();
    foreach ($this->getExportUnits() as $unit) {
      foreach ($this->iterateExportUnit((string) $unit['key']) as $file) {
        yield $file;
      }
    }
  }

  public function iterateExportUnit(string $unitKey, ?callable $progress = NULL): iterable {
    $this->assertRuntimeAvailable();
    if (!isset($this->entities[$unitKey])) {
      throw new \RuntimeException('Unknown CiviRules export unit: ' . $unitKey);
    }
    $def = $this->entities[$unitKey];
    $entity = (string) $def['entity'];
    if (!$this->entityAvailable($entity)) {
      return;
    }

    // One provider scan only: spool cleaned rows to disk while retaining compact
    // identity/fingerprint multiplicities. The second phase reads the disk spool
    // instead of issuing the same API query again.
    $spool = new DiskRowSpool();
    $identityCounts = [];
    $fingerprintCounts = [];
    try {
      $scanned = 0;
      foreach ($this->api4Iterate($entity, [], ['*'], (array) $def['order']) as $rawRow) {
        $rawRow = (array) $rawRow;
        $sourceId = isset($rawRow['id']) && is_scalar($rawRow['id']) && is_numeric($rawRow['id']) ? (int) $rawRow['id'] : NULL;
        $rawIdentity = $this->identityValue($rawRow, $def);
        $row = $this->cleanRow($rawRow, $def);

        if ($this->isPortableDefinition($def)) {
          if ($rawIdentity === '') {
            continue;
          }
          $identity = $rawIdentity;
          $fingerprint = $this->nonPortableFingerprint($row);
        }
        else {
          $identity = $this->monitorDisplayIdentity($unitKey, $row);
          $fingerprint = $this->nonPortableFingerprint($this->monitorFingerprintRow($row));
        }

        $identityCounts[$identity] = ($identityCounts[$identity] ?? 0) + 1;
        $fingerprintKey = $identity . "\0" . $fingerprint;
        $fingerprintCounts[$fingerprintKey] = ($fingerprintCounts[$fingerprintKey] ?? 0) + 1;
        $spool->append([
          'source_id' => $sourceId,
          'identity' => $identity,
          'fingerprint' => $fingerprint,
          'row' => $row,
        ]);
        $scanned++;
        if ($progress !== NULL && ($scanned % 100) === 0) {
          $progress(['processed' => $scanned, 'stage' => 'scan', 'message' => 'Scanning active CiviRules ' . $this->bucketLabel($unitKey) . ': ' . $scanned . ' record(s) checked for portable/ambiguous identity.']);
        }
      }

      if ($progress !== NULL) {
        $progress(['processed' => $scanned, 'stage' => 'spool_complete', 'message' => 'Identity scan complete for CiviRules ' . $this->bucketLabel($unitKey) . '. Building deterministic temporary YAML from the disk spool.']);
      }
      $occurrences = [];
      foreach ($spool->iterate() as $entry) {
        $identity = (string) ($entry['identity'] ?? '');
        $fingerprint = (string) ($entry['fingerprint'] ?? '');
        $row = (array) ($entry['row'] ?? []);
        $sourceId = isset($entry['source_id']) && is_numeric($entry['source_id']) ? (int) $entry['source_id'] : NULL;
        $groupCount = (int) ($identityCounts[$identity] ?? 0);
        $fingerprintKey = $identity . "\0" . $fingerprint;
        $contentCount = (int) ($fingerprintCounts[$fingerprintKey] ?? 0);
        $occurrence = ($occurrences[$fingerprintKey] ?? 0) + 1;
        $occurrences[$fingerprintKey] = $occurrence;

        $portable = $this->isPortableDefinition($def) && $groupCount === 1;
        if ($portable) {
          $filename = $unitKey . '/' . $this->safeName($identity) . '.yml';
          $ambiguity = NULL;
        }
        else {
          // Occurrence is intentionally part of the *snapshot filename*, not
          // portable identity. Rows are scanned in deterministic provider order.
          // Identical duplicate content therefore becomes -01, -02, ... without
          // colliding, while Synchronize can compare the fingerprint multiset.
          $filename = $unitKey . '/' . $this->safeName($identity)
            . '--ambiguous-' . substr($fingerprint, 0, 12)
            . '-' . str_pad((string) $occurrence, 2, '0', STR_PAD_LEFT) . '.yml';
          $ambiguity = [
            'reason' => $this->isPortableDefinition($def) ? 'duplicate_portable_identity' : 'local_id_only',
            'group_count' => $groupCount,
            'content_count' => $contentCount,
            'content_fingerprint' => $fingerprint,
            'occurrence' => $occurrence,
          ];
        }

        $data = [
          'schema_version' => 1,
          'type' => 'civirules.item',
          'entity' => $entity,
          'bucket' => $unitKey,
          'name' => $identity,
          'identity_field' => (string) $def['identity'],
          'identity_portable' => $portable,
          'identity_confidence' => $portable ? 'DISCOVERED_UNIQUE' : 'AMBIGUOUS',
          'monitor_only' => !$portable,
          'dependencies' => $this->dependenciesForRow($entity, $row),
          'item' => $row,
        ];
        if ($ambiguity !== NULL) {
          $data['ambiguity'] = $ambiguity;
        }

        yield [
          'filename' => $filename,
          'source_id' => $sourceId,
          'data' => $data,
        ];
      }
    }
    finally {
      $spool->close();
    }
  }

  public function validate(array $items): array {
    $errors = [];
    $warnings = [];
    foreach ($items as $filename => $file) {
      if (($file['type'] ?? '') !== 'civirules.item') {
        $errors[] = ['file' => $filename, 'message' => 'Invalid type. Expected civirules.item.'];
        continue;
      }
      $entity = (string) ($file['entity'] ?? '');
      if ($entity === '' || !$this->entityAvailable($entity)) {
        $errors[] = ['file' => $filename, 'message' => 'CiviRules API4 entity is not available on this site: ' . ($entity ?: '[missing entity]') . '. Install/enable CiviRules before importing this YAML.'];
        continue;
      }
      $row = (array) ($file['item'] ?? []);
      $def = $this->definitionForEntity($entity);
      if ($def !== NULL && (!$this->isPortableDefinition($def) || $this->isMonitorOnlyDocument($file))) {
        $warnings[] = [
          'file' => $filename,
          'message' => $entity . ' is a monitor-only snapshot because it does not have a proven unique portable identity. It will be compared by snapshot/fingerprint state but automatic create/update/delete remains disabled.',
        ];
        continue;
      }
      if (!$this->identityField($row, (string) ($file['identity_field'] ?? ''))) {
        $errors[] = ['file' => $filename, 'message' => 'CiviRules item is missing a stable identity field. Re-export from source site.'];
      }
    }
    return ['type' => $this->getType(), 'valid' => empty($errors), 'warnings' => $warnings, 'errors' => $errors, 'count' => count($items)];
  }

  public function import(array $items, bool $dryRun = TRUE): array {
    return $this->importIterable($items, $dryRun);
  }

  public function importIterable(iterable $items, bool $dryRun = TRUE): array {
    $summary = $this->baseImportSummary($dryRun);
    $summary['compatibility'] = [];
    $summary['monitor_only'] = 0;
    $desired = [];

    foreach ($items as $filename => $file) {
      $file = (array) $file;
      if (($file['type'] ?? '') !== 'civirules.item') {
        $summary['errors'][] = ['file' => $filename, 'message' => 'Invalid type. Expected civirules.item.'];
        continue;
      }
      $entity = (string) ($file['entity'] ?? '');
      if (!$this->entityAvailable($entity)) {
        $summary['errors'][] = ['file' => $filename, 'message' => 'CiviRules API4 entity is not available on this site: ' . $entity];
        continue;
      }
      $def = $this->definitionForEntity($entity);
      if ($def !== NULL && (!$this->isPortableDefinition($def) || $this->isMonitorOnlyDocument($file))) {
        $summary['skip']++;
        $summary['monitor_only']++;
        $summary['compatibility'][] = [
          'file' => $filename,
          'message' => $entity . ' is an intentional monitor-only snapshot. Automatic create/update/delete was not attempted; other proven-safe configuration may continue importing.',
        ];
        continue;
      }

      $identityField = (string) ($file['identity_field'] ?? 'name');
      $row = (array) ($file['item'] ?? []);
      $identityField = $this->identityField($row, $identityField);
      if (!$identityField) {
        $summary['errors'][] = ['file' => $filename, 'message' => 'CiviRules item is missing identity field.'];
        continue;
      }
      $identityValue = (string) $row[$identityField];
      $desired[$entity][$identityField . ':' . $identityValue] = TRUE;

      // A YAML document which was proven portable at source but matches more
      // than one target row is a blocking target conflict, not monitor-only
      // source data. Dry-run reports the blocker and a real import performs no
      // write for this item; ConfigManager's full preflight prevents all writes.
      try {
        $matches = $this->portableTargetMatches($entity, $identityField, $identityValue);
      }
      catch (\Throwable $e) {
        $summary['errors'][] = ['file' => $filename, 'name' => $identityValue, 'message' => $e->getMessage()];
        continue;
      }
      if (count($matches) > 1) {
        $summary['errors'][] = [
          'file' => $filename,
          'name' => $identityValue,
          'message' => 'CiviRules target conflict: portable identity "' . $identityValue . '" matches more than one active ' . $entity . ' row. Import is blocked until the target ambiguity is resolved.',
        ];
        continue;
      }

      if (!$this->importWritesEnabled) {
        continue;
      }
      try {
        $clean = $this->cleanImportRow($row, $identityField);
        $existing = $matches ? $matches[0] : NULL;
        if ($existing) {
          if ($this->desiredDiffers($existing, $clean)) {
            $summary['update']++;
            if (!$dryRun) {
              $this->api4Update($entity, [['id', '=', $existing['id']]], $clean);
            }
          }
          else {
            $summary['skip']++;
          }
        }
        else {
          $summary['create']++;
          if (!$dryRun) {
            $this->api4Create($entity, $clean);
          }
        }
      }
      catch (\Throwable $e) {
        $summary['errors'][] = ['file' => $filename, 'name' => $identityValue, 'message' => $e->getMessage()];
      }
    }

    if ($this->deleteMissingEnabled) {
      foreach ($this->entities as $def) {
        $entity = (string) $def['entity'];
        if (!$this->entityAvailable($entity)) {
          continue;
        }
        if (!$this->isPortableDefinition($def)) {
          $summary['compatibility'][] = [
            'message' => $entity . ' delete-missing is disabled because this provider entity does not expose a portable identity.',
          ];
          continue;
        }

        // Per-identity safety: duplicates do not disable cleanup for unrelated
        // unique rows in the same provider.
        $liveCounts = [];
        foreach ($this->api4Iterate($entity, [], ['id', $def['identity']], (array) $def['order']) as $existing) {
          $existing = (array) $existing;
          $field = $this->identityField($existing, (string) $def['identity']);
          if ($field) {
            $value = (string) $existing[$field];
            $liveCounts[$value] = ($liveCounts[$value] ?? 0) + 1;
          }
        }
        foreach ($this->api4Iterate($entity, [], ['id', $def['identity']], (array) $def['order']) as $existing) {
          $existing = (array) $existing;
          $field = $this->identityField($existing, (string) $def['identity']);
          if (!$field) {
            continue;
          }
          $identityValue = (string) $existing[$field];
          if (($liveCounts[$identityValue] ?? 0) !== 1) {
            $summary['compatibility'][] = [
              'name' => $identityValue,
              'message' => $entity . ' delete-missing skipped duplicate identity "' . $identityValue . '"; other unique identities remain eligible for safe cleanup.',
            ];
            continue;
          }
          if (isset($desired[$entity][$field . ':' . $identityValue])) {
            continue;
          }
          $summary['delete']++;
          $summary['warnings'][] = ['name' => $identityValue, 'message' => $entity . ' exists in CiviCRM but not YAML and will be deleted: ' . $identityValue];
          if (!$dryRun) {
            $this->api4Delete($entity, [['id', '=', (int) $existing['id']]]);
          }
        }
      }
    }

    $summary['ok'] = empty($summary['errors']);
    return $summary;
  }

  private function assertRuntimeAvailable(): void {
    $availability = $this->getRuntimeAvailability();
    if (empty($availability['available'])) {
      throw new \RuntimeException((string) $availability['reason']);
    }
  }

  private function definitionForEntity(string $entity): ?array {
    foreach ($this->entities as $def) {
      if ((string) ($def['entity'] ?? '') === $entity) {
        return $def;
      }
    }
    return NULL;
  }

  private function isPortableDefinition(array $def): bool {
    return !array_key_exists('portable', $def) || !empty($def['portable']);
  }

  private function isMonitorOnlyDocument(array $file): bool {
    return !empty($file['monitor_only'])
      // Alpha61/62 portable YAML did not always carry identity_portable.
      // Absence is legacy metadata, not evidence that the identity is unsafe.
      || (array_key_exists('identity_portable', $file) && empty($file['identity_portable']))
      || (($file['identity_confidence'] ?? '') === 'AMBIGUOUS');
  }

  private function entityAvailable(string $entity): bool {
    return $entity !== '' && class_exists('Civi\\Api4\\' . $entity);
  }

  private function cleanRow(array $row, array $def): array {
    unset($row['id']);
    return $row;
  }

  private function cleanImportRow(array $row, string $identityField): array {
    unset($row['id']);
    foreach (array_keys($row) as $key) {
      if (strpos((string) $key, '.') !== FALSE) {
        unset($row[$key]);
      }
    }
    return $row;
  }

  private function identityValue(array $row, array $def): string {
    $field = $this->identityField($row, (string) $def['identity']);
    return $field ? (string) $row[$field] : '';
  }

  private function identityField(array $row, string $preferred): ?string {
    foreach (array_filter([$preferred, 'name', 'label', 'title']) as $field) {
      if (array_key_exists($field, $row) && is_scalar($row[$field]) && trim((string) $row[$field]) !== '') {
        return $field;
      }
    }
    return NULL;
  }

  /** @return array<int,array<string,mixed>> */
  private function portableTargetMatches(string $entity, string $field, string $identity): array {
    $matches = [];
    foreach ($this->api4Iterate($entity, [[$field, '=', $identity]], ['*'], ['id' => 'ASC']) as $row) {
      $matches[] = (array) $row;
      if (count($matches) > 1) {
        break;
      }
    }
    return $matches;
  }

  private function dependenciesForRow(string $entity, array $row): array {
    $dependencies = [];
    foreach (['trigger_id.name' => 'CiviRulesTrigger', 'condition_id.name' => 'CiviRulesCondition', 'action_id.name' => 'CiviRulesAction', 'rule_id.name' => 'CiviRulesRule'] as $field => $depEntity) {
      if (!empty($row[$field])) {
        $dependencies[] = ['type' => 'civirules', 'entity' => $depEntity, 'name' => (string) $row[$field], 'reason' => $entity . ' references this CiviRules component.'];
      }
    }
    return $dependencies;
  }

  private function monitorDisplayIdentity(string $bucket, array $row): string {
    $parts = [];
    if (!empty($row['rule_id.name'])) {
      $parts[] = (string) $row['rule_id.name'];
    }
    if ($bucket === 'rule-actions' && !empty($row['action_id.name'])) {
      $parts[] = (string) $row['action_id.name'];
    }
    if ($bucket === 'rule-conditions' && !empty($row['condition_id.name'])) {
      $parts[] = (string) $row['condition_id.name'];
    }
    return $parts ? implode('--', $parts) : 'junction';
  }

  /**
   * Remove database-local relationship IDs from the monitor fingerprint only
   * when the provider also supplied the corresponding semantic name.
   */
  private function monitorFingerprintRow(array $row): array {
    foreach (['rule_id', 'action_id', 'condition_id', 'trigger_id'] as $field) {
      if (array_key_exists($field . '.name', $row) && trim((string) $row[$field . '.name']) !== '') {
        unset($row[$field]);
      }
    }
    return $row;
  }

  private function nonPortableFingerprint(array $row): string {
    $this->sortRecursive($row);
    return hash('sha256', serialize($row));
  }

  private function sortRecursive(array &$value): void {
    foreach ($value as &$child) {
      if (is_array($child)) {
        $this->sortRecursive($child);
      }
    }
    unset($child);
    ksort($value, SORT_STRING);
  }

  private function bucketLabel(string $bucket): string {
    return ucwords(str_replace('-', ' ', $bucket));
  }

  private function safeName(string $name): string {
    $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name);
    return trim((string) $safe, '-') ?: sha1($name);
  }
}
