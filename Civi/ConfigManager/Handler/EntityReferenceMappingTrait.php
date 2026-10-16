<?php
namespace Civi\ConfigManager\Handler;

/**
 * EntityReferenceMapping methods from EntityDefinitionHandler.
 *
 * Methods run on the original owning object and retain the same state and
 * access modifiers. Do not instantiate this trait separately.
 */
trait EntityReferenceMappingTrait {

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
}
