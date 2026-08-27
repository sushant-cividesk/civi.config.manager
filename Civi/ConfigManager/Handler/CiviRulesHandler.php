<?php
namespace Civi\ConfigManager\Handler;

/**
 * Alpha handler for CiviRules configuration. It uses API4 entities when the
 * CiviRules extension exposes them. On sites without CiviRules/API4 metadata it
 * fails validation clearly instead of silently importing incomplete rules.
 */
class CiviRulesHandler extends AbstractHandler implements StreamingHandlerInterface, StreamingImportHandlerInterface {
  private bool $importWritesEnabled = TRUE;
  private bool $deleteMissingEnabled = TRUE;

  private array $entities = [
    'rules' => ['entity' => 'CiviRulesRule', 'identity' => 'name', 'order' => ['name' => 'ASC']],
    'triggers' => ['entity' => 'CiviRulesTrigger', 'identity' => 'name', 'order' => ['name' => 'ASC']],
    'conditions' => ['entity' => 'CiviRulesCondition', 'identity' => 'name', 'order' => ['name' => 'ASC']],
    'actions' => ['entity' => 'CiviRulesAction', 'identity' => 'name', 'order' => ['name' => 'ASC']],
    // Junction rows use database-local numeric IDs in the provider API. Keep
    // them exportable for backup/diff visibility, but never use those IDs as
    // cross-environment create/update/delete identities.
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

  public function export(): array {
    return iterator_to_array($this->iterateExport(), FALSE);
  }

  public function iterateExport(): iterable {
    $availability = $this->getRuntimeAvailability();
    if (empty($availability['available'])) {
      throw new \RuntimeException((string) $availability['reason']);
    }

    foreach ($this->entities as $bucket => $def) {
      if (!$this->entityAvailable($def['entity'])) {
        continue;
      }

      // Count semantic identities first using only compact strings. This is a
      // deliberate second provider pass: it prevents ambiguous names from being
      // mistaken for portable identities without retaining all provider rows.
      $counts = [];
      foreach ($this->api4Iterate($def['entity'], [], ['*'], $def['order']) as $rawRow) {
        $row = $this->cleanRow((array) $rawRow, $def);
        $identity = $this->identityValue($row, $def);
        if ($identity !== '') {
          $counts[$identity] = ($counts[$identity] ?? 0) + 1;
        }
      }

      foreach ($this->api4Iterate($def['entity'], [], ['*'], $def['order']) as $rawRow) {
        $rawRow = (array) $rawRow;
        $sourceId = isset($rawRow['id']) && is_scalar($rawRow['id']) ? (int) $rawRow['id'] : NULL;
        $row = $this->cleanRow($rawRow, $def);
        $identity = $this->identityValue($row, $def);
        if ($identity === '') {
          continue;
        }
        $portable = $this->isPortableDefinition($def) && (($counts[$identity] ?? 0) === 1);
        $filename = $bucket . '/' . $this->safeName($identity) . '.yml';
        if (!$portable) {
          // A duplicate semantic name is not a portable CRUD identity. Keep
          // each row visible as a deterministic backup/monitor document while
          // preventing import/delete from guessing which live row it means.
          $suffix = $this->nonPortableFingerprint($row);
          $filename = $bucket . '/' . $this->safeName($identity) . '--ambiguous-' . substr($suffix, 0, 12) . '.yml';
        }
        yield [
          'filename' => $filename,
          'source_id' => $sourceId,
          'data' => [
            'schema_version' => 1,
            'type' => 'civirules.item',
            'entity' => $def['entity'],
            'bucket' => $bucket,
            'name' => $identity,
            'identity_field' => $def['identity'],
            'identity_portable' => $portable,
            'identity_confidence' => $portable ? 'DISCOVERED_UNIQUE' : 'AMBIGUOUS',
            'dependencies' => $this->dependenciesForRow($def['entity'], $row),
            'item' => $row,
          ],
        ];
      }
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
      }
      $row = (array) ($file['item'] ?? []);
      $def = $this->definitionForEntity($entity);
      if ($def !== NULL && !$this->isPortableDefinition($def)) {
        $warnings[] = [
          'file' => $filename,
          'message' => $entity . ' is backup/monitor-only because its provider identity is a database-local numeric ID. Automatic cross-site create/update/delete stays blocked.',
        ];
        continue;
      }
      if (empty($file['identity_portable']) || (($file['identity_confidence'] ?? '') === 'AMBIGUOUS')) {
        $warnings[] = [
          'file' => $filename,
          'message' => $entity . ' is backup/monitor-only because this exported row does not have a proven unique portable identity. Automatic create/update/delete stays blocked.',
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
    $desired = [];
    foreach ($items as $filename => $file) {
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
      if ($def !== NULL && !$this->isPortableDefinition($def)) {
        $summary['skip']++;
        $summary['compatibility'][] = [
          'file' => $filename,
          'message' => $entity . ' remains backup/monitor-only because its provider identity is a database-local numeric ID. Automatic cross-site create/update/delete was not attempted.',
        ];
        continue;
      }
      if (empty($file['identity_portable']) || (($file['identity_confidence'] ?? '') === 'AMBIGUOUS')) {
        $summary['skip']++;
        $summary['compatibility'][] = [
          'file' => $filename,
          'message' => $entity . ' remains backup/monitor-only because this row does not have a proven unique portable identity. Automatic create/update/delete was not attempted.',
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
      if (!$this->importWritesEnabled) {
        continue;
      }
      try {
        $clean = $this->cleanImportRow($row, $identityField);
        $existing = $this->api4GetFirst($entity, [[$identityField, '=', $identityValue]], ['*']);
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
        $entity = $def['entity'];
        if (!$this->entityAvailable($entity)) {
          continue;
        }
        if (!$this->isPortableDefinition($def)) {
          $summary['compatibility'][] = [
            'message' => $entity . ' delete-missing is disabled because its provider identity is a database-local numeric ID.',
          ];
          continue;
        }
        // Delete-missing is safe only when every live row has a unique semantic
        // identity. A duplicate live name means YAML cannot authorize deletion
        // of either occurrence without guessing which record it represents.
        $liveCounts = [];
        foreach ($this->api4Iterate($entity, [], ['id', $def['identity']], $def['order']) as $existing) {
          $existing = (array) $existing;
          $field = $this->identityField($existing, $def['identity']);
          if ($field) {
            $value = (string) $existing[$field];
            $liveCounts[$value] = ($liveCounts[$value] ?? 0) + 1;
          }
        }
        foreach ($this->api4Iterate($entity, [], ['id', $def['identity']], $def['order']) as $existing) {
          $existing = (array) $existing;
          $field = $this->identityField($existing, $def['identity']);
          if (!$field) {
            continue;
          }
          $identityValue = (string) $existing[$field];
          if (($liveCounts[$identityValue] ?? 0) !== 1) {
            $summary['compatibility'][] = [
              'name' => $identityValue,
              'message' => $entity . ' delete-missing was skipped for duplicate identity "' . $identityValue . '" because it is not safe to map automatically.',
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
      if (!empty($row[$field])) {
        return $field;
      }
    }
    return NULL;
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

  private function nonPortableFingerprint(array $row): string {
    ksort($row);
    return hash('sha256', serialize($row));
  }

  private function safeName(string $name): string {
    $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name);
    return trim((string) $safe, '-') ?: sha1($name);
  }
}
