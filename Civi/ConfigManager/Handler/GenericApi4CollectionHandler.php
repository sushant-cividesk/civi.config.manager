<?php
namespace Civi\ConfigManager\Handler;

class GenericApi4CollectionHandler extends AbstractHandler {
  private string $type;
  private string $label;
  private string $directory;
  private string $entity;
  private array $select;
  private array $orderBy;
  private int $weight;
  private string $fileName;
  private bool $splitFiles;
  private bool $importWritesEnabled = TRUE;
  private bool $deleteMissingEnabled = TRUE;

  public function __construct(string $type, string $label, string $directory, string $entity, array $select, array $orderBy, int $weight, string $fileName, bool $splitFiles = FALSE) {
    $this->type = $type;
    $this->label = $label;
    $this->directory = $directory;
    $this->entity = $entity;
    $this->select = $select;
    $this->orderBy = $orderBy;
    $this->weight = $weight;
    $this->fileName = $fileName;
    $this->splitFiles = $splitFiles;
  }

  public function getType(): string { return $this->type; }
  public function getLabel(): string { return $this->label; }
  public function getDirectory(): string { return $this->directory; }
  public function getWeight(): int { return $this->weight; }

  public function getRuntimeAvailability(): array {
    $class = 'Civi\\Api4\\' . $this->entity;
    if (class_exists($class)) {
      return ['available' => TRUE, 'reason' => ''];
    }
    return [
      'available' => FALSE,
      'reason' => 'API4 entity ' . $this->entity . ' is not available on this site. Install/enable the provider before managing this type.',
    ];
  }

  public function setImportWriteEnabled(bool $enabled): self {
    $this->importWritesEnabled = $enabled;
    return $this;
  }

  public function setDeleteMissingEnabled(bool $enabled): self {
    $this->deleteMissingEnabled = $enabled;
    return $this;
  }

  public function export(): array {
    $rawRows = $this->api4Get($this->entity, [], array_values(array_unique(array_merge(['id'], $this->select))), $this->orderBy);
    $rows = array_map(function($row) {
      return $this->cleanExportRow((array) $row);
    }, $rawRows);

    if (!$this->splitFiles) {
      return [[
        'filename' => $this->fileName,
        'data' => [
          'schema_version' => 1,
          'type' => $this->type . '.collection',
          'entity' => $this->entity,
          'dependencies' => $this->collectionDependencies($rows),
          'items' => $rows,
        ],
      ]];
    }

    $files = [];
    foreach ($rawRows as $rawRow) {
      $rawRow = (array) $rawRow;
      $sourceId = isset($rawRow['id']) && is_scalar($rawRow['id']) ? (int) $rawRow['id'] : NULL;
      $row = $this->cleanExportRow($rawRow);
      $identityField = $this->getIdentityField($row);
      if (!$identityField) {
        continue;
      }
      $name = (string) $row[$identityField];
      $files[] = [
        'filename' => $this->fileNameForRow($row, $name),
        'source_id' => $sourceId,
        'data' => [
          'schema_version' => 1,
          'type' => $this->type . '.item',
          'entity' => $this->entity,
          'name' => $name,
          'identity_field' => $identityField,
          'identity_confidence' => 'DISCOVERED_UNIQUE',
          'dependencies' => $this->dependenciesForRow($row),
          'item' => $row,
        ],
      ];
    }

    usort($files, fn($a, $b) => strcmp($a['filename'], $b['filename']));
    return $files;
  }

  public function validate(array $items): array {
    $errors = [];
    $warnings = [];
    foreach ($items as $filename => $item) {
      $type = (string) ($item['type'] ?? '');
      if ($type === $this->type . '.collection') {
        if (($item['entity'] ?? '') !== $this->entity) {
          $errors[] = [
            'file' => $filename,
            'message' => 'Invalid entity. Expected ' . $this->entity . '.',
          ];
        }
        foreach (($item['items'] ?? []) as $index => $row) {
          if (!$this->getIdentityField((array) $row)) {
            $errors[] = [
              'file' => $filename,
              'message' => 'Item at index ' . $index . ' is missing a supported identity field.',
            ];
          }
        }
        if ($this->splitFiles) {
          $warnings[] = [
            'file' => $filename,
            'message' => 'Collection format is still accepted for import, but the current export format writes one YAML file per item.',
          ];
        }
        continue;
      }

      if ($type === $this->type . '.item') {
        if (($item['entity'] ?? '') !== $this->entity) {
          $errors[] = [
            'file' => $filename,
            'message' => 'Invalid entity. Expected ' . $this->entity . '.',
          ];
        }
        $row = (array) ($item['item'] ?? []);
        if (!$this->getIdentityField($row)) {
          $errors[] = [
            'file' => $filename,
            'message' => 'Item file is missing a supported identity field in item.',
          ];
        }
        if (!array_key_exists('dependencies', $item)) {
          $warnings[] = [
            'file' => $filename,
            'message' => 'Dependency metadata is missing. Re-export this item before using it as deployment source.',
          ];
        }
        continue;
      }

      $errors[] = [
        'file' => $filename,
        'message' => 'Invalid type. Expected ' . $this->type . '.collection or ' . $this->type . '.item.',
      ];
    }
    return [
      'type' => $this->getType(),
      'valid' => empty($errors),
      'warnings' => $warnings,
      'errors' => $errors,
      'count' => count($items),
    ];
  }

  public function import(array $items, bool $dryRun = TRUE): array {
    $summary = [
      'type' => $this->getType(),
      'status' => $dryRun ? 'dry_run' : 'applied',
      'dry_run' => $dryRun,
      'create' => 0,
      'update' => 0,
      'delete' => 0,
      'skip' => 0,
      'warnings' => [],
      'errors' => [],
    ];

    $desiredKeys = [];
    $identityFields = [];

    foreach ($this->expandFilesToRows($items, $summary) as $entry) {
      $filename = $entry['filename'];
      $row = (array) $entry['row'];
      $identityField = $this->getIdentityField($row);
      if (!$identityField) {
        $summary['errors'][] = ['file' => $filename, 'message' => 'Item is missing a supported identity field.'];
        continue;
      }

      $identityValue = (string) $row[$identityField];
      $desiredKeys[$this->identityKeyForRow($row, $identityField, $identityValue)] = TRUE;
      $identityFields[$identityField] = TRUE;

      if (!$this->importWritesEnabled) {
        continue;
      }

      try {
        $desired = $this->cleanImportValues($row);
        $existing = $this->findExistingRow($identityField, $identityValue, $row, $desired, $summary, $filename);
        if ($existing) {
          $this->applyExistingRow($existing, $desired, $dryRun, $summary);
        }
        else {
          $summary['create']++;
          if (!$dryRun) {
            try {
              $this->api4Create($this->entity, $desired);
            }
            catch (\Throwable $createError) {
              if ($this->isAlreadyExistsError($createError)) {
                $existing = $this->findExistingRow($identityField, $identityValue, $row, $desired, $summary, $filename, TRUE);
                if ($existing) {
                  $summary['create']--;
                  $summary['warnings'][] = [
                    'file' => $filename,
                    'name' => $identityValue,
                    'message' => $this->entity . ' already existed on the target site, so import matched the existing record instead of creating a duplicate: ' . $identityValue,
                  ];
                  $this->applyExistingRow($existing, $desired, $dryRun, $summary);
                }
                else {
                  throw $createError;
                }
              }
              else {
                throw $createError;
              }
            }
          }
        }
      }
      catch (\Throwable $e) {
        $summary['errors'][] = [
          'file' => $filename,
          'name' => $identityValue,
          'message' => $this->formatImportException($e, $filename, $identityValue),
        ];
      }
    }

    if ($this->deleteMissingEnabled) {
      $this->deleteRecordsMissingFromYaml($desiredKeys, array_keys($identityFields), $dryRun, $summary);
    }

    $summary['ok'] = empty($summary['errors']);
    return $summary;
  }


  private function deleteRecordsMissingFromYaml(array $desiredKeys, array $identityFields, bool $dryRun, array &$summary): void {
    if (!$identityFields) {
      $identityFields = array_values(array_intersect(['name', 'name_a_b', 'title'], $this->select));
    }
    if (!$identityFields) {
      return;
    }
    $select = array_values(array_unique(array_merge(['id'], $identityFields)));
    if ($this->entity === 'SearchDisplay' && in_array('saved_search_id.name', $this->select, TRUE)) {
      $select[] = 'saved_search_id.name';
    }
    $existingRows = $this->api4Get($this->entity, [], array_values(array_unique($select)), $this->orderBy);
    foreach ($existingRows as $existing) {
      $existing = (array) $existing;
      if (empty($existing['id'])) {
        continue;
      }
      $identityField = $this->getIdentityField($existing);
      if (!$identityField) {
        continue;
      }
      $identityValue = (string) $existing[$identityField];
      if (isset($desiredKeys[$this->identityKeyForRow($existing, $identityField, $identityValue)])) {
        continue;
      }
      $summary['delete']++;
      $summary['warnings'][] = [
        'name' => $identityValue,
        'message' => sprintf('%s exists in CiviCRM but not in YAML and will be deleted when import is applied.', $identityValue),
      ];
      if (!$dryRun) {
        try {
          $this->api4Delete($this->entity, [['id', '=', $existing['id']]]);
        }
        catch (\Throwable $e) {
          $summary['errors'][] = [
            'name' => $identityValue,
            'message' => 'Delete failed: ' . $e->getMessage(),
          ];
        }
      }
    }
  }

  private function identityKey(string $field, string $value): string {
    return $field . ':' . $value;
  }

  private function identityKeyForRow(array $row, string $field, string $value): string {
    if ($this->entity === 'SearchDisplay') {
      $savedSearchName = (string) ($row['saved_search_id.name'] ?? '');
      if ($savedSearchName !== '') {
        return 'saved_search_id.name:' . $savedSearchName . '|name:' . $value;
      }
      if (!empty($row['saved_search_id'])) {
        return 'saved_search_id:' . (string) $row['saved_search_id'] . '|name:' . $value;
      }
    }
    return $this->identityKey($field, $value);
  }

  private function findExistingRow(string $identityField, string $identityValue, array $sourceRow, array $desired, array &$summary, string $filename, bool $afterCreateConflict = FALSE): ?array {
    if ($this->entity === 'SearchDisplay') {
      $where = [['name', '=', $identityValue]];
      if (!empty($desired['saved_search_id'])) {
        $where[] = ['saved_search_id', '=', (int) $desired['saved_search_id']];
      }
      $existing = $this->api4GetFirst($this->entity, $where, ['*']);
      if ($existing) {
        return $existing;
      }
    }

    $existing = $this->api4GetFirst($this->entity, [[$identityField, '=', $identityValue]], ['*']);
    if ($existing) {
      return $existing;
    }

    if ($this->entity === 'RelationshipType') {
      $labelAB = (string) ($desired['label_a_b'] ?? $sourceRow['label_a_b'] ?? '');
      $labelBA = (string) ($desired['label_b_a'] ?? $sourceRow['label_b_a'] ?? '');
      if ($labelAB !== '' && $labelBA !== '') {
        $existing = $this->api4GetFirst($this->entity, [['label_a_b', '=', $labelAB], ['label_b_a', '=', $labelBA]], ['*']);
        if ($existing) {
          $summary['warnings'][] = [
            'file' => $filename,
            'name' => $identityValue,
            'message' => 'Relationship type matched an existing CiviCRM record by labels because the machine name differed. Review this before deploying to production: ' . $labelAB . ' / ' . $labelBA,
          ];
          return $existing;
        }
      }
    }

    return NULL;
  }

  private function applyExistingRow(array $existing, array $desired, bool $dryRun, array &$summary): void {
    if ($this->desiredDiffers($existing, $desired)) {
      $summary['update']++;
      if (!$dryRun) {
        $this->api4Update($this->entity, [['id', '=', $existing['id']]], $desired);
      }
    }
    else {
      $summary['skip']++;
    }
  }

  private function isAlreadyExistsError(\Throwable $e): bool {
    return stripos($e->getMessage(), 'already exists') !== FALSE || stripos($e->getMessage(), 'duplicate') !== FALSE;
  }

  private function formatImportException(\Throwable $e, string $filename, string $identityValue): string {
    $message = $e->getMessage();
    if ($this->isAlreadyExistsError($e)) {
      return 'Target already has a conflicting ' . $this->entity . ' record for ' . $identityValue . '. Check ' . $filename . ' and any extension-provided SearchKit/FormBuilder records. Import skipped this item to avoid creating duplicates. Original error: ' . $message;
    }
    return $message;
  }

  private function expandFilesToRows(array $items, array &$summary): array {
    $rows = [];
    foreach ($items as $filename => $file) {
      $type = (string) ($file['type'] ?? '');
      if ($type === $this->type . '.collection') {
        foreach (($file['items'] ?? []) as $row) {
          $rows[] = ['filename' => $filename, 'row' => (array) $row];
        }
      }
      elseif ($type === $this->type . '.item') {
        $rows[] = ['filename' => $filename, 'row' => (array) ($file['item'] ?? [])];
      }
      else {
        $summary['errors'][] = ['file' => $filename, 'message' => 'Invalid type. Expected ' . $this->type . '.collection or ' . $this->type . '.item.'];
      }
    }
    return $rows;
  }

  private function getIdentityField(array $row): ?string {
    foreach (['name', 'name_a_b', 'title'] as $field) {
      if (!empty($row[$field])) {
        return $field;
      }
    }
    return NULL;
  }

  private function cleanImportValues(array $row): array {
    if ($this->entity === 'SearchDisplay' && !empty($row['saved_search_id.name'])) {
      $savedSearch = $this->api4GetFirst('SavedSearch', [['name', '=', (string) $row['saved_search_id.name']]], ['id', 'name']);
      if (!$savedSearch || empty($savedSearch['id'])) {
        throw new \RuntimeException('SearchDisplay requires missing SavedSearch dependency: ' . (string) $row['saved_search_id.name']);
      }
      $row['saved_search_id'] = $savedSearch['id'];
    }
    unset($row['id']);
    foreach (array_keys($row) as $key) {
      if (strpos((string) $key, '.') !== FALSE) {
        unset($row[$key]);
      }
    }
    return $row;
  }


  protected function normaliseDataForDiff(array $data): array {
    unset($data['required_by']);
    if (isset($data['item']) && is_array($data['item'])) {
      unset($data['item']['required_by']);
    }
    return $this->stripRuntimeFields($data);
  }

  private function cleanExportRow(array $row): array {
    return $this->stripRuntimeFields($row);
  }

  private function stripRuntimeFields(array $data): array {
    foreach ($data as $key => $value) {
      if (is_array($value)) {
        $data[$key] = $this->stripRuntimeFields($value);
      }
    }

    // Numeric IDs are database-local runtime values. They should not be used
    // for cross-instance diff/import decisions.
    unset($data['id']);

    // SearchDisplay links to SavedSearch by numeric saved_search_id in the DB,
    // but the stable deployment identity is saved_search_id.name.
    if ($this->entity === 'SearchDisplay' && !empty($data['saved_search_id.name'])) {
      unset($data['saved_search_id']);
    }

    return $data;
  }

  private function fileNameForRow(array $row, string $name): string {
    if ($this->entity === 'SearchDisplay' && !empty($row['saved_search_id.name'])) {
      return $this->safeFilePart((string) $row['saved_search_id.name']) . '__' . $this->safeFilePart($name) . '.yml';
    }
    return $this->safeFilePart($name) . '.yml';
  }

  private function safeFilePart(string $name): string {
    $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name);
    $safe = trim((string) $safe, '-');
    return $safe === '' ? sha1($name) : $safe;
  }

  private function collectionDependencies(array $rows): array {
    $dependencies = [];
    foreach ($rows as $row) {
      foreach ($this->dependenciesForRow((array) $row) as $dependency) {
        $dependencies[] = $dependency;
      }
    }
    return $this->uniqueDependencies($dependencies);
  }

  private function dependenciesForRow(array $row): array {
    $dependencies = [];

    if ($this->entity === 'SavedSearch') {
      $savedSearchName = (string) ($row['name'] ?? '');
      if ($savedSearchName !== '') {
        foreach ($this->api4Get('SearchDisplay', [['saved_search_id.name', '=', $savedSearchName]], ['name', 'label', 'saved_search_id.name'], ['name' => 'ASC']) as $display) {
          if (!empty($display['name'])) {
            $dependencies[] = [
              'type' => 'searchkit-displays',
              'entity' => 'SearchDisplay',
              'name' => (string) $display['name'],
              'reason' => 'This SearchKit display belongs to the SavedSearch and should be exported/imported with it.',
            ];
          }
        }
      }
    }

    if ($this->entity === 'SearchDisplay') {
      $savedSearch = (string) ($row['saved_search_id.name'] ?? '');
      if ($savedSearch !== '') {
        $dependencies[] = [
          'type' => 'searchkit-saved-searches',
          'entity' => 'SavedSearch',
          'name' => $savedSearch,
          'reason' => 'SearchDisplay belongs to this SavedSearch.',
        ];
      }
      elseif (!empty($row['saved_search_id'])) {
        $dependencies[] = [
          'type' => 'searchkit-saved-searches',
          'entity' => 'SavedSearch',
          'id' => $row['saved_search_id'],
          'reason' => 'SearchDisplay belongs to this SavedSearch. Re-export to include the machine name.',
        ];
      }
    }

    if ($this->entity === 'Afform') {
      foreach ($this->detectAfformSearchkitDependencies($row) as $name) {
        $dependencies[] = [
          'type' => 'searchkit-displays',
          'entity' => 'SearchDisplay',
          'name' => $name,
          'reason' => 'FormBuilder layout references this SearchKit display.',
        ];
      }
    }

    if ($this->entity === 'Job' && !empty($row['api_entity'])) {
      $dependencies[] = [
        'type' => 'api-entity',
        'entity' => (string) $row['api_entity'],
        'reason' => 'Scheduled Job calls this API entity.',
      ];
    }

    return $this->uniqueDependencies($dependencies);
  }

  private function detectAfformSearchkitDependencies(array $row): array {
    $json = json_encode($row['layout'] ?? $row);
    if (!$json) {
      return [];
    }
    preg_match_all('/afsearch[A-Za-z0-9_:-]+/', $json, $matches);
    $names = array_values(array_unique($matches[0] ?? []));
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    return $names;
  }

  private function uniqueDependencies(array $dependencies): array {
    $seen = [];
    $unique = [];
    foreach ($dependencies as $dependency) {
      $key = json_encode($dependency);
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = TRUE;
      $unique[] = $dependency;
    }
    return $unique;
  }
}
