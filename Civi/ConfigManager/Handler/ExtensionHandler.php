<?php
namespace Civi\ConfigManager\Handler;

use Civi\ConfigManager\Version;

class ExtensionHandler extends AbstractHandler {
  private bool $importWritesEnabled = TRUE;
  private bool $deleteMissingEnabled = TRUE;
  private ?array $discoveredEntityDefinitions = NULL;
  private array $runtimeTypeFilters = [];
  private array $identityRowsByDefinition = [];
  private static array $api3ActionsByEntity = [];
  private static array $api3ListActionByEntity = [];

  public function getType(): string { return 'extensions'; }
  public function getLabel(): string { return 'Extensions'; }
  public function getDirectory(): string { return 'extensions'; }
  public function getWeight(): int { return 10; }

  public function setImportWriteEnabled(bool $enabled): self {
    $this->importWritesEnabled = $enabled;
    return $this;
  }

  public function setDeleteMissingEnabled(bool $enabled): self {
    $this->deleteMissingEnabled = $enabled;
    return $this;
  }

  public function setRuntimeTypeFilters(array $filters): self {
    $this->runtimeTypeFilters = array_values(array_unique(array_filter(array_map('strval', $filters))));
    return $this;
  }

  public function getFilterOptions(): array {
    $rows = [];
    foreach ($this->discoverEntityDefinitions() as $definition) {
      if ($this->isGenericConfigSkippedExtension((string) $definition['extension']) || $this->isNonImportableDefinition($definition)) {
        continue;
      }
      $rows[] = [
        'type' => $this->virtualTypeForDefinition($definition),
        'base_type' => $this->getType(),
        'label' => $this->labelForDefinition($definition),
        'provider' => (string) $definition['extension'],
        'directory' => $this->getDirectory(),
        'weight' => $this->getWeight() + 1,
      ];
    }
    return $rows;
  }

  public function filterYamlFilesByRuntimeFilters(array $files): array {
    if (!$this->hasRuntimeSubtypeFilter()) {
      return $files;
    }
    $filtered = [];
    foreach ($files as $filename => $data) {
      $filename = (string) $filename;
      if ($this->yamlFilenameMatchesRuntimeFilter($filename, (array) $data)) {
        $filtered[$filename] = $data;
      }
    }
    return $filtered;
  }

  public function export(): array {
    $manager = \CRM_Extension_System::singleton()->getManager();
    $settingsByExtension = $this->discoverSettingsByExtension();
    $configExport = $this->discoverSplitConfigByExtension();

    $files = [];
    foreach ($manager->getStatuses() as $key => $status) {
      $key = (string) $key;
      if (!$this->extensionMatchesRuntimeFilter($key)) {
        continue;
      }
      $data = [
        'schema_version' => 1,
        'type' => 'extension.item',
        'key' => $key,
        'dependencies' => $this->dependenciesForExtension($key),
        'extension' => [
          'key' => $key,
          'status' => (string) $status,
        ],
      ];
      if (!empty($settingsByExtension[$key])) {
        $data['settings'] = $settingsByExtension[$key];
      }
      if (!empty($configExport['index'][$key])) {
        $data['config_index'] = $configExport['index'][$key];
      }

      $files[] = [
        'filename' => $this->safeName($key) . '.yml',
        'data' => $data,
      ];
    }
    foreach (($configExport['files'] ?? []) as $file) {
      $files[] = $file;
    }
    usort($files, fn($a, $b) => strcmp($a['filename'], $b['filename']));
    return $files;
  }

  /**
   * Report generic contributed-extension configuration coverage.
   *
   * This is discovery evidence for QA. A FULL result means every discovered
   * provider is write-capable and has verified safe identities in the current
   * fixture; end-to-end round-trip tests remain authoritative.
   */
  public function getCompatibilityReport(): array {
    $statuses = [];
    try {
      $statuses = (array) \CRM_Extension_System::singleton()->getManager()->getStatuses();
    }
    catch (\Throwable $e) {
      return [];
    }

    $settings = $this->discoverSettingsByExtension();
    $definitions = [];
    foreach ($this->discoverEntityDefinitions() as $definition) {
      $definitions[(string) $definition['extension']][] = $definition;
    }

    $report = [];
    foreach ($statuses as $extensionKey => $status) {
      $extensionKey = (string) $extensionKey;
      $status = strtolower((string) $status);
      if (!in_array($status, ['installed', 'enabled'], TRUE)) {
        continue;
      }
      $providers = [];
      $safeProviders = 0;
      $unsafeProviders = 0;
      $unverifiedProviders = 0;
      $readOnly = 0;
      $errorProviders = 0;
      foreach ((array) ($definitions[$extensionKey] ?? []) as $definition) {
        $definition = (array) $definition;
        $importable = !$this->isNonImportableDefinition($definition);
        $identityRows = [];
        $identitySafety = 'UNVERIFIED';
        $discoveryError = '';
        try {
          $identityRows = $this->identityRowsForDefinition($definition);
          $identitySafety = $this->identitySafetyForRows($identityRows, $definition);
        }
        catch (\Throwable $e) {
          $identitySafety = 'ERROR';
          $discoveryError = $e->getMessage();
          $errorProviders++;
        }
        $provider = [
          'api' => (string) ($definition['api'] ?? ''),
          'entity' => (string) ($definition['entity'] ?? ''),
          'list_action' => (string) ($definition['list_action'] ?? 'get'),
          'match_fields' => array_values((array) ($definition['match_fields'] ?? [])),
          'can_create' => !empty($definition['can_create']),
          'can_update' => !empty($definition['can_update']),
          'can_delete' => !empty($definition['can_delete']),
          'importable' => $importable,
          'records' => count($identityRows),
          'identity_safety' => $identitySafety,
        ];
        if ($discoveryError !== '') {
          $provider['error'] = $discoveryError;
        }
        $providers[] = $provider;
        if ($identitySafety === 'ERROR') {
          continue;
        }
        if (!$importable) {
          $readOnly++;
        }
        elseif ($identitySafety === 'SAFE') {
          $safeProviders++;
        }
        elseif ($identitySafety === 'UNSAFE') {
          $unsafeProviders++;
        }
        else {
          $unverifiedProviders++;
        }
      }
      $settingsCount = count((array) ($settings[$extensionKey] ?? []));
      if ($errorProviders > 0) {
        $classification = 'ERROR';
      }
      elseif (!$providers && $settingsCount === 0) {
        $classification = 'NO_PORTABLE_CONFIG';
      }
      elseif ($settingsCount === 0 && $safeProviders === 0 && $unverifiedProviders === 0 && ($readOnly > 0 || $unsafeProviders > 0)) {
        $classification = 'UNSUPPORTED';
      }
      elseif ($readOnly > 0 || $unsafeProviders > 0 || $unverifiedProviders > 0) {
        $classification = 'PARTIAL';
      }
      else {
        $classification = 'FULL';
      }
      $report[$extensionKey] = [
        'extension' => $extensionKey,
        'extension_status' => $status,
        'classification' => $classification,
        'classification_basis' => 'discovery',
        'settings_count' => $settingsCount,
        'providers' => $providers,
      ];
    }
    ksort($report, SORT_NATURAL | SORT_FLAG_CASE);
    return $report;
  }

  public function validate(array $items): array {
    $errors = [];
    $warnings = [];
    $definitions = $this->entityDefinitionsByKey();

    foreach ($items as $filename => $item) {
      $type = (string) ($item['type'] ?? '');
      if ($type === 'extensions.collection') {
        foreach (($item['items'] ?? []) as $index => $extension) {
          if (empty($extension['key'])) {
            $errors[] = ['file' => $filename, 'message' => 'Extension row ' . $index . ' is missing key.'];
          }
        }
        continue;
      }
      if ($type === 'extension_config.item') {
        $this->validateExtensionConfigItem($filename, $item, $definitions, $errors, $warnings);
        continue;
      }
      if ($type !== 'extension.item') {
        $errors[] = ['file' => $filename, 'message' => 'Invalid type. Expected extension.item or extension_config.item.'];
        continue;
      }

      $extension = (array) ($item['extension'] ?? []);
      $key = (string) ($extension['key'] ?? ($item['key'] ?? ''));
      if ($key === '') {
        $errors[] = ['file' => $filename, 'message' => 'Extension item is missing extension.key.'];
        continue;
      }

      if (!empty($item['settings'])) {
        foreach ((array) $item['settings'] as $settingName => $settingValue) {
          $settingName = (string) $settingName;
          if (!$this->isSafeSettingName($settingName)) {
            $errors[] = ['file' => $filename, 'message' => 'Unsafe extension setting name: ' . $settingName];
          }
          if ($this->isSensitiveSettingName($settingName)) {
            $errors[] = ['file' => $filename, 'message' => 'Sensitive extension setting is blocked from import: ' . $settingName];
          }
          if (!$this->settingNameLooksRelatedToExtension($settingName, $key)) {
            $warnings[] = ['file' => $filename, 'message' => 'Setting name does not clearly match extension namespace; review before importing: ' . $settingName];
          }
        }
      }

      foreach ($this->flattenBundledConfig($item['config'] ?? []) as $entry) {
        $definitionKey = $this->definitionKey($key, $entry['api'], $entry['entity']);
        if (!isset($definitions[$definitionKey])) {
          $errors[] = [
            'file' => $filename,
            'message' => sprintf('Bundled extension config provider is not available: extension %s, %s entity %s. Install/enable that extension before import.', $key, $entry['api'], $entry['entity']),
          ];
          continue;
        }
        $definition = $definitions[$definitionKey];
        $row = (array) ($entry['item']['item'] ?? []);
        $identityField = (string) ($entry['item']['identity_field'] ?? '');
        if ($identityField === '' || empty($row[$identityField])) {
          $identityField = (string) ($this->identityField($row, $definition) ?? '');
        }
        if ($identityField === '') {
          $errors[] = ['file' => $filename, 'message' => sprintf('Bundled extension config for %s %s is missing a stable identity field.', $entry['api'], $entry['entity'])];
        }
        elseif ($this->runtimeIdentityConfidence($definition, $identityField, (string) $row[$identityField]) === 'AMBIGUOUS') {
          $warnings[] = ['file' => $filename, 'message' => sprintf('Bundled extension config for %s %s does not have a unique stable identity and will not be written automatically.', $entry['api'], $entry['entity'])];
        }
      }
    }

    return [
      'type' => $this->getType(),
      'valid' => empty($errors),
      'errors' => $errors,
      'warnings' => $warnings,
      'count' => count($items),
    ];
  }

  public function import(array $items, bool $dryRun = TRUE): array {
    $summary = [
      'type' => $this->getType(),
      'status' => $dryRun ? 'dry_run' : 'applied',
      'dry_run' => $dryRun,
      'install' => 0,
      'enable' => 0,
      'disable' => 0,
      'delete' => 0,
      'settings' => ['update' => 0, 'skip' => 0],
      'config' => ['create' => 0, 'update' => 0, 'delete' => 0, 'skip' => 0],
      'skip' => 0,
      'warnings' => [],
      'errors' => [],
    ];

    $manager = \CRM_Extension_System::singleton()->getManager();
    $current = (array) $manager->getStatuses();
    $desiredKeys = [];
    $definitions = $this->entityDefinitionsByKey();
    $desiredConfigKeys = [];

    foreach ($this->expandConfigIndexes($items) as $index) {
      $definitionKey = $this->definitionKey($index['extension'], $index['api'], $index['entity']);
      if (isset($definitions[$definitionKey])) {
        $desiredConfigKeys[$definitionKey] = $desiredConfigKeys[$definitionKey] ?? [];
      }
    }

    foreach ($this->expandItems($items, $summary) as $entry) {
      $filename = $entry['filename'];
      $extension = (array) $entry['extension'];
      $fullItem = (array) ($entry['item'] ?? []);
      $key = (string) ($extension['key'] ?? '');
      $desired = strtolower((string) ($extension['status'] ?? ''));
      if ($key === '') {
        $summary['errors'][] = ['file' => $filename, 'message' => 'Extension key missing.'];
        continue;
      }
      $desiredKeys[$key] = TRUE;

      if ($this->importWritesEnabled) {
        $this->applyExtensionStatus($manager, $current, $filename, $key, $desired, $dryRun, $summary);
        $this->applyBundledSettings($filename, $key, (array) ($fullItem['settings'] ?? []), $dryRun, $summary);
      }

      foreach ($this->flattenBundledConfig($fullItem['config'] ?? []) as $configEntry) {
        $this->processExtensionConfigEntry($filename, $key, $configEntry, $definitions, $desiredConfigKeys, $dryRun, $summary);
      }
    }

    foreach ($this->expandExtensionConfigItems($items, $summary) as $configEntry) {
      $this->processExtensionConfigEntry($configEntry['filename'], $configEntry['extension'], $configEntry, $definitions, $desiredConfigKeys, $dryRun, $summary);
    }

    if ($this->deleteMissingEnabled) {
      foreach ($desiredConfigKeys as $definitionKey => $desiredForEntity) {
        if (!isset($definitions[$definitionKey])) {
          continue;
        }
        $this->deleteMissingBundledConfig($definitions[$definitionKey], $desiredForEntity, $dryRun, $summary);
      }

      foreach ($current as $key => $status) {
        if (isset($desiredKeys[(string) $key]) || (string) $key === Version::EXTENSION_KEY) {
          continue;
        }
        $summary['skip']++;
        $summary['warnings'][] = ['extension' => (string) $key, 'message' => 'Extension exists in CiviCRM but not YAML. It is not uninstalled automatically for safety: ' . (string) $key];
      }
    }

    $summary['ok'] = empty($summary['errors']);
    return $summary;
  }

  protected function normaliseDataForDiff(array $data): array {
    unset($data['required_by']);
    if (isset($data['item']) && is_array($data['item'])) {
      unset($data['item']['required_by']);
    }
    return $data;
  }

  private function applyExtensionStatus($manager, array $current, string $filename, string $key, string $desired, bool $dryRun, array &$summary): void {
    $actual = strtolower((string) ($current[$key] ?? 'missing'));
    if ($actual === $desired) {
      $summary['skip']++;
      return;
    }

    try {
      if (in_array($desired, ['installed', 'enabled'], TRUE)) {
        if ($actual === 'missing') {
          $summary['errors'][] = ['file' => $filename, 'extension' => $key, 'message' => 'Extension code is not available on this site: ' . $key];
          return;
        }
        if (in_array($actual, ['uninstalled', 'not installed', 'not_installed'], TRUE)) {
          $summary['install']++;
          $summary['warnings'][] = ['file' => $filename, 'extension' => $key, 'message' => 'Extension will be installed: ' . $key];
          if (!$dryRun) {
            $this->callManager($manager, 'install', [$key]);
          }
        }
        else {
          $summary['enable']++;
          $summary['warnings'][] = ['file' => $filename, 'extension' => $key, 'message' => 'Extension will be enabled: ' . $key];
          if (!$dryRun) {
            $this->callManager($manager, 'enable', [$key]);
          }
        }
      }
      elseif ($desired === 'disabled') {
        if ($key === Version::EXTENSION_KEY) {
          $summary['skip']++;
          $summary['warnings'][] = ['file' => $filename, 'extension' => $key, 'message' => 'Self-disable is skipped so Configuration Manager can finish the import safely.'];
          return;
        }
        $summary['disable']++;
        $summary['warnings'][] = ['file' => $filename, 'extension' => $key, 'message' => 'Extension will be disabled: ' . $key];
        if (!$dryRun) {
          $this->callManager($manager, 'disable', [$key]);
        }
      }
      elseif (in_array($desired, ['uninstalled', 'not installed', 'not_installed'], TRUE)) {
        $summary['skip']++;
        $summary['warnings'][] = ['file' => $filename, 'extension' => $key, 'message' => 'Uninstall is skipped for safety: ' . $key];
      }
      else {
        $summary['skip']++;
        $summary['warnings'][] = ['file' => $filename, 'extension' => $key, 'message' => 'Unknown target status for ' . $key . ': ' . $desired];
      }
    }
    catch (\Throwable $e) {
      $summary['errors'][] = ['file' => $filename, 'extension' => $key, 'message' => $e->getMessage()];
    }
  }

  private function applyBundledSettings(string $filename, string $extensionKey, array $settings, bool $dryRun, array &$summary): void {
    foreach ($settings as $name => $value) {
      $name = (string) $name;
      if (!$this->isSafeSettingName($name) || $this->isSensitiveSettingName($name)) {
        $summary['errors'][] = ['file' => $filename, 'message' => 'Unsafe or sensitive extension setting skipped: ' . $name];
        continue;
      }
      if (!$this->settingNameLooksRelatedToExtension($name, $extensionKey)) {
        $summary['warnings'][] = ['file' => $filename, 'message' => 'Setting namespace is not clearly tied to ' . $extensionKey . '; skipped for safety: ' . $name];
        $summary['settings']['skip']++;
        continue;
      }
      $current = \Civi::settings()->get($name);
      if ($this->normaliseComparableValue($current) !== $this->normaliseComparableValue($value)) {
        $summary['settings']['update']++;
        if (!$dryRun) {
          \Civi::settings()->set($name, $value);
        }
      }
      else {
        $summary['settings']['skip']++;
      }
    }
  }

  private function applyBundledConfigItem(string $filename, array $definition, array $row, string $identityField, string $identity, bool $dryRun, array &$summary): void {
    try {
      $desired = $this->cleanEntityRowForImport($row, $definition);
      $existing = $this->findExistingEntityRow($definition, $identityField, $identity);
      if ($existing) {
        if ($this->desiredDiffers($existing, $desired)) {
          if (empty($definition['can_update']) && array_key_exists('can_update', $definition)) {
            $summary['config']['skip']++;
            $summary['warnings'][] = ['file' => $filename, 'name' => $identity, 'message' => sprintf('Skipped update for read-only extension config %s %s.', $definition['api'], $definition['entity'])];
            return;
          }
          $summary['config']['update']++;
          if (!$dryRun) {
            $this->updateEntityRow($definition, (array) $existing, $desired);
          }
        }
        else {
          $summary['config']['skip']++;
        }
      }
      else {
        if (empty($definition['can_create']) && array_key_exists('can_create', $definition)) {
          $summary['config']['skip']++;
          $summary['warnings'][] = ['file' => $filename, 'name' => $identity, 'message' => sprintf('Skipped create for read-only extension config %s %s.', $definition['api'], $definition['entity'])];
          return;
        }
        $summary['config']['create']++;
        if (!$dryRun) {
          $this->createEntityRow($definition, $desired);
        }
      }
    }
    catch (\Throwable $e) {
      $message = $this->formatEntityImportException($e, $definition, $identity);
      if ($this->isEntityConflictException($e)) {
        $summary['config']['skip']++;
        $summary['warnings'][] = ['file' => $filename, 'name' => $identity, 'message' => $message];
      }
      else {
        $summary['errors'][] = ['file' => $filename, 'name' => $identity, 'message' => $message];
      }
    }
  }

  private function deleteMissingBundledConfig(array $definition, array $desiredKeys, bool $dryRun, array &$summary): void {
    $rows = array_values(array_map(fn($row) => (array) $row, $this->fetchEntityRows($definition)));
    foreach ($rows as $existing) {
      if (empty($existing['id'])) {
        continue;
      }
      $identityField = $this->identityField($existing, $definition);
      if ($identityField === NULL) {
        continue;
      }
      $identity = (string) $existing[$identityField];
      $confidence = $this->identityConfidence($identityField, $definition);
      if ($confidence === 'AMBIGUOUS' || !$this->identityValueIsUnique($rows, $identityField, $identity)) {
        $summary['config']['skip']++;
        $summary['warnings'][] = [
          'name' => $identity,
          'message' => sprintf('Skipped delete for extension config %s %s because %s=%s is not a unique stable identity.', $definition['api'], $definition['entity'], $identityField, $identity),
        ];
        continue;
      }
      if (isset($desiredKeys[$this->identityKey($identityField, $identity)])) {
        continue;
      }
      if (empty($definition['can_delete']) && array_key_exists('can_delete', $definition)) {
        $summary['config']['skip']++;
        $summary['warnings'][] = [
          'name' => $identity,
          'message' => sprintf('Skipped delete for extension config %s %s because the provider API does not expose delete.', $definition['api'], $definition['entity']),
        ];
        continue;
      }
      $summary['config']['delete']++;
      $summary['warnings'][] = [
        'name' => $identity,
        'message' => sprintf('Bundled extension config %s %s exists in CiviCRM but not YAML and will be deleted when import is applied: %s', $definition['api'], $definition['entity'], $identity),
      ];
      if (!$dryRun) {
        try {
          $this->deleteEntityRow($definition, (int) $existing['id']);
        }
        catch (\Throwable $e) {
          $summary['errors'][] = ['name' => $identity, 'message' => 'Delete failed: ' . $e->getMessage()];
        }
      }
    }
  }

  private function expandItems(array $items, array &$summary): array {
    $rows = [];
    foreach ($items as $filename => $item) {
      $type = (string) ($item['type'] ?? '');
      if ($type === 'extensions.collection') {
        foreach (($item['items'] ?? []) as $extension) {
          $rows[] = ['filename' => $filename, 'extension' => (array) $extension, 'item' => ['extension' => (array) $extension]];
        }
      }
      elseif ($type === 'extension.item') {
        $extension = (array) ($item['extension'] ?? []);
        if (empty($extension['key']) && !empty($item['key'])) {
          $extension['key'] = $item['key'];
        }
        $rows[] = ['filename' => $filename, 'extension' => $extension, 'item' => (array) $item];
      }
      elseif ($type !== 'extension_config.item') {
        $summary['errors'][] = ['file' => $filename, 'message' => 'Invalid extension YAML type. Expected extension.item or extension_config.item.'];
      }
    }
    return $rows;
  }

  private function validateExtensionConfigItem(string $filename, array $item, array $definitions, array &$errors, array &$warnings): void {
    $extensionKey = (string) ($item['extension'] ?? '');
    $api = (string) ($item['api'] ?? '');
    $entity = (string) ($item['entity'] ?? '');
    if ($extensionKey === '' || $api === '' || $entity === '') {
      $errors[] = ['file' => $filename, 'message' => 'Extension config item is missing extension, api, or entity.'];
      return;
    }
    $definitionKey = $this->definitionKey($extensionKey, $api, $entity);
    if (!isset($definitions[$definitionKey])) {
      if ($this->isNonImportableLegacyExtensionConfig($extensionKey, $api, $entity)) {
        $warnings[] = [
          'file' => $filename,
          'message' => sprintf('Read-only/generated extension config is no longer imported and should be removed by running Export: extension %s, %s entity %s.', $extensionKey, $api, $entity),
        ];
        return;
      }
      $errors[] = [
        'file' => $filename,
        'message' => sprintf('Extension config provider is not available: extension %s, %s entity %s. Install/enable that extension before import.', $extensionKey, $api, $entity),
      ];
      return;
    }
    $row = (array) ($item['item'] ?? []);
    $identityField = (string) ($item['identity_field'] ?? '');
    $definition = $definitions[$definitionKey];
    if ($identityField === '' || empty($row[$identityField])) {
      $identityField = (string) ($this->identityField($row, $definition) ?? '');
    }
    if ($identityField === '') {
      $errors[] = ['file' => $filename, 'message' => sprintf('Extension config item for %s %s is missing a stable identity field.', $api, $entity)];
    }
    elseif ($this->runtimeIdentityConfidence($definition, $identityField, (string) $row[$identityField]) === 'AMBIGUOUS') {
      $warnings[] = [
        'file' => $filename,
        'message' => sprintf('Extension config %s %s does not have a unique stable identity for %s. Export/diff are allowed, but automatic create/update/delete is blocked until the provider supplies a stable key.', $api, $entity, $identityField),
      ];
    }
  }

  private function processExtensionConfigEntry(string $filename, string $extensionKey, array $configEntry, array $definitions, array &$desiredConfigKeys, bool $dryRun, array &$summary): void {
    $api = (string) ($configEntry['api'] ?? '');
    $entity = (string) ($configEntry['entity'] ?? '');
    if (!$this->extensionConfigMatchesRuntimeFilter($extensionKey, $api, $entity)) {
      $summary['config']['skip']++;
      return;
    }
    $definitionKey = $this->definitionKey($extensionKey, $api, $entity);
    if (!isset($definitions[$definitionKey])) {
      if ($this->isNonImportableLegacyExtensionConfig($extensionKey, $api, $entity)) {
        $summary['config']['skip']++;
        $summary['warnings'][] = [
          'file' => $filename,
          'message' => sprintf('Skipped read-only/generated extension config %s %s. Re-export to remove this obsolete YAML file.', $api, $entity),
        ];
        return;
      }
      $summary['errors'][] = [
        'file' => $filename,
        'message' => sprintf('Extension config provider is not available: extension %s, %s entity %s.', $extensionKey, $api, $entity),
      ];
      return;
    }
    $definition = $definitions[$definitionKey];
    if ($this->isNonImportableDefinition($definition)) {
      $summary['config']['skip']++;
      $summary['warnings'][] = [
        'file' => $filename,
        'message' => sprintf('Skipped read-only/generated extension config %s %s. Re-export to remove this obsolete YAML file.', $api, $entity),
      ];
      return;
    }
    $configItem = (array) ($configEntry['item'] ?? []);
    $row = (array) ($configItem['item'] ?? $configItem);
    $identityField = (string) ($configItem['identity_field'] ?? ($configEntry['identity_field'] ?? ''));
    if ($identityField === '' || empty($row[$identityField])) {
      $identityField = (string) ($this->identityField($row, $definition) ?? '');
    }
    if ($identityField === '') {
      $summary['errors'][] = ['file' => $filename, 'message' => sprintf('Extension config for %s %s is missing a stable identity field.', $api, $entity)];
      return;
    }
    $identity = (string) $row[$identityField];
    if ($this->runtimeIdentityConfidence($definition, $identityField, $identity) === 'AMBIGUOUS') {
      $summary['config']['skip']++;
      $summary['warnings'][] = [
        'file' => $filename,
        'message' => sprintf('Skipped automatic write for %s %s because %s=%s is not a unique stable cross-environment identity.', $api, $entity, $identityField, $identity),
      ];
      return;
    }
    $desiredConfigKeys[$definitionKey][$this->identityKey($identityField, $identity)] = TRUE;

    if ($this->importWritesEnabled) {
      $this->applyBundledConfigItem($filename, $definition, $row, $identityField, $identity, $dryRun, $summary);
    }
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
          'dependencies' => $item['dependencies'] ?? [],
          'item' => (array) ($item['item'] ?? []),
        ],
      ];
    }
    return $rows;
  }


  private function dependenciesForExtension(string $key): array {
    return [];
  }

  private function discoverSettingsByExtension(): array {
    $groups = [];
    $metadata = $this->discoverSettingMetadata();
    foreach ($this->discoverRuntimeSettingNames() as $name) {
      $metadata[$name] = $metadata[$name] ?? ['name' => $name];
    }
    ksort($metadata, SORT_NATURAL | SORT_FLAG_CASE);

    foreach ($metadata as $name => $meta) {
      $name = (string) $name;
      if (!$this->isSafeSettingName($name) || $this->isSensitiveSettingName($name)) {
        continue;
      }
      $extensionKey = $this->extensionKeyForSetting($name, (array) $meta);
      if ($extensionKey === '' || $this->isGenericConfigSkippedExtension($extensionKey)) {
        continue;
      }
      $groups[$extensionKey][$name] = \Civi::settings()->get($name);
    }
    foreach ($groups as &$settings) {
      ksort($settings, SORT_NATURAL | SORT_FLAG_CASE);
    }
    ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
    return $groups;
  }

  private function discoverRuntimeSettingNames(): array {
    $names = [];
    try {
      $dao = \CRM_Core_DAO::executeQuery("SELECT DISTINCT name FROM civicrm_setting WHERE name IS NOT NULL AND name <> '' ORDER BY name");
      while ($dao->fetch()) {
        $name = (string) ($dao->name ?? '');
        if ($name !== '') {
          $names[$name] = TRUE;
        }
      }
      $dao->free();
    }
    catch (\Throwable $e) {
      // Some tests or install states may not have the settings table yet.
    }
    return array_keys($names);
  }

  private function discoverSplitConfigByExtension(): array {
    $files = [];
    $index = [];
    foreach ($this->discoverEntityDefinitions() as $definition) {
      $extensionKey = (string) $definition['extension'];
      if ($this->isGenericConfigSkippedExtension($extensionKey) || $this->isNonImportableDefinition($definition)) {
        continue;
      }
      if (!$this->definitionMatchesRuntimeFilter($definition)) {
        continue;
      }

      $providerRows = $this->identityRowsForDefinition($definition);

      $usedNames = [];
      foreach ($providerRows as $row) {
        $identityField = $this->identityField($row, $definition);
        if ($identityField === NULL) {
          continue;
        }
        $identity = (string) $row[$identityField];
        $identityConfidence = $this->identityConfidence($identityField, $definition);
        if ($identityConfidence !== 'AMBIGUOUS' && !$this->identityValueIsUnique($providerRows, $identityField, $identity)) {
          $identityConfidence = 'AMBIGUOUS';
        }
        $safeExtension = $this->safeName($extensionKey);
        $filename = $safeExtension . '/' . $this->safeName((string) $definition['api']) . '/' . $this->safeName((string) $definition['entity']) . '/' . $this->uniqueConfigFileName($identity, $usedNames) . '.yml';
        $dependencies = $this->dependenciesForEntityRow($row, $definition);
        $files[] = [
          'filename' => $filename,
          'data' => [
            'schema_version' => 1,
            'type' => 'extension_config.item',
            'extension' => $extensionKey,
            'api' => (string) $definition['api'],
            'entity' => (string) $definition['entity'],
            'name' => $identity,
            'identity_field' => $identityField,
            'identity_confidence' => $identityConfidence,
            'capabilities' => [
              'create' => !empty($definition['can_create']) && $identityConfidence !== 'AMBIGUOUS',
              'update' => !empty($definition['can_update']) && $identityConfidence !== 'AMBIGUOUS',
              'delete' => !empty($definition['can_delete']) && $identityConfidence !== 'AMBIGUOUS',
            ],
            'dependencies' => $dependencies,
            'item' => $row,
          ],
        ];
      }
      if (!empty($usedNames)) {
        $index[$extensionKey][] = [
          'api' => (string) $definition['api'],
          'entity' => (string) $definition['entity'],
          'directory' => $this->safeName($extensionKey) . '/' . $this->safeName((string) $definition['api']) . '/' . $this->safeName((string) $definition['entity']),
          'count' => count($usedNames),
        ];
      }
    }
    foreach ($index as &$rows) {
      usort($rows, fn($a, $b) => strcmp($a['api'] . ':' . $a['entity'], $b['api'] . ':' . $b['entity']));
    }
    unset($rows);
    ksort($index, SORT_NATURAL | SORT_FLAG_CASE);
    usort($files, fn($a, $b) => strcmp((string) $a['filename'], (string) $b['filename']));
    return ['files' => $files, 'index' => $index];
  }

  private function uniqueConfigFileName(string $identity, array &$used): string {
    $base = $this->safeName($identity);
    $candidate = $base;
    $i = 2;
    while (isset($used[$candidate])) {
      $candidate = $base . '-' . $i;
      $i++;
    }
    $used[$candidate] = TRUE;
    return $candidate;
  }

  private function isPackagedExtensionAssetRow(array $row, array $definition): bool {
    $extensionKey = (string) ($definition['extension'] ?? '');
    $json = json_encode($row, JSON_UNESCAPED_SLASHES);
    if (!$json || $extensionKey === '') {
      return FALSE;
    }
    $hasPackagedPath = stripos($json, '/ext/' . $extensionKey . '/') !== FALSE
      || stripos($json, '/ext/' . str_replace('.', '/', $extensionKey) . '/') !== FALSE
      || (stripos($json, $extensionKey) !== FALSE && preg_match('/\/(packages|templates|resources|assets)\//i', $json));
    if (!$hasPackagedPath) {
      return FALSE;
    }
    foreach (['content', 'html', 'body', 'template', 'msg_html', 'msg_text'] as $field) {
      if (!empty($row[$field]) && is_string($row[$field]) && strlen($row[$field]) > 200) {
        return FALSE;
      }
    }
    return TRUE;
  }


  private function discoverConfigByExtension(): array {
    $groups = [];
    foreach ($this->discoverEntityDefinitions() as $definition) {
      $extensionKey = (string) $definition['extension'];
      if ($this->isGenericConfigSkippedExtension($extensionKey) || $this->isNonImportableDefinition($definition)) {
        continue;
      }
      foreach ($this->fetchEntityRows($definition) as $row) {
        $row = $this->cleanEntityRowForExport((array) $row, $definition);
        $identityField = $this->identityField($row);
        if ($identityField === NULL) {
          continue;
        }
        $identity = (string) $row[$identityField];
        $groups[$extensionKey][(string) $definition['api']][(string) $definition['entity']][] = [
          'name' => $identity,
          'identity_field' => $identityField,
          'dependencies' => $this->dependenciesForEntityRow($row, $definition),
          'item' => $row,
        ];
      }
    }
    foreach ($groups as &$apiGroups) {
      foreach ($apiGroups as &$entityGroups) {
        foreach ($entityGroups as &$rows) {
          usort($rows, fn($a, $b) => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
        }
        ksort($entityGroups, SORT_NATURAL | SORT_FLAG_CASE);
      }
      ksort($apiGroups, SORT_NATURAL | SORT_FLAG_CASE);
    }
    ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
    return $groups;
  }

  private function flattenBundledConfig($config): array {
    $entries = [];
    foreach ((array) $config as $api => $entities) {
      foreach ((array) $entities as $entity => $items) {
        foreach ((array) $items as $item) {
          if (is_array($item)) {
            $entries[] = ['api' => (string) $api, 'entity' => (string) $entity, 'item' => $item];
          }
        }
      }
    }
    return $entries;
  }

  private function discoverEntityDefinitions(): array {
    if ($this->discoveredEntityDefinitions !== NULL) {
      return $this->discoveredEntityDefinitions;
    }
    $definitions = [];
    foreach ($this->extensionBasePaths() as $extensionKey => $basePath) {
      if ($this->isGenericConfigSkippedExtension($extensionKey)) {
        continue;
      }
      $api4EntityNames = [];
      foreach ($this->discoverApi4Entities($extensionKey, $basePath) as $definition) {
        $api4EntityNames[strtolower((string) $definition['entity'])] = TRUE;
        $definitions[$this->definitionKey($definition['extension'], $definition['api'], $definition['entity'])] = $definition;
      }
      foreach ($this->discoverApi3Entities($extensionKey, $basePath) as $definition) {
        if (isset($api4EntityNames[strtolower((string) $definition['entity'])])) {
          continue;
        }
        $definitions[$this->definitionKey($definition['extension'], $definition['api'], $definition['entity'])] = $definition;
      }
    }
    uasort($definitions, function($a, $b) {
      return strcmp($a['extension'] . ':' . $a['api'] . ':' . $a['entity'], $b['extension'] . ':' . $b['api'] . ':' . $b['entity']);
    });
    $this->discoveredEntityDefinitions = array_values($definitions);
    return $this->discoveredEntityDefinitions;
  }

  private function entityDefinitionsByKey(): array {
    $definitions = [];
    foreach ($this->discoverEntityDefinitions() as $definition) {
      $definitions[$this->definitionKey($definition['extension'], $definition['api'], $definition['entity'])] = $definition;
    }
    return $definitions;
  }

  private function extensionBasePaths(): array {
    $paths = [];
    try {
      $system = \CRM_Extension_System::singleton();
      $manager = $system->getManager();
      $mapper = method_exists($system, 'getMapper') ? $system->getMapper() : NULL;
      foreach ((array) $manager->getStatuses() as $key => $status) {
        $status = strtolower((string) $status);
        if (!in_array($status, ['installed', 'enabled'], TRUE)) {
          continue;
        }
        $base = '';
        if ($mapper && method_exists($mapper, 'keyToBasePath')) {
          $base = (string) $mapper->keyToBasePath((string) $key);
        }
        elseif ($mapper && method_exists($mapper, 'getBasePath')) {
          $base = (string) $mapper->getBasePath((string) $key);
        }
        if ($base !== '' && is_dir($base)) {
          $paths[(string) $key] = rtrim($base, DIRECTORY_SEPARATOR);
        }
      }
    }
    catch (\Throwable $e) {
      // Discovery is best-effort.
    }
    ksort($paths, SORT_NATURAL | SORT_FLAG_CASE);
    return $paths;
  }

  private function discoverApi4Entities(string $extensionKey, string $basePath): array {
    $dir = $basePath . '/Civi/Api4';
    if (!is_dir($dir)) {
      return [];
    }
    $definitions = [];
    foreach (glob($dir . '/*.php') ?: [] as $file) {
      $entity = basename($file, '.php');
      if ($entity === 'ConfigManager') {
        continue;
      }
      $class = 'Civi\\Api4\\' . $entity;
      if (!class_exists($class) || !$this->api4EntityUsable($entity)) {
        continue;
      }
      $info = $this->api4Info($entity);
      $definitions[] = [
        'extension' => $extensionKey,
        'api' => 'api4',
        'entity' => $entity,
        'class' => $class,
        'fields' => $this->api4Fields($entity),
        'match_fields' => array_values(array_filter((array) ($info['match_fields'] ?? []), fn($field) => (string) $field !== 'id')),
        'can_create' => is_callable([$class, 'create']),
        'can_update' => is_callable([$class, 'update']),
        'can_delete' => is_callable([$class, 'delete']),
      ];
    }
    return $definitions;
  }

  private function discoverApi3Entities(string $extensionKey, string $basePath): array {
    $dir = $basePath . '/api/v3';
    if (!is_dir($dir) || !function_exists('civicrm_api3')) {
      return [];
    }
    $definitions = [];
    $files = glob($dir . '/*.php') ?: [];
    try {
      $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
      foreach ($iterator as $candidate) {
        if ($candidate instanceof \SplFileInfo && $candidate->isFile() && strtolower($candidate->getExtension()) === 'php') {
          $files[] = $candidate->getPathname();
        }
      }
      $files = array_values(array_unique($files));
    }
    catch (\Throwable $e) {
      // Keep top-level API3 files only.
    }
    $entities = [];
    foreach ($files as $file) {
      $entity = $this->api3EntityNameFromFile($dir, (string) $file);
      if ($entity === '' || in_array(strtolower($entity), ['utils', 'index'], TRUE)) {
        continue;
      }
      $entities[$entity] = TRUE;
    }

    foreach (array_keys($entities) as $entity) {
      if ($this->isNonImportableLegacyExtensionConfig($extensionKey, 'api3', $entity)) {
        continue;
      }
      $listAction = $this->api3ListAction($entity);
      if ($listAction === NULL || !$this->api3EntityHasAction($entity, 'create')) {
        continue;
      }
      $definitions[] = [
        'extension' => $extensionKey,
        'api' => 'api3',
        'entity' => $entity,
        'fields' => [],
        'list_action' => $listAction,
        'can_create' => TRUE,
        'can_update' => TRUE,
        'can_delete' => $this->api3EntityHasAction($entity, 'delete'),
      ];
    }
    return $definitions;
  }

  /**
   * Resolve an API3 entity from both legacy Entity.php and Entity/Action.php layouts.
   */
  private function api3EntityNameFromFile(string $apiDir, string $file): string {
    $apiDir = rtrim(str_replace('\\', '/', $apiDir), '/');
    $file = str_replace('\\', '/', $file);
    if (strpos($file, $apiDir . '/') !== 0) {
      return '';
    }
    $relative = substr($file, strlen($apiDir) + 1);
    $parts = array_values(array_filter(explode('/', $relative), 'strlen'));
    if (count($parts) > 1) {
      return (string) $parts[0];
    }
    return $parts ? basename((string) $parts[0], '.php') : '';
  }

  private function api4EntityUsable(string $entity): bool {
    $class = 'Civi\\Api4\\' . $entity;
    if (!class_exists($class) || !method_exists($class, 'get')) {
      return FALSE;
    }
    try {
      $class::get(FALSE)->setLimit(1)->execute();
      return TRUE;
    }
    catch (\Throwable $e) {
      return FALSE;
    }
  }

  private function api3EntityUsable(string $entity): bool {
    return $this->api3ListAction($entity) !== NULL;
  }

  /**
   * Find a safe collection-read action for API3 config providers.
   *
   * Most entities use get. Some contributed extensions expose collection reads
   * through GetAll/get_all instead, so probe only known read-style actions.
   */
  private function api3ListAction(string $entity): ?string {
    $cacheKey = strtolower($entity);
    if (array_key_exists($cacheKey, self::$api3ListActionByEntity)) {
      return self::$api3ListActionByEntity[$cacheKey];
    }

    $actions = array_merge(['get', 'get_all', 'getall'], $this->api3CollectionActionCandidates($this->api3EntityActions($entity)));
    foreach (array_values(array_unique($actions)) as $action) {
      try {
        $params = ['sequential' => 1];
        if ($action === 'get') {
          $params['options'] = ['limit' => 1];
        }
        civicrm_api3($entity, $action, $params);
        self::$api3ListActionByEntity[$cacheKey] = $action;
        return self::$api3ListActionByEntity[$cacheKey];
      }
      catch (\Throwable $e) {
        // Try the next read-only collection action.
      }
    }
    self::$api3ListActionByEntity[$cacheKey] = NULL;
    return self::$api3ListActionByEntity[$cacheKey];
  }

  /**
   * Return API3 action names exposed by an entity.
   */
  private function api3EntityActions(string $entity): array {
    $cacheKey = strtolower($entity);
    if (array_key_exists($cacheKey, self::$api3ActionsByEntity)) {
      return self::$api3ActionsByEntity[$cacheKey];
    }

    try {
      $result = civicrm_api3($entity, 'getactions', ['sequential' => 1]);
      $actions = [];
      foreach ((array) ($result['values'] ?? []) as $key => $value) {
        if (is_string($key) && $key !== '') {
          $actions[] = strtolower($key);
        }
        if (is_scalar($value) && (string) $value !== '') {
          $actions[] = strtolower((string) $value);
        }
        elseif (is_array($value) && !empty($value['name'])) {
          $actions[] = strtolower((string) $value['name']);
        }
      }
      self::$api3ActionsByEntity[$cacheKey] = array_values(array_unique($actions));
    }
    catch (\Throwable $e) {
      self::$api3ActionsByEntity[$cacheKey] = [];
    }

    return self::$api3ActionsByEntity[$cacheKey];
  }

  /**
   * Keep generic custom API3 collection discovery read-only and conservative.
   *
   * Some contributed extensions use names such as getalltasks/get_all_items
   * instead of the standard get/get_all actions.
   */
  private function api3CollectionActionCandidates(array $actions): array {
    $candidates = [];
    foreach ($actions as $action) {
      $action = strtolower((string) $action);
      if ($action !== '' && preg_match('/^get_?all[a-z0-9_]*$/', $action)) {
        $candidates[] = $action;
      }
    }
    sort($candidates, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values(array_unique($candidates));
  }

  private function api3EntityHasAction(string $entity, string $action): bool {
    if (in_array(strtolower($action), $this->api3EntityActions($entity), TRUE)) {
      return TRUE;
    }
    $function = 'civicrm_api3_' . strtolower($entity) . '_' . strtolower($action);
    return function_exists($function);
  }


  private function api4Info(string $entity): array {
    $class = 'Civi\\Api4\\' . $entity;
    if (!class_exists($class) || !method_exists($class, 'getInfo')) {
      return [];
    }
    try {
      $info = $class::getInfo();
      return is_array($info) ? $info : [];
    }
    catch (\Throwable $e) {
      return [];
    }
  }

  private function api4Fields(string $entity): array {
    $class = 'Civi\\Api4\\' . $entity;
    if (!class_exists($class) || !method_exists($class, 'getFields')) {
      return [];
    }
    try {
      $rows = (array) $class::getFields(FALSE)->execute();
      $fields = [];
      foreach ($rows as $row) {
        $row = (array) $row;
        if (!empty($row['name'])) {
          $fields[(string) $row['name']] = $row;
        }
      }
      return $fields;
    }
    catch (\Throwable $e) {
      return [];
    }
  }

  private function fetchEntityRows(array $definition): array {
    $api = (string) ($definition['api'] ?? '');
    $entity = (string) ($definition['entity'] ?? '');

    if ($api === 'api4') {
      $class = (string) ($definition['class'] ?? '');
      if ($class === '' || !class_exists($class)) {
        throw new \RuntimeException('Contributed configuration provider is unavailable: API4 ' . $entity . '.');
      }
      try {
        return (array) $class::get(FALSE)->addSelect('*')->execute();
      }
      catch (\Throwable $e) {
        throw new \RuntimeException('Could not read contributed configuration provider API4 ' . $entity . ': ' . $e->getMessage(), 0, $e);
      }
    }

    try {
      $action = (string) ($definition['list_action'] ?? 'get');
      $params = ['sequential' => 1];
      if ($action === 'get') {
        $params['options'] = ['limit' => 0];
      }
      $result = civicrm_api3($entity, $action, $params);
      $rows = $this->normalizeApi3Rows((array) $result);

      // A custom get-all action may intentionally return a lightweight list.
      // When the entity also has get and rows expose IDs, hydrate each row so
      // export receives the complete configuration exposed by the provider.
      if ($action !== 'get' && $this->api3EntityHasAction($entity, 'get')) {
        foreach ($rows as $index => $row) {
          $row = (array) $row;
          if (empty($row['id']) || !is_scalar($row['id'])) {
            continue;
          }
          try {
            $detail = civicrm_api3($entity, 'get', ['sequential' => 1, 'id' => $row['id']]);
            $detailRows = $this->normalizeApi3Rows((array) $detail);
            if (!empty($detailRows[0])) {
              $rows[$index] = (array) $detailRows[0];
            }
          }
          catch (\Throwable $e) {
            throw new \RuntimeException('Could not hydrate contributed configuration provider API3 ' . $entity . '.' . $action . ' row ' . (string) $row['id'] . ' through get: ' . $e->getMessage(), 0, $e);
          }
        }
      }
      return array_values($rows);
    }
    catch (\Throwable $e) {
      if ($e instanceof \RuntimeException && strpos($e->getMessage(), 'Could not hydrate contributed configuration provider') === 0) {
        throw $e;
      }
      throw new \RuntimeException('Could not read contributed configuration provider API3 ' . $entity . '.' . (string) ($definition['list_action'] ?? 'get') . ': ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Normalize both standard API3 get results and custom single-record results.
   */
  private function normalizeApi3Rows(array $result): array {
    $values = (array) ($result['values'] ?? []);
    if (!$values) {
      return [];
    }
    foreach ($values as $value) {
      if (!is_array($value)) {
        return [$values];
      }
    }
    return array_values($values);
  }

  private function cleanEntityRowForExport(array $row, array $definition): array {
    $row = $this->stripRuntime($row);
    if ($definition['api'] === 'api4') {
      $row = $this->stripReadOnlyFields($row, (array) ($definition['fields'] ?? []));
    }
    ksort($row, SORT_NATURAL | SORT_FLAG_CASE);
    return $row;
  }

  private function cleanEntityRowForImport(array $row, array $definition): array {
    $row = $this->stripRuntime($row);
    if ($definition['api'] === 'api4') {
      $row = $this->stripReadOnlyFields($row, (array) ($definition['fields'] ?? []));
    }
    return $row;
  }

  private function stripRuntime(array $row): array {
    // Remove only known top-level runtime metadata. Nested fields with the same
    // names may be genuine contributed-extension configuration and must remain.
    unset($row['id'], $row['created_date'], $row['modified_date'], $row['last_modified'], $row['created_id'], $row['modified_id']);
    return $row;
  }

  private function stripReadOnlyFields(array $row, array $fields): array {
    foreach ($fields as $name => $field) {
      $field = (array) $field;
      if (!empty($field['readonly']) || !empty($field['read_only']) || (($field['type'] ?? '') === 'Extra')) {
        unset($row[$name]);
      }
    }
    return $row;
  }

  private function identityField(array $row, array $definition = []): ?string {
    foreach ((array) ($definition['match_fields'] ?? []) as $field) {
      $field = (string) $field;
      if ($field !== 'id' && array_key_exists($field, $row) && is_scalar($row[$field]) && (string) $row[$field] !== '') {
        return $field;
      }
    }
    foreach (['key', 'machine_name', 'name', 'workflow_name', 'name_a_b'] as $field) {
      if (!empty($row[$field]) && is_scalar($row[$field])) {
        return $field;
      }
    }
    // Weak identities are still useful for export/diff visibility, but imports
    // are blocked unless the provider exposes a stronger stable key.
    foreach (['title', 'label'] as $field) {
      if (!empty($row[$field]) && is_scalar($row[$field])) {
        return $field;
      }
    }
    return NULL;
  }

  private function identityConfidence(string $field, array $definition = []): string {
    if (in_array($field, array_map('strval', (array) ($definition['match_fields'] ?? [])), TRUE)) {
      return 'API_VERIFIED';
    }
    return in_array(strtolower($field), ['key', 'machine_name', 'name', 'workflow_name', 'name_a_b'], TRUE)
      ? 'DISCOVERED_UNIQUE'
      : 'AMBIGUOUS';
  }

  private function identityValueIsUnique(array $rows, string $field, string $identity): bool {
    $matches = 0;
    foreach ($rows as $row) {
      $row = (array) $row;
      if (array_key_exists($field, $row) && is_scalar($row[$field]) && (string) $row[$field] === $identity) {
        $matches++;
        if ($matches > 1) {
          return FALSE;
        }
      }
    }
    return $matches === 1;
  }

  private function runtimeIdentityConfidence(array $definition, string $field, string $identity): string {
    $confidence = $this->identityConfidence($field, $definition);
    if ($confidence === 'AMBIGUOUS') {
      return $confidence;
    }
    return $this->identityValueIsUnique($this->identityRowsForDefinition($definition), $field, $identity)
      ? $confidence
      : 'AMBIGUOUS';
  }

  /**
   * Return cleaned portable rows once per provider for identity checks.
   *
   * Validation/import can inspect many YAML items for the same provider. Cache
   * the provider collection for the lifetime of this handler so uniqueness
   * checks do not repeatedly call API3/API4.
   */
  private function identityRowsForDefinition(array $definition): array {
    $key = $this->definitionKey(
      (string) ($definition['extension'] ?? ''),
      (string) ($definition['api'] ?? ''),
      (string) ($definition['entity'] ?? '')
    );
    if (array_key_exists($key, $this->identityRowsByDefinition)) {
      return $this->identityRowsByDefinition[$key];
    }

    $rows = [];
    foreach ($this->fetchEntityRows($definition) as $row) {
      $row = $this->cleanEntityRowForExport((array) $row, $definition);
      if (!$this->isPackagedExtensionAssetRow($row, $definition)) {
        $rows[] = $row;
      }
    }
    $this->identityRowsByDefinition[$key] = array_values($rows);
    return $this->identityRowsByDefinition[$key];
  }

  /**
   * Summarize whether current provider rows have safe cross-environment keys.
   */
  private function identitySafetyForRows(array $rows, array $definition): string {
    if (!$rows) {
      return 'UNVERIFIED';
    }

    foreach ($rows as $row) {
      $row = (array) $row;
      $field = $this->identityField($row, $definition);
      if ($field === NULL || $this->identityConfidence($field, $definition) === 'AMBIGUOUS') {
        return 'UNSAFE';
      }
      if (!$this->identityValueIsUnique($rows, $field, (string) $row[$field])) {
        return 'UNSAFE';
      }
    }

    return 'SAFE';
  }

  private function findExistingEntityRow(array $definition, string $identityField, string $identity): ?array {
    if ($definition['api'] === 'api4') {
      return $this->api4GetFirst((string) $definition['entity'], [[$identityField, '=', $identity]], ['*']);
    }
    try {
      $action = (string) ($definition['list_action'] ?? 'get');
      if ($action === 'get') {
        $result = civicrm_api3((string) $definition['entity'], 'get', ['sequential' => 1, $identityField => $identity, 'options' => ['limit' => 1]]);
        $values = array_values((array) ($result['values'] ?? []));
        return $values[0] ?? NULL;
      }
      foreach ($this->fetchEntityRows($definition) as $row) {
        $row = (array) $row;
        if (isset($row[$identityField]) && (string) $row[$identityField] === $identity) {
          return $row;
        }
      }
      return NULL;
    }
    catch (\Throwable $e) {
      throw new \RuntimeException('Could not look up existing contributed configuration provider API3 ' . (string) $definition['entity'] . ': ' . $e->getMessage(), 0, $e);
    }
  }

  private function createEntityRow(array $definition, array $values): array {
    if ($definition['api'] === 'api4') {
      return $this->api4Create((string) $definition['entity'], $values);
    }
    $result = civicrm_api3((string) $definition['entity'], 'create', $values + ['sequential' => 1]);
    $values = array_values((array) ($result['values'] ?? []));
    return $values[0] ?? [];
  }

  private function updateEntityRow(array $definition, array $existing, array $values): array {
    if ($definition['api'] === 'api4') {
      return $this->api4Update((string) $definition['entity'], [['id', '=', (int) $existing['id']]], $values);
    }
    if (empty($existing['id'])) {
      throw new \RuntimeException('Existing APIv3 row has no id, so it cannot be updated safely.');
    }
    $values['id'] = (int) $existing['id'];
    $result = civicrm_api3((string) $definition['entity'], 'create', $values + ['sequential' => 1]);
    $rows = array_values((array) ($result['values'] ?? []));
    return $rows[0] ?? [];
  }

  private function deleteEntityRow(array $definition, int $id): void {
    if ($definition['api'] === 'api4') {
      $this->api4Delete((string) $definition['entity'], [['id', '=', $id]]);
      return;
    }
    civicrm_api3((string) $definition['entity'], 'delete', ['id' => $id]);
  }

  private function dependenciesForEntityRow(array $row, array $definition): array {
    $dependencies = [[
      'type' => 'extensions',
      'entity' => 'Extension',
      'name' => (string) $definition['extension'],
      'reason' => 'This bundled configuration is provided by this extension API entity.',
    ]];
    $json = json_encode($row);
    if ($json) {
      preg_match_all('/afsearch[A-Za-z0-9_:-]+/', $json, $matches);
      foreach (array_values(array_unique($matches[0] ?? [])) as $name) {
        $dependencies[] = [
          'type' => 'searchkit-displays',
          'entity' => 'SearchDisplay',
          'name' => $name,
          'reason' => 'Extension configuration references this SearchKit display.',
        ];
      }
    }
    return $this->uniqueDependencies($dependencies);
  }

  private function discoverSettingMetadata(): array {
    $settings = [];
    if (!function_exists('civicrm_api3')) {
      return $settings;
    }
    try {
      $result = civicrm_api3('Setting', 'getfields', ['sequential' => 1]);
      foreach ((array) ($result['values'] ?? []) as $key => $meta) {
        $meta = (array) $meta;
        $name = (string) ($meta['name'] ?? (is_string($key) ? $key : ''));
        if ($name !== '') {
          $settings[$name] = $meta;
        }
      }
    }
    catch (\Throwable $e) {
      // Optional setting discovery.
    }
    ksort($settings, SORT_NATURAL | SORT_FLAG_CASE);
    return $settings;
  }

  private function extensionKeyForSetting(string $name, array $meta): string {
    $installed = $this->installedExtensionKeys();
    foreach (['extension', 'extension_key', 'component', 'module', 'group', 'group_name'] as $field) {
      $candidate = (string) ($meta[$field] ?? '');
      if ($candidate !== '' && isset($installed[$candidate])) {
        return $candidate;
      }
    }
    foreach (array_keys($installed) as $extensionKey) {
      if ($this->settingNameLooksRelatedToExtension($name, $extensionKey)) {
        return $extensionKey;
      }
    }
    return '';
  }

  private function settingNameLooksRelatedToExtension(string $settingName, string $extensionKey): bool {
    $setting = strtolower($settingName);
    foreach ($this->extensionTokens($extensionKey) as $token) {
      if (preg_match('/^' . preg_quote($token, '/') . '([_.:-]|$)/', $setting)) {
        return TRUE;
      }
      if (preg_match('/(^|[_.:-])' . preg_quote($token, '/') . '([_.:-]|$)/', $setting)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  private function extensionTokens(string $extensionKey): array {
    $parts = preg_split('/[^A-Za-z0-9]+/', strtolower($extensionKey));
    $tokens = [];
    foreach ((array) $parts as $part) {
      if (strlen($part) >= 4 && !in_array($part, ['civi', 'civicrm', 'org', 'com', 'net', 'co', 'uk', 'info', 'extension'], TRUE)) {
        $tokens[] = $part;
      }
    }
    $last = end($parts);
    if (is_string($last) && strlen($last) >= 3) {
      $tokens[] = $last;
    }
    // Contrib extensions sometimes use a singular setting namespace while the
    // extension key is plural (for example `sqltask_*` vs `sqltasks`). Apply
    // this only to namespace parts, not the fully compacted extension key.
    foreach (array_values(array_unique($tokens)) as $token) {
      if (strlen($token) >= 5 && substr($token, -1) === 's' && substr($token, -2) !== 'ss') {
        $tokens[] = substr($token, 0, -1);
      }
    }

    $compact = preg_replace('/[^A-Za-z0-9]+/', '', strtolower($extensionKey));
    if ($compact !== '') {
      $tokens[] = $compact;
    }
    return array_values(array_unique($tokens));
  }

  private function installedExtensionKeys(): array {
    $keys = [];
    try {
      $statuses = (array) \CRM_Extension_System::singleton()->getManager()->getStatuses();
      foreach ($statuses as $key => $status) {
        if (in_array(strtolower((string) $status), ['installed', 'enabled', 'disabled'], TRUE)) {
          $keys[(string) $key] = TRUE;
        }
      }
    }
    catch (\Throwable $e) {
      // Leave empty.
    }
    return $keys;
  }

  private function isNonImportableDefinition(array $definition): bool {
    if ($this->isNonImportableLegacyExtensionConfig(
      (string) ($definition['extension'] ?? ''),
      (string) ($definition['api'] ?? ''),
      (string) ($definition['entity'] ?? '')
    )) {
      return TRUE;
    }
    foreach (['can_create', 'can_update'] as $flag) {
      if (array_key_exists($flag, $definition) && empty($definition[$flag])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  private function isNonImportableLegacyExtensionConfig(string $extensionKey, string $api, string $entity): bool {
    $entityLower = strtolower($entity);
    $extensionLower = strtolower($extensionKey);

    // Some extension API entities are generated/read-only views over packaged
    // files or runtime state. They are useful to read, but not safe deployable
    // YAML unless the provider explicitly supports create/update.
    if ($api === 'api3' && $this->api3EntityUsable($entity) && !$this->api3EntityHasAction($entity, 'create')) {
      return TRUE;
    }
    if ($api === 'api4') {
      $class = 'Civi\\Api4\\' . $entity;
      if (class_exists($class) && (!is_callable([$class, 'create']) || !is_callable([$class, 'update']))) {
        return TRUE;
      }
    }

    // Known generated-provider fallback. This stays as a safety belt for older
    // Mosaico builds where API3 action discovery may be incomplete.
    if ($extensionLower === 'uk.co.vedaconsulting.mosaico' && $entityLower === 'mosaicobasetemplate') {
      return TRUE;
    }

    return FALSE;
  }

  private function isGenericConfigSkippedExtension(string $extensionKey): bool {
    if ($extensionKey === Version::EXTENSION_KEY) {
      return TRUE;
    }
    if (preg_match('/^civi_/i', $extensionKey)) {
      return TRUE;
    }
    return in_array($extensionKey, [
      'org.civicrm.afform',
      'org.civicrm.api4',
      'org.civicrm.search_kit',
      'org.civicrm.flexmailer',
    ], TRUE);
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

  private function isSafeSettingName(string $name): bool {
    return (bool) preg_match('/^[A-Za-z0-9_.:-]+$/', $name);
  }

  private function isSensitiveSettingName(string $name): bool {
    return (bool) preg_match('/(password|passwd|secret|credential|private|token|api[_-]?key|key)$/i', $name);
  }

  private function definitionKey(string $extension, string $api, string $entity): string {
    return strtolower($extension . '|' . $api . '|' . $entity);
  }

  private function identityKey(string $field, string $value): string {
    return $field . ':' . $value;
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

  private function isEntityConflictException(\Throwable $e): bool {
    $message = $e->getMessage();
    return stripos($message, 'already exists') !== FALSE || stripos($message, 'duplicate') !== FALSE;
  }

  private function formatEntityImportException(\Throwable $e, array $definition, string $identity): string {
    $message = $e->getMessage();
    if (stripos($message, 'already exists') !== FALSE || stripos($message, 'duplicate') !== FALSE) {
      return sprintf('Target already has a conflicting bundled extension config record for %s %s / %s. Import skipped this item to avoid creating a duplicate. Original error: %s', $definition['api'], $definition['entity'], $identity, $message);
    }
    return $message;
  }

  private function callManager($manager, string $method, array $keys): void {
    if (!method_exists($manager, $method)) {
      throw new \RuntimeException('Extension manager does not support method ' . $method . ' on this CiviCRM version.');
    }
    $manager->{$method}($keys);
    self::$api3ActionsByEntity = [];
    self::$api3ListActionByEntity = [];
    $this->discoveredEntityDefinitions = NULL;
    $this->identityRowsByDefinition = [];
  }

  private function safeName(string $name): string {
    $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name);
    return trim((string) $safe, '-') ?: sha1($name);
  }
}
