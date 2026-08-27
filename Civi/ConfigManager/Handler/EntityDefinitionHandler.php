<?php
namespace Civi\ConfigManager\Handler;

/**
 * Metadata-driven API4 handler registered through civicfg_entityDefinitions().
 *
 * This lets another extension make its config export/importable by describing
 * stable keys, exported fields, runtime fields, and dependencies without
 * writing a full handler class.
 */
class EntityDefinitionHandler extends AbstractHandler implements StreamingHandlerInterface, StreamingImportHandlerInterface {
  private array $definition;
  private string $type;
  private string $label;
  private string $entity;
  private string $directory;
  private array $keyFields;
  private array $exportFields;
  private array $ignoreFields;
  private array $runtimeFields;
  private array $sensitiveFields;
  private array $referenceFields;
  private array $orderedPaths;
  private array $unorderedPaths;
  private array $orderBy;
  private array $where;
  private array $dependencies;
  private int $weight;
  private bool $splitFiles;
  private bool $deleteMissing;
  private bool $importable;
  private bool $canCreate;
  private bool $canUpdate;
  private bool $canDelete;

  public function __construct(string $type, array $definition) {
    $this->definition = $definition;
    $this->type = $type;
    $this->label = (string) ($definition['label'] ?? $type);
    $this->entity = (string) ($definition['entity'] ?? '');
    $this->directory = trim((string) ($definition['path'] ?? ('extensions/' . $type)), '/');
    $this->keyFields = array_values((array) ($definition['key_fields'] ?? ['name']));
    $this->exportFields = array_values((array) ($definition['export_fields'] ?? ['*']));
    $this->ignoreFields = array_values(array_unique(array_merge(['id'], (array) ($definition['ignore_fields'] ?? []))));
    $this->runtimeFields = array_values((array) ($definition['runtime_fields'] ?? []));
    $this->sensitiveFields = array_values((array) ($definition['sensitive_fields'] ?? []));
    $this->referenceFields = (array) ($definition['reference_fields'] ?? []);
    $this->orderedPaths = array_values((array) ($definition['ordered_paths'] ?? []));
    $this->unorderedPaths = array_values((array) ($definition['unordered_paths'] ?? []));
    $this->orderBy = (array) ($definition['order_by'] ?? $this->defaultOrderBy());
    $this->where = array_values((array) ($definition['where'] ?? []));
    $this->dependencies = (array) ($definition['dependencies'] ?? []);
    $this->weight = (int) ($definition['weight'] ?? 500);
    $this->splitFiles = (bool) ($definition['split_files'] ?? TRUE);
    $this->deleteMissing = (bool) ($definition['delete_missing'] ?? FALSE);
    $this->importable = (bool) ($definition['import'] ?? TRUE);
    $this->canCreate = (bool) ($definition['can_create'] ?? TRUE);
    $this->canUpdate = (bool) ($definition['can_update'] ?? TRUE);
    $this->canDelete = (bool) ($definition['can_delete'] ?? $this->deleteMissing);
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
    $files = iterator_to_array($this->iterateExport(), FALSE);
    usort($files, static function(array $a, array $b): int {
      return strnatcasecmp((string) ($a['filename'] ?? ''), (string) ($b['filename'] ?? ''));
    });
    return $files;
  }

  public function iterateExport(): iterable {
    $this->assertUsableDefinition();
    $select = $this->selectFields();

    if (!$this->splitFiles) {
      $rows = [];
      foreach ($this->api4Iterate($this->entity, $this->where, $select, $this->orderBy) as $rawRow) {
        $row = $this->prepareExportRow((array) $rawRow);
        if ($this->buildKey($row) !== '') {
          $rows[] = $row;
        }
      }
      yield [
        'filename' => $this->safeFilePart($this->type) . '.yml',
        'data' => [
          'schema_version' => 1,
          'type' => $this->type . '.collection',
          'entity' => $this->entity,
          'key_fields' => $this->keyFields,
          'dependencies' => $this->normalizedDependencies(),
          'capabilities' => $this->capabilities(),
          'items' => $rows,
        ],
      ];
      return;
    }

    foreach ($this->api4Iterate($this->entity, $this->where, $select, $this->orderBy) as $rawRow) {
      $rawRow = (array) $rawRow;
      $sourceId = isset($rawRow['id']) && is_scalar($rawRow['id']) ? (int) $rawRow['id'] : NULL;
      $row = $this->prepareExportRow($rawRow);
      $key = $this->buildKey($row);
      if ($key === '') {
        continue;
      }
      yield [
        'filename' => $this->fileNameForKey($key),
        'source_id' => $sourceId,
        'data' => $this->itemDocument($row, $key),
      ];
    }
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
          $this->validateReferences((array) $row, $filename, $errors);
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
      $this->validateReferences($row, $filename, $errors);
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
    return $this->importIterable($items, $dryRun);
  }

  public function importIterable(iterable $items, bool $dryRun = TRUE): array {
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
      foreach ($items as $_filename => $_item) {
        $summary['skip']++;
      }
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
            if (!$this->canUpdate) {
              $summary['errors'][] = ['file' => $filename, 'name' => $key, 'message' => 'Update is not allowed by this configuration provider.'];
              continue;
            }
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
          if (!$this->canCreate) {
            $summary['errors'][] = ['file' => $filename, 'name' => $key, 'message' => 'Create is not allowed by this configuration provider.'];
            continue;
          }
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
      if ($this->canDelete) {
        $this->deleteMissingRows($desiredKeys, $dryRun, $summary);
      }
      else {
        $summary['warnings'][] = ['message' => 'Delete-missing is enabled, but delete is not allowed by this configuration provider.'];
      }
    }

    $summary['ok'] = empty($summary['errors']);
    return $summary;
  }

  protected function normaliseDataForDiff(array $data): array {
    return $this->stripIgnoredFields(parent::normaliseDataForDiff($data));
  }

  protected function getCanonicalOptions(): array {
    return [
      'ignored_fields' => array_values(array_unique(array_merge(
        $this->documentPaths($this->ignoreFields),
        $this->documentPaths($this->runtimeFields)
      ))),
      'sensitive_fields' => $this->documentPaths($this->sensitiveFields),
      'ordered_paths' => $this->documentPaths($this->orderedPaths),
      'unordered_paths' => $this->documentPaths($this->unorderedPaths),
    ];
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
      $topLevel = [];
      foreach (array_merge($this->exportFields, $this->keyFields, array_keys($this->referenceFields)) as $field) {
        $topLevel[] = explode('.', (string) $field)[0];
      }
      $row = array_intersect_key($row, array_flip(array_values(array_unique($topLevel))));
    }
    $row = $this->resolveReferencesForExport($row);
    return $this->stripIgnoredFields($row);
  }

  private function prepareImportRow(array $row): array {
    $row = $this->stripIgnoredFields($row);
    $row = $this->resolveReferencesForImport($row);
    foreach (array_keys($row) as $field) {
      if (strpos((string) $field, '.') !== FALSE) {
        unset($row[$field]);
      }
    }
    return $row;
  }

  private function stripIgnoredFields(array $data): array {
    foreach (array_values(array_unique(array_merge($this->ignoreFields, $this->runtimeFields, $this->sensitiveFields))) as $field) {
      $this->unsetPath($data, (string) $field);
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
    foreach ($this->api4Iterate($this->entity, $this->where, $select, $this->orderBy) as $existing) {
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

  private function expandItems(iterable $items, array &$summary): iterable {
    foreach ($items as $filename => $file) {
      $type = (string) ($file['type'] ?? '');
      if ($type === $this->type . '.collection') {
        foreach ((array) ($file['items'] ?? []) as $row) {
          yield ['filename' => $filename, 'row' => (array) $row];
        }
      }
      elseif ($type === $this->type . '.item') {
        yield ['filename' => $filename, 'row' => (array) ($file['item'] ?? [])];
      }
      else {
        $summary['errors'][] = ['file' => $filename, 'message' => 'Invalid type. Expected ' . $this->type . '.item or ' . $this->type . '.collection.'];
      }
    }
  }

  private function itemDocument(array $row, string $key): array {
    return [
      'schema_version' => 1,
      'type' => $this->type . '.item',
      'entity' => $this->entity,
      'key_fields' => $this->keyFields,
      'key' => $key,
      'dependencies' => array_values(array_merge($this->normalizedDependencies(), $this->referenceDependencies($row))),
      'capabilities' => $this->capabilities(),
      'item' => $row,
    ];
  }

  private function capabilities(): array {
    return [
      'create' => $this->importable && $this->canCreate,
      'update' => $this->importable && $this->canUpdate,
      'delete' => $this->importable && $this->canDelete,
    ];
  }

  private function resolveReferencesForExport(array $row): array {
    foreach ($this->referenceFields as $path => $definition) {
      $definition = (array) $definition;
      $entity = (string) ($definition['entity'] ?? '');
      $keyFields = array_values((array) ($definition['key_fields'] ?? ['name']));
      $idField = (string) ($definition['id_field'] ?? 'id');
      $value = $this->getPathValue($row, (string) $path);
      if ($entity === '' || !$keyFields || $value === NULL || $value === '' || !is_scalar($value)) {
        continue;
      }
      $select = array_values(array_unique(array_merge([$idField], $keyFields)));
      $target = $this->api4GetFirst($entity, [[$idField, '=', $value]], $select);
      if (!$target) {
        throw new \RuntimeException('Could not resolve portable configuration reference ' . (string) $path . ' from local ' . $idField . '=' . (string) $value . ' to ' . $entity . '.');
      }
      $key = [];
      foreach ($keyFields as $keyField) {
        $keyValue = $this->getPathValue($target, (string) $keyField);
        if ($keyValue === NULL || $keyValue === '') {
          $key = [];
          break;
        }
        $key[(string) $keyField] = $keyValue;
      }
      if (!$key) {
        throw new \RuntimeException('Could not build portable configuration reference ' . (string) $path . ' for ' . $entity . ' because one or more stable key fields are empty.');
      }
      $this->setPathValue($row, (string) $path, [
        'provider' => 'api4:' . $entity,
        'entity' => $entity,
        'key' => $key,
      ]);
    }
    return $row;
  }

  private function resolveReferencesForImport(array $row): array {
    foreach ($this->referenceFields as $path => $definition) {
      $definition = (array) $definition;
      $entity = (string) ($definition['entity'] ?? '');
      $idField = (string) ($definition['id_field'] ?? 'id');
      $keyFields = array_values(array_map('strval', (array) ($definition['key_fields'] ?? ['name'])));
      $reference = $this->getPathValueRaw($row, (string) $path);
      if ($entity === '' || $reference === NULL || $reference === '') {
        continue;
      }
      $this->assertSemanticReference((string) $path, $reference, $entity, $keyFields);
      $where = [];
      foreach ($keyFields as $field) {
        $where[] = [$field, '=', $reference['key'][$field]];
      }
      $target = $this->api4GetFirst($entity, $where, [$idField]);
      if (!$target || !array_key_exists($idField, $target)) {
        throw new \RuntimeException('Could not resolve configuration reference ' . $path . ' to ' . $entity . '.');
      }
      $this->setPathValue($row, (string) $path, $target[$idField]);
    }
    return $row;
  }

  private function referenceDependencies(array $row): array {
    $dependencies = [];
    foreach ($this->referenceFields as $path => $definition) {
      $definition = (array) $definition;
      $dependencyType = (string) ($definition['dependency_type'] ?? '');
      $reference = $this->getPathValueRaw($row, (string) $path);
      if ($dependencyType === '' || !is_array($reference) || empty($reference['key']) || !is_array($reference['key'])) {
        continue;
      }
      $keyValues = (array) $reference['key'];
      $name = count($keyValues) === 1 ? (string) reset($keyValues) : $this->buildKeyForFields($keyValues, array_keys($keyValues));
      if ($name !== '') {
        $dependencies[] = [
          'type' => $dependencyType,
          'name' => $name,
          'reason' => 'Referenced by ' . $this->type . ' field ' . (string) $path . '.',
        ];
      }
    }
    return $dependencies;
  }

  private function buildKeyForFields(array $row, array $fields): string {
    $parts = [];
    foreach ($fields as $field) {
      $value = $this->getPathValue($row, (string) $field);
      if ($value === NULL || $value === '') {
        return '';
      }
      $parts[] = (string) $field . '=' . (string) $value;
    }
    return implode('|', $parts);
  }

  private function getPathValueRaw(array $row, string $path) {
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
    return $cursor;
  }

  private function setPathValue(array &$row, string $path, $value): void {
    $parts = array_values(array_filter(explode('.', $path), 'strlen'));
    if (!$parts) {
      return;
    }
    $cursor =& $row;
    foreach ($parts as $index => $part) {
      if ($index === count($parts) - 1) {
        $cursor[$part] = $value;
        return;
      }
      if (!isset($cursor[$part]) || !is_array($cursor[$part])) {
        $cursor[$part] = [];
      }
      $cursor =& $cursor[$part];
    }
  }

  private function documentPaths(array $paths): array {
    $result = [];
    foreach ($paths as $path) {
      $path = trim((string) $path);
      if ($path === '') {
        continue;
      }
      $result[] = 'item.' . $path;
      $result[] = 'items.*.' . $path;
    }
    return array_values(array_unique($result));
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

  private function validateReferences(array $row, string $filename, array &$errors): void {
    foreach ($this->referenceFields as $path => $definition) {
      $definition = (array) $definition;
      $entity = (string) ($definition['entity'] ?? '');
      $keyFields = array_values(array_map('strval', (array) ($definition['key_fields'] ?? ['name'])));
      $reference = $this->getPathValueRaw($row, (string) $path);
      if ($entity === '' || $reference === NULL || $reference === '') {
        continue;
      }
      try {
        $this->assertSemanticReference((string) $path, $reference, $entity, $keyFields);
      }
      catch (\Throwable $e) {
        $errors[] = ['file' => $filename, 'message' => $e->getMessage()];
      }
    }
  }

  private function assertSemanticReference(string $path, $reference, string $entity, array $keyFields): void {
    if (!is_array($reference) || empty($reference['key']) || !is_array($reference['key'])) {
      throw new \RuntimeException('Reference field ' . $path . ' must use a semantic configuration reference, not a local database ID.');
    }
    if (isset($reference['entity']) && (string) $reference['entity'] !== $entity) {
      throw new \RuntimeException('Reference field ' . $path . ' targets unexpected entity ' . (string) $reference['entity'] . '; expected ' . $entity . '.');
    }
    $expectedProvider = 'api4:' . $entity;
    if (isset($reference['provider']) && (string) $reference['provider'] !== $expectedProvider) {
      throw new \RuntimeException('Reference field ' . $path . ' targets unexpected provider ' . (string) $reference['provider'] . '; expected ' . $expectedProvider . '.');
    }

    $actualFields = array_map('strval', array_keys((array) $reference['key']));
    $expectedFields = array_values(array_map('strval', $keyFields));
    sort($actualFields, SORT_STRING);
    sort($expectedFields, SORT_STRING);
    if ($actualFields !== $expectedFields) {
      throw new \RuntimeException('Reference field ' . $path . ' must use exactly these stable key fields for ' . $entity . ': ' . implode(', ', $keyFields) . '.');
    }
    foreach ($keyFields as $field) {
      $value = $reference['key'][$field] ?? NULL;
      if (!is_scalar($value) || (string) $value === '') {
        throw new \RuntimeException('Reference field ' . $path . ' has an empty or invalid stable key value for ' . $field . '.');
      }
    }
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
