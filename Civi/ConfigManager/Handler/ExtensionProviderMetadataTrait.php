<?php
namespace Civi\ConfigManager\Handler;

/**
 * ExtensionProviderMetadata methods from ExtensionHandler.
 *
 * Methods run on the original owning object and retain the same state and
 * access modifiers. Do not instantiate this trait separately.
 */
trait ExtensionProviderMetadataTrait {

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
    $probe = $this->quietDiscoveryProbe(static function () use ($class) {
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
    $probe = $this->quietDiscoveryProbe(static function () use ($class) {
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
    set_error_handler(static function ($severity, $message, $file = '', $line = 0) use (&$warnings): bool {
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

}
