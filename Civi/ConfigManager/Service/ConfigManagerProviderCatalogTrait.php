<?php
namespace Civi\ConfigManager\Service;


/**
 * ConfigManagerProviderCatalog methods from ConfigManager.
 *
 * Methods run on the original owning object and retain the same state and
 * access modifiers. Do not instantiate this trait separately.
 */
trait ConfigManagerProviderCatalogTrait {

  public function getHandlers(): array {
    return array_values(array_filter($this->getAllHandlers(), function($handler) {
      return $this->scope->isManagedType((string) $handler->getType());
    }));
  }

  public function getAllHandlers(): array {
    if ($this->allHandlersCache === NULL) {
      $this->allHandlersCache = $this->registry->getHandlers();
    }
    return $this->allHandlersCache;
  }

  /**
   * Build a deterministic, metadata-only inventory of registered providers.
   *
   * This boundary must not call export(), compatibility reports, provider
   * collection actions, or inspect YAML content. It describes capability; it
   * does not prove runtime identity uniqueness or admit a provider for writes.
   */
  public function getProviderInventory(): array {
    $providers = [];
    $registrations = $this->registry->getHandlerRegistrations();
    foreach ($registrations as $registration) {
      $handler = $registration['handler'];
      $source = (string) ($registration['registration_source'] ?? 'unknown');
      $metadata = method_exists($handler, 'getProviderMetadata')
        ? (array) $handler->getProviderMetadata()
        : [];
      $capability = $this->scopeCapabilityForHandler($handler);
      $capabilityKey = (string) $capability['key'];
      $capabilityReason = (string) $capability['help'];
      $owner = trim((string) ($metadata['owner'] ?? ''));
      if ($owner === '' || ($source !== 'core_handler' && $owner === 'civi.config.manager')) {
        $owner = $source === 'core_handler' ? 'civi.config.manager' : 'hook-provider';
      }

      $type = (string) $handler->getType();
      $providers[] = [
        'provider_key' => 'handler:' . $type,
        'type' => $type,
        'base_type' => $type,
        'label' => (string) $handler->getLabel(),
        'owner' => $owner,
        'registration_source' => $source,
        'api_version' => (string) ($metadata['api_version'] ?? 'handler'),
        'entity' => (string) ($metadata['entity'] ?? ''),
        'actions' => $this->normaliseInventoryActions((array) ($metadata['actions'] ?? [])),
        'discovered_actions' => $this->sortedInventoryStrings((array) ($metadata['discovered_actions'] ?? [])),
        'field_names' => $this->sortedInventoryStrings((array) ($metadata['field_names'] ?? [])),
        'identity_fields' => $this->sortedInventoryStrings((array) ($metadata['identity_fields'] ?? [])),
        'reference_fields' => $this->sortedInventoryStrings((array) ($metadata['reference_fields'] ?? [])),
        'sensitive_fields' => $this->sortedInventoryStrings((array) ($metadata['sensitive_fields'] ?? [])),
        'runtime_fields' => $this->sortedInventoryStrings((array) ($metadata['runtime_fields'] ?? [])),
        'admitted' => $capabilityKey !== 'unavailable',
        'capability' => $capabilityKey,
        'capability_reason_code' => 'handler_' . $capabilityKey,
        'capability_reason' => $capabilityReason,
        'identity_evidence' => (string) ($metadata['identity_evidence'] ?? 'none'),
        'metadata_completeness' => (string) ($metadata['metadata_completeness'] ?? 'basic'),
        'collection_read_during_inventory' => FALSE,
      ];

      if ($handler instanceof \Civi\ConfigManager\Handler\ExtensionHandler) {
        foreach ($handler->getDiscoveredProviderInventory() as $provider) {
          $providers[] = $provider;
        }
      }
    }

    foreach ($this->registry->getRegistrationDiagnostics() as $index => $diagnostic) {
      $type = (string) ($diagnostic['type'] ?? '');
      $source = (string) ($diagnostic['registration_source'] ?? 'unknown');
      $providers[] = [
        'provider_key' => 'rejected:' . $source . ':' . ($type !== '' ? $type : 'unknown') . ':' . $index,
        'type' => $type,
        'base_type' => $type,
        'label' => $type !== '' ? $type : 'Rejected provider registration',
        'owner' => $source === 'core_handler' ? 'civi.config.manager' : 'hook-provider',
        'registration_source' => $source,
        'api_version' => 'handler',
        'entity' => '',
        'actions' => $this->normaliseInventoryActions([]),
        'discovered_actions' => [],
        'field_names' => [],
        'identity_fields' => [],
        'reference_fields' => [],
        'sensitive_fields' => [],
        'runtime_fields' => [],
        'admitted' => FALSE,
        'capability' => 'unavailable',
        'capability_reason_code' => 'registry_' . (string) ($diagnostic['reason_code'] ?? 'registration_rejected'),
        'capability_reason' => (string) ($diagnostic['reason'] ?? 'Provider registration was rejected.'),
        'identity_evidence' => 'none',
        'metadata_completeness' => 'rejected',
        'collection_read_during_inventory' => FALSE,
      ];
    }

    usort($providers, static function(array $a, array $b): int {
      return strcmp((string) $a['provider_key'], (string) $b['provider_key']);
    });

    $capabilities = [];
    foreach ($providers as $provider) {
      $key = (string) ($provider['capability'] ?? 'unknown');
      $capabilities[$key] = ($capabilities[$key] ?? 0) + 1;
    }
    ksort($capabilities, SORT_STRING);

    return [
      'ok' => TRUE,
      'schema_version' => 1,
      'providers' => $providers,
      'summary' => [
        'provider_count' => count($providers),
        'admitted_count' => count(array_filter($providers, static function(array $provider): bool {
          return !empty($provider['admitted']);
        })),
        'rejected_registration_count' => count($this->registry->getRegistrationDiagnostics()),
        'capabilities' => $capabilities,
      ],
    ];
  }

  private function normaliseInventoryActions(array $actions): array {
    return [
      'read' => array_key_exists('read', $actions) ? (bool) $actions['read'] : NULL,
      'create' => array_key_exists('create', $actions) && $actions['create'] !== NULL ? (bool) $actions['create'] : NULL,
      'update' => array_key_exists('update', $actions) && $actions['update'] !== NULL ? (bool) $actions['update'] : NULL,
      'delete' => array_key_exists('delete', $actions) && $actions['delete'] !== NULL ? (bool) $actions['delete'] : NULL,
    ];
  }

  private function sortedInventoryStrings(array $values): array {
    $values = array_values(array_unique(array_filter(array_map('strval', $values), 'strlen')));
    sort($values, SORT_NATURAL | SORT_FLAG_CASE);
    return $values;
  }

  public function getManagedTypeOptions(): array {
    if ($this->managedTypeOptionsCache !== NULL) {
      return $this->managedTypeOptionsCache;
    }
    $rows = [];
    foreach ($this->getAllHandlers() as $handler) {
      $rows[] = [
        'type' => $handler->getType(),
        'base_type' => $handler->getType(),
        'label' => $handler->getLabel(),
        'directory' => $handler->getDirectory(),
        'weight' => $handler->getWeight(),
        'virtual' => FALSE,
        'provider' => '',
      ];
      if (method_exists($handler, 'getFilterOptions')) {
        foreach ((array) $handler->getFilterOptions() as $option) {
          if (empty($option['type']) || empty($option['label'])) {
            continue;
          }
          $rows[] = [
            'type' => (string) $option['type'],
            'base_type' => (string) ($option['base_type'] ?? $handler->getType()),
            'label' => (string) $option['label'],
            'directory' => (string) ($option['directory'] ?? $handler->getDirectory()),
            'weight' => (int) ($option['weight'] ?? ($handler->getWeight() + 1)),
            'virtual' => TRUE,
            'provider' => (string) ($option['provider'] ?? ''),
          ];
        }
      }
    }
    usort($rows, function($a, $b) {
      $cmp = ((int) ($a['weight'] ?? 0)) <=> ((int) ($b['weight'] ?? 0));
      if ($cmp !== 0) {
        return $cmp;
      }
      return strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
    });
    $this->managedTypeOptionsCache = $rows;
    return $this->managedTypeOptionsCache;
  }

  public function getScopePolicies(): array {
    return $this->scope->getPolicies();
  }

  public function getScopePolicy(string $type): array {
    return $this->scope->getPolicy($type);
  }

  public function getScopeDefaultMode(): string {
    return $this->scope->getDefaultMode();
  }

  public function saveScopePolicies(array $policies): void {
    if ($this->scope->isPolicyOverridden()) {
      throw new \RuntimeException('Configuration scope is overridden in civicrm.settings.php and cannot be changed from the UI.');
    }

    $previousPolicies = [];
    foreach ($this->getScopeTypeOptions() as $row) {
      $type = (string) ($row['type'] ?? '');
      if ($type !== '') {
        $previousPolicies[$type] = $this->scope->getPolicy($type);
      }
    }

    $this->scope->savePolicies($policies);
    $watchBaselineTypes = [];

    // Watch state is local and disposable. Clear records that are no longer
    // watched, and establish a baseline immediately when a saved scope starts
    // watching a type or changes which selected items are watched. This makes
    // "Monitor everything else in this type" effective from the moment the
    // administrator saves the scope instead of silently treating the first
    // later watch scan as the baseline.
    try {
      $store = new StateStore();
      foreach ($this->getScopeTypeOptions() as $row) {
        $type = (string) ($row['type'] ?? '');
        if ($type === '') {
          continue;
        }
        $currentPolicy = $this->scope->getPolicy($type);
        if (!$this->scope->isWatchedType($type)) {
          $store->clearWatchStatesByType($type);
          continue;
        }
        $previousPolicy = (array) ($previousPolicies[$type] ?? []);
        $coverageChanged = $this->watchCoverageSignature($previousPolicy) !== $this->watchCoverageSignature($currentPolicy);
        $watchStateMissing = !$store->getWatchStatesByType($type);
        if ($coverageChanged || $watchStateMissing) {
          $watchBaselineTypes[] = $type;
        }
      }
    }
    catch (\Throwable $e) {
      // Do not roll back successfully saved scope because disposable watch
      // fingerprints could not be cleaned up. The next watch scan can rebuild
      // this state safely.
    }

    \Civi::settings()->set('civicfg_last_health', []);
    \Civi::settings()->set('civicfg_watch_summary', []);

    if ($watchBaselineTypes) {
      try {
        $this->initializeWatchBaselines($watchBaselineTypes);
      }
      catch (\Throwable $e) {
        \Civi::settings()->set('civicfg_watch_summary', [
          'ok' => FALSE,
          'scanned_at' => date('c'),
          'watched' => 0,
          'baseline' => 0,
          'new' => 0,
          'changed' => 0,
          'missing' => 0,
          'items' => [],
          'errors' => [['type' => 'watch', 'message' => $e->getMessage()]],
        ]);
      }
    }
  }
}
