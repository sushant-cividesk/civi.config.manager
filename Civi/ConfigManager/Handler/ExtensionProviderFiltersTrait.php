<?php
namespace Civi\ConfigManager\Handler;


/**
 * ExtensionProviderFilters methods from ExtensionHandler.
 *
 * Methods run on the original owning object and retain the same state and
 * access modifiers. Do not instantiate this trait separately.
 */
trait ExtensionProviderFiltersTrait {

  private function desiredConfigKeysForRuntimeFilter(array $definitions): array {
    $desired = [];
    if (!$this->hasRuntimeSubtypeFilter()) {
      return $desired;
    }
    foreach ($definitions as $definitionKey => $definition) {
      if ($this->definitionMatchesRuntimeFilter((array) $definition)) {
        $desired[(string) $definitionKey] = [];
      }
    }
    return $desired;
  }

  private function expandConfigIndexes(array $items): array {
    $indexes = [];
    foreach ($items as $item) {
      if (($item['type'] ?? '') !== 'extension.item') {
        continue;
      }
      $extension = (array) ($item['extension'] ?? []);
      $extensionKey = (string) ($extension['key'] ?? ($item['key'] ?? ''));
      if ($extensionKey === '') {
        continue;
      }
      foreach ((array) ($item['config_index'] ?? []) as $row) {
        $row = (array) $row;
        if (!empty($row['api']) && !empty($row['entity'])) {
          $indexes[] = [
            'extension' => $extensionKey,
            'api' => (string) $row['api'],
            'entity' => (string) $row['entity'],
            'identity_safety' => (string) ($row['identity_safety'] ?? ''),
            'delete_safe' => !empty($row['delete_safe']),
          ];
        }
      }
    }
    return $indexes;
  }

  private function expandExtensionConfigItems(array $items, array &$summary): array {
    $rows = [];
    foreach ($items as $filename => $item) {
      if (($item['type'] ?? '') !== 'extension_config.item') {
        continue;
      }
      $extensionKey = (string) ($item['extension'] ?? '');
      if ($extensionKey === '') {
        $summary['errors'][] = ['file' => $filename, 'message' => 'Extension config item is missing extension.'];
        continue;
      }
      $rows[] = [
        'filename' => (string) $filename,
        'extension' => $extensionKey,
        'api' => (string) ($item['api'] ?? ''),
        'entity' => (string) ($item['entity'] ?? ''),
        'item' => [
          'name' => $item['name'] ?? NULL,
          'identity_field' => $item['identity_field'] ?? NULL,
          'identity_confidence' => $item['identity_confidence'] ?? NULL,
          'capabilities' => (array) ($item['capabilities'] ?? []),
          'dependencies' => $item['dependencies'] ?? [],
          'item' => (array) ($item['item'] ?? []),
        ],
      ];
    }
    return $rows;
  }


  private function hasRuntimeSubtypeFilter(): bool {
    foreach ($this->runtimeTypeFilters as $filter) {
      if (strpos($filter, 'extensions:') === 0) {
        return TRUE;
      }
    }
    return FALSE;
  }

  private function virtualTypeForDefinition(array $definition): string {
    return 'extensions:' . $this->safeName((string) $definition['extension']) . ':' . $this->safeName((string) $definition['api']) . ':' . $this->safeName((string) $definition['entity']);
  }

  private function labelForDefinition(array $definition): string {
    $entity = preg_replace('/(?<!^)[A-Z]/', ' $0', (string) $definition['entity']);
    $entity = trim((string) $entity) ?: (string) $definition['entity'];
    return $entity;
  }

  private function definitionMatchesRuntimeFilter(array $definition): bool {
    if (!$this->hasRuntimeSubtypeFilter()) {
      return TRUE;
    }
    $wanted = array_fill_keys($this->runtimeTypeFilters, TRUE);
    return isset($wanted[$this->virtualTypeForDefinition($definition)]);
  }

  private function extensionConfigMatchesRuntimeFilter(string $extensionKey, string $api, string $entity): bool {
    if (!$this->hasRuntimeSubtypeFilter()) {
      return TRUE;
    }
    $definition = ['extension' => $extensionKey, 'api' => $api, 'entity' => $entity];
    return $this->definitionMatchesRuntimeFilter($definition);
  }

  private function extensionMatchesRuntimeFilter(string $extensionKey): bool {
    if (!$this->hasRuntimeSubtypeFilter()) {
      return TRUE;
    }
    $safeExtension = $this->safeName($extensionKey);
    foreach ($this->runtimeTypeFilters as $filter) {
      if (strpos($filter, 'extensions:' . $safeExtension . ':') === 0) {
        return TRUE;
      }
    }
    return FALSE;
  }

  private function yamlFilenameMatchesRuntimeFilter(string $filename, array $data): bool {
    if (!$this->hasRuntimeSubtypeFilter()) {
      return TRUE;
    }
    $extensionKey = '';
    $api = '';
    $entity = '';
    if (($data['type'] ?? '') === 'extension_config.item') {
      $extensionKey = (string) ($data['extension'] ?? '');
      $api = (string) ($data['api'] ?? '');
      $entity = (string) ($data['entity'] ?? '');
      return $this->extensionConfigMatchesRuntimeFilter($extensionKey, $api, $entity);
    }
    if (($data['type'] ?? '') === 'extension.item') {
      $extension = (array) ($data['extension'] ?? []);
      $extensionKey = (string) ($extension['key'] ?? ($data['key'] ?? ''));
      return $this->extensionMatchesRuntimeFilter($extensionKey);
    }
    foreach ($this->runtimeTypeFilters as $filter) {
      $parts = explode(':', $filter);
      if (count($parts) === 4) {
        $prefix = $parts[1] . '/' . $parts[2] . '/' . $parts[3] . '/';
        if (strpos($filename, $prefix) === 0) {
          return TRUE;
        }
        if ($filename === $parts[1] . '.yml') {
          return TRUE;
        }
      }
    }
    return FALSE;
  }
}
