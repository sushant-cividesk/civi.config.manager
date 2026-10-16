<?php
namespace Civi\ConfigManager\Handler;


/**
 * ExtensionProviderInventory methods from ExtensionHandler.
 *
 * Methods run on the original owning object and retain the same state and
 * access modifiers. Do not instantiate this trait separately.
 */
trait ExtensionProviderInventoryTrait {

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
        'reference_fields' => $this->sortedStringValues((array) ($definition['reference_fields'] ?? [])),
        'sensitive_fields' => $this->sortedStringValues($sensitiveFields),
        'runtime_fields' => $this->sortedStringValues($runtimeFields),
        'admitted' => $admitted,
        'capability' => $this->inventoryCapability($admitted, $canCreate, $canUpdate, $canDelete),
        'capability_reason_code' => (string) ($definition['admission_reason_code'] ?? ($admitted ? 'provider_admitted' : 'provider_not_admitted')),
        'capability_reason' => (string) ($definition['admission_reason'] ?? ''),
        'identity_evidence' => !empty($definition['reviewed_provider'])
          ? 'reviewed_adapter'
          : (!empty($definition['match_fields']) ? 'provider_metadata' : 'none'),
        'admission_stages' => (array) ($definition['admission_stages'] ?? []),
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
}
