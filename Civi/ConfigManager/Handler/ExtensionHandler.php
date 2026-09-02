<?php
namespace Civi\ConfigManager\Handler;

use Civi\ConfigManager\Version;
use Civi\ConfigManager\Service\DiskRowSpool;

class ExtensionHandler extends AbstractHandler implements StreamingHandlerInterface, StreamingImportHandlerInterface, ChunkedStreamingHandlerInterface {
  private bool $importWritesEnabled = TRUE;
  private bool $deleteMissingEnabled = TRUE;
  private ?array $discoveredEntityDefinitions = NULL;
  private ?array $inventoryEntityDefinitions = NULL;
  private array $runtimeTypeFilters = [];
  private array $identityRowsByDefinition = [];
  private static array $api3ActionsByEntity = [];
  private static array $api3ListActionByEntity = [];
  private static array $api3WritableFieldsByEntity = [];
  private static array $api3DeleteActionByEntity = [];
  private array $exportErrors = [];

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

  /**
   * Inventory automatically discovered extension providers without reading
   * any provider collection or business record.
   *
   * Discovery may load the provider class and inspect API4 metadata. It must
   * not call API4 get(), API3 collection actions, reviewed BAO generators, or
   * this handler's export/compatibility-report paths.
   *
   * @return array<int,array<string,mixed>>
   */
  public function getDiscoveredProviderInventory(): array {
    $rows = [];
    foreach ($this->discoverInventoryEntityDefinitions() as $definition) {
      $definition = (array) $definition;
      $api = (string) ($definition['api'] ?? '');
      $entity = (string) ($definition['entity'] ?? '');
      $extension = (string) ($definition['extension'] ?? '');
      $admitted = !array_key_exists('generic_config_admitted', $definition) || !empty($definition['generic_config_admitted']);
      $canCreate = $admitted && !empty($definition['can_create']);
      $canUpdate = $admitted && !empty($definition['can_update']);
      $canDelete = $admitted && !empty($definition['can_delete']);

      $fieldNames = [];
      foreach ((array) ($definition['fields'] ?? []) as $key => $field) {
        $field = (array) $field;
        $name = is_string($key) ? $key : (string) ($field['name'] ?? '');
        if ($name !== '') {
          $fieldNames[] = $name;
        }
      }
      foreach ((array) ($definition['write_fields'] ?? []) as $name) {
        if (is_scalar($name) && (string) $name !== '') {
          $fieldNames[] = (string) $name;
        }
      }

      $sensitiveFields = [];
      foreach ($fieldNames as $name) {
        if ($this->isSensitiveSettingName((string) $name)) {
          $sensitiveFields[] = (string) $name;
        }
      }
      $runtimeFields = array_values(array_intersect($fieldNames, [
        'id', 'created_date', 'modified_date', 'last_modified',
        'created_id', 'modified_id', 'last_run', 'last_run_end',
        'last_executed', 'last_runtime', 'next_execution',
      ]));

      $discoveredActions = array_values(array_map('strval', (array) ($definition['discovered_actions'] ?? [])));
      if ($api === 'api4') {
        $discoveredActions = array_values(array_filter([
          'get',
          !empty($definition['can_create']) ? 'create' : '',
          !empty($definition['can_update']) ? 'update' : '',
          !empty($definition['can_delete']) ? 'delete' : '',
        ], 'strlen'));
      }
      elseif (!empty($definition['list_action'])) {
        $discoveredActions[] = (string) $definition['list_action'];
      }
      if (!empty($definition['read_adapter'])) {
        $discoveredActions[] = 'adapter:' . (string) $definition['read_adapter'];
      }
      $discoveredActions = array_values(array_unique($discoveredActions));

      $rows[] = [
        'provider_key' => implode(':', ['extensions', $extension, $api, $entity]),
        'type' => $this->virtualTypeForDefinition($definition),
        'base_type' => $this->getType(),
        'label' => $this->labelForDefinition($definition),
        'owner' => $extension,
        'registration_source' => 'automatic_extension_api',
        'api_version' => $api,
        'entity' => $entity,
        'actions' => [
          'read' => $api === 'api4' || !empty($definition['list_action']) || !empty($definition['read_adapter']),
          'create' => $canCreate,
          'update' => $canUpdate,
          'delete' => $canDelete,
        ],
        'discovered_actions' => $discoveredActions,
        'field_names' => $this->sortedStringValues($fieldNames),
        'identity_fields' => $this->sortedStringValues((array) ($definition['match_fields'] ?? [])),
        'reference_fields' => [],
        'sensitive_fields' => $this->sortedStringValues($sensitiveFields),
        'runtime_fields' => $this->sortedStringValues($runtimeFields),
        'admitted' => $admitted,
        'capability' => $this->inventoryCapability($admitted, $canCreate, $canUpdate, $canDelete),
        'capability_reason_code' => (string) ($definition['admission_reason_code'] ?? ($admitted ? 'provider_admitted' : 'provider_not_admitted')),
        'capability_reason' => (string) ($definition['admission_reason'] ?? ''),
        'identity_evidence' => !empty($definition['reviewed_provider'])
          ? 'reviewed_adapter'
          : (!empty($definition['match_fields']) ? 'provider_metadata' : 'none'),
        'metadata_completeness' => $fieldNames ? 'discovered' : 'partial',
        'collection_read_during_inventory' => FALSE,
      ];
    }

    usort($rows, static function(array $a, array $b): int {
      return strcmp((string) $a['provider_key'], (string) $b['provider_key']);
    });
    return $rows;
  }

  private function inventoryCapability(bool $admitted, bool $canCreate, bool $canUpdate, bool $canDelete): string {
    if (!$admitted) {
      return 'unsupported';
    }
    if ($canCreate && $canUpdate && $canDelete) {
      return 'full';
    }
    if ($canCreate && $canUpdate) {
      return 'managed_no_delete';
    }
    return 'export_only';
  }

  private function sortedStringValues(array $values): array {
    $values = array_values(array_unique(array_filter(array_map('strval', $values), 'strlen')));
    sort($values, SORT_NATURAL | SORT_FLAG_CASE);
    return $values;
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

  /** @return array<int,array{key:string,label:string,path_prefix?:string}> */
  public function getExportUnits(): array {
    $units = [[
      'key' => '__statuses__',
      'label' => 'Extension status and settings',
    ]];
    foreach ($this->discoverEntityDefinitions() as $definition) {
      $extensionKey = (string) ($definition['extension'] ?? '');
      if ($this->isGenericConfigSkippedExtension($extensionKey)
        || $this->isNonImportableDefinition($definition)
        || !$this->definitionMatchesRuntimeFilter($definition)) {
        continue;
      }
      $units[] = [
        'key' => $this->exportUnitKey($definition),
        'label' => 'Extension provider: ' . $this->labelForDefinition($definition),
        'path_prefix' => $this->safeName($extensionKey) . '/' . $this->safeName((string) ($definition['api'] ?? '')) . '/' . $this->safeName((string) ($definition['entity'] ?? '')),
      ];
    }
    return $units;
  }

  public function export(): array {
    $files = iterator_to_array($this->iterateExport(), FALSE);
    usort($files, static function(array $a, array $b): int {
      return strcmp((string) ($a['filename'] ?? ''), (string) ($b['filename'] ?? ''));
    });
    return $files;
  }

  public function iterateExport(): iterable {
    $this->exportErrors = [];
    foreach ($this->getExportUnits() as $unit) {
      foreach ($this->iterateExportUnitInternal((string) $unit['key']) as $file) {
        yield $file;
      }
    }
  }

  public function iterateExportUnit(string $unitKey, ?callable $progress = NULL): iterable {
    $this->exportErrors = [];
    foreach ($this->iterateExportUnitInternal($unitKey, $progress) as $file) {
      yield $file;
    }
  }

  /**
   * @return \Generator<int,array<string,mixed>>
   */
  private function iterateExportUnitInternal(string $unitKey, ?callable $progress = NULL): \Generator {
    if ($unitKey === '__statuses__') {
      $manager = \CRM_Extension_System::singleton()->getManager();
      $settingsByExtension = [];
      try {
        $settingsByExtension = $this->discoverSettingsByExtension();
      }
      catch (\Throwable $e) {
        $this->addExportError('Extension settings could not be discovered: ' . $e->getMessage());
      }

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
          'extension' => ['key' => $key, 'status' => (string) $status],
        ];
        if (!empty($settingsByExtension[$key])) {
          $data['settings'] = $settingsByExtension[$key];
        }
        // config_index is rebuilt from the provider documents which actually
        // survive scope/ignore filtering in the staged workspace. This avoids
        // coupling status emission to provider order and enables durable units.
        yield ['filename' => $this->safeName($key) . '.yml', 'data' => $data];
      }
      return;
    }

    $definition = $this->definitionForExportUnit($unitKey);
    if ($definition === NULL) {
      throw new \RuntimeException('Unknown extension-provider export unit. The provider list changed after the job was planned. Start a fresh export.');
    }

    $extensionKey = (string) ($definition['extension'] ?? '');
    $api = (string) ($definition['api'] ?? '');
    $entity = (string) ($definition['entity'] ?? '');
    $spool = new DiskRowSpool();
    try {
      $counts = [];
      $fingerprintCounts = [];
      $rowCount = 0;
      foreach ($this->iterateCleanProviderRows($definition) as $row) {
        $row = (array) $row;
        $identityField = $this->identityField($row, $definition);
        if ($identityField === NULL) {
          // Rows without a usable semantic field cannot be addressed safely.
          // Keep provider export fail-closed rather than silently omitting them.
          throw new \RuntimeException($api . ' ' . $entity . ' returned a configuration row without a usable semantic identity field.');
        }
        $identity = (string) $row[$identityField];
        $fingerprint = $this->providerMonitorFingerprint($row);
        $counts[$identityField][$identity] = ($counts[$identityField][$identity] ?? 0) + 1;
        $fingerprintKey = $identityField . "\0" . $identity . "\0" . $fingerprint;
        $fingerprintCounts[$fingerprintKey] = ($fingerprintCounts[$fingerprintKey] ?? 0) + 1;
        $spool->append([
          'identity_field' => $identityField,
          'identity' => $identity,
          'fingerprint' => $fingerprint,
          'row' => $row,
        ]);
        $rowCount++;
        if ($progress !== NULL && ($rowCount % 100) === 0) {
          $progress(['processed' => $rowCount, 'stage' => 'scan', 'message' => 'Scanning extension provider ' . $this->labelForDefinition($definition) . ': ' . $rowCount . ' record(s) checked for portable/ambiguous identity.']);
        }
      }
      if ($progress !== NULL) {
        $progress(['processed' => $rowCount, 'stage' => 'spool_complete', 'message' => 'Provider identity scan complete for ' . $this->labelForDefinition($definition) . '. Building deterministic temporary YAML from the disk spool.']);
      }

      $occurrences = [];
      foreach ($spool->iterate() as $entry) {
        $row = (array) ($entry['row'] ?? []);
        $identityField = (string) ($entry['identity_field'] ?? '');
        $identity = (string) ($entry['identity'] ?? '');
        $fingerprint = (string) ($entry['fingerprint'] ?? '');
        $identityConfidence = $this->identityConfidence($identityField, $definition);
        $groupCount = (int) ($counts[$identityField][$identity] ?? 0);
        if ($identityConfidence !== 'AMBIGUOUS' && $groupCount !== 1) {
          $identityConfidence = 'AMBIGUOUS';
        }
        $monitorOnly = $identityConfidence === 'AMBIGUOUS';
        $fingerprintKey = $identityField . "\0" . $identity . "\0" . $fingerprint;
        $occurrence = ($occurrences[$fingerprintKey] ?? 0) + 1;
        $occurrences[$fingerprintKey] = $occurrence;

        $directory = $this->safeName($extensionKey) . '/' . $this->safeName($api) . '/' . $this->safeName($entity);
        if ($monitorOnly) {
          $filename = $directory . '/' . $this->safeName($identity)
            . '--ambiguous-' . substr($fingerprint, 0, 12)
            . '-' . str_pad((string) $occurrence, 2, '0', STR_PAD_LEFT) . '.yml';
        }
        else {
          $filename = $directory . '/' . $this->safeName($identity) . '.yml';
        }

        $data = [
          'schema_version' => 1,
          'type' => 'extension_config.item',
          'extension' => $extensionKey,
          'api' => $api,
          'entity' => $entity,
          'name' => $identity,
          'identity_field' => $identityField,
          'identity_portable' => !$monitorOnly,
          'identity_confidence' => $identityConfidence,
          'monitor_only' => $monitorOnly,
          'capabilities' => [
            'create' => !empty($definition['can_create']) && !$monitorOnly,
            'update' => !empty($definition['can_update']) && !$monitorOnly,
            'delete' => !empty($definition['can_delete']) && !$monitorOnly,
          ],
          'dependencies' => $this->dependenciesForEntityRow($row, $definition),
          'item' => $row,
        ];
        if ($monitorOnly) {
          $data['ambiguity'] = [
            'reason' => 'duplicate_or_unproven_portable_identity',
            'group_count' => $groupCount,
            'content_count' => (int) ($fingerprintCounts[$fingerprintKey] ?? 0),
            'content_fingerprint' => $fingerprint,
            'occurrence' => $occurrence,
          ];
        }
        yield ['filename' => $filename, 'data' => $data];
      }
    }
    catch (\Throwable $e) {
      $provider = trim($extensionKey . ' ' . $api . ' ' . $entity);
      $this->addExportError('Extension configuration provider ' . ($provider !== '' ? $provider : '(unknown)') . ' could not be exported: ' . $e->getMessage());
    }
    finally {
      $spool->close();
      $this->invalidateIdentityRowsForDefinition($definition);
    }
  }

  private function exportUnitKey(array $definition): string {
    return 'provider:' . substr(hash('sha256', $this->definitionKey(
      (string) ($definition['extension'] ?? ''),
      (string) ($definition['api'] ?? ''),
      (string) ($definition['entity'] ?? '')
    )), 0, 24);
  }

  private function definitionForExportUnit(string $unitKey): ?array {
    foreach ($this->discoverEntityDefinitions() as $definition) {
      $extensionKey = (string) ($definition['extension'] ?? '');
      if ($this->isGenericConfigSkippedExtension($extensionKey)
        || $this->isNonImportableDefinition($definition)
        || !$this->definitionMatchesRuntimeFilter($definition)) {
        continue;
      }
      if ($this->exportUnitKey($definition) === $unitKey) {
        return $definition;
      }
    }
    return NULL;
  }

  private function providerMonitorFingerprint(array $row): string {
    $normalized = $this->normaliseDataForDiff($row);
    return $this->fingerprint($normalized);
  }

  /**
   * Iterate one contributed provider without materializing the complete API4 or
   * normal API3 get collection. Read-adapter/custom actions retain the existing
   * conservative adapter because their pagination semantics are provider-owned.
   *
   * @return \Generator<int,array<string,mixed>>
   */
  private function iterateCleanProviderRows(array $definition): \Generator {
    $api = (string) ($definition['api'] ?? '');
    $entity = (string) ($definition['entity'] ?? '');

    if ($api === 'api4') {
      foreach ($this->api4Iterate($entity, [], ['*']) as $row) {
        $row = $this->cleanEntityRowForExport((array) $row, $definition);
        if (!$this->isPackagedExtensionAssetRow($row, $definition)) {
          yield $row;
        }
      }
      return;
    }

    $readAdapter = trim((string) ($definition['read_adapter'] ?? ''));
    $action = (string) ($definition['list_action'] ?? 'get');
    if ($readAdapter === '' && $action === 'get') {
      $offset = 0;
      $batchSize = 200;
      while (TRUE) {
        $result = civicrm_api3($entity, 'get', [
          'sequential' => 1,
          'options' => ['limit' => $batchSize, 'offset' => $offset],
        ]);
        $page = $this->normalizeApi3Rows((array) $result);
        $count = count($page);
        foreach ($page as $row) {
          $row = $this->cleanEntityRowForExport((array) $row, $definition);
          if (!$this->isPackagedExtensionAssetRow($row, $definition)) {
            yield $row;
          }
        }
        unset($page, $result);
        if ($count < $batchSize) {
          break;
        }
        $offset += $count;
      }
      return;
    }

    foreach ($this->fetchEntityRows($definition) as $row) {
      $row = $this->cleanEntityRowForExport((array) $row, $definition);
      if (!$this->isPackagedExtensionAssetRow($row, $definition)) {
        yield $row;
      }
    }
  }

  /**
   * Return and clear non-fatal errors collected during the latest export.
   *
   * Base extension status YAML remains exportable when one contributed
   * provider cannot be inspected. ConfigManager consumes these errors so the
   * UI never reports an incomplete extension scan as In Sync.
   */
  public function consumeExportErrors(): array {
    $errors = $this->exportErrors;
    $this->exportErrors = [];
    return $errors;
  }

  private function addExportError(string $message): void {
    $message = trim($message);
    if ($message !== '' && !in_array($message, $this->exportErrors, TRUE)) {
      $this->exportErrors[] = $message;
    }
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
        $identitySafety = $importable ? 'UNVERIFIED' : 'EXCLUDED';
        $discoveryError = '';
        if ($importable) {
          try {
            $identityRows = $this->identityRowsForDefinition($definition);
            $identitySafety = $this->identitySafetyForRows($identityRows, $definition);
          }
          catch (\Throwable $e) {
            $identitySafety = 'ERROR';
            $discoveryError = $e->getMessage();
            $errorProviders++;
          }
        }
        $provider = [
          'api' => (string) ($definition['api'] ?? ''),
          'entity' => (string) ($definition['entity'] ?? ''),
          'list_action' => (string) ($definition['list_action'] ?? 'get'),
          'read_adapter' => (string) ($definition['read_adapter'] ?? ''),
          'match_fields' => array_values((array) ($definition['match_fields'] ?? [])),
          'generic_config_admitted' => !array_key_exists('generic_config_admitted', $definition) || !empty($definition['generic_config_admitted']),
          'admission_reason' => (string) ($definition['admission_reason'] ?? ''),
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
    $compatibility = [];
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
        $this->validateExtensionConfigItem($filename, $item, $definitions, $errors, $warnings, $compatibility);
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
        $resolved = $this->resolveConfigDefinition($definitions, $key, (string) $entry['api'], (string) $entry['entity']);
        if ($resolved === NULL) {
          $errors[] = [
            'file' => $filename,
            'message' => sprintf('Bundled extension config provider is not available: extension %s, %s entity %s. Install/enable that extension before import.', $key, $entry['api'], $entry['entity']),
          ];
          continue;
        }
        $definition = $resolved['definition'];
        if ($this->isNonImportableDefinition($definition)) {
          $compatibility[] = [
            'file' => $filename,
            'message' => sprintf('Skipped non-admitted extension provider %s %s during validation. Re-export removes obsolete generic-provider YAML; active CiviCRM business/config data is untouched.', $entry['api'], $entry['entity']),
          ];
          continue;
        }
        $row = (array) ($entry['item']['item'] ?? []);
        $identityField = (string) ($entry['item']['identity_field'] ?? '');
        if ($identityField === '' || empty($row[$identityField])) {
          $identityField = (string) ($this->identityField($row, $definition) ?? '');
        }
        if ($identityField === '') {
          $errors[] = ['file' => $filename, 'message' => sprintf('Bundled extension config for %s %s is missing a stable identity field.', $entry['api'], $entry['entity'])];
        }
        elseif ($this->runtimeIdentityConfidence($definition, $identityField, (string) $row[$identityField]) === 'AMBIGUOUS') {
          $compatibility[] = ['file' => $filename, 'message' => sprintf('%s %s is monitor-only snapshot because the provider does not expose a unique portable identity. Automatic create/update/delete stays blocked.', $entry['api'], $entry['entity'])];
        }
      }
    }

    return [
      'type' => $this->getType(),
      'valid' => empty($errors),
      'errors' => $errors,
      'warnings' => $warnings,
      'compatibility' => $compatibility,
      'count' => count($items),
    ];
  }

  public function import(array $items, bool $dryRun = TRUE): array {
    return $this->importIterable($items, $dryRun);
  }

  public function importIterable(iterable $items, bool $dryRun = TRUE): array {
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
      'compatibility' => [],
      'errors' => [],
    ];

    $manager = \CRM_Extension_System::singleton()->getManager();
    $current = (array) $manager->getStatuses();
    $desiredKeys = [];
    $definitions = $this->entityDefinitionsByKey();
    $desiredConfigKeys = $this->desiredConfigKeysForRuntimeFilter($definitions);
    $providerDeleteSafe = [];

    // One pass is important here: a generator cannot be rewound for separate
    // config-index, extension-status, and provider-item passes. Process each
    // YAML document immediately and retain only compact desired identity sets.
    foreach ($items as $filename => $item) {
      $filename = (string) $filename;
      $item = (array) $item;
      $type = (string) ($item['type'] ?? '');

      if ($type === 'extensions.collection') {
        foreach ((array) ($item['items'] ?? []) as $extension) {
          $extension = (array) $extension;
          $key = (string) ($extension['key'] ?? '');
          if ($key === '') {
            $summary['errors'][] = ['file' => $filename, 'message' => 'Extension key missing.'];
            continue;
          }
          $desiredKeys[$key] = TRUE;
          if ($this->importWritesEnabled) {
            $this->applyExtensionStatus($manager, $current, $filename, $key, strtolower((string) ($extension['status'] ?? '')), $dryRun, $summary);
          }
        }
        continue;
      }

      if ($type === 'extension.item') {
        $extension = (array) ($item['extension'] ?? []);
        if (empty($extension['key']) && !empty($item['key'])) {
          $extension['key'] = $item['key'];
        }
        $key = (string) ($extension['key'] ?? '');
        if ($key === '') {
          $summary['errors'][] = ['file' => $filename, 'message' => 'Extension key missing.'];
          continue;
        }
        $desiredKeys[$key] = TRUE;

        foreach ((array) ($item['config_index'] ?? []) as $index) {
          $index = (array) $index;
          if (empty($index['api']) || empty($index['entity'])) {
            continue;
          }
          $resolved = $this->resolveConfigDefinition($definitions, $key, (string) $index['api'], (string) $index['entity']);
          if ($resolved !== NULL) {
            $definitionKey = (string) $resolved['key'];
            $desiredConfigKeys[$definitionKey] = $desiredConfigKeys[$definitionKey] ?? [];
            $this->mergeProviderDeleteSafety($providerDeleteSafe, $definitionKey, !empty($index['delete_safe']));
          }
        }

        if ($this->importWritesEnabled) {
          $this->applyExtensionStatus($manager, $current, $filename, $key, strtolower((string) ($extension['status'] ?? '')), $dryRun, $summary);
          $this->applyBundledSettings($filename, $key, (array) ($item['settings'] ?? []), $dryRun, $summary);
        }
        foreach ($this->flattenBundledConfig($item['config'] ?? []) as $configEntry) {
          $this->recordSourceProviderDeleteSafety($providerDeleteSafe, $definitions, $key, $configEntry);
          $this->processExtensionConfigEntry($filename, $key, $configEntry, $definitions, $desiredConfigKeys, $dryRun, $summary);
        }
        continue;
      }

      if ($type === 'extension_config.item') {
        $extensionKey = (string) ($item['extension'] ?? '');
        if ($extensionKey === '') {
          $summary['errors'][] = ['file' => $filename, 'message' => 'Extension config item is missing extension.'];
          continue;
        }
        $configEntry = [
          'filename' => $filename,
          'extension' => $extensionKey,
          'api' => (string) ($item['api'] ?? ''),
          'entity' => (string) ($item['entity'] ?? ''),
          'identity_field' => $item['identity_field'] ?? NULL,
          'identity_confidence' => $item['identity_confidence'] ?? NULL,
          'monitor_only' => !empty($item['monitor_only']),
          'ambiguity' => (array) ($item['ambiguity'] ?? []),
          'capabilities' => (array) ($item['capabilities'] ?? []),
          'item' => [
            'name' => $item['name'] ?? NULL,
            'identity_field' => $item['identity_field'] ?? NULL,
            'identity_confidence' => $item['identity_confidence'] ?? NULL,
            'monitor_only' => !empty($item['monitor_only']),
            'ambiguity' => (array) ($item['ambiguity'] ?? []),
            'capabilities' => (array) ($item['capabilities'] ?? []),
            'dependencies' => $item['dependencies'] ?? [],
            'item' => (array) ($item['item'] ?? []),
          ],
        ];
        $this->recordSourceProviderDeleteSafety($providerDeleteSafe, $definitions, $extensionKey, $configEntry);
        $this->processExtensionConfigEntry($filename, $extensionKey, $configEntry, $definitions, $desiredConfigKeys, $dryRun, $summary);
        continue;
      }

      $summary['errors'][] = ['file' => $filename, 'message' => 'Invalid extension YAML type. Expected extension.item or extension_config.item.'];
    }

    if ($this->deleteMissingEnabled) {
      foreach ($desiredConfigKeys as $definitionKey => $desiredForEntity) {
        if (!isset($definitions[$definitionKey])) {
          continue;
        }
        $this->deleteMissingBundledConfig($definitions[$definitionKey], $desiredForEntity, !empty($providerDeleteSafe[$definitionKey]), $dryRun, $summary);
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
      if (($data['type'] ?? '') === 'extension_config.item') {
        $definitions = $this->entityDefinitionsByKey();
        $resolved = $this->resolveConfigDefinition(
          $definitions,
          (string) ($data['extension'] ?? ''),
          (string) ($data['api'] ?? ''),
          (string) ($data['entity'] ?? '')
        );
        if ($resolved !== NULL) {
          $data['item'] = $this->cleanEntityRowForImport($data['item'], $resolved['definition']);
        }
      }
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
          if (!$dryRun) {
            $this->callManager($manager, 'install', [$key]);
          }
        }
        else {
          $summary['enable']++;
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
            $summary['compatibility'][] = ['file' => $filename, 'name' => $identity, 'message' => sprintf('%s %s is read-only on this site, so the YAML backup was not applied automatically.', $definition['api'], $definition['entity'])];
            return;
          }
          $summary['config']['update']++;
          if (!$dryRun) {
            $this->updateEntityRow($definition, (array) $existing, $desired);
            $this->invalidateIdentityRowsForDefinition($definition);
          }
        }
        else {
          $summary['config']['skip']++;
        }
      }
      else {
        if (empty($definition['can_create']) && array_key_exists('can_create', $definition)) {
          $summary['config']['skip']++;
          $summary['compatibility'][] = ['file' => $filename, 'name' => $identity, 'message' => sprintf('%s %s is read-only on this site, so the YAML backup was not created automatically.', $definition['api'], $definition['entity'])];
          return;
        }
        $summary['config']['create']++;
        if (!$dryRun) {
          $this->createEntityRow($definition, $desired);
          $this->invalidateIdentityRowsForDefinition($definition);
          if ($this->findExistingEntityRow($definition, $identityField, $identity) === NULL) {
            throw new \RuntimeException(sprintf(
              'Provider %s %s accepted create but the new record could not be read back by %s=%s.',
              $definition['api'],
              $definition['entity'],
              $identityField,
              $identity
            ));
          }
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

  private function deleteMissingBundledConfig(array $definition, array $desiredKeys, bool $sourceDeleteSafe, bool $dryRun, array &$summary): void {
    if (!$sourceDeleteSafe) {
      $summary['compatibility'][] = [
        'message' => sprintf('%s %s delete-missing is disabled because the source YAML does not prove delete capability for any portable identity in this provider.', $definition['api'], $definition['entity']),
      ];
      return;
    }

    $spool = new DiskRowSpool();
    $identityCounts = [];
    try {
      foreach ($this->iterateCleanProviderRows($definition) as $row) {
        $row = (array) $row;
        $identityField = $this->identityField($row, $definition);
        if ($identityField === NULL) {
          continue;
        }
        $identity = (string) $row[$identityField];
        $identityCounts[$identityField][$identity] = ($identityCounts[$identityField][$identity] ?? 0) + 1;
        $spool->append(['identity_field' => $identityField, 'identity' => $identity, 'row' => $row]);
      }

      foreach ($spool->iterate() as $entry) {
        $identityField = (string) ($entry['identity_field'] ?? '');
        $identity = (string) ($entry['identity'] ?? '');
        if ($identityField === '' || $identity === '') {
          continue;
        }
        $confidence = $this->identityConfidence($identityField, $definition);
        if ($confidence === 'AMBIGUOUS' || (($identityCounts[$identityField][$identity] ?? 0) !== 1)) {
          $summary['config']['skip']++;
          $summary['compatibility'][] = [
            'name' => $identity,
            'message' => sprintf('%s %s delete-missing skipped ambiguous identity %s=%s; unrelated unique identities remain eligible for safe cleanup.', $definition['api'], $definition['entity'], $identityField, $identity),
          ];
          continue;
        }
        if (isset($desiredKeys[$this->identityKey($identityField, $identity)])) {
          continue;
        }
        if (empty($definition['can_delete']) && array_key_exists('can_delete', $definition)) {
          $summary['config']['skip']++;
          $summary['compatibility'][] = [
            'name' => $identity,
            'message' => sprintf('%s %s does not expose a delete action, so Configuration Manager leaves the active record in place.', $definition['api'], $definition['entity']),
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
            $live = $this->findExistingEntityRow($definition, $identityField, $identity);
            if ($live && !empty($live['id'])) {
              $this->deleteEntityRow($definition, (int) $live['id']);
            }
          }
          catch (\Throwable $e) {
            $summary['errors'][] = ['name' => $identity, 'message' => 'Delete failed: ' . $e->getMessage()];
          }
        }
      }
    }
    finally {
      $spool->close();
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

  private function validateExtensionConfigItem(string $filename, array $item, array $definitions, array &$errors, array &$warnings, array &$compatibility): void {
    $extensionKey = (string) ($item['extension'] ?? '');
    $api = (string) ($item['api'] ?? '');
    $entity = (string) ($item['entity'] ?? '');
    if ($extensionKey === '' || $api === '' || $entity === '') {
      $errors[] = ['file' => $filename, 'message' => 'Extension config item is missing extension, api, or entity.'];
      return;
    }
    $resolved = $this->resolveConfigDefinition($definitions, $extensionKey, $api, $entity);
    if ($resolved === NULL) {
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
    $definition = $resolved['definition'];
    if ($this->isNonImportableDefinition($definition)) {
      $compatibility[] = [
        'file' => $filename,
        'message' => sprintf('Extension provider %s %s is no longer admitted by generic configuration discovery. Import will skip this YAML; re-export removes it safely.', $api, $entity),
      ];
      return;
    }
    if ($identityField === '' || empty($row[$identityField])) {
      $identityField = (string) ($this->identityField($row, $definition) ?? '');
    }
    if ($identityField === '') {
      $errors[] = ['file' => $filename, 'message' => sprintf('Extension config item for %s %s is missing a stable identity field.', $api, $entity)];
    }
    elseif ($this->runtimeIdentityConfidence($definition, $identityField, (string) $row[$identityField]) === 'AMBIGUOUS') {
      $compatibility[] = [
        'file' => $filename,
        'message' => sprintf('%s %s is monitor-only snapshot because %s is not a unique portable identity. Export/diff remain available; automatic create/update/delete stays blocked.', $api, $entity, $identityField),
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
    $resolved = $this->resolveConfigDefinition($definitions, $extensionKey, $api, $entity);
    if ($resolved === NULL) {
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
    $definitionKey = (string) $resolved['key'];
    $definition = $resolved['definition'];
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
    $sourceIdentityConfidence = strtoupper(trim((string) ($configItem['identity_confidence'] ?? ($configEntry['identity_confidence'] ?? ''))));
    $sourceCapabilities = (array) ($configItem['capabilities'] ?? ($configEntry['capabilities'] ?? []));
    $identityField = (string) ($configItem['identity_field'] ?? ($configEntry['identity_field'] ?? ''));
    if ($identityField === '' || !array_key_exists($identityField, $row) || !is_scalar($row[$identityField]) || trim((string) $row[$identityField]) === '') {
      $identityField = (string) ($this->identityField($row, $definition) ?? '');
    }
    if ($identityField === '') {
      $summary['errors'][] = ['file' => $filename, 'message' => sprintf('Extension config for %s %s is missing a stable identity field.', $api, $entity)];
      return;
    }
    $identity = (string) $row[$identityField];
    $identityKey = $this->identityKey($identityField, $identity);

    // Monitor-only source rows still protect their semantic key from
    // delete-missing. They cannot authorize writes, but they must never be
    // mistaken for objects which are absent from the source snapshot.
    $desiredConfigKeys[$definitionKey][$identityKey] = TRUE;

    if ($sourceIdentityConfidence === 'AMBIGUOUS' || !empty($configItem['monitor_only']) || !empty($configEntry['monitor_only'])) {
      $summary['config']['skip']++;
      $summary['compatibility'][] = [
        'file' => $filename,
        'message' => sprintf('%s %s is an intentional monitor-only snapshot because the source identity is ambiguous. Automatic create/update/delete was not attempted; unrelated safe identities may continue.', $api, $entity),
      ];
      return;
    }
    if ($sourceCapabilities && (empty($sourceCapabilities['create']) || empty($sourceCapabilities['update']))) {
      $summary['config']['skip']++;
      $summary['compatibility'][] = [
        'file' => $filename,
        'message' => sprintf('%s %s is a monitor-only snapshot because the source export did not authorize safe create/update capability.', $api, $entity),
      ];
      return;
    }
    if ($this->runtimeIdentityConfidence($definition, $identityField, $identity) === 'AMBIGUOUS') {
      $summary['config']['skip']++;
      $summary['compatibility'][] = [
        'file' => $filename,
        'message' => sprintf('%s %s target conflict: portable source identity %s=%s matches ambiguously on this site.', $api, $entity, $identityField, $identity),
      ];
      $summary['errors'][] = [
        'file' => $filename,
        'message' => sprintf('%s %s portable source identity %s=%s is ambiguous on the target. Resolve duplicate target records before import.', $api, $entity, $identityField, $identity),
      ];
      return;
    }

    if ($this->importWritesEnabled) {
      $this->applyBundledConfigItem($filename, $definition, $row, $identityField, $identity, $dryRun, $summary);
    }
  }

  /**
   * Record whether source YAML can authorize provider delete-missing.
   *
   * Destructive cleanup requires explicit portable identity confidence and
   * delete capability from the source export. Legacy/incomplete metadata fails
   * closed and remains export/compare-only until it is re-exported.
   */
  private function recordSourceProviderDeleteSafety(array &$providerDeleteSafe, array $definitions, string $extensionKey, array $configEntry): void {
    $resolved = $this->resolveConfigDefinition(
      $definitions,
      $extensionKey,
      (string) ($configEntry['api'] ?? ''),
      (string) ($configEntry['entity'] ?? '')
    );
    if ($resolved === NULL) {
      return;
    }
    $configItem = (array) ($configEntry['item'] ?? []);
    $identityConfidence = strtoupper(trim((string) ($configItem['identity_confidence'] ?? ($configEntry['identity_confidence'] ?? ''))));
    $capabilities = (array) ($configItem['capabilities'] ?? ($configEntry['capabilities'] ?? []));
    $safe = $identityConfidence !== ''
      && $identityConfidence !== 'AMBIGUOUS'
      && !empty($capabilities['delete']);
    $this->mergeProviderDeleteSafety($providerDeleteSafe, (string) $resolved['key'], $safe);
  }

  private function mergeProviderDeleteSafety(array &$providerDeleteSafe, string $definitionKey, bool $safe): void {
    if (!array_key_exists($definitionKey, $providerDeleteSafe)) {
      $providerDeleteSafe[$definitionKey] = $safe;
      return;
    }
    // Alpha63 applies delete safety per identity. One monitor-only source row no
    // longer disables safe cleanup for every other unique row in the provider.
    // Monitor-only desired identities are retained in desiredConfigKeys above.
    $providerDeleteSafe[$definitionKey] = !empty($providerDeleteSafe[$definitionKey]) || $safe;
  }

  private function providerIdentitySafetyForDelete(array $rows, array $definition): string {
    if ($rows) {
      return $this->identitySafetyForRows($rows, $definition);
    }
    $matchFields = array_values(array_filter(array_map('strval', (array) ($definition['match_fields'] ?? [])), static function($field) {
      return $field !== '' && strtolower($field) !== 'id';
    }));
    // An explicitly declared provider match key can safely represent an empty
    // authoritative collection. Without one, an empty export cannot prove how
    // target rows would be matched and therefore cannot authorize deletion.
    return $matchFields ? 'SAFE' : 'UNVERIFIED';
  }

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
      $extensionKey = (string) ($definition['extension'] ?? '');
      $api = (string) ($definition['api'] ?? '');
      $entity = (string) ($definition['entity'] ?? '');

      try {
        if ($this->isGenericConfigSkippedExtension($extensionKey) || $this->isNonImportableDefinition($definition)) {
          continue;
        }
        if (!$this->definitionMatchesRuntimeFilter($definition)) {
          continue;
        }

        $providerRows = $this->identityRowsForDefinition($definition);
        $identityCounts = $this->identityMultiplicityForRows($providerRows, $definition);
        $usedNames = [];
        foreach ($providerRows as $row) {
          $identityField = $this->identityField($row, $definition);
          if ($identityField === NULL) {
            continue;
          }
          $identity = (string) $row[$identityField];
          $identityConfidence = $this->identityConfidence($identityField, $definition);
          if ($identityConfidence !== 'AMBIGUOUS' && (($identityCounts[$identityField][$identity] ?? 0) !== 1)) {
            $identityConfidence = 'AMBIGUOUS';
          }
          $safeExtension = $this->safeName($extensionKey);
          $filename = $safeExtension . '/' . $this->safeName($api) . '/' . $this->safeName($entity) . '/' . $this->uniqueConfigFileName($identity, $usedNames) . '.yml';
          $dependencies = $this->dependenciesForEntityRow($row, $definition);
          $files[] = [
            'filename' => $filename,
            'data' => [
              'schema_version' => 1,
              'type' => 'extension_config.item',
              'extension' => $extensionKey,
              'api' => $api,
              'entity' => $entity,
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

        // Keep a zero-count provider index only after the provider was read
        // successfully. A failed provider must never look like an authoritative
        // empty desired set because that could authorize destructive cleanup on
        // import.
        $identitySafety = $this->providerIdentitySafetyForDelete($providerRows, $definition);
        $index[$extensionKey][] = [
          'api' => $api,
          'entity' => $entity,
          'directory' => $this->safeName($extensionKey) . '/' . $this->safeName($api) . '/' . $this->safeName($entity),
          'count' => count($usedNames),
          'identity_safety' => $identitySafety,
          'delete_safe' => $identitySafety === 'SAFE' && !empty($definition['can_delete']),
        ];
      }
      catch (\Throwable $e) {
        $provider = trim($extensionKey . ' ' . $api . ' ' . $entity);
        $this->addExportError('Extension configuration provider ' . ($provider !== '' ? $provider : '(unknown)') . ' could not be exported: ' . $e->getMessage());
      }

      finally {
        // Export only needs one provider collection at a time. Release the
        // identity cache before moving to the next extension provider so a
        // large site does not retain every provider row for the whole request.
        $this->invalidateIdentityRowsForDefinition($definition);
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
    $this->discoveredEntityDefinitions = $this->discoverEntityDefinitionsInternal(FALSE);
    return $this->discoveredEntityDefinitions;
  }

  /**
   * Broader diagnostic scan for inventory. Providers owned by a dedicated
   * handler or excluded namespace stay visible but cannot gain management
   * authority through this path.
   */
  private function discoverInventoryEntityDefinitions(): array {
    if ($this->inventoryEntityDefinitions !== NULL) {
      return $this->inventoryEntityDefinitions;
    }
    $this->inventoryEntityDefinitions = $this->discoverEntityDefinitionsInternal(TRUE);
    return $this->inventoryEntityDefinitions;
  }

  private function discoverEntityDefinitionsInternal(bool $includeSkippedExtensions): array {
    $definitions = [];
    foreach ($this->extensionBasePaths() as $extensionKey => $basePath) {
      $skipped = $this->isGenericConfigSkippedExtension($extensionKey);
      if ($skipped && !$includeSkippedExtensions) {
        continue;
      }
      $api4EntityNames = [];
      foreach ($this->discoverApi4Entities($extensionKey, $basePath) as $definition) {
        if ($skipped) {
          $definition['generic_config_admitted'] = FALSE;
          $definition['admission_reason_code'] = 'dedicated_or_excluded_extension';
          $definition['admission_reason'] = 'The extension is owned by a dedicated handler or excluded namespace; automatic API discovery is inventory-only for this provider.';
        }
        $api4EntityNames[strtolower((string) $definition['entity'])] = TRUE;
        $definitions[$this->definitionKey($definition['extension'], $definition['api'], $definition['entity'])] = $definition;
      }
      foreach ($this->discoverApi3Entities($extensionKey, $basePath) as $definition) {
        if (isset($api4EntityNames[strtolower((string) $definition['entity'])])) {
          continue;
        }
        if ($skipped) {
          $definition['generic_config_admitted'] = FALSE;
          $definition['admission_reason_code'] = 'dedicated_or_excluded_extension';
          $definition['admission_reason'] = 'The extension is owned by a dedicated handler or excluded namespace; automatic API discovery is inventory-only for this provider.';
        }
        $definitions[$this->definitionKey($definition['extension'], $definition['api'], $definition['entity'])] = $definition;
      }
    }
    uasort($definitions, function($a, $b) {
      return strcmp($a['extension'] . ':' . $a['api'] . ':' . $a['entity'], $b['extension'] . ':' . $b['api'] . ':' . $b['entity']);
    });
    return array_values($definitions);
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
      $statuses = (array) $manager->getStatuses();
    }
    catch (\Throwable $e) {
      return [];
    }

    $extensionsDir = '';
    try {
      $extensionsDir = rtrim((string) (\CRM_Core_Config::singleton()->extensionsDir ?? ''), DIRECTORY_SEPARATOR);
    }
    catch (\Throwable $e) {
      // The mapper remains the authoritative source when config is unavailable.
    }

    foreach ($statuses as $key => $status) {
      $key = (string) $key;
      $status = strtolower((string) $status);
      if (!in_array($status, ['installed', 'enabled'], TRUE)) {
        continue;
      }

      $base = '';
      try {
        if ($mapper && method_exists($mapper, 'keyToBasePath')) {
          $base = (string) $mapper->keyToBasePath($key);
        }
        elseif ($mapper && method_exists($mapper, 'getBasePath')) {
          $base = (string) $mapper->getBasePath($key);
        }
      }
      catch (\Throwable $e) {
        // One stale/broken mapper entry must not abort discovery of every
        // other installed contributed extension.
      }

      // Freshly copied/enabled extensions can be visible to the manager before
      // every mapper cache is refreshed in an isolated CLI request. Use the
      // configured extensions directory only as a conservative filesystem
      // fallback for the exact installed key.
      if (($base === '' || !is_dir($base)) && $extensionsDir !== '') {
        $candidate = $extensionsDir . DIRECTORY_SEPARATOR . $key;
        if (is_dir($candidate) && is_file($candidate . DIRECTORY_SEPARATOR . 'info.xml')) {
          $base = $candidate;
        }
      }

      if ($base !== '' && is_dir($base)) {
        $paths[$key] = rtrim($base, DIRECTORY_SEPARATOR);
      }
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
      if (!$this->loadApi4ClassFromProvider($entity, (string) $file)) {
        continue;
      }

      // Generic discovery must never execute provider collection actions.
      // A get() probe can read business data, emit warnings/deprecations, depend
      // on user-specific context, or have extension-specific side effects. Use
      // class/metadata capability only here; the actual export read is allowed
      // only after the provider has passed the configuration-admission gate.
      $isSqltasksSqlTask = strtolower($extensionKey) === 'de.systopia.sqltasks'
        && strtolower($entity) === 'sqltask';
      if (!$this->api4EntityDeclarativelyReadable($entity)) {
        continue;
      }
      $info = $this->api4Info($entity);
      $fields = $this->api4Fields($entity);
      $matchFields = array_values(array_filter((array) ($info['match_fields'] ?? []), static function($field) {
        $field = trim((string) $field);
        return $field !== '' && strtolower($field) !== 'id';
      }));
      $reviewedProvider = $isSqltasksSqlTask;
      $admission = $this->genericApi4ConfigAdmission($matchFields, $fields, $reviewedProvider);
      $definitions[] = [
        'extension' => $extensionKey,
        'api' => 'api4',
        'entity' => $entity,
        'class' => $class,
        'fields' => $fields,
        'match_fields' => $matchFields,
        'reviewed_provider' => $reviewedProvider,
        'generic_config_admitted' => !empty($admission['admitted']),
        'admission_reason_code' => (string) ($admission['reason_code'] ?? 'provider_not_admitted'),
        'admission_reason' => (string) ($admission['reason'] ?? ''),
        'can_create' => is_callable([$class, 'create']),
        'can_update' => is_callable([$class, 'update']),
        'can_delete' => is_callable([$class, 'delete']),
      ];
    }
    return $definitions;
  }

  /**
   * Load an API4 provider class from the exact discovered extension file.
   *
   * CiviCRM normally registers extension classloaders before discovery, but
   * CLI/early-bootstrap requests can see extension files before the provider's
   * PSR-4 loader is active. Loading the exact Civi/Api4 file avoids false
   * API3 fallback without scanning or executing unrelated provider code.
   */
  private function loadApi4ClassFromProvider(string $entity, string $file): bool {
    $class = 'Civi\\Api4\\' . $entity;
    if (class_exists($class)) {
      return TRUE;
    }
    if ($entity === '' || !is_file($file)) {
      return FALSE;
    }

    $probe = $this->quietDiscoveryProbe(static function() use ($file) {
      require_once $file;
      return TRUE;
    });
    if (empty($probe['ok'])) {
      return FALSE;
    }
    return class_exists($class, FALSE) || class_exists($class);
  }

  private function discoverApi3Entities(string $extensionKey, string $basePath): array {
    $dir = $basePath . '/api/v3';
    if (!is_dir($dir)) {
      return [];
    }

    // Generic API3 introspection is intentionally file-only. Calling get,
    // getactions, getfields, or custom get-all actions while merely discovering
    // candidates executes arbitrary contributed-extension code. Real projects
    // have shown that this can emit warnings/deprecations and can expose
    // participant/payment/business APIs which are not deployable configuration.
    // Reviewed adapters are resolved first; all other API3 entities remain
    // diagnostic-only candidates until an extension opts in through an explicit
    // handler/adapter contract.
    $reviewed = $this->reviewedApi3ProviderDefinitions($extensionKey, $basePath);
    if ($reviewed !== NULL) {
      return $reviewed;
    }

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
      // Keep top-level API3 files only. Discovery itself stays read-only.
    }

    $entities = [];
    $fileActions = [];
    foreach ($files as $file) {
      $entity = $this->api3EntityNameFromFile($dir, (string) $file);
      if ($entity === '' || in_array(strtolower($entity), ['utils', 'index'], TRUE)) {
        continue;
      }
      $entities[$entity] = TRUE;
      $fileAction = $this->api3ActionNameFromFile($dir, (string) $file);
      if ($fileAction !== '') {
        $fileActions[$entity][strtolower($fileAction)] = TRUE;
      }
    }

    $definitions = [];
    foreach (array_keys($entities) as $entity) {
      $knownActions = array_values(array_keys($fileActions[$entity] ?? []));
      sort($knownActions, SORT_NATURAL | SORT_FLAG_CASE);
      $definitions[] = [
        'extension' => $extensionKey,
        'api' => 'api3',
        'entity' => $entity,
        'fields' => [],
        'list_action' => '',
        'read_adapter' => '',
        'write_fields' => [],
        'base_path' => $basePath,
        'discovered_actions' => $knownActions,
        'reviewed_provider' => FALSE,
        'generic_config_admitted' => FALSE,
        'admission_reason_code' => 'api3_requires_explicit_adapter',
        'admission_reason' => 'Generic API3 entity discovered from provider files only. API3 CRUD/read capability is not proof of deployable configuration, so Configuration Manager will not execute or manage it without a reviewed adapter or explicit custom handler.',
        'can_create' => FALSE,
        'can_update' => FALSE,
        'delete_action' => NULL,
        'can_delete' => FALSE,
      ];
    }
    return $definitions;
  }

  /**
   * Return declarative definitions for narrowly reviewed API3 providers whose
   * runtime metadata cannot safely describe their collection/read capability.
   *
   * Returning NULL means "use generic discovery". Returning an array (including
   * an empty array) means the extension has a reviewed discovery policy and
   * generic probing must not run for it.
   */
  private function reviewedApi3ProviderDefinitions(string $extensionKey, string $basePath): ?array {
    if (strtolower($extensionKey) !== 'de.systopia.sqltasks') {
      return NULL;
    }

    $entity = 'Sqltask';
    $nativeApi4File = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'Civi' . DIRECTORY_SEPARATOR . 'Api4' . DIRECTORY_SEPARATOR . 'SqlTask.php';
    if ($this->loadApi4ClassFromProvider('SqlTask', $nativeApi4File)) {
      return [];
    }

    $apiDir = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'v3' . DIRECTORY_SEPARATOR . $entity;
    $createFile = $apiDir . DIRECTORY_SEPARATOR . 'Create.php';
    if (!is_file($createFile)) {
      return [];
    }

    $adapter = $this->api3ReadAdapterDefinition($entity);
    if ($adapter === NULL || empty($adapter['name'])) {
      return [];
    }

    // SQLTasks 2.2.x has no native API4 SqlTask class and no
    // CRM_Sqltasks_BAO_SqlTask adapter. It does provide a reviewed API3
    // collection action, Sqltask.getalltasks, plus Sqltask.get for row
    // hydration. Prefer that provider-owned public API when available.
    $listAction = is_file($apiDir . DIRECTORY_SEPARATOR . 'Getalltasks.php') ? 'getalltasks' : '';
    $readAdapter = $listAction === '' ? (string) $adapter['name'] : '';

    $deleteAction = is_file($apiDir . DIRECTORY_SEPARATOR . 'Deletetask.php') ? 'deletetask' : NULL;
    return [[
      'extension' => $extensionKey,
      'api' => 'api3',
      'entity' => $entity,
      'fields' => [],
      'match_fields' => ['name'],
      'list_action' => $listAction,
      'read_adapter' => $readAdapter,
      'write_fields' => array_values((array) ($adapter['write_fields'] ?? [])),
      'base_path' => $basePath,
      'reviewed_provider' => TRUE,
      'generic_config_admitted' => TRUE,
      'admission_reason_code' => 'reviewed_adapter',
      'admission_reason' => 'Reviewed provider adapter supplies a bounded collection read and explicit writable configuration fields.',
      'can_create' => TRUE,
      'can_update' => TRUE,
      'delete_action' => $deleteAction,
      'can_delete' => $deleteAction !== NULL,
    ]];
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

  /**
   * Resolve an API3 action name from an Entity/Action.php provider layout.
   *
   * File-backed action discovery is intentionally preferred when available.
   * Some contributed APIs expose valid action files but their runtime
   * getactions introspection is incomplete or emits warnings. SQLTasks 3.x is
   * one such provider. Top-level Entity.php files return no action here and
   * continue through the normal runtime capability checks.
   */
  private function api3ActionNameFromFile(string $apiDir, string $file): string {
    $apiDir = rtrim(str_replace('\\', '/', $apiDir), '/');
    $file = str_replace('\\', '/', $file);
    if (strpos($file, $apiDir . '/') !== 0) {
      return '';
    }
    $relative = substr($file, strlen($apiDir) + 1);
    $parts = array_values(array_filter(explode('/', $relative), 'strlen'));
    if (count($parts) < 2) {
      return '';
    }
    return basename((string) end($parts), '.php');
  }

  private function api4EntityDeclarativelyReadable(string $entity): bool {
    $class = 'Civi\\Api4\\' . $entity;
    if (!class_exists($class) || !method_exists($class, 'get')) {
      return FALSE;
    }

    try {
      // Do not execute get(). Reflection is enough to reject provider actions
      // which require extra context that ConfigManager's generic reader cannot
      // supply. Normal API4 get($checkPermissions = TRUE) has no required args.
      $method = new \ReflectionMethod($class, 'get');
      return $method->getNumberOfRequiredParameters() === 0;
    }
    catch (\Throwable $e) {
      return FALSE;
    }
  }

  private function api3EntityUsable(string $entity): bool {
    return $this->api3ReadAdapter($entity) !== NULL || $this->api3ListAction($entity) !== NULL;
  }

  /**
   * Resolve a narrowly-scoped read-only adapter for API3 providers that do not
   * expose a collection action.
   *
   * SQLTasks 3.x exposes Sqltask.get for one required numeric ID, while its BAO
   * provides the provider-owned generator/exportData pair used to enumerate
   * portable task configuration. Keep this fallback explicit and read-only;
   * create/update/delete continue through the provider's API3 actions.
   */
  private function api3ReadAdapter(string $entity, string $basePath = ''): ?string {
    $adapter = $this->api3ReadAdapterDefinition($entity);
    if ($adapter === NULL) {
      return NULL;
    }

    $class = (string) ($adapter['class'] ?? '');
    $collectionMethod = (string) ($adapter['collection_method'] ?? '');
    $rowMethod = (string) ($adapter['row_method'] ?? '');
    if ($class === '' || $collectionMethod === '' || $rowMethod === '') {
      return NULL;
    }
    if (!$this->loadApi3ReadAdapterClass($adapter, $basePath)) {
      return NULL;
    }
    if (!method_exists($class, $collectionMethod) || !method_exists($class, $rowMethod)) {
      return NULL;
    }

    return (string) ($adapter['name'] ?? '');
  }

  /**
   * Make one reviewed provider adapter available from its extension base path.
   *
   * CiviCRM normally registers extension classloaders before this code runs,
   * but isolated CLI/bootstrap contexts can discover the provider files before
   * the provider's legacy CRM_* classes have been autoloaded. Loading only the
   * reviewed DAO/BAO files keeps this deterministic without executing arbitrary
   * provider code or weakening generic discovery safety.
   */
  private function loadApi3ReadAdapterClass(array $adapter, string $basePath): bool {
    $class = (string) ($adapter['class'] ?? '');
    if ($class === '') {
      return FALSE;
    }
    if (class_exists($class)) {
      return TRUE;
    }
    if ($basePath === '') {
      return FALSE;
    }

    foreach ((array) ($adapter['load_files'] ?? []) as $relativeFile) {
      $relativeFile = ltrim(str_replace('\\', '/', (string) $relativeFile), '/');
      if ($relativeFile === '') {
        continue;
      }
      $path = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeFile);
      if (!is_file($path)) {
        return FALSE;
      }
      require_once $path;
    }

    return class_exists($class, FALSE) || class_exists($class);
  }

  /**
   * Describe only reviewed provider-specific collection adapters.
   */
  private function api3ReadAdapterDefinition(string $entity): ?array {
    $adapters = [
      'sqltask' => [
        'name' => 'sqltasks_bao_generator',
        'class' => 'CRM_Sqltasks_BAO_SqlTask',
        'collection_method' => 'generator',
        'row_method' => 'exportData',
        'load_files' => [
          'CRM/Sqltasks/DAO/SqlTask.php',
          'CRM/Sqltasks/BAO/SqlTask.php',
        ],
        'write_fields' => [
          'name',
          'description',
          'run_permissions',
          'category',
          'weight',
          'scheduled',
          'parallel_exec',
          'input_required',
          'enabled',
          'config',
          'abort_on_error',
        ],
      ],
    ];
    return $adapters[strtolower($entity)] ?? NULL;
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
        // Keep discovery probes bounded. API3's global options are safe for
        // standard get and are also understood by well-behaved contributed
        // collection actions; actions which ignore them are still executed only
        // as a probe here and never looped.
        $params = [
          'sequential' => 1,
          'options' => ['limit' => 1, 'offset' => 0],
        ];
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

  private function api3EntityHasAction(string $entity, string $action, array $knownActions = []): bool {
    $action = strtolower($action);
    $knownActions = array_map('strtolower', array_map('strval', $knownActions));
    if (in_array($action, $knownActions, TRUE)) {
      return TRUE;
    }
    if (in_array($action, $this->api3EntityActions($entity), TRUE)) {
      return TRUE;
    }
    $function = 'civicrm_api3_' . strtolower($entity) . '_' . $action;
    return function_exists($function);
  }

  /**
   * Resolve the provider action used to delete one API3 record.
   *
   * Standard API3 entities use `delete`. SQLTasks exposes the same single-row
   * behavior as `deletetask`, so keep that provider alias explicit rather than
   * guessing at arbitrary destructive action names.
   */
  private function api3DeleteAction(string $entity, array $knownActions = []): ?string {
    $cacheKey = strtolower($entity);
    if (array_key_exists($cacheKey, self::$api3DeleteActionByEntity)) {
      return self::$api3DeleteActionByEntity[$cacheKey];
    }

    if ($this->api3EntityHasAction($entity, 'delete', $knownActions)) {
      self::$api3DeleteActionByEntity[$cacheKey] = 'delete';
      return 'delete';
    }

    $aliases = [
      'sqltask' => 'deletetask',
    ];
    $alias = $aliases[$cacheKey] ?? NULL;
    if ($alias !== NULL && $this->api3EntityHasAction($entity, $alias, $knownActions)) {
      self::$api3DeleteActionByEntity[$cacheKey] = $alias;
      return $alias;
    }

    self::$api3DeleteActionByEntity[$cacheKey] = NULL;
    return NULL;
  }


  private function api4Info(string $entity): array {
    $class = 'Civi\\Api4\\' . $entity;
    if (!class_exists($class) || !method_exists($class, 'getInfo')) {
      return [];
    }
    $probe = $this->quietDiscoveryProbe(static function() use ($class) {
      return $class::getInfo();
    });
    if (empty($probe['ok']) || !empty($probe['warnings']) || trim((string) ($probe['output'] ?? '')) !== '') {
      return [];
    }
    $info = $probe['value'] ?? NULL;
    return is_array($info) ? $info : [];
  }

  private function api4Fields(string $entity): array {
    $class = 'Civi\\Api4\\' . $entity;
    if (!class_exists($class) || !method_exists($class, 'getFields')) {
      return [];
    }
    $probe = $this->quietDiscoveryProbe(static function() use ($class) {
      return (array) $class::getFields(FALSE)->execute();
    });
    if (empty($probe['ok']) || !empty($probe['warnings']) || trim((string) ($probe['output'] ?? '')) !== '') {
      return [];
    }
    $fields = [];
    foreach ((array) ($probe['value'] ?? []) as $row) {
      $row = (array) $row;
      if (!empty($row['name'])) {
        $fields[(string) $row['name']] = $row;
      }
    }
    return $fields;
  }

  /**
   * Run discovery metadata without leaking provider warnings/output into CLI,
   * JSON, AJAX, or queue responses. This helper is discovery-only; actual
   * export/import reads are never muted and still fail atomically on errors.
   *
   * @return array{ok:bool,value:mixed,warnings:string[],output:string,error:string}
   */
  private function quietDiscoveryProbe(callable $probe): array {
    $warnings = [];
    $startLevel = ob_get_level();
    ob_start();
    set_error_handler(static function($severity, $message, $file = '', $line = 0) use (&$warnings): bool {
      $warnings[] = trim((string) $message) . ($file !== '' ? ' at ' . (string) $file . ':' . (int) $line : '');
      return TRUE;
    });

    try {
      $value = $probe();
      $output = '';
      while (ob_get_level() > $startLevel) {
        $chunk = ob_get_clean();
        if ($chunk !== FALSE) {
          $output = (string) $chunk . $output;
        }
      }
      restore_error_handler();
      return [
        'ok' => TRUE,
        'value' => $value,
        'warnings' => array_values(array_unique($warnings)),
        'output' => trim($output),
        'error' => '',
      ];
    }
    catch (\Throwable $e) {
      $output = '';
      while (ob_get_level() > $startLevel) {
        $chunk = ob_get_clean();
        if ($chunk !== FALSE) {
          $output = (string) $chunk . $output;
        }
      }
      restore_error_handler();
      return [
        'ok' => FALSE,
        'value' => NULL,
        'warnings' => array_values(array_unique($warnings)),
        'output' => trim($output),
        'error' => $e->getMessage(),
      ];
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
        // Use AbstractHandler's bounded API4 reader. Large contributed
        // providers must not ask API4 to materialize the complete collection
        // in one request.
        return $this->api4Get($entity, [], ['*']);
      }
      catch (\Throwable $e) {
        throw new \RuntimeException('Could not read contributed configuration provider API4 ' . $entity . ': ' . $e->getMessage(), 0, $e);
      }
    }

    $readAdapter = trim((string) ($definition['read_adapter'] ?? ''));
    if ($readAdapter !== '') {
      return $this->fetchApi3ReadAdapterRows($definition, $readAdapter);
    }

    try {
      $action = (string) ($definition['list_action'] ?? 'get');
      if ($action === 'get') {
        $rows = $this->fetchApi3GetRowsPaged($entity);
      }
      else {
        // Contributed get-all actions are not guaranteed to implement offset
        // semantics. Ask for a bounded page once; if the provider honors API3
        // options we page it, otherwise preserve the returned collection and
        // never risk an infinite pagination loop.
        $rows = $this->fetchApi3CollectionRowsConservatively($entity, $action);
      }

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
            $detail = civicrm_api3($entity, 'get', [
              'sequential' => 1,
              'id' => $row['id'],
              'options' => ['limit' => 1],
            ]);
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
   * Read a normal API3 get collection in bounded pages.
   */
  private function fetchApi3GetRowsPaged(string $entity): array {
    $rows = [];
    $offset = 0;
    $batchSize = 200;
    while (TRUE) {
      $result = civicrm_api3($entity, 'get', [
        'sequential' => 1,
        'options' => ['limit' => $batchSize, 'offset' => $offset],
      ]);
      $page = $this->normalizeApi3Rows((array) $result);
      foreach ($page as $row) {
        $rows[] = $row;
      }
      $count = count($page);
      unset($page, $result);
      if ($count < $batchSize) {
        break;
      }
      $offset += $count;
    }
    return $rows;
  }

  /**
   * Page a contributed read action only when it demonstrably honors offsets.
   *
   * Custom API3 get-all actions vary widely. The first call is bounded through
   * API3 options. If a full page is returned, a second page is requested. A
   * repeated page means the provider ignored offset; in that case fail closed
   * rather than silently truncating or looping forever.
   */
  private function fetchApi3CollectionRowsConservatively(string $entity, string $action): array {
    $batchSize = 200;
    $result = civicrm_api3($entity, $action, [
      'sequential' => 1,
      'options' => ['limit' => $batchSize, 'offset' => 0],
    ]);
    $rows = $this->normalizeApi3Rows((array) $result);
    unset($result);
    if (count($rows) < $batchSize) {
      return array_values($rows);
    }

    $offset = count($rows);
    $seenPages = [$this->api3PageFingerprint($rows) => TRUE];
    while (TRUE) {
      $result = civicrm_api3($entity, $action, [
        'sequential' => 1,
        'options' => ['limit' => $batchSize, 'offset' => $offset],
      ]);
      $page = $this->normalizeApi3Rows((array) $result);
      unset($result);
      if (!$page) {
        break;
      }
      $fingerprint = $this->api3PageFingerprint($page);
      if (isset($seenPages[$fingerprint])) {
        throw new \RuntimeException('Provider collection action does not honor bounded offset pagination; refusing an incomplete or unbounded export.');
      }
      $seenPages[$fingerprint] = TRUE;
      foreach ($page as $row) {
        $rows[] = $row;
      }
      $count = count($page);
      unset($page);
      if ($count < $batchSize) {
        break;
      }
      $offset += $count;
    }
    return array_values($rows);
  }

  private function api3PageFingerprint(array $rows): string {
    if (!$rows) {
      return '';
    }
    $sample = [];
    foreach (array_slice($rows, 0, 3) as $row) {
      $row = (array) $row;
      foreach (['id', 'key', 'name', 'machine_name', 'title'] as $field) {
        if (array_key_exists($field, $row) && is_scalar($row[$field])) {
          $sample[] = $field . '=' . (string) $row[$field];
          continue 2;
        }
      }
      $sample[] = sha1(serialize($row));
    }
    return sha1(implode('|', $sample));
  }

  /**
   * Read rows through one reviewed provider-owned adapter.
   */
  private function fetchApi3ReadAdapterRows(array $definition, string $adapterName): array {
    $entity = (string) ($definition['entity'] ?? '');
    $adapter = $this->api3ReadAdapterDefinition($entity);
    if ($adapter === NULL || (string) ($adapter['name'] ?? '') !== $adapterName) {
      throw new \RuntimeException('Unknown API3 contributed-provider read adapter: ' . $adapterName . '.');
    }

    $class = (string) ($adapter['class'] ?? '');
    $collectionMethod = (string) ($adapter['collection_method'] ?? '');
    $rowMethod = (string) ($adapter['row_method'] ?? '');
    $basePath = (string) ($definition['base_path'] ?? '');
    if ($class === '' || !$this->loadApi3ReadAdapterClass($adapter, $basePath) || !method_exists($class, $collectionMethod) || !method_exists($class, $rowMethod)) {
      // SQLTasks 3.x ships native API4 SqlTask as well as its older API3/BAO
      // surface. An old YAML export can still reference API3 Sqltask, while a
      // target site's classloader may not expose the BAO in this request. Use
      // the native API4 collection as the safe read fallback instead of
      // blocking preview/import solely because the legacy adapter is absent.
      if (strtolower($entity) === 'sqltask') {
        $nativeApi4File = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'Civi' . DIRECTORY_SEPARATOR . 'Api4' . DIRECTORY_SEPARATOR . 'SqlTask.php';
        if ($this->loadApi4ClassFromProvider('SqlTask', $nativeApi4File)) {
          try {
            return $this->api4Get('SqlTask', [], ['*']);
          }
          catch (\Throwable $e) {
            throw new \RuntimeException('Could not read SQLTasks through native API4 fallback: ' . $e->getMessage(), 0, $e);
          }
        }

        // SQLTasks 2.2.x exposes Sqltask.getalltasks as its supported
        // collection API. Old YAML/provider metadata may still request the
        // later BAO adapter, so transparently fall back to the public API3
        // collection instead of blocking export/diff/import.
        $getAllFile = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'v3' . DIRECTORY_SEPARATOR . 'Sqltask' . DIRECTORY_SEPARATOR . 'Getalltasks.php';
        if (is_file($getAllFile)) {
          $fallbackDefinition = $definition;
          $fallbackDefinition['read_adapter'] = '';
          $fallbackDefinition['list_action'] = 'getalltasks';
          return $this->fetchEntityRows($fallbackDefinition);
        }
      }
      throw new \RuntimeException('API3 contributed-provider read adapter is unavailable for ' . $entity . '.');
    }

    try {
      $generatorMethod = new \ReflectionMethod($class, $collectionMethod);
      $rowExportMethod = new \ReflectionMethod($class, $rowMethod);
      $items = $generatorMethod->invoke(NULL, []);
      if (!is_iterable($items)) {
        throw new \RuntimeException('Provider collection adapter did not return an iterable result.');
      }

      $rows = [];
      foreach ($items as $item) {
        if (!is_object($item)) {
          throw new \RuntimeException('Provider collection adapter returned a non-object row.');
        }
        $row = $rowExportMethod->invoke($item);
        if (!is_array($row)) {
          throw new \RuntimeException('Provider row adapter did not return an array.');
        }
        $rows[] = $row;
      }
      return $rows;
    }
    catch (\Throwable $e) {
      throw new \RuntimeException('Could not read contributed configuration provider API3 ' . $entity . ' through ' . $adapterName . ': ' . $e->getMessage(), 0, $e);
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
    elseif ($definition['api'] === 'api3') {
      $row = $this->stripApi3NonWritableFields($row, $definition);
    }
    ksort($row, SORT_NATURAL | SORT_FLAG_CASE);
    return $row;
  }

  private function cleanEntityRowForImport(array $row, array $definition): array {
    $row = $this->stripRuntime($row);
    if ($definition['api'] === 'api4') {
      $row = $this->stripReadOnlyFields($row, (array) ($definition['fields'] ?? []));
    }
    elseif ($definition['api'] === 'api3') {
      $row = $this->stripApi3NonWritableFields($row, $definition);
    }
    return $row;
  }

  /**
   * Keep only fields accepted by an API3 provider's create action when the
   * provider exposes a usable getfields specification.
   *
   * Collection/read APIs often return calculated runtime fields which cannot
   * be passed back to create/update. SQLTasks is a concrete example: its read
   * result contains fields such as last_executed, last_runtime, next_execution,
   * schedule_label, short_desc, and archive state, while Sqltask.create accepts
   * only the task's writable configuration fields. Nested provider-owned data
   * such as config.actions is deliberately preserved unchanged.
   */
  private function stripApi3NonWritableFields(array $row, array $definition): array {
    $fields = $this->api3WritableFields($definition);
    if (!$fields) {
      // Fail open for portability when a third-party API does not expose a
      // trustworthy create spec. Existing identity/write-safety checks still
      // decide whether the provider can be managed automatically.
      return $row;
    }
    return array_intersect_key($row, array_fill_keys($fields, TRUE));
  }

  private function api3WritableFields(array $definition): array {
    if (!empty($definition['write_fields'])) {
      return $this->normaliseApi3WritableFields((array) $definition['write_fields']);
    }

    $entity = (string) ($definition['entity'] ?? '');
    if ($entity === '' || !function_exists('civicrm_api3')) {
      return [];
    }
    $cacheKey = strtolower($entity);
    if (array_key_exists($cacheKey, self::$api3WritableFieldsByEntity)) {
      return self::$api3WritableFieldsByEntity[$cacheKey];
    }

    try {
      $result = civicrm_api3($entity, 'getfields', [
        'sequential' => 1,
        'action' => 'create',
      ]);
      self::$api3WritableFieldsByEntity[$cacheKey] = $this->normaliseApi3WritableFields((array) ($result['values'] ?? []));
    }
    catch (\Throwable $e) {
      self::$api3WritableFieldsByEntity[$cacheKey] = [];
    }

    return self::$api3WritableFieldsByEntity[$cacheKey];
  }

  /**
   * @return string[]
   */
  private function normaliseApi3WritableFields(array $fields): array {
    $names = [];
    foreach ($fields as $key => $field) {
      if (is_string($field)) {
        $name = $field;
      }
      else {
        $field = (array) $field;
        $name = (string) ($field['name'] ?? (is_string($key) ? $key : ''));
      }
      $name = trim($name);
      if ($name === '' || $name === 'id') {
        continue;
      }
      $names[$name] = TRUE;
    }
    $names = array_keys($names);
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    return $names;
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

    // Source/export identity validation requires exactly one matching row, but
    // target import is different: zero matches is the normal CREATE case. A
    // strong semantic identity is unsafe only when the target already contains
    // more than one matching record and Configuration Manager cannot choose.
    return $this->providerIdentityHasDuplicateConflict($definition, $field, $identity) ? 'AMBIGUOUS' : $confidence;
  }

  /**
   * Check one target identity without caching the provider's complete rows.
   */
  private function providerIdentityHasDuplicateConflict(array $definition, string $field, string $identity): bool {
    $definitionKey = $this->definitionKey(
      (string) ($definition['extension'] ?? ''),
      (string) ($definition['api'] ?? ''),
      (string) ($definition['entity'] ?? '')
    );
    if (array_key_exists($definitionKey, $this->identityRowsByDefinition)) {
      return $this->identityValueHasDuplicateConflict(
        (array) $this->identityRowsByDefinition[$definitionKey],
        $field,
        $identity
      );
    }

    $matches = 0;
    if (($definition['api'] ?? '') === 'api4') {
      foreach ($this->api4Iterate((string) $definition['entity'], [[$field, '=', $identity]], [$field, 'id']) as $_row) {
        $matches++;
        if ($matches > 1) {
          return TRUE;
        }
      }
      return FALSE;
    }

    $readAdapter = trim((string) ($definition['read_adapter'] ?? ''));
    $action = (string) ($definition['list_action'] ?? 'get');
    if ($readAdapter === '' && $action === 'get') {
      $result = civicrm_api3((string) $definition['entity'], 'get', [
        'sequential' => 1,
        $field => $identity,
        'options' => ['limit' => 2],
      ]);
      return count($this->normalizeApi3Rows((array) $result)) > 1;
    }

    foreach ($this->iterateCleanProviderRows($definition) as $row) {
      $row = (array) $row;
      if (!array_key_exists($field, $row) || !is_scalar($row[$field]) || (string) $row[$field] !== $identity) {
        continue;
      }
      $matches++;
      if ($matches > 1) {
        return TRUE;
      }
    }
    return FALSE;
  }

  private function identityValueHasDuplicateConflict(array $rows, string $field, string $identity): bool {
    $matches = 0;
    foreach ($rows as $row) {
      $row = (array) $row;
      if (!array_key_exists($field, $row) || !is_scalar($row[$field]) || (string) $row[$field] !== $identity) {
        continue;
      }
      $matches++;
      if ($matches > 1) {
        return TRUE;
      }
    }
    return FALSE;
  }

  private function invalidateIdentityRowsForDefinition(array $definition): void {
    $key = $this->definitionKey(
      (string) ($definition['extension'] ?? ''),
      (string) ($definition['api'] ?? ''),
      (string) ($definition['entity'] ?? '')
    );
    unset($this->identityRowsByDefinition[$key]);
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

    // Keep the cache bounded to the provider currently being inspected. YAML
    // for extension providers is naturally grouped, so this retains the useful
    // no-repeat behavior without pinning every provider collection in memory
    // for the lifetime of a full export/validate/import request.
    $this->identityRowsByDefinition = [];

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

    $counts = $this->identityMultiplicityForRows($rows, $definition);
    foreach ($rows as $row) {
      $row = (array) $row;
      $field = $this->identityField($row, $definition);
      if ($field === NULL || $this->identityConfidence($field, $definition) === 'AMBIGUOUS') {
        return 'UNSAFE';
      }
      $identity = (string) $row[$field];
      if (($counts[$field][$identity] ?? 0) !== 1) {
        return 'UNSAFE';
      }
    }

    return 'SAFE';
  }

  /**
   * Build identity multiplicities in O(n) for provider-wide safety checks.
   *
   * Older code rescanned the complete provider collection for every row,
   * making large providers quadratic during export/delete safety analysis.
   * Keep one compact count map instead.
   *
   * @return array<string,array<string,int>>
   */
  private function identityMultiplicityForRows(array $rows, array $definition): array {
    $counts = [];
    foreach ($rows as $row) {
      $row = (array) $row;
      $field = $this->identityField($row, $definition);
      if ($field === NULL || !array_key_exists($field, $row) || !is_scalar($row[$field])) {
        continue;
      }
      $identity = (string) $row[$field];
      $counts[$field][$identity] = ($counts[$field][$identity] ?? 0) + 1;
    }
    return $counts;
  }

  private function findExistingEntityRow(array $definition, string $identityField, string $identity): ?array {
    if ($definition['api'] === 'api4') {
      return $this->api4GetFirst((string) $definition['entity'], [[$identityField, '=', $identity]], ['*']);
    }
    try {
      $readAdapter = trim((string) ($definition['read_adapter'] ?? ''));
      $action = (string) ($definition['list_action'] ?? 'get');
      if ($readAdapter === '' && $action === 'get') {
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
    return $this->firstApi3ResultRow((array) $result);
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
    return $this->firstApi3ResultRow((array) $result);
  }

  /**
   * Normalize API3 write responses which may return either a row list or one
   * associative row directly under `values`.
   */
  private function firstApi3ResultRow(array $result): array {
    $rows = $this->normalizeApi3Rows($result);
    return !empty($rows[0]) && is_array($rows[0]) ? (array) $rows[0] : [];
  }

  private function deleteEntityRow(array $definition, int $id): void {
    if ($definition['api'] === 'api4') {
      $this->api4Delete((string) $definition['entity'], [['id', '=', $id]]);
      return;
    }
    $action = (string) ($definition['delete_action'] ?? 'delete');
    civicrm_api3((string) $definition['entity'], $action, ['id' => $id]);
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
          'type' => 'formbuilder-afforms',
          'entity' => 'Afform',
          'name' => $name,
          'reason' => 'Extension configuration references this FormBuilder Afform.',
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

  /**
   * Decide whether an automatically discovered API4 entity is safe to treat as
   * deployable configuration. CRUD capability alone is not evidence: many
   * contributed extensions expose contacts, grants, payments, activities, or
   * other business records through API4. Accidentally exporting those records
   * would be both a configuration-integrity and data-exposure bug.
   *
   * Explicit civicfg_entityDefinitions() handlers are outside this generic
   * discovery path. Reviewed adapters may opt in here after fixture review.
   *
   * @param string[] $matchFields
   * @param array<string,array<string,mixed>> $fields
   * @return array{admitted:bool,reason:string,reason_code:string}
   */
  private function genericApi4ConfigAdmission(array $matchFields, array $fields, bool $reviewedProvider = FALSE): array {
    if ($reviewedProvider) {
      return [
        'admitted' => TRUE,
        'reason_code' => 'reviewed_adapter',
        'reason' => 'Reviewed provider adapter.',
      ];
    }

    if (!$matchFields) {
      return [
        'admitted' => FALSE,
        'reason_code' => 'missing_portable_identity',
        'reason' => 'API4 provider does not declare a non-ID match_fields identity. CRUD capability alone does not prove deployable configuration.',
      ];
    }

    foreach ($matchFields as $field) {
      $field = trim((string) $field);
      if ($field === '' || !isset($fields[$field])) {
        return [
          'admitted' => FALSE,
          'reason_code' => 'incomplete_identity_metadata',
          'reason' => 'API4 provider match_fields metadata is incomplete for field ' . ($field !== '' ? $field : '[empty]') . '.',
        ];
      }
      if ($this->isSensitiveSettingName($field)) {
        return [
          'admitted' => FALSE,
          'reason_code' => 'sensitive_identity',
          'reason' => 'API4 provider uses a sensitive-looking field as portable identity. Declare the provider explicitly so sensitive-field handling can be reviewed.',
        ];
      }
    }

    $businessMarkers = [
      'contact_id', 'activity_id', 'case_id', 'contribution_id', 'participant_id',
      'membership_id', 'membership_type_id', 'event_id', 'grant_type_id',
      'financial_type_id', 'payment_processor_id', 'recurring_contribution_id',
      'amount', 'amount_total', 'amount_requested', 'amount_granted', 'currency',
    ];
    foreach ($businessMarkers as $field) {
      if (isset($fields[$field])) {
        return [
          'admitted' => FALSE,
          'reason_code' => 'business_data_marker',
          'reason' => 'API4 provider exposes business/transaction field ' . $field . '. Generic discovery will not treat it as deployable configuration; use an explicit provider definition if this is intentionally configuration.',
        ];
      }
    }

    foreach ($fields as $name => $metadata) {
      $name = (string) $name;
      $metadata = (array) $metadata;
      if (!empty($metadata['readonly']) || !empty($metadata['read_only']) || (($metadata['type'] ?? '') === 'Extra')) {
        continue;
      }
      if ($name !== '' && $this->isSensitiveSettingName($name)) {
        return [
          'admitted' => FALSE,
          'reason_code' => 'sensitive_writable_field',
          'reason' => 'API4 provider exposes sensitive-looking writable field ' . $name . '. Generic discovery cannot safely decide how to redact/restore it; declare the provider explicitly.',
        ];
      }
    }

    return [
      'admitted' => TRUE,
      'reason_code' => 'portable_identity_and_field_policy',
      'reason' => 'API4 provider declares a non-ID portable match_fields identity and no generic business/sensitive-data safety marker was detected.',
    ];
  }

  private function isNonImportableDefinition(array $definition): bool {
    if (array_key_exists('generic_config_admitted', $definition) && empty($definition['generic_config_admitted'])) {
      return TRUE;
    }
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
      // Dedicated CiviRulesHandler is the single owner of CiviRules config.
      'org.civicoop.civirules',
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

  /**
   * Resolve YAML provider metadata to the canonical runtime provider.
   *
   * SQLTasks exports from older Configuration Manager builds can reference the
   * API3 Sqltask provider. SQLTasks 3.x also exposes native API4 SqlTask and
   * Configuration Manager deliberately prefers API4. Treat the old API3
   * metadata as a compatibility alias when API4 is available, while retaining
   * the reviewed API3/BAO implementation as fallback on older sites.
   *
   * @return array{key:string,definition:array}|null
   */
  private function resolveConfigDefinition(array $definitions, string $extension, string $api, string $entity): ?array {
    if (
      strtolower($extension) === 'de.systopia.sqltasks'
      && strtolower($api) === 'api3'
      && strtolower($entity) === 'sqltask'
    ) {
      foreach ($definitions as $key => $definition) {
        if (
          strtolower((string) ($definition['extension'] ?? '')) === 'de.systopia.sqltasks'
          && strtolower((string) ($definition['api'] ?? '')) === 'api4'
          && strtolower((string) ($definition['entity'] ?? '')) === 'sqltask'
        ) {
          return ['key' => (string) $key, 'definition' => (array) $definition];
        }
      }
    }

    $key = $this->definitionKey($extension, $api, $entity);
    if (!isset($definitions[$key])) {
      return NULL;
    }
    return ['key' => $key, 'definition' => (array) $definitions[$key]];
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
    self::$api3DeleteActionByEntity = [];
    $this->discoveredEntityDefinitions = NULL;
    $this->identityRowsByDefinition = [];
  }

  private function safeName(string $name): string {
    $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name);
    return trim((string) $safe, '-') ?: sha1($name);
  }
}
