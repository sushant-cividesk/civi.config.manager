<?php
namespace Civi\ConfigManager\Handler;

/**
 * Metadata-driven API4 handler registered through civicfg_entityDefinitions().
 *
 * This lets another extension make its config export/importable by describing
 * stable keys, exported fields, runtime fields, and dependencies without
 * writing a full handler class.
 */
class EntityDefinitionHandler extends AbstractHandler {
  private array $definition;
  private string $type;
  private string $label;
  private string $entity;
  private string $directory;
  private array $keyFields;
  private array $exportFields;
  private array $ignoreFields;
  private array $sensitiveFields;
  private array $orderBy;
  private array $where;
  private array $dependencies;
  private int $weight;
  private bool $splitFiles;
  private bool $deleteMissing;
  private bool $importable;

  public function __construct(string $type, array $definition) {
    $this->definition = $definition;
    $this->type = $type;
    $this->label = (string) ($definition['label'] ?? $type);
    $this->entity = (string) ($definition['entity'] ?? '');
    $this->directory = trim((string) ($definition['path'] ?? ('extensions/' . $type)), '/');
    $this->keyFields = array_values((array) ($definition['key_fields'] ?? ['name']));
    $this->exportFields = array_values((array) ($definition['export_fields'] ?? ['*']));
    $this->ignoreFields = array_values(array_unique(array_merge(['id'], (array) ($definition['ignore_fields'] ?? []))));
    $this->sensitiveFields = array_values((array) ($definition['sensitive_fields'] ?? []));
    $this->orderBy = (array) ($definition['order_by'] ?? $this->defaultOrderBy());
    $this->where = array_values((array) ($definition['where'] ?? []));
    $this->dependencies = (array) ($definition['dependencies'] ?? []);
    $this->weight = (int) ($definition['weight'] ?? 500);
    $this->splitFiles = (bool) ($definition['split_files'] ?? TRUE);
    $this->deleteMissing = (bool) ($definition['delete_missing'] ?? FALSE);
    $this->importable = (bool) ($definition['import'] ?? TRUE);
  }

  public function getType(): string {
    return $this->type;
  }

  public function getLabel(): string {
    return $this->label;
  }

  public function getDirectory(): string {
    return $this->directory;
  }

  public function getWeight(): int {
    return $this->weight;
  }

  public function export(): array {
    $this->assertUsableDefinition();
    $select = $this->selectFields();
    $rows = $this->api4Get($this->entity, $this->where, $select, $this->orderBy);
    $items = [];

    foreach ($rows as $row) {
      $row = $this->prepareExportRow((array) $row);
      $key = $this->buildKey($row);
      if ($key === '') {
        continue;
      }
      $items[] = [
        'filename' => $this->fileNameForKey($key),
        'data' => $this->itemDocument($row, $key),
      ];
    }

    if ($this->splitFiles) {
      usort($items, fn($a, $b) => strcmp($a['filename'], $b['filename']));
      return $items;
    }

    return [[
      'filename' => $this->safeFilePart($this->type) . '.yml',
      'data' => [
        'schema_version' => 1,
        'type' => $this->type . '.collection',
        'entity' => $this->entity,
        'key_fields' => $this->keyFields,
        'dependencies' => $this->normalizedDependencies(),
        'items' => array_values(array_map(fn($item) => $item['data']['item'], $items)),
      ],
    ]];
  }

  public function validate(array $items): array {
    $errors = [];
    $warnings = [];

    try {
      $this->assertUsableDefinition();
    }
    catch (\Throwable $e) {
      $errors[] = ['message' => $e->getMessage()];
    }

    foreach ($items as $filename => $item) {
      $type = (string) ($item['type'] ?? '');
      if ($type === $this->type . '.collection') {
        foreach ((array) ($item['items'] ?? []) as $index => $row) {
          if ($this->buildKey((array) $row) === '') {
            $errors[] = ['file' => $filename, 'message' => 'Collection item at index ' . $index . ' is missing required key fields: ' . implode(', ', $this->keyFields) . '.'];
          }
          $this->validateNoSensitiveFields((array) $row, $filename, $errors);
        }
        continue;
      }

      if ($type !== $this->type . '.item') {
        $errors[] = ['file' => $filename, 'message' => 'Invalid type. Expected ' . $this->type . '.item or ' . $this->type . '.collection.'];
        continue;
      }

      if (($item['entity'] ?? '') !== $this->entity) {
        $errors[] = ['file' => $filename, 'message' => 'Invalid entity. Expected ' . $this->entity . '.'];
      }
      $row = (array) ($item['item'] ?? []);
      if ($this->buildKey($row) === '') {
        $errors[] = ['file' => $filename, 'message' => 'Item is missing required key fields: ' . implode(', ', $this->keyFields) . '.'];
      }
      $this->validateNoSensitiveFields($row, $filename, $errors);
      if (!array_key_exists('dependencies', $item)) {
        $warnings[] = ['file' => $filename, 'message' => 'Dependency metadata is missing. Re-export this item before using it as a deployment source.'];
      }
    }

    return [
      'type' => $this->type,
      'valid' => empty($errors),
      'warnings' => $warnings,
      'errors' => $errors,
      'count' => count($items),
    ];
  }

  public function import(array $items, bool $dryRun = TRUE): array {
    $summary = [
      'type' => $this->type,
      'status' => $dryRun ? 'dry_run' : 'applied',
      'dry_run' => $dryRun,
      'create' => 0,
      'update' => 0,
      'delete' => 0,
      'skip' => 0,
      'warnings' => [],
      'errors' => [],
    ];

    if (!$this->importable) {
      $summary['warnings'][] = ['message' => 'Import is disabled for this entity definition.'];
      $summary['skip'] = count($items);
      $summary['ok'] = TRUE;
      return $summary;
    }

    try {
      $this->assertUsableDefinition();
    }
    catch (\Throwable $e) {
      $summary['errors'][] = ['message' => $e->getMessage()];
      $summary['ok'] = FALSE;
      return $summary;
    }

    $desiredKeys = [];
    foreach ($this->expandItems($items, $summary) as $entry) {
      $filename = $entry['filename'];
      $row = (array) $entry['row'];
      $key = $this->buildKey($row);
      if ($key === '') {
        $summary['errors'][] = ['file' => $filename, 'message' => 'Item is missing required key fields: ' . implode(', ', $this->keyFields) . '.'];
        continue;
      }
      $desiredKeys[$key] = TRUE;

      try {
        $desired = $this->prepareImportRow($row);
        $existing = $this->findExistingByKey($row);
        if ($existing) {
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
        else {
          $summary['create']++;
          if (!$dryRun) {
            $this->api4Create($this->entity, $desired);
          }
        }
      }
      catch (\Throwable $e) {
        $summary['errors'][] = ['file' => $filename, 'name' => $key, 'message' => $e->getMessage()];
      }
    }

    if ($this->deleteMissing) {
      $this->deleteMissingRows($desiredKeys, $dryRun, $summary);
    }

    $summary['ok'] = empty($summary['errors']);
    return $summary;
  }

  protected function normaliseDataForDiff(array $data): array {
    return $this->stripIgnoredFields(parent::normaliseDataForDiff($data));
  }

  private function assertUsableDefinition(): void {
    if (($this->definition['api_version'] ?? 4) !== 4) {
      throw new \RuntimeException('Only APIv4 entity definitions are supported by civicfg_entityDefinitions() right now: ' . $this->type);
    }
    if ($this->type === '' || preg_match('/^[A-Za-z0-9_.:-]+$/', $this->type) !== 1) {
      throw new \RuntimeException('Invalid config definition key: ' . $this->type);
    }
    if ($this->entity === '') {
      throw new \RuntimeException('Entity definition is missing entity for ' . $this->type . '.');
    }
    if (!$this->keyFields) {
      throw new \RuntimeException('Entity definition is missing key_fields for ' . $this->type . '.');
    }
    if (!$this->exportFields) {
      throw new \RuntimeException('Entity definition is missing export_fields for ' . $this->type . '.');
    }
    $class = 'Civi\\Api4\\' . $this->entity;
    if (!class_exists($class)) {
      throw new \RuntimeException('API4 entity is not available for ' . $this->type . ': ' . $this->entity . '. Check that the providing extension is enabled.');
    }
  }

  private function selectFields(): array {
    $select = $this->exportFields;
    if ($select === ['*']) {
      return ['*'];
    }
    foreach ($this->keyFields as $field) {
      if (!in_array($field, $select, TRUE)) {
        $select[] = $field;
      }
    }
    return array_values(array_unique($select));
  }

  private function prepareExportRow(array $row): array {
    if ($this->exportFields !== ['*']) {
      $row = array_intersect_key($row, array_flip(array_merge($this->exportFields, $this->keyFields)));
    }
    return $this->stripIgnoredFields($row);
  }

  private function prepareImportRow(array $row): array {
    $row = $this->stripIgnoredFields($row);
    foreach (array_keys($row) as $field) {
      if (strpos((string) $field, '.') !== FALSE) {
        unset($row[$field]);
      }
    }
    return $row;
  }

  private function stripIgnoredFields(array $data): array {
    foreach ($this->ignoreFields as $field) {
      $this->unsetPath($data, $field);
    }
    foreach ($this->sensitiveFields as $field) {
      $this->unsetPath($data, $field);
    }
    foreach ($data as $key => $value) {
      if (is_array($value)) {
        $data[$key] = $this->stripIgnoredFields($value);
      }
    }
    return $data;
  }

  private function unsetPath(array &$data, string $path): void {
    if ($path === '') {
      return;
    }
    if (strpos($path, '.') === FALSE) {
      unset($data[$path]);
      return;
    }
    $parts = explode('.', $path);
    $cursor =& $data;
    foreach ($parts as $index => $part) {
      if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
        return;
      }
      if ($index === count($parts) - 1) {
        unset($cursor[$part]);
        return;
      }
      $cursor =& $cursor[$part];
    }
  }

  private function buildKey(array $row): string {
    $parts = [];
    foreach ($this->keyFields as $field) {
      $value = $this->getPathValue($row, $field);
      if ($value === NULL || $value === '') {
        return '';
      }
      $parts[] = $field . '=' . (string) $value;
    }
    return implode('|', $parts);
  }

  private function getPathValue(array $row, string $path) {
    if (array_key_exists($path, $row)) {
      return $row[$path];
    }
    $cursor = $row;
    foreach (explode('.', $path) as $part) {
      if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
        return NULL;
      }
      $cursor = $cursor[$part];
    }
    return is_scalar($cursor) ? $cursor : NULL;
  }

  private function findExistingByKey(array $row): ?array {
    $where = [];
    foreach ($this->keyFields as $field) {
      $value = $this->getPathValue($row, $field);
      if ($value === NULL || $value === '') {
        return NULL;
      }
      $where[] = [$field, '=', $value];
    }
    return $this->api4GetFirst($this->entity, $where, ['*']);
  }

  private function deleteMissingRows(array $desiredKeys, bool $dryRun, array &$summary): void {
    $select = array_values(array_unique(array_merge(['id'], $this->keyFields)));
    foreach ($this->api4Get($this->entity, $this->where, $select, $this->orderBy) as $existing) {
      $existing = (array) $existing;
      if (empty($existing['id'])) {
        continue;
      }
      $key = $this->buildKey($existing);
      if ($key === '' || isset($desiredKeys[$key])) {
        continue;
      }
      $summary['delete']++;
      $summary['warnings'][] = ['name' => $key, 'message' => $key . ' exists in CiviCRM but not in YAML and will be deleted when import is applied.'];
      if (!$dryRun) {
        $this->api4Delete($this->entity, [['id', '=', $existing['id']]]);
      }
    }
  }

  private function expandItems(array $items, array &$summary): array {
    $rows = [];
    foreach ($items as $filename => $file) {
      $type = (string) ($file['type'] ?? '');
      if ($type === $this->type . '.collection') {
        foreach ((array) ($file['items'] ?? []) as $row) {
          $rows[] = ['filename' => $filename, 'row' => (array) $row];
        }
      }
      elseif ($type === $this->type . '.item') {
        $rows[] = ['filename' => $filename, 'row' => (array) ($file['item'] ?? [])];
      }
      else {
        $summary['errors'][] = ['file' => $filename, 'message' => 'Invalid type. Expected ' . $this->type . '.item or ' . $this->type . '.collection.'];
      }
    }
    return $rows;
  }

  private function itemDocument(array $row, string $key): array {
    return [
      'schema_version' => 1,
      'type' => $this->type . '.item',
      'entity' => $this->entity,
      'key_fields' => $this->keyFields,
      'key' => $key,
      'dependencies' => $this->normalizedDependencies(),
      'item' => $row,
    ];
  }

  private function normalizedDependencies(): array {
    $dependencies = [];
    foreach ($this->dependencies as $kind => $values) {
      foreach ((array) $values as $value) {
        $dependencies[] = ['type' => (string) $kind, 'name' => (string) $value];
      }
    }
    return $dependencies;
  }

  private function validateNoSensitiveFields(array $row, string $filename, array &$errors): void {
    foreach ($this->sensitiveFields as $field) {
      if ($this->getPathValue($row, $field) !== NULL) {
        $errors[] = ['file' => $filename, 'message' => 'Sensitive field must not be present in YAML: ' . $field . '.'];
      }
    }
  }

  private function defaultOrderBy(): array {
    $field = $this->keyFields[0] ?? 'name';
    return [$field => 'ASC'];
  }

  private function fileNameForKey(string $key): string {
    $parts = [];
    foreach (explode('|', $key) as $part) {
      $pieces = explode('=', $part, 2);
      $parts[] = $this->safeFilePart($pieces[1] ?? $part);
    }
    return implode('__', $parts) . '.yml';
  }

  private function safeFilePart(string $name): string {
    $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name);
    $safe = trim((string) $safe, '-');
    return $safe === '' ? sha1($name) : $safe;
  }
}
