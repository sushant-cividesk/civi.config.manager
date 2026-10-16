<?php
namespace Civi\ConfigManager\Handler;

/**
 * ExtensionProviderDiscovery methods from ExtensionHandler.
 *
 * Methods run on the original owning object and retain the same state and
 * access modifiers. Do not instantiate this trait separately.
 */
trait ExtensionProviderDiscoveryTrait {

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
    uasort($definitions, function ($a, $b) {
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
      $matchFields = array_values(array_filter((array) ($info['match_fields'] ?? []), static function ($field) {
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
        'admission_stages' => (array) ($admission['stages'] ?? []),
        'writable_fields' => (array) ($admission['writable_fields'] ?? []),
        'reference_fields' => (array) ($admission['reference_fields'] ?? []),
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

    $probe = $this->quietDiscoveryProbe(static function () use ($file) {
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
    ],
    ];
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

}
