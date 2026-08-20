<?php
namespace Civi\ConfigManager\Service;

use Civi\ConfigManager\Storage\YamlFileStorage;
use Civi\ConfigManager\Util\SimpleYaml;
use Civi\ConfigManager\Version;

class ConfigManager {
  private const WATCH_HISTORY_LIMIT = 200;
  private HandlerRegistry $registry;
  private ConfigScope $scope;
  private ?array $allHandlersCache = NULL;
  private ?array $managedTypeOptionsCache = NULL;
  private array $activeDependencyNamesCache = [];

  public function __construct(?HandlerRegistry $registry = NULL, ?ConfigScope $scope = NULL) {
    $this->registry = $registry ?: new HandlerRegistry();
    $this->scope = $scope ?: new ConfigScope();
  }

  public function getSyncDir(): string {
    $dir = trim((string) \Civi::settings()->get('civicfg_sync_dir'));
    if ($dir === '' || $dir === '../civicrm-config') {
      $dir = 'civicrm-config';
    }

    if ($this->isUrlPath($dir)) {
      throw new \RuntimeException('Sync Directory Must Be A Server File Path, Not A URL.');
    }

    if ($dir[0] !== DIRECTORY_SEPARATOR) {
      $dir = rtrim($this->getProjectRoot(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $dir;
    }

    return $this->normalizePath($dir);
  }

  public function getDefaultSyncDirSetting(): string {
    return 'civicrm-config';
  }


  public function getSiteIdentifier(): string {
    $siteId = trim((string) \Civi::settings()->get('civicfg_site_id'));
    if ($this->isValidSiteIdentifier($siteId)) {
      return $siteId;
    }
    $siteId = $this->generateSiteIdentifier();
    \Civi::settings()->set('civicfg_site_id', $siteId);
    return $siteId;
  }

  public function isValidSiteIdentifier(string $siteId): bool {
    return $siteId !== '' && (bool) preg_match('/^[A-Za-z0-9_.:-]+$/', $siteId);
  }

  private function generateSiteIdentifier(): string {
    try {
      $bytes = random_bytes(16);
    }
    catch (\Throwable $e) {
      $bytes = sha1(uniqid('', TRUE) . microtime(TRUE), TRUE);
    }
    $hex = bin2hex($bytes);
    return 'civicfg-' . substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
  }

  public function confirmIdentityAlias(string $providerKey, string $oldConfigKey, string $newConfigKey): array {
    $providerKey = trim($providerKey);
    $oldConfigKey = trim($oldConfigKey);
    $newConfigKey = trim($newConfigKey);
    if ($providerKey === '' || $oldConfigKey === '' || $newConfigKey === '') {
      throw new \InvalidArgumentException('Provider key, old config key, and new config key are required.');
    }
    if ($oldConfigKey === $newConfigKey) {
      throw new \InvalidArgumentException('Identity alias requires two different configuration keys.');
    }
    $prefix = $providerKey . '|';
    if (strpos($oldConfigKey, $prefix) !== 0 || strpos($newConfigKey, $prefix) !== 0) {
      throw new \InvalidArgumentException('Identity alias keys must belong to the supplied configuration provider.');
    }

    $oldIdentity = [
      'provider_key' => $providerKey,
      'config_key' => $oldConfigKey,
      'identity_hash' => hash('sha256', $oldConfigKey),
    ];
    $newIdentity = [
      'provider_key' => $providerKey,
      'config_key' => $newConfigKey,
      'identity_hash' => hash('sha256', $newConfigKey),
    ];
    (new StateStore())->confirmAlias($oldIdentity, $newIdentity);

    return [
      'ok' => TRUE,
      'provider_key' => $providerKey,
      'old_config_key' => $oldConfigKey,
      'new_config_key' => $newConfigKey,
      'message' => 'Configuration identity rename confirmed. The alias will preserve baseline continuity after YAML is re-exported.',
    ];
  }

  public function getProjectRoot(): string {
    foreach ($this->getProjectRootCandidates() as $candidate) {
      if ($candidate !== '' && is_dir($candidate)) {
        return $candidate;
      }
    }

    $config = \CRM_Core_Config::singleton();
    if (!empty($config->configAndLogDir)) {
      return dirname((string) $config->configAndLogDir);
    }

    return (string) getcwd();
  }

  private function getProjectRootCandidates(): array {
    $candidates = [];

    if (defined('DRUPAL_ROOT')) {
      $candidates[] = DRUPAL_ROOT;
    }

    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
      $candidates[] = rtrim((string) $_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR);
    }

    foreach ($this->getSettingsFileCandidates() as $settingsFile) {
      $dir = dirname($settingsFile);
      if (basename($dir) === 'default' && basename(dirname($dir)) === 'sites') {
        $candidates[] = dirname($dir, 2);
      }
      $candidates[] = dirname($settingsFile);
      $candidates[] = dirname($settingsFile, 2);
    }

    try {
      $config = \CRM_Core_Config::singleton();
      if (!empty($config->userFrameworkResourceURL)) {
        // No-op. Referencing config here keeps the method safe across CMS variants.
      }
      if (!empty($config->configAndLogDir)) {
        $candidates[] = dirname((string) $config->configAndLogDir);
        $candidates[] = dirname((string) $config->configAndLogDir, 2);
        $candidates[] = dirname((string) $config->configAndLogDir, 3);
      }
    }
    catch (\Throwable $e) {
      // Ignore config discovery errors; other candidates may still work.
    }

    $candidates[] = (string) getcwd();

    return array_values(array_unique(array_filter($candidates, 'is_string')));
  }

  private function getSettingsFileCandidates(): array {
    $candidates = [];
    if (defined('CIVICRM_SETTINGS_PATH')) {
      $candidates[] = CIVICRM_SETTINGS_PATH;
    }
    if (!empty($_SERVER['CIVICRM_SETTINGS'])) {
      $candidates[] = $_SERVER['CIVICRM_SETTINGS'];
    }
    if (!empty($_ENV['CIVICRM_SETTINGS'])) {
      $candidates[] = $_ENV['CIVICRM_SETTINGS'];
    }
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
      $candidates[] = rtrim((string) $_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR) . '/sites/default/civicrm.settings.php';
    }
    return array_values(array_unique(array_filter($candidates, 'is_string')));
  }

  private function isUrlPath(string $path): bool {
    return (bool) preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $path);
  }

  private function normalizePath(string $path): string {
    $prefix = '';
    if (strpos($path, DIRECTORY_SEPARATOR) === 0) {
      $prefix = DIRECTORY_SEPARATOR;
    }

    $parts = [];
    foreach (explode(DIRECTORY_SEPARATOR, $path) as $part) {
      if ($part === '' || $part === '.') {
        continue;
      }
      if ($part === '..') {
        array_pop($parts);
        continue;
      }
      $parts[] = $part;
    }

    return $prefix . implode(DIRECTORY_SEPARATOR, $parts);
  }

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

  /**
   * Capture fingerprints for newly watched objects without accepting changes
   * to objects that already have a watch baseline.
   *
   * @return array<string,mixed>
   */
  public function initializeWatchBaselines(array $typeFilter = []): array {
    $normalisedFilter = $this->normaliseTypeFilter($typeFilter);
    $baseFilter = $this->baseTypesFromFilter($normalisedFilter);
    $store = new StateStore();
    $identityService = new ConfigIdentity();
    $canonicalizer = new Canonicalizer();
    $storage = new YamlFileStorage($this->getSyncDir());
    $manifest = $this->readManifest($storage);
    $summary = [
      'ok' => TRUE,
      'scanned_at' => date('c'),
      'watched' => 0,
      'baseline' => 0,
      'new' => 0,
      'changed' => 0,
      'missing' => 0,
      'items' => [],
      'errors' => [],
    ];

    foreach ($this->getAllHandlers() as $handler) {
      $type = (string) $handler->getType();
      if ($baseFilter && !in_array($type, $baseFilter, TRUE)) {
        continue;
      }
      if (!$this->scope->isWatchedType($type)) {
        continue;
      }
      $this->prepareHandlerForTypeFilter($handler, $normalisedFilter);
      try {
        $exported = $handler->export();
        $selectorMap = $this->scope->portableSelectorMapFromManifest($manifest, $type);
        $partition = $this->scope->partition($type, $exported, FALSE, $selectorMap);
        $watched = (array) ($partition['watched'] ?? []);
        $previousRows = $store->getWatchStatesByType($type);
        $previousByHash = [];
        foreach ($previousRows as $row) {
          $identityHash = (string) ($row['identity_hash'] ?? '');
          if ($identityHash !== '') {
            $previousByHash[$identityHash] = $row;
          }
        }

        $allActiveHashes = [];
        foreach ($exported as $file) {
          $file = (array) $file;
          $filename = (string) ($file['filename'] ?? '');
          if ($filename === '') {
            continue;
          }
          $identity = $identityService->identify($type, (array) ($file['data'] ?? []), $filename);
          $allActiveHashes[(string) $identity['identity_hash']] = TRUE;
        }

        $seenWatched = [];
        foreach ($watched as $file) {
          $file = (array) $file;
          $filename = (string) ($file['filename'] ?? '');
          $data = (array) ($file['data'] ?? []);
          if ($filename === '') {
            continue;
          }
          $identity = $identityService->identify($type, $data, $filename);
          $identityHash = (string) $identity['identity_hash'];
          $seenWatched[$identityHash] = TRUE;
          $summary['watched']++;
          if (isset($previousByHash[$identityHash])) {
            continue;
          }
          $hash = $canonicalizer->hash($data, $handler->getCanonicalizationOptions());
          $label = $this->displayLabelForConfigFile($type, $file);
          $store->upsertWatchState($type, $identity, $filename, $label, $hash, $data, 'baseline');
          $summary['baseline']++;
        }

        // If scope changed from watched to managed for a still-active item,
        // discard its stale watch row. Missing watched items intentionally keep
        // their previous baseline so the next explicit scan can report missing.
        foreach ($previousRows as $previous) {
          $identityHash = (string) ($previous['identity_hash'] ?? '');
          if ($identityHash === '' || isset($seenWatched[$identityHash])) {
            continue;
          }
          if (isset($allActiveHashes[$identityHash])) {
            $store->deleteWatchState((string) $previous['provider_key'], $identityHash);
          }
        }
      }
      catch (\Throwable $e) {
        $summary['ok'] = FALSE;
        $summary['errors'][] = ['type' => $type, 'message' => $e->getMessage()];
      }
    }

    \Civi::settings()->set('civicfg_watch_summary', $summary);
    return $summary;
  }

  private function watchCoverageSignature(array $policy): string {
    $mode = (string) ($policy['mode'] ?? ConfigScope::MODE_ALL);
    if ($mode === ConfigScope::MODE_WATCH) {
      return ConfigScope::MODE_WATCH . ':all';
    }
    if ($mode !== ConfigScope::MODE_SELECTED || empty($policy['watch_unmanaged'])) {
      return '';
    }
    $selectors = array_values(array_unique(array_filter(array_map('strval', (array) ($policy['selectors'] ?? [])), 'strlen')));
    sort($selectors, SORT_NATURAL | SORT_FLAG_CASE);
    return ConfigScope::MODE_SELECTED . ':watch-unmanaged:' . implode('|', $selectors);
  }

  public function isScopePolicyOverridden(): bool {
    return $this->scope->isPolicyOverridden();
  }

  public function getScopeSelectorHelp(): string {
    return $this->scope->selectorHelp();
  }

  public function allowsDeleteMissingForType(string $type): bool {
    return $this->scope->allowsDeleteMissing($type);
  }

  public function getScopeTypeOptions(): array {
    $rows = [];
    foreach ($this->getAllHandlers() as $handler) {
      $capability = $this->scopeCapabilityForHandler($handler);
      $rows[] = [
        'type' => (string) $handler->getType(),
        'base_type' => (string) $handler->getType(),
        'label' => (string) $handler->getLabel(),
        'directory' => (string) $handler->getDirectory(),
        'weight' => (int) $handler->getWeight(),
        'virtual' => FALSE,
        'provider' => '',
        'capability' => $capability['key'],
        'capability_label' => $capability['label'],
        'capability_help' => $capability['help'],
      ];
    }
    usort($rows, static function($a, $b) {
      $cmp = ((int) $a['weight']) <=> ((int) $b['weight']);
      return $cmp !== 0 ? $cmp : strcmp((string) $a['label'], (string) $b['label']);
    });
    return $rows;
  }

  /**
   * Lazily enumerate active items for one scope type. Settings never calls this
   * until an administrator opens that type's item picker.
   */
  public function getScopePickerItems(string $type): array {
    if (!preg_match('/^[A-Za-z0-9_.-]+$/', $type)) {
      throw new \RuntimeException('Invalid Configuration Scope type.');
    }

    $handler = NULL;
    foreach ($this->getAllHandlers() as $candidate) {
      if ((string) $candidate->getType() === $type) {
        $handler = $candidate;
        break;
      }
    }
    if ($handler === NULL) {
      throw new \RuntimeException('Unknown Configuration Scope type: ' . $type);
    }

    $storage = new YamlFileStorage($this->getSyncDir());
    $exported = $this->attachScopeRelativePaths($handler, $handler->export());
    $partition = $this->scopePartition($handler, $exported, $storage, FALSE);
    $selectedKeys = array_fill_keys(array_map('strval', (array) ($partition['managed_config_keys'] ?? [])), TRUE);
    $identityService = new ConfigIdentity();
    $items = [];

    foreach ($exported as $file) {
      $file = (array) $file;
      $filename = (string) ($file['filename'] ?? '');
      $data = (array) ($file['data'] ?? []);
      if ($filename === '') {
        continue;
      }
      $identity = $identityService->identify($type, $data, $filename);
      $configKey = (string) ($identity['config_key'] ?? '');
      $relative = (string) ($file['relative_path'] ?? (trim((string) $handler->getDirectory(), '/') . '/' . $filename));
      if ($this->isIgnoredPath($relative)) {
        continue;
      }
      $items[] = [
        'selector' => 'key:' . $configKey,
        'config_key' => $configKey,
        'label' => $this->displayLabelForConfigFile($type, $file),
        'path' => $relative,
        'source_id' => isset($file['source_id']) && is_scalar($file['source_id']) ? (string) $file['source_id'] : '',
        'selected' => isset($selectedKeys[$configKey]),
        'write_safe' => !empty($identity['write_safe']),
        'identity_confidence' => (string) ($identity['identity_confidence'] ?? ''),
      ];
    }

    // Keep configured selectors visible even when the active record is missing.
    // This prevents opening/saving the picker from silently losing a managed
    // backup that is intentionally waiting to be restored.
    $presentSelectors = array_fill_keys(array_map(static fn($item) => (string) ($item['selector'] ?? ''), $items), TRUE);
    foreach ((array) ($partition['selector_config_keys'] ?? []) as $selector => $configKey) {
      $stableSelector = 'key:' . trim((string) $configKey);
      if ($stableSelector === 'key:' || isset($presentSelectors[$stableSelector])) {
        continue;
      }
      $items[] = [
        'selector' => $stableSelector,
        'config_key' => (string) $configKey,
        'label' => 'Managed item currently missing from CiviCRM',
        'path' => '',
        'source_id' => '',
        'selected' => TRUE,
        'write_safe' => FALSE,
        'identity_confidence' => '',
        'missing' => TRUE,
        'source_selector' => (string) $selector,
      ];
    }
    foreach ((array) ($partition['unresolved_selectors'] ?? []) as $selector) {
      $selector = trim((string) $selector);
      if ($selector === '') {
        continue;
      }
      $items[] = [
        'selector' => $selector,
        'config_key' => '',
        'label' => 'Configured selector not found yet',
        'path' => '',
        'source_id' => '',
        'selected' => TRUE,
        'write_safe' => FALSE,
        'identity_confidence' => '',
        'missing' => TRUE,
        'source_selector' => $selector,
      ];
    }

    usort($items, static function($a, $b) {
      $missingCmp = ((int) !empty($a['missing'])) <=> ((int) !empty($b['missing']));
      if ($missingCmp !== 0) {
        return $missingCmp;
      }
      return strnatcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
    });

    return [
      'type' => $type,
      'label' => (string) $handler->getLabel(),
      'policy' => $this->scope->getPolicy($type),
      'items' => $items,
    ];
  }

  /**
   * Generate a deployable civicrm.settings.php example from effective scope.
   */
  public function getScopeSettingsExample(): string {
    $lines = [
      'global $civicrm_setting;',
      '',
      '$civicrm_setting[\'domain\'][\'civicfg_scope\'] = [',
    ];
    foreach ($this->getScopeTypeOptions() as $row) {
      $type = (string) ($row['type'] ?? '');
      if ($type === '') {
        continue;
      }
      $policy = $this->scope->getPolicy($type);
      $mode = (string) ($policy['mode'] ?? ConfigScope::MODE_ALL);
      if ($mode === ConfigScope::MODE_ALL && empty($policy['selectors']) && empty($policy['watch_unmanaged'])) {
        continue;
      }
      $lines[] = '  ' . var_export($type, TRUE) . ' => [';
      $lines[] = '    \'mode\' => ' . var_export($mode, TRUE) . ',';
      if ($mode === ConfigScope::MODE_SELECTED) {
        $lines[] = '    \'selectors\' => [';
        foreach ((array) ($policy['selectors'] ?? []) as $selector) {
          $lines[] = '      ' . var_export((string) $selector, TRUE) . ',';
        }
        $lines[] = '    ],';
        if (!empty($policy['watch_unmanaged'])) {
          $lines[] = '    \'watch_unmanaged\' => TRUE,';
        }
      }
      $lines[] = '  ],';
    }
    $lines[] = '];';
    return implode("\n", $lines);
  }

  /**
   * Return effective scope configuration without enumerating active records.
   */
  public function getScopeConfiguration(): array {
    $types = [];
    foreach ($this->getScopeTypeOptions() as $row) {
      $type = (string) ($row['type'] ?? '');
      if ($type === '') {
        continue;
      }
      $types[] = $row + ['policy' => $this->scope->getPolicy($type)];
    }
    return [
      'ok' => TRUE,
      'overridden' => $this->scope->isPolicyOverridden(),
      'types' => $types,
    ];
  }

  /**
   * Change one scope policy while preserving all other type policies.
   */
  public function setScopePolicy(string $type, string $mode, array $selectors = [], bool $watchUnmanaged = FALSE): array {
    $type = trim($type);
    $mode = strtolower(trim($mode));
    $validTypes = [];
    foreach ($this->getScopeTypeOptions() as $row) {
      $validTypes[(string) $row['type']] = TRUE;
    }
    if ($type === '' || !isset($validTypes[$type])) {
      throw new \RuntimeException('Unknown Configuration Scope type: ' . $type);
    }
    if (!in_array($mode, [ConfigScope::MODE_ALL, ConfigScope::MODE_SELECTED, ConfigScope::MODE_WATCH, ConfigScope::MODE_IGNORE], TRUE)) {
      throw new \RuntimeException('Invalid Configuration Scope mode: ' . $mode);
    }

    $selectors = array_values(array_unique(array_filter(array_map(static function($selector) {
      return trim((string) $selector);
    }, $selectors), 'strlen')));
    if ($mode !== ConfigScope::MODE_SELECTED) {
      $selectors = [];
      $watchUnmanaged = FALSE;
    }

    $policies = $this->scope->getPolicies();
    $policies[$type] = [
      'mode' => $mode,
      'selectors' => $selectors,
      'watch_unmanaged' => $watchUnmanaged,
    ];
    $this->saveScopePolicies($policies);

    return [
      'ok' => TRUE,
      'type' => $type,
      'policy' => $this->scope->getPolicy($type),
      'overridden' => $this->scope->isPolicyOverridden(),
    ];
  }

  public function getCrossSiteImportPolicy(): array {
    return [
      'ok' => TRUE,
      'allowed' => (bool) \Civi::settings()->get('civicfg_allow_cross_site_import'),
      'site_id' => $this->getSiteIdentifier(),
    ];
  }

  public function setCrossSiteImportAllowed(bool $allowed): array {
    \Civi::settings()->set('civicfg_allow_cross_site_import', $allowed ? 1 : 0);
    return $this->getCrossSiteImportPolicy();
  }

  private function scopeCapabilityForHandler($handler): array {
    if (method_exists($handler, 'getRuntimeAvailability')) {
      try {
        $availability = (array) $handler->getRuntimeAvailability();
        if (array_key_exists('available', $availability) && empty($availability['available'])) {
          return [
            'key' => 'unavailable',
            'label' => 'Unavailable on this site',
            'help' => trim((string) ($availability['reason'] ?? 'Required runtime provider is not available on this site.')),
          ];
        }
      }
      catch (\Throwable $e) {
        return [
          'key' => 'unavailable',
          'label' => 'Unavailable on this site',
          'help' => 'Runtime provider availability could not be confirmed: ' . $e->getMessage(),
        ];
      }
    }

    if ($handler instanceof \Civi\ConfigManager\Handler\ExtensionHandler) {
      return [
        'key' => 'mixed',
        'label' => 'Mixed provider capabilities',
        'help' => 'Extension status and safe provider config can be managed. Providers without a safe portable identity stay export/monitor-only.',
      ];
    }
    try {
      $method = new \ReflectionMethod($handler, 'import');
      if ($method->getDeclaringClass()->getName() === \Civi\ConfigManager\Handler\AbstractHandler::class) {
        return [
          'key' => 'export_only',
          'label' => 'Export + compare',
          'help' => 'This handler can be exported, compared, watched, and backed up, but automatic restore/import is not implemented.',
        ];
      }
    }
    catch (\ReflectionException $e) {
      // Registered handlers implement HandlerInterface; use the conservative
      // export/compare label if a custom implementation is unusual.
      return [
        'key' => 'export_only',
        'label' => 'Export + compare',
        'help' => 'Automatic restore/import capability could not be confirmed for this handler.',
      ];
    }
    return [
      'key' => 'full',
      'label' => 'Full management',
      'help' => "Supports managed YAML plus the handler's safe import/restore behavior.",
    ];
  }

  public function status(): array {
    $dir = $this->getSyncDir();
    $exists = is_dir($dir);
    $parent = dirname($dir);
    $writable = $exists ? is_writable($dir) : (is_dir($parent) && is_writable($parent));
    $yamlRuntime = SimpleYaml::runtimeStatus();
    $civiVersion = class_exists('CRM_Utils_System') ? (string) \CRM_Utils_System::version() : '';
    $providerAvailability = [];
    foreach ($this->getScopeTypeOptions() as $scopeType) {
      $providerAvailability[] = [
        'type' => (string) ($scopeType['type'] ?? ''),
        'capability' => (string) ($scopeType['capability'] ?? ''),
        'available' => (string) ($scopeType['capability'] ?? '') !== 'unavailable',
        'message' => (string) ($scopeType['capability_help'] ?? ''),
      ];
    }
    $types = [];
    foreach ($this->getHandlers() as $handler) {
      $types[] = [
        'type' => $handler->getType(),
        'label' => $handler->getLabel(),
        'directory' => $handler->getDirectory(),
        'weight' => $handler->getWeight(),
      ];
    }
    return [
      'ok' => TRUE,
      'sync_dir' => $dir,
      'exists' => $exists,
      'writable' => $writable,
      'runtime' => [
        'php_version' => PHP_VERSION,
        'php_74_compatible' => version_compare(PHP_VERSION, '7.4.0', '>='),
        'civicrm_version' => $civiVersion,
        'civicrm_576_compatible' => $civiVersion === '' ? NULL : version_compare($civiVersion, '5.76.0', '>='),
        'yaml' => $yamlRuntime,
        'provider_availability' => $providerAvailability,
      ],
      'cli' => (new CliInstaller($this))->status(),
      'types' => $types,
    ];
  }

  public function getEffectiveExportTypeFilter(array $typeFilter = []): array {
    $requested = $this->normaliseTypeFilter($typeFilter);
    if (!$requested) {
      return [];
    }

    $available = [];
    foreach ($this->getHandlers() as $handler) {
      $available[$handler->getType()] = TRUE;
    }

    $expanded = [];
    foreach ($this->baseTypesFromFilter($requested) as $type) {
      if (isset($available[$type])) {
        $expanded[$type] = TRUE;
      }
    }

    $map = $this->getExportRelatedTypeMap();
    $changed = TRUE;
    while ($changed) {
      $changed = FALSE;
      foreach (array_keys($expanded) as $type) {
        foreach (($map[$type] ?? []) as $relatedType) {
          if (isset($available[$relatedType]) && !isset($expanded[$relatedType])) {
            $expanded[$relatedType] = TRUE;
            $changed = TRUE;
          }
        }
      }
    }

    $ordered = [];
    foreach ($this->getHandlers() as $handler) {
      $type = $handler->getType();
      if (isset($expanded[$type])) {
        $ordered[] = $type;
      }
    }
    return $ordered;
  }

  private function normaliseTypeFilter(array $typeFilter): array {
    $typeFilter = array_values(array_unique(array_filter(array_map('strval', $typeFilter))));
    if (!$typeFilter) {
      return [];
    }

    $valid = [];
    foreach ($this->getManagedTypeOptions() as $row) {
      $valid[(string) $row['type']] = TRUE;
    }

    return array_values(array_filter($typeFilter, fn($type) => isset($valid[$type])));
  }

  private function baseTypesFromFilter(array $typeFilter): array {
    $base = [];
    $map = [];
    foreach ($this->getManagedTypeOptions() as $row) {
      $map[(string) $row['type']] = (string) ($row['base_type'] ?? $row['type']);
    }
    foreach (array_values(array_unique(array_filter(array_map('strval', $typeFilter)))) as $type) {
      if (isset($map[$type])) {
        $base[$map[$type]] = TRUE;
      }
    }
    return array_keys($base);
  }

  private function prepareHandlerForTypeFilter($handler, array $typeFilter): void {
    if (method_exists($handler, 'setRuntimeTypeFilters')) {
      $handler->setRuntimeTypeFilters($typeFilter);
    }
  }

  private function applyHandlerFileFilter($handler, array $files): array {
    if (method_exists($handler, 'filterYamlFilesByRuntimeFilters')) {
      return $handler->filterYamlFilesByRuntimeFilters($files);
    }
    return $files;
  }

  private function getExportRelatedTypeMap(): array {
    return [
      // A SearchKit saved search is normally deployed with its displays, and
      // FormBuilder afforms may embed those displays. Export the set together.
      'searchkit-saved-searches' => ['searchkit-displays', 'formbuilder-afforms'],
      'searchkit-displays' => ['searchkit-saved-searches', 'formbuilder-afforms'],
      'formbuilder-afforms' => ['searchkit-displays', 'searchkit-saved-searches'],

      // Custom fields can depend on option groups and the contact type scope.
      'custom-data' => ['option-groups', 'contact-types', 'site-tokens'],

      // Extension-owned config is bundled under each extension file.
      'extensions' => ['message-templates', 'contact-types', 'custom-data', 'option-groups'],

      // Relationship types can depend on contact/sub-contact types.
      'relationship-types' => ['contact-types'],
      'civirules' => ['extensions'],
      'site-tokens' => ['extensions'],
    ];
  }

  /**
   * Return the effective high-level scope state without exporting any records.
   *
   * Selected mode only counts as managed when at least one selector is present.
   * This prevents an empty selected scope, all-Ignore scope, or watch-only scope
   * from being presented as a successful managed YAML synchronization.
   */
  public function getScopeSetupState(): array {
    $managed = FALSE;
    $watched = FALSE;

    foreach ($this->getScopeTypeOptions() as $row) {
      $type = trim((string) ($row['type'] ?? ''));
      if ($type === '') {
        continue;
      }

      $policy = $this->scope->getPolicy($type);
      $mode = (string) ($policy['mode'] ?? ConfigScope::MODE_ALL);
      if ($mode === ConfigScope::MODE_ALL) {
        $managed = TRUE;
      }
      elseif ($mode === ConfigScope::MODE_SELECTED) {
        if (!empty($policy['selectors'])) {
          $managed = TRUE;
        }
        if (!empty($policy['watch_unmanaged'])) {
          $watched = TRUE;
        }
      }
      elseif ($mode === ConfigScope::MODE_WATCH) {
        $watched = TRUE;
      }

      if ($managed && $watched) {
        break;
      }
    }

    return [
      'managed' => $managed,
      'watched' => $watched,
      'watch_only' => !$managed && $watched,
      'setup_required' => !$managed && !$watched,
    ];
  }

  public function hasManagedScopeConfigured(): bool {
    return !empty($this->getScopeSetupState()['managed']);
  }

  public function isInitialExportRequired(): bool {
    return $this->hasManagedScopeConfigured() && !$this->hasManagedYamlFiles($this->getSyncDir());
  }

  /**
   * Cheap current-format marker check for non-Synchronize UI/status rendering.
   */
  public function hasCurrentManifest(): bool {
    return is_file(rtrim($this->getSyncDir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'manifest.yml');
  }

  private function readManifest(YamlFileStorage $storage): array {
    try {
      return $storage->readFile('manifest.yml');
    }
    catch (\Throwable $e) {
      return [];
    }
  }

  private function scopePartition($handler, array $files, YamlFileStorage $storage, bool $forExport = FALSE): array {
    $manifest = $this->readManifest($storage);
    $type = (string) $handler->getType();
    $selectorMap = $this->scope->portableSelectorMapFromManifest($manifest, $type);
    return $this->scope->partition($type, $this->attachScopeRelativePaths($handler, $files), $forExport, $selectorMap);
  }

  private function attachScopeRelativePaths($handler, array $files): array {
    $directory = trim((string) $handler->getDirectory(), '/');
    foreach ($files as &$file) {
      if (!is_array($file) || empty($file['filename'])) {
        continue;
      }
      $file['relative_path'] = ($directory !== '' ? $directory . '/' : '') . ltrim((string) $file['filename'], '/');
    }
    unset($file);
    return $files;
  }

  private function filterYamlByScope($handler, array $files, array $partition, YamlFileStorage $storage): array {
    $keys = array_map('strval', (array) ($partition['managed_config_keys'] ?? []));
    return $this->scope->filterYamlFiles((string) $handler->getType(), $files, $keys);
  }

  /**
   * Load only YAML that belongs to the effective managed scope.
   *
   * Selected scope is portable through manifest config keys. It deliberately
   * does not fall back to "all YAML" when the manifest lacks selected keys,
   * because that could turn an incomplete scope definition into an unsafe
   * bulk import.
   */
  private function loadManagedYamlFiles($handler, YamlFileStorage $storage): array {
    $files = $this->filterIgnoredFiles($handler->getDirectory(), $storage->readDirectory($handler->getDirectory()));
    $files = $this->applyHandlerFileFilter($handler, $files);
    $files = $this->filterIgnoredValuesInFiles($handler->getDirectory(), $files);
    $policy = $this->scope->getPolicy((string) $handler->getType());
    if (($policy['mode'] ?? ConfigScope::MODE_ALL) === ConfigScope::MODE_ALL) {
      return $files;
    }
    if (($policy['mode'] ?? '') !== ConfigScope::MODE_SELECTED) {
      return [];
    }
    $manifest = $this->readManifest($storage);
    $selectorMap = $this->scope->portableSelectorMapFromManifest($manifest, (string) $handler->getType());
    $exportLikeFiles = [];
    foreach ($files as $filename => $data) {
      $exportLikeFiles[] = [
        'filename' => (string) $filename,
        'relative_path' => trim((string) $handler->getDirectory(), '/') . '/' . ltrim((string) $filename, '/'),
        'data' => (array) $data,
      ];
    }
    $partition = $this->scope->partition((string) $handler->getType(), $exportLikeFiles, FALSE, $selectorMap);
    return $this->scope->filterYamlFiles(
      (string) $handler->getType(),
      $files,
      array_map('strval', (array) ($partition['managed_config_keys'] ?? []))
    );
  }

  public function export(bool $dryRun = TRUE, array $typeFilter = []): array {
    $storage = new YamlFileStorage($this->getSyncDir());
    $requestedTypes = $this->normaliseTypeFilter($typeFilter);
    $effectiveTypes = $this->getEffectiveExportTypeFilter($requestedTypes);
    $dependencyTypes = $requestedTypes ? array_values(array_diff($effectiveTypes, $requestedTypes)) : [];
    $summary = [
      'ok' => TRUE,
      'dry_run' => $dryRun,
      'sync_dir' => $storage->getRoot(),
      'requested_types' => $requestedTypes,
      'effective_types' => $effectiveTypes,
      'dependency_types' => $dependencyTypes,
      'written' => [],
      'deleted' => [],
      'planned' => [],
      'delete_planned' => [],
      'available' => [],
      'skipped' => [],
      'warnings' => [],
      'errors' => [],
      'message' => NULL,
    ];

    $queue = [];
    $successfulHandlers = [];
    $scopeManifestUpdates = [];

    // Keep scope modes that do not depend on resolved selected-item keys
    // explicit in manifest.yml before handler export. This prevents stale
    // metadata (for example, extensions: ignore) from surviving after an
    // administrator saves Manage everything, even if that handler later
    // reports a provider-specific export error. Selected mode is still written
    // only after a successful partition because it needs resolved portable keys.
    foreach ($this->getScopeTypeOptions() as $scopeType) {
      $type = (string) ($scopeType['type'] ?? '');
      if ($type === '') {
        continue;
      }
      $policy = $this->scope->getPolicy($type);
      if (!in_array((string) ($policy['mode'] ?? ''), [ConfigScope::MODE_ALL, ConfigScope::MODE_WATCH, ConfigScope::MODE_IGNORE], TRUE)) {
        continue;
      }
      $scopeManifestUpdates[$type] = $this->scope->manifestEntry($type, ['policy' => $policy]);
    }

    foreach ($this->getHandlers() as $handler) {
      if ($effectiveTypes && !in_array($handler->getType(), $effectiveTypes, TRUE)) {
        continue;
      }
      $this->prepareHandlerForTypeFilter($handler, $requestedTypes);
      try {
        $exported = $handler->export();
        $handlerExportErrors = $this->consumeHandlerExportErrors($handler, $summary['errors']);
        $partition = $this->scopePartition($handler, $exported, $storage, TRUE);
        $handlerType = (string) $handler->getType();
        $handlerPolicy = $this->scope->getPolicy($handlerType);
        if ($handlerExportErrors === 0 || (string) ($handlerPolicy['mode'] ?? '') !== ConfigScope::MODE_SELECTED) {
          $scopeManifestUpdates[$handlerType] = $this->scope->manifestEntry($handlerType, $partition);
        }
        if (!$dryRun && $handlerExportErrors === 0) {
          $this->scope->persistResolvedMatches($handlerType, $partition);
        }
        foreach ((array) ($partition['unresolved_selectors'] ?? []) as $selector) {
          $summary['warnings'][] = [
            'type' => $handler->getType(),
            'message' => 'Configured scope selector has never resolved to an active CiviCRM object: ' . (string) $selector . '.',
          ];
        }
        foreach ((array) ($partition['missing_selectors'] ?? []) as $selector) {
          $summary['warnings'][] = [
            'type' => $handler->getType(),
            'message' => 'Configured managed object is currently missing from CiviCRM: ' . (string) $selector . '. Existing YAML backup is preserved for review or restore.',
          ];
        }
        foreach ((array) ($partition['managed'] ?? []) as $file) {
          $relative = trim($handler->getDirectory(), '/') . '/' . $file['filename'];
          if ($this->isIgnoredPath($relative)) {
            $summary['skipped'][] = $relative . ' (ignored)';
            continue;
          }
          $queue[] = [
            'type' => $handler->getType(),
            'label' => $handler->getLabel(),
            'directory' => $handler->getDirectory(),
            'filename' => $file['filename'],
            'relative' => $relative,
            'data' => $this->applyIgnoredValueRules($relative, (array) ($file['data'] ?? [])),
          ];
        }
        // A handler can return useful partial backup files while reporting that
        // one contributed provider could not be read. Keep those files, but do
        // not authorize stale-file deletion or baseline acceptance for that
        // incomplete handler.
        if ($handlerExportErrors === 0) {
          $successfulHandlers[] = $handler;
        }
      }
      catch (\Throwable $e) {
        $summary['errors'][] = [
          'type' => $handler->getType(),
          'message' => $e->getMessage(),
        ];
      }
    }

    $queue = $this->pruneExtensionIndexesForIgnoredOrFilteredConfig($queue);
    $queue = $this->addReverseDependencyMetadataToExportQueue($queue);
    foreach ($queue as $file) {
      $summary['available'][] = [
        'type' => (string) ($file['type'] ?? ''),
        'label' => (string) ($file['label'] ?? $file['type'] ?? ''),
        'directory' => (string) ($file['directory'] ?? ''),
        'file' => (string) ($file['filename'] ?? ''),
        'path' => (string) ($file['relative'] ?? ''),
      ];
    }

    if (!$dryRun) {
      $existingManifest = $this->readManifest($storage);
      $existingScope = (array) ($existingManifest['managed_scope'] ?? []);
      foreach ($scopeManifestUpdates as $type => $scopeEntry) {
        $existingScope[$type] = $scopeEntry;
      }
      ksort($existingScope, SORT_NATURAL | SORT_FLAG_CASE);
      $manifest = $this->getManifestData($existingScope);
      if (!$storage->isSame('', 'manifest.yml', $manifest)) {
        $summary['written'][] = $storage->write('', 'manifest.yml', $manifest);
      }
      else {
        $summary['skipped'][] = 'manifest.yml';
      }
    }

    foreach ($this->findStaleYamlFilesForExport($storage, $successfulHandlers, $queue) as $staleFile) {
      if ($dryRun) {
        $summary['delete_planned'][] = (string) $staleFile['relative'];
        $summary['planned'][] = (string) $staleFile['relative'] . ' (delete stale YAML)';
      }
      else {
        $summary['deleted'][] = $storage->delete((string) $staleFile['directory'], (string) $staleFile['filename']);
      }
    }

    foreach ($queue as $file) {
      $data = $this->applyIgnoredValueRules((string) $file['relative'], (array) $file['data']);
      $isSame = $storage->isSame((string) $file['directory'], (string) $file['filename'], $data);
      if ($dryRun) {
        if (!$isSame) {
          $summary['planned'][] = (string) $file['relative'];
        }
        else {
          $summary['skipped'][] = (string) $file['relative'];
        }
      }
      else {
        if ($isSame) {
          $summary['skipped'][] = (string) $file['relative'];
        }
        else {
          $summary['written'][] = $storage->write((string) $file['directory'], (string) $file['filename'], $data);
        }
      }
    }

    if (!$dryRun && empty($summary['errors'])) {
      try {
        $stateManager = new ConfigStateManager();
        foreach ($successfulHandlers as $handler) {
          $exportedForHandler = [];
          foreach ($queue as $file) {
            if (($file['type'] ?? '') !== $handler->getType()) {
              continue;
            }
            $exportedForHandler[] = [
              'filename' => (string) $file['filename'],
              'data' => (array) $file['data'],
            ];
          }
          $stateManager->acceptExportedBaseline($handler, $exportedForHandler, 'export');
        }
      }
      catch (\Throwable $e) {
        $summary['warnings'][] = ['type' => 'state', 'message' => 'YAML export succeeded, but local baseline state could not be updated: ' . $e->getMessage()];
      }
    }

    $summary['ok'] = empty($summary['errors']);
    if ($dryRun && !$summary['planned'] && !$summary['errors']) {
      $summary['message'] = 'No export changes. YAML files already match the active database configuration.';
    }
    elseif (!$dryRun && !$summary['written'] && !$summary['deleted'] && !$summary['errors']) {
      $summary['message'] = 'No files written. YAML files already match the active database configuration.';
    }
    return $summary;
  }

  /**
   * Return one currently managed active export item.
   *
   * This is used by the single-file preview/download endpoints so a crafted
   * request cannot bypass selected/watch/ignore scope.
   */
  public function getManagedActiveExportFile(string $type, string $filename): array {
    $storage = new YamlFileStorage($this->getSyncDir());
    foreach ($this->getHandlers() as $handler) {
      if ((string) $handler->getType() !== $type) {
        continue;
      }
      $partition = $this->scopePartition($handler, $handler->export(), $storage, TRUE);
      foreach ((array) ($partition['managed'] ?? []) as $file) {
        $file = (array) $file;
        if ((string) ($file['filename'] ?? '') !== $filename) {
          continue;
        }
        $relative = trim((string) $handler->getDirectory(), '/') . '/' . $filename;
        if ($this->isIgnoredPath($relative)) {
          throw new \RuntimeException('Selected export item is ignored by Config Ignore: ' . $relative);
        }
        return [
          'type' => $type,
          'label' => (string) $handler->getLabel(),
          'directory' => (string) $handler->getDirectory(),
          'filename' => $filename,
          'relative' => $relative,
          'data' => $this->applyIgnoredValueRules($relative, (array) ($file['data'] ?? [])),
        ];
      }
      throw new \RuntimeException('Selected export item is outside the current managed scope or was not found: ' . $filename);
    }
    throw new \RuntimeException('Unknown or unmanaged configuration type: ' . $type);
  }

  /**
   * Read the current portable YAML set for ZIP download without scanning active
   * CiviCRM. Stale backups from deselected/watch/ignored scope stay on disk for
   * safety but are not part of the managed archive.
   *
   * @return array<string,array<string,mixed>> Relative path => YAML document.
   */
  public function getManagedYamlArchiveFiles(): array {
    $storage = new YamlFileStorage($this->getSyncDir());
    $files = [];
    $manifest = $this->readManifest($storage);
    if ($manifest) {
      $files['manifest.yml'] = $manifest;
    }
    foreach ($this->getHandlers() as $handler) {
      foreach ($this->loadManagedYamlFiles($handler, $storage) as $filename => $data) {
        $relative = trim((string) $handler->getDirectory(), '/') . '/' . ltrim((string) $filename, '/');
        if ($relative !== '' && !$this->isIgnoredPath($relative)) {
          $files[$relative] = (array) $data;
        }
      }
    }
    ksort($files, SORT_NATURAL | SORT_FLAG_CASE);
    return $files;
  }

  private function findStaleYamlFilesForExport(YamlFileStorage $storage, array $handlers, array $queue): array {
    $stale = [];
    $exportedByType = [];
    foreach ($queue as $file) {
      $type = (string) ($file['type'] ?? '');
      $filename = (string) ($file['filename'] ?? '');
      if ($type === '' || $filename === '') {
        continue;
      }
      $exportedByType[$type][] = [
        'filename' => $filename,
        'data' => (array) ($file['data'] ?? []),
      ];
    }

    foreach ($handlers as $handler) {
      if (!$this->scope->allowsDeleteMissing((string) $handler->getType())) {
        continue;
      }
      $directory = trim((string) $handler->getDirectory(), '/');
      $files = $this->filterIgnoredFiles($handler->getDirectory(), $storage->readDirectory($handler->getDirectory()));
      $files = $this->applyHandlerFileFilter($handler, $files);
      $files = $this->filterIgnoredValuesInFiles($handler->getDirectory(), $files);
      $exported = $exportedByType[$handler->getType()] ?? [];
      $diff = $handler->diffFromExports($exported, $files);
      foreach ((array) ($diff['missing_in_db'] ?? []) as $filename) {
        $filename = (string) $filename;
        if ($filename === '') {
          continue;
        }
        $relative = $directory === '' ? $filename : $directory . '/' . $filename;
        if ($this->isIgnoredPath($relative)) {
          continue;
        }
        $stale[$relative] = [
          'directory' => $handler->getDirectory(),
          'filename' => $filename,
          'relative' => $relative,
        ];
      }
      foreach ((array) ($diff['renamed'] ?? []) as $rename) {
        $filename = (string) ($rename['from'] ?? '');
        $targetFilename = (string) ($rename['to'] ?? '');
        if ($filename === '' || $targetFilename === '' || $filename === $targetFilename) {
          continue;
        }
        $relative = $directory === '' ? $filename : $directory . '/' . $filename;
        if ($this->isIgnoredPath($relative)) {
          continue;
        }
        $stale[$relative] = [
          'directory' => $handler->getDirectory(),
          'filename' => $filename,
          'relative' => $relative,
        ];
      }
    }
    ksort($stale);
    return array_values($stale);
  }


  private function pruneExtensionIndexesForIgnoredOrFilteredConfig(array $queue): array {
    $counts = [];
    foreach ($queue as $file) {
      $data = (array) ($file['data'] ?? []);
      if (($data['type'] ?? '') !== 'extension_config.item') {
        continue;
      }
      $extension = (string) ($data['extension'] ?? '');
      $api = (string) ($data['api'] ?? '');
      $entity = (string) ($data['entity'] ?? '');
      if ($extension === '' || $api === '' || $entity === '') {
        continue;
      }
      $key = $api . ':' . $entity;
      $counts[$extension][$key] = ($counts[$extension][$key] ?? 0) + 1;
    }

    foreach ($queue as &$file) {
      $data = (array) ($file['data'] ?? []);
      if (($data['type'] ?? '') !== 'extension.item' || empty($data['config_index']) || !is_array($data['config_index'])) {
        continue;
      }
      $extensionData = (array) ($data['extension'] ?? []);
      $extensionKey = (string) ($extensionData['key'] ?? ($data['key'] ?? ''));
      $filteredIndex = [];
      foreach ((array) $data['config_index'] as $row) {
        $row = (array) $row;
        $api = (string) ($row['api'] ?? '');
        $entity = (string) ($row['entity'] ?? '');
        $key = $api . ':' . $entity;
        $count = (int) ($counts[$extensionKey][$key] ?? 0);
        if ($api === '' || $entity === '' || $count <= 0) {
          continue;
        }
        $row['count'] = $count;
        $filteredIndex[] = $row;
      }
      if ($filteredIndex) {
        $file['data']['config_index'] = $filteredIndex;
      }
      else {
        unset($file['data']['config_index']);
      }
    }
    unset($file);
    return $queue;
  }

  private function addReverseDependencyMetadataToExportQueue(array $queue): array {
    $index = [];
    foreach ($queue as $i => $file) {
      foreach ($this->namesFromYamlFile((array) ($file['data'] ?? [])) as $name) {
        $index[(string) $file['type']][(string) $name][] = $i;
      }
    }

    foreach ($queue as $i => $file) {
      $sourceData = (array) ($file['data'] ?? []);
      $sourceNames = $this->namesFromYamlFile($sourceData);
      $sourceName = $sourceNames[0] ?? (string) ($file['relative'] ?? '');
      foreach ($this->extractDependenciesFromYamlFile($sourceData) as $dependency) {
        $dependencyType = (string) ($dependency['type'] ?? '');
        $dependencyName = (string) ($dependency['name'] ?? '');
        if ($dependencyType === '' || $dependencyName === '' || empty($index[$dependencyType][$dependencyName])) {
          continue;
        }
        foreach ($index[$dependencyType][$dependencyName] as $targetIndex) {
          if ($targetIndex === $i) {
            continue;
          }
          $queue[$targetIndex]['data']['required_by'][] = [
            'type' => (string) ($file['type'] ?? ''),
            'name' => (string) $sourceName,
            'path' => (string) ($file['relative'] ?? ''),
            'reason' => (string) ($dependency['reason'] ?? 'This YAML item depends on this configuration.'),
          ];
        }
      }
    }

    foreach ($queue as &$file) {
      if (!empty($file['data']['required_by']) && is_array($file['data']['required_by'])) {
        $file['data']['required_by'] = $this->uniqueDependencyLikeRows((array) $file['data']['required_by']);
      }
    }
    unset($file);
    return $queue;
  }

  private function uniqueDependencyLikeRows(array $rows): array {
    $seen = [];
    $unique = [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      ksort($row);
      $key = json_encode($row);
      if ($key === FALSE || isset($seen[$key])) {
        continue;
      }
      $seen[$key] = TRUE;
      $unique[] = $row;
    }
    return $unique;
  }

  public function diff(array $typeFilter = []): array {
    $storage = new YamlFileStorage($this->getSyncDir());
    $result = ['ok' => TRUE, 'sync_dir' => $storage->getRoot(), 'items' => [], 'errors' => []];
    $scopeState = $this->getScopeSetupState();
    if (empty($scopeState['managed'])) {
      $result['no_managed_scope'] = TRUE;
      $result['watch_only'] = !empty($scopeState['watch_only']);
      $result['setup_required'] = !empty($scopeState['setup_required']);
      $result['message'] = !empty($scopeState['watch_only'])
        ? 'Watch-only configuration is enabled, but no configuration is currently managed in YAML.'
        : 'Configuration scope setup is required before managed YAML synchronization can begin.';
      $this->cacheHealthFromDiff($result);
      return $result;
    }

    // Before the first managed export there is no useful YAML comparison. Do
    // not scan the whole CiviCRM installation simply to report every active
    // record as "Only in CiviCRM".
    if ($this->isInitialExportRequired()) {
      $result['initial_export_required'] = TRUE;
      $result['message'] = 'Initial YAML export required before configuration differences can be calculated.';
      $this->cacheHealthFromDiff($result);
      return $result;
    }

    $normalisedFilter = $this->normaliseTypeFilter($typeFilter);
    $baseFilter = $this->baseTypesFromFilter($normalisedFilter);
    $stateManager = NULL;
    try {
      $stateManager = new ConfigStateManager();
      if (!$typeFilter) {
        // A full managed scan replaces the rebuildable object-state index.
        // Watch-only state lives in its own table and is not cleared here.
        $stateManager->rebuildObjectState();
      }
    }
    catch (\Throwable $e) {
      $result['state_warning'] = 'Configuration diff is available, but local state/baseline tracking could not be initialized: ' . $e->getMessage();
      $stateManager = NULL;
    }

    foreach ($this->getHandlers() as $handler) {
      if ($baseFilter && !in_array($handler->getType(), $baseFilter, TRUE)) {
        continue;
      }
      $this->prepareHandlerForTypeFilter($handler, $normalisedFilter);
      try {
        $files = $this->filterIgnoredFiles($handler->getDirectory(), $storage->readDirectory($handler->getDirectory()));
        $files = $this->applyHandlerFileFilter($handler, $files);
        $files = $this->filterIgnoredValuesInFiles($handler->getDirectory(), $files);

        $activeFiles = $handler->export();
        $this->consumeHandlerExportErrors($handler, $result['errors']);
        $partition = $this->scopePartition($handler, $activeFiles, $storage, FALSE);
        $exported = $this->filterIgnoredValuesInExportFiles($handler->getDirectory(), (array) ($partition['managed'] ?? []));
        $files = $this->filterYamlByScope($handler, $files, $partition, $storage);

        $item = $handler->diffFromExports($exported, $files);
        if (!empty($partition['unresolved_selectors'])) {
          $item['scope_warnings'] = array_values(array_map(static function($selector) {
            return 'Configured managed selector is not present in active CiviCRM: ' . (string) $selector . '. YAML backup remains managed.';
          }, (array) $partition['unresolved_selectors']));
        }
        if ($stateManager !== NULL) {
          try {
            $item = $stateManager->enrichDiff($handler, $exported, $files, $item);
          }
          catch (\Throwable $e) {
            $result['state_warning'] = 'Configuration diff succeeded, but local state/baseline tracking could not be updated: ' . $e->getMessage();
            $stateManager = NULL;
          }
        }
        $item = $this->filterIgnoredDiffItem($item, $handler->getDirectory());
        if (($item['status'] ?? '') !== 'in_sync' || !empty($item['files']) || !empty($item['scope_warnings'])) {
          $result['items'][] = $item;
        }
      }
      catch (\Throwable $e) {
        $result['errors'][] = ['type' => $handler->getType(), 'message' => $e->getMessage()];
      }
    }
    $result['ok'] = empty($result['errors']);
    $this->cacheHealthFromDiff($result);
    return $result;
  }

  /**
   * Collect non-fatal handler export errors while allowing safe partial files.
   *
   * ExtensionHandler uses this for contributed providers where one provider
   * may be unreadable while extension status YAML remains safe to export.
   */
  private function consumeHandlerExportErrors($handler, array &$errors): int {
    if (!method_exists($handler, 'consumeExportErrors')) {
      return 0;
    }

    try {
      $messages = (array) $handler->consumeExportErrors();
    }
    catch (\Throwable $e) {
      $messages = ['Could not read handler export diagnostics: ' . $e->getMessage()];
    }

    $count = 0;
    foreach ($messages as $message) {
      $message = trim((string) $message);
      if ($message === '') {
        continue;
      }
      $errors[] = [
        'type' => (string) $handler->getType(),
        'message' => $message,
      ];
      $count++;
    }
    return $count;
  }

  public function validate(array $typeFilter = []): array {
    // Active dependency lookups are cached only for one validation pass. A
    // long-lived ConfigManager instance may have applied configuration since a
    // previous validation, so never reuse target-state fingerprints here.
    $this->activeDependencyNamesCache = [];
    $storage = new YamlFileStorage($this->getSyncDir());
    $result = ['ok' => TRUE, 'sync_dir' => $storage->getRoot(), 'items' => [], 'errors' => []];
    $yamlByType = [];
    $normalisedFilter = $this->normaliseTypeFilter($typeFilter);
    $baseFilter = $this->baseTypesFromFilter($normalisedFilter);

    foreach ($this->getHandlers() as $handler) {
      if ($baseFilter && !in_array($handler->getType(), $baseFilter, TRUE)) {
        continue;
      }
      $this->prepareHandlerForTypeFilter($handler, $normalisedFilter);
      try {
        $files = $this->loadManagedYamlFiles($handler, $storage);
        $yamlByType[$handler->getType()] = $files;
        $validation = $handler->validate($files);
        $result['items'][] = $validation;
        if (empty($validation['valid'])) {
          $result['ok'] = FALSE;
        }
      }
      catch (\Throwable $e) {
        $result['errors'][] = ['type' => $handler->getType(), 'message' => $e->getMessage()];
      }
    }
    $this->addDependencyWarnings($result, $yamlByType);
    try {
      $this->addManifestValidation($result, $storage->readFile('manifest.yml'));
    }
    catch (\Throwable $e) {
      $result['errors'][] = ['type' => 'manifest', 'message' => $e->getMessage()];
    }
    $result['ok'] = $result['ok'] && empty($result['errors']);
    return $result;
  }

  private function addDependencyWarnings(array &$result, array $yamlByType): void {
    $available = $this->collectManagedYamlNames($yamlByType);
    $registeredTypes = [];
    foreach ($this->getAllHandlers() as $handler) {
      $registeredTypes[$handler->getType()] = $handler->getLabel();
    }
    $managedTypes = [];
    foreach ($this->getHandlers() as $handler) {
      $managedTypes[$handler->getType()] = $handler->getLabel();
    }
    $itemIndex = [];
    foreach ($result['items'] as $index => $item) {
      if (!empty($item['type'])) {
        $itemIndex[(string) $item['type']] = $index;
      }
    }

    foreach ($yamlByType as $type => $files) {
      if (!isset($itemIndex[$type])) {
        continue;
      }
      foreach ($files as $filename => $file) {
        foreach ($this->extractDependenciesFromYamlFile((array) $file) as $dependency) {
          $dependencyType = (string) ($dependency['type'] ?? '');
          $dependencyName = (string) ($dependency['name'] ?? '');
          if ($dependencyType === '' || $dependencyName === '') {
            continue;
          }
          if (!isset($registeredTypes[$dependencyType])) {
            // Non-managed runtime dependencies such as api-entity are informational.
            continue;
          }
          if (isset($available[$dependencyType][$dependencyName])) {
            continue;
          }

          // Selective configuration is allowed to depend on existing target
          // configuration that is intentionally outside the managed YAML set.
          // This is safe because handlers resolve these references by stable
          // semantic names during import. Only block when the dependency is
          // absent from both the import bundle and active CiviCRM.
          if ($this->activeDependencyExists($dependencyType, $dependencyName)) {
            continue;
          }

          $result['ok'] = FALSE;
          $reason = (string) ($dependency['reason'] ?? 'This YAML item references another managed config item.');
          $ignoredHint = $this->ignoredDependencyHint($dependencyType, $dependencyName);
          $message = $this->formatMissingDependencyMessage($filename, $type, $dependencyType, $dependencyName, $reason, $ignoredHint);
          $result['items'][$itemIndex[$type]]['errors'][] = [
            'file' => $filename,
            'message' => $message,
          ];
        }
        foreach ($this->extractRequiredByFromYamlFile((array) $file) as $requiredBy) {
          $requiredByType = (string) ($requiredBy['type'] ?? '');
          $requiredByName = (string) ($requiredBy['name'] ?? '');
          if ($requiredByType === '' || $requiredByName === '' || !isset($managedTypes[$requiredByType])) {
            continue;
          }
          if (!isset($available[$requiredByType][$requiredByName])) {
            $result['items'][$itemIndex[$type]]['warnings'][] = [
              'file' => $filename,
              'message' => sprintf('Reverse dependency metadata says this item is required by %s "%s", but that YAML item is not present. This is usually stale metadata or a filtered/ignored dependency; re-export the related items together before relying on this dependency graph.', $requiredByType, $requiredByName),
            ];
          }
        }
      }
    }
  }


  private function activeDependencyExists(string $type, string $name): bool {
    if (!array_key_exists($type, $this->activeDependencyNamesCache)) {
      $names = [];
      foreach ($this->getAllHandlers() as $handler) {
        if ((string) $handler->getType() !== $type) {
          continue;
        }
        try {
          foreach ($handler->export() as $file) {
            $file = (array) $file;
            foreach ($this->namesFromYamlFile((array) ($file['data'] ?? [])) as $activeName) {
              $names[(string) $activeName] = TRUE;
            }
          }
        }
        catch (\Throwable $e) {
          // Fail closed: if active state cannot be verified, validation should
          // continue to report the missing portable dependency below.
          $names = [];
        }
        break;
      }
      $this->activeDependencyNamesCache[$type] = $names;
    }
    return isset($this->activeDependencyNamesCache[$type][$name]);
  }

  private function formatMissingDependencyMessage(string $filename, string $ownerType, string $dependencyType, string $dependencyName, string $reason, string $ignoredHint = ''): string {
    $prefix = sprintf('Cannot import %s/%s: dependency %s "%s" is not available in the managed YAML set or active CiviCRM.', $ownerType, $filename, $dependencyType, $dependencyName);
    if ($dependencyType === 'contact-types' && preg_match('/^[0-9]+$/', $dependencyName)) {
      $prefix .= ' The dependency name is numeric, which usually means this YAML was exported by an older alpha using a local database ID instead of the Contact Type machine name.';
      $prefix .= ' Re-export Custom Groups and Fields together with Contact Types using the current build, or update the YAML dependency to the stable contact type name before importing.';
    }
    else {
      $prefix .= ' ' . $reason . ' Re-export the related items together, or restore the missing YAML file before importing.';
    }
    if ($ignoredHint !== '') {
      $prefix .= ' The dependency appears to be hidden by Config Ignore: ' . $ignoredHint . '. Remove or narrow that ignore rule before importing this item.';
    }
    return $prefix;
  }

  private function ignoredDependencyHint(string $type, string $name): string {
    foreach ($this->dependencyCandidatePaths($type, $name) as $path) {
      if ($this->isIgnoredPath($path)) {
        return $path;
      }
    }
    return '';
  }

  private function dependencyCandidatePaths(string $type, string $name): array {
    $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name);
    $safe = trim((string) $safe, '-') ?: sha1($name);
    $map = [
      'extensions' => ['extensions/' . $safe . '.yml'],
      'searchkit-saved-searches' => ['searchkit/saved-searches/' . $safe . '.yml'],
      'searchkit-displays' => ['searchkit/displays/' . $safe . '.yml', 'searchkit/displays/*__' . $safe . '.yml'],
      'formbuilder-afforms' => ['formbuilder/afforms/' . $safe . '.yml'],
      'scheduled-jobs' => ['scheduled-jobs/' . $safe . '.yml'],
      'site-tokens' => ['site-tokens/' . $safe . '.yml'],
      'civirules' => ['civirules/' . $safe . '.yml', 'civirules/*/' . $safe . '.yml'],
    ];
    return $map[$type] ?? [$type . '/' . $safe . '.yml'];
  }

  private function collectManagedYamlNames(array $yamlByType): array {
    $available = [];
    foreach ($yamlByType as $type => $files) {
      foreach ($files as $file) {
        $file = (array) $file;
        foreach ($this->namesFromYamlFile($file) as $name) {
          $available[$type][(string) $name] = TRUE;
        }
      }
    }
    return $available;
  }

  private function namesFromYamlFile(array $file): array {
    $names = [];
    if (!empty($file['name'])) {
      $names[] = (string) $file['name'];
    }
    if (!empty($file['key'])) {
      $names[] = (string) $file['key'];
    }
    if (!empty($file['extension']) && is_string($file['extension'])) {
      $names[] = (string) $file['extension'];
    }
    if (!empty($file['extension']) && is_array($file['extension']) && !empty($file['extension']['key'])) {
      $names[] = (string) $file['extension']['key'];
    }
    if (!empty($file['item']) && is_array($file['item'])) {
      foreach (['name', 'title', 'label', 'name_a_b'] as $key) {
        if (!empty($file['item'][$key])) {
          $names[] = (string) $file['item'][$key];
        }
      }
    }
    foreach (($file['items'] ?? []) as $row) {
      if (is_array($row)) {
        foreach (['name', 'title', 'label', 'name_a_b'] as $key) {
          if (!empty($row[$key])) {
            $names[] = (string) $row[$key];
          }
        }
      }
    }
    return array_values(array_unique($names));
  }

  private function extractDependenciesFromYamlFile(array $file): array {
    $dependencies = [];
    foreach (($file['dependencies'] ?? []) as $dependency) {
      if (is_array($dependency)) {
        $dependencies[] = $dependency;
      }
    }
    foreach (($file['item']['dependencies'] ?? []) as $dependency) {
      if (is_array($dependency)) {
        $dependencies[] = $dependency;
      }
    }
    return $dependencies;
  }


  private function extractRequiredByFromYamlFile(array $file): array {
    $requiredBy = [];
    foreach (($file['required_by'] ?? []) as $row) {
      if (is_array($row)) {
        $requiredBy[] = $row;
      }
    }
    foreach (($file['item']['required_by'] ?? []) as $row) {
      if (is_array($row)) {
        $requiredBy[] = $row;
      }
    }
    return $requiredBy;
  }

  /**
   * Preserve virtual provider filters while validating imports.
   *
   * A virtual type such as extensions:de.systopia.sqltasks:api3:Sqltask
   * selects one contributed provider inside the Extensions handler. Replacing
   * it with the base `extensions` type would make validation inspect every
   * contributed provider YAML file and allow an unrelated provider failure to
   * block the requested import.
   */
  private function getImportValidationTypeFilter(array $requestedTypes, array $effectiveTypes): array {
    if (!$requestedTypes) {
      return [];
    }
    if ($this->isExtensionSubtypeOnlyFilter($requestedTypes)) {
      return $requestedTypes;
    }
    return array_values(array_unique(array_merge($effectiveTypes, $requestedTypes)));
  }

  /**
   * A provider-subtype-only import applies only the Extensions handler.
   *
   * Related-type expansion is useful for normal grouped imports, but a user
   * explicitly selecting one contributed extension provider must not also
   * apply unrelated Message Templates, Custom Data, Contact Types, etc.
   */
  private function getImportApplyTypeFilter(array $requestedTypes, array $effectiveTypes): array {
    if ($this->isExtensionSubtypeOnlyFilter($requestedTypes)) {
      return ['extensions'];
    }
    return $effectiveTypes;
  }

  private function isExtensionSubtypeOnlyFilter(array $requestedTypes): bool {
    if (!$requestedTypes) {
      return FALSE;
    }
    foreach ($requestedTypes as $type) {
      if (strpos((string) $type, 'extensions:') !== 0) {
        return FALSE;
      }
    }
    return TRUE;
  }

  public function import(bool $dryRun = TRUE, bool $yes = FALSE, array $typeFilter = []): array {
    $storage = new YamlFileStorage($this->getSyncDir());
    $requestedTypes = $this->normaliseTypeFilter($typeFilter);
    $effectiveTypes = $this->getEffectiveExportTypeFilter($requestedTypes);
    $validationTypes = $this->getImportValidationTypeFilter($requestedTypes, $effectiveTypes);
    $applyTypes = $this->getImportApplyTypeFilter($requestedTypes, $effectiveTypes);
    // Validate the complete import closure for normal type filters. Virtual
    // extension-provider subtypes must retain their original filter so a
    // SQLTasks-only import cannot be blocked by unrelated provider YAML.
    $validation = $this->validate($validationTypes);
    if (!$validation['ok']) {
      return [
        'ok' => FALSE,
        'dry_run' => $dryRun,
        'message' => 'Import stopped because validation failed.',
        'validation' => $validation,
      ];
    }
    $result = ['ok' => TRUE, 'dry_run' => $dryRun, 'applied' => !$dryRun && $yes, 'items' => []];
    $handlers = [];
    foreach ($this->getHandlers() as $handler) {
      if ($applyTypes && !in_array($handler->getType(), $applyTypes, TRUE)) {
        continue;
      }
      $this->prepareHandlerForTypeFilter($handler, $requestedTypes);
      $handlers[] = $handler;
    }

    $possibleRenames = $this->findPossibleRenameCandidates($handlers, $storage);
    if ($possibleRenames) {
      $result['possible_renames'] = $possibleRenames;
      if (!$dryRun && $yes) {
        $result['ok'] = FALSE;
        $result['applied'] = FALSE;
        $result['message'] = 'Import stopped because possible configuration renames require review. Confirm the intended identity change, align YAML with the accepted identity, and preview the import again.';
        return $result;
      }
    }

    if (!$dryRun && $yes) {
      // Apply create/update first for all types, then delete missing records in
      // reverse order. Selected scope disables bulk delete-missing centrally:
      // absence from a selective YAML set must never delete unselected config.
      foreach ($handlers as $handler) {
        $this->setHandlerImportPhase($handler, TRUE, FALSE);
        $files = $this->loadManagedYamlFiles($handler, $storage);
        $item = $handler->import($files, FALSE);
        $item['phase'] = 'create_update';
        $result['items'][] = $item;
        if (!empty($item['errors'])) {
          $result['ok'] = FALSE;
        }
      }
      foreach (array_reverse($handlers) as $handler) {
        $this->setHandlerImportPhase($handler, FALSE, TRUE);
        $files = $this->loadManagedYamlFiles($handler, $storage);
        $item = $handler->import($files, FALSE);
        $item['phase'] = 'delete_missing';
        $result['items'][] = $item;
        if (!empty($item['errors'])) {
          $result['ok'] = FALSE;
        }
        $this->setHandlerImportPhase($handler, TRUE, TRUE);
      }
      if (!empty($result['ok'])) {
        try {
          $stateManager = new ConfigStateManager();
          foreach ($handlers as $handler) {
            $files = $this->loadManagedYamlFiles($handler, $storage);
            $stateManager->acceptYamlBaseline($handler, $files, 'import');
          }
        }
        catch (\Throwable $e) {
          $result['state_warning'] = 'Import was applied successfully, but local baseline state could not be updated: ' . $e->getMessage();
        }
      }
      $result['summary_message'] = $this->buildImportSummaryMessage($result);
      return $result;
    }

    foreach ($handlers as $handler) {
      $this->setHandlerImportPhase($handler, TRUE, TRUE);
      $files = $this->loadManagedYamlFiles($handler, $storage);
      $item = $handler->import($files, $dryRun || !$yes);
      $result['items'][] = $item;
      if (!empty($item['errors'])) {
        $result['ok'] = FALSE;
      }
    }
    $result['summary_message'] = $this->buildImportSummaryMessage($result);
    return $result;
  }

  private function findPossibleRenameCandidates(array $handlers, YamlFileStorage $storage): array {
    $candidates = [];
    foreach ($handlers as $handler) {
      $files = $this->loadManagedYamlFiles($handler, $storage);
      $partition = $this->scopePartition($handler, $handler->export(), $storage, FALSE);
      $exported = $this->filterIgnoredValuesInExportFiles($handler->getDirectory(), (array) ($partition['managed'] ?? []));
      $diff = $handler->diffFromExports($exported, $files);
      foreach ((array) ($diff['possible_renames'] ?? []) as $candidate) {
        if (!is_array($candidate)) {
          continue;
        }
        $candidate['type'] = $handler->getType();
        $candidate['label'] = $handler->getLabel();
        $candidates[] = $candidate;
      }
    }
    return $candidates;
  }

  private function buildImportSummaryMessage(array $result): string {
    $create = $update = $delete = $skip = $errors = $warnings = 0;
    foreach (($result['items'] ?? []) as $item) {
      $create += (int) ($item['create'] ?? 0);
      $update += (int) ($item['update'] ?? 0);
      $delete += (int) ($item['delete'] ?? 0);
      $skip += (int) ($item['skip'] ?? 0);

      if (!empty($item['groups']) && is_array($item['groups'])) {
        $create += (int) ($item['groups']['create'] ?? 0);
        $update += (int) ($item['groups']['update'] ?? 0);
        $skip += (int) ($item['groups']['skip'] ?? 0);
      }
      if (!empty($item['values']) && is_array($item['values'])) {
        $create += (int) ($item['values']['create'] ?? 0);
        $update += (int) ($item['values']['update'] ?? 0);
        $delete += (int) ($item['values']['delete'] ?? 0);
        $skip += (int) ($item['values']['skip'] ?? 0);
      }
      if (!empty($item['settings']) && is_array($item['settings'])) {
        $update += (int) ($item['settings']['update'] ?? 0);
        $skip += (int) ($item['settings']['skip'] ?? 0);
      }
      if (!empty($item['config']) && is_array($item['config'])) {
        $create += (int) ($item['config']['create'] ?? 0);
        $update += (int) ($item['config']['update'] ?? 0);
        $delete += (int) ($item['config']['delete'] ?? 0);
        $skip += (int) ($item['config']['skip'] ?? 0);
      }
      $update += (int) ($item['install'] ?? 0) + (int) ($item['enable'] ?? 0) + (int) ($item['disable'] ?? 0);

      $errors += !empty($item['errors']) ? count($item['errors']) : 0;
      $warnings += !empty($item['warnings']) ? count($item['warnings']) : 0;
    }
    return sprintf('Import result: %d created, %d updated, %d deleted, %d skipped, %d warning(s), %d error(s).', $create, $update, $delete, $skip, $warnings, $errors);
  }

  private function setHandlerImportPhase($handler, bool $writeEnabled, bool $deleteEnabled): void {
    if (method_exists($handler, 'setImportWriteEnabled')) {
      $handler->setImportWriteEnabled($writeEnabled);
    }
    if (method_exists($handler, 'setDeleteMissingEnabled')) {
      $handler->setDeleteMissingEnabled($deleteEnabled && $this->scope->allowsDeleteMissing((string) $handler->getType()));
    }
  }

  public function getIgnorePatterns(): array {
    $configured = (array) \Civi::settings()->get('civicfg_ignore_paths');
    $defaults = [
      // Avoid self-management loops. Teams may remove this in settings if they
      // intentionally want Configuration Manager to manage its own extension state.
      'extensions/' . Version::EXTENSION_KEY . '.yml',
    ];
    $patterns = array_merge($defaults, $configured);
    $patterns = array_values(array_unique(array_filter(array_map(function($pattern) {
      return trim(str_replace('\\', '/', (string) $pattern));
    }, $patterns))));
    return $patterns;
  }

  private function filterIgnoredFiles(string $directory, array $files): array {
    $filtered = [];
    foreach ($files as $filename => $data) {
      $relative = trim($directory, '/') . '/' . ltrim((string) $filename, '/');
      if ($this->isIgnoredPath($relative)) {
        continue;
      }
      $filtered[$filename] = $data;
    }
    return $filtered;
  }

  private function filterIgnoredDiffItem(array $item, string $directory = ''): array {
    $ignoredFiles = [];
    $files = [];
    foreach (($item['files'] ?? []) as $file) {
      $path = (string) ($file['path'] ?? '');
      if ($path !== '' && $this->isIgnoredPath($path)) {
        $ignoredFiles[(string) ($file['file'] ?? basename($path))] = TRUE;
        continue;
      }
      $files[] = $file;
    }
    $item['files'] = $files;

    foreach (['changed', 'new_in_db', 'missing_in_db'] as $bucket) {
      $values = [];
      foreach (($item[$bucket] ?? []) as $filename) {
        $filename = (string) $filename;
        $relative = trim($directory, '/') !== '' ? trim($directory, '/') . '/' . ltrim($filename, '/') : $filename;
        if (!isset($ignoredFiles[$filename]) && !$this->isIgnoredPath($relative)) {
          $values[] = $filename;
        }
      }
      $item[$bucket] = $values;
    }

    if (empty($item['changed']) && empty($item['new_in_db']) && empty($item['missing_in_db'])) {
      $item['status'] = 'in_sync';
    }
    return $item;
  }

  public function shouldIgnorePath(string $relativePath): bool {
    return $this->isIgnoredPath($relativePath);
  }

  private function isIgnoredPath(string $relativePath): bool {
    $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
    foreach ($this->getIgnorePatterns() as $pattern) {
      $pattern = trim(str_replace('\\', '/', (string) $pattern), '/');
      if ($pattern === '') {
        continue;
      }
      if ($relativePath === $pattern) {
        return TRUE;
      }
      $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';
      if (preg_match($regex, $relativePath)) {
        return TRUE;
      }
    }
    return FALSE;
  }


  public function getIgnoreValuePatterns(): array {
    $configured = (array) \Civi::settings()->get('civicfg_ignore_values');
    $rules = [];
    foreach ($configured as $rule) {
      $rule = trim(str_replace('\\', '/', (string) $rule));
      if ($rule === '' || strpos($rule, ':') === FALSE) {
        continue;
      }
      [$path, $valuePath] = array_map('trim', explode(':', $rule, 2));
      $path = trim($path, '/');
      $valuePath = trim($valuePath);
      if ($path === '' || $valuePath === '') {
        continue;
      }
      $rules[] = ['path' => $path, 'value_path' => $valuePath, 'raw' => $path . ':' . $valuePath];
    }
    return $rules;
  }

  private function filterIgnoredValuesInFiles(string $directory, array $files): array {
    $filtered = [];
    foreach ($files as $filename => $data) {
      $relative = trim($directory, '/') . '/' . ltrim((string) $filename, '/');
      $filtered[$filename] = $this->applyIgnoredValueRules($relative, (array) $data);
    }
    return $filtered;
  }

  private function filterIgnoredValuesInExportFiles(string $directory, array $files): array {
    foreach ($files as &$file) {
      if (empty($file['filename'])) {
        continue;
      }
      $relative = trim($directory, '/') . '/' . ltrim((string) $file['filename'], '/');
      $file['data'] = $this->applyIgnoredValueRules($relative, (array) ($file['data'] ?? []));
    }
    return $files;
  }

  public function applyIgnoredValueRules(string $relativePath, array $data): array {
    foreach ($this->getIgnoreValuePatterns() as $rule) {
      if (!$this->pathMatchesPattern($relativePath, (string) $rule['path'])) {
        continue;
      }
      $data = $this->removeValuePath($data, (string) $rule['value_path']);
    }
    return $data;
  }

  private function removeValuePath(array $data, string $path): array {
    $segments = $this->splitValuePath($path);
    if (!$segments) {
      return $data;
    }
    $this->unsetValuePath($data, $segments);
    return $data;
  }

  private function unsetValuePath(array &$data, array $segments): void {
    $segment = array_shift($segments);
    if ($segment === NULL) {
      return;
    }
    if ($segment === '*') {
      foreach ($data as &$child) {
        if (is_array($child)) {
          if ($segments) {
            $this->unsetValuePath($child, $segments);
          }
          else {
            $child = NULL;
          }
        }
      }
      unset($child);
      return;
    }
    if (!array_key_exists($segment, $data)) {
      return;
    }
    if (!$segments) {
      unset($data[$segment]);
      return;
    }
    if (is_array($data[$segment])) {
      $this->unsetValuePath($data[$segment], $segments);
    }
  }

  private function splitValuePath(string $path): array {
    $path = trim($path);
    if ($path === '') {
      return [];
    }
    $path = preg_replace('/\[([^\]]+)\]/', '.$1', $path);
    $parts = preg_split('/\.+/', (string) $path);
    return array_values(array_filter(array_map('trim', (array) $parts), static fn($part) => $part !== ''));
  }

  private function pathMatchesPattern(string $relativePath, string $pattern): bool {
    $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
    $pattern = trim(str_replace('\\', '/', $pattern), '/');
    if ($relativePath === $pattern) {
      return TRUE;
    }
    $regex = '/^' . str_replace('\\*', '.*', preg_quote($pattern, '/')) . '$/';
    return (bool) preg_match($regex, $relativePath);
  }

  private function addManifestValidation(array &$result, array $manifest): void {
    if (!$manifest) {
      return;
    }
    $manifestSite = trim((string) ($manifest['site_id'] ?? ''));
    $localSite = $this->getSiteIdentifier();
    $allowCrossSite = (bool) \Civi::settings()->get('civicfg_allow_cross_site_import');
    if ($manifestSite !== '' && $localSite !== '' && $manifestSite !== $localSite && !$allowCrossSite) {
      $result['ok'] = FALSE;
      $result['errors'][] = [
        'type' => 'manifest',
        'message' => sprintf('Manifest site_id "%s" does not match this site_id "%s". This usually means the YAML belongs to a different site family. Dev/stage/prod for the same project should share this automatically generated identifier through the database. Enable experimental cross-site import only for a reviewed one-off migration.', $manifestSite, $localSite),
      ];
    }
  }


  public function getIgnoredDependencyWarnings(): array {
    $storage = new YamlFileStorage($this->getSyncDir());
    $warnings = [];
    $yamlByType = [];
    foreach ($this->getHandlers() as $handler) {
      $yamlByType[$handler->getType()] = $this->filterIgnoredFiles($handler->getDirectory(), $storage->readDirectory($handler->getDirectory()));
    }
    $available = $this->collectManagedYamlNames($yamlByType);
    foreach ($yamlByType as $type => $files) {
      foreach ($files as $filename => $file) {
        foreach ($this->extractDependenciesFromYamlFile((array) $file) as $dependency) {
          $dependencyType = (string) ($dependency['type'] ?? '');
          $dependencyName = (string) ($dependency['name'] ?? '');
          if ($dependencyType === '' || $dependencyName === '') {
            continue;
          }
          if (!empty($available[$dependencyType][$dependencyName])) {
            continue;
          }
          $ignoredHint = $this->ignoredDependencyHint($dependencyType, $dependencyName);
          if ($ignoredHint !== '') {
            $warnings[] = sprintf('%s depends on ignored YAML %s. Remove or narrow the ignore rule before importing related configuration.', $filename, $ignoredHint);
          }
        }
      }
    }
    return array_values(array_unique($warnings));
  }


  public function revertCiviFromYaml(string $relativePath): array {
    $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath === '' || strpos($relativePath, '..') !== FALSE || $this->isIgnoredPath($relativePath)) {
      throw new \RuntimeException('Cannot revert an empty, unsafe, or ignored YAML path.');
    }

    $storage = new YamlFileStorage($this->getSyncDir());
    $selected = $this->resolveHandlerPath($relativePath);
    if (!$selected) {
      throw new \RuntimeException('No managed handler owns YAML path: ' . $relativePath);
    }

    $affectedPaths = $this->collectRevertDependencyPaths($storage, [$relativePath]);
    if (!in_array($relativePath, $affectedPaths, TRUE)) {
      array_unshift($affectedPaths, $relativePath);
    }

    $items = [];
    $errors = [];
    $warnings = [];
    $appliedPaths = [];

    foreach ($this->getAllHandlers() as $handler) {
      $ownedPaths = [];
      foreach ($affectedPaths as $path) {
        $parts = $this->handlerPathParts($handler, $path);
        if ($parts) {
          $ownedPaths[$path] = $parts;
        }
      }
      if (!$ownedPaths) {
        continue;
      }

      $desired = [];
      foreach ($handler->export() as $file) {
        if (empty($file['filename'])) {
          continue;
        }
        $filename = (string) $file['filename'];
        $relative = $this->relativePathForHandlerFile($handler, $filename);
        $desired[$filename] = $this->applyIgnoredValueRules($relative, (array) ($file['data'] ?? []));
      }

      foreach ($ownedPaths as $path => $parts) {
        $filename = (string) $parts['filename'];
        if ($storage->exists((string) $parts['directory'], $filename)) {
          $desired[$filename] = $this->applyIgnoredValueRules($path, $storage->readFile($path));
        }
        else {
          unset($desired[$filename]);
        }
        $appliedPaths[$path] = TRUE;
      }
      ksort($desired);

      try {
        $validation = $handler->validate($desired);
        if (empty($validation['valid'])) {
          foreach ((array) ($validation['errors'] ?? []) as $error) {
            $errors[] = [
              'type' => $handler->getType(),
              'message' => (string) ($error['message'] ?? json_encode($error)),
              'file' => (string) ($error['file'] ?? ''),
            ];
          }
          continue;
        }
        foreach ((array) ($validation['warnings'] ?? []) as $warning) {
          $warnings[] = [
            'type' => $handler->getType(),
            'message' => (string) ($warning['message'] ?? json_encode($warning)),
            'file' => (string) ($warning['file'] ?? ''),
          ];
        }

        $this->setHandlerImportPhase($handler, TRUE, TRUE);
        $item = $handler->import($desired, FALSE);
        $item['revert_paths'] = array_keys($ownedPaths);
        $items[] = $item;
        foreach ((array) ($item['warnings'] ?? []) as $warning) {
          $warnings[] = [
            'type' => $handler->getType(),
            'message' => (string) ($warning['message'] ?? json_encode($warning)),
            'file' => (string) ($warning['file'] ?? ''),
          ];
        }
        foreach ((array) ($item['errors'] ?? []) as $error) {
          $errors[] = [
            'type' => $handler->getType(),
            'message' => (string) ($error['message'] ?? json_encode($error)),
            'file' => (string) ($error['file'] ?? ''),
          ];
        }
      }
      finally {
        $this->setHandlerImportPhase($handler, TRUE, TRUE);
      }
    }

    $summaryMessage = $this->buildImportSummaryMessage(['items' => $items]);
    $pathCount = count($appliedPaths);
    $dependencyNote = $pathCount > 1 ? sprintf(' The selected file and %d dependent YAML file(s) were applied.', $pathCount - 1) : ' The selected YAML file was applied.';

    return [
      'ok' => empty($errors),
      'path' => $relativePath,
      'paths' => array_keys($appliedPaths),
      'items' => $items,
      'warnings' => $warnings,
      'errors' => $errors,
      'message' => empty($errors)
        ? 'Active CiviCRM was reverted from YAML.' . $dependencyNote . ' ' . $summaryMessage
        : 'Revert from YAML found problems. ' . $summaryMessage,
    ];
  }

  private function collectRevertDependencyPaths(YamlFileStorage $storage, array $seedPaths): array {
    $index = $this->buildYamlDependencyIndex($storage);
    $queue = array_values(array_unique(array_filter(array_map(function($path) {
      return trim(str_replace('\\', '/', (string) $path), '/');
    }, $seedPaths))));
    $seen = [];

    while ($queue) {
      $path = array_shift($queue);
      if ($path === '' || isset($seen[$path])) {
        continue;
      }
      $seen[$path] = TRUE;
      $file = $storage->readFile($path);
      if (!$file) {
        continue;
      }
      foreach ($this->extractDependenciesFromYamlFile((array) $file) as $dependency) {
        $type = (string) ($dependency['type'] ?? '');
        $name = (string) ($dependency['name'] ?? '');
        if ($type === '' || $name === '') {
          continue;
        }
        $paths = $index[$type][$name] ?? [];
        if (!$paths) {
          foreach ($this->dependencyCandidatePaths($type, $name) as $candidate) {
            if ($storage->readFile($candidate)) {
              $paths[] = $candidate;
            }
          }
        }
        foreach ($paths as $dependencyPath) {
          $dependencyPath = trim(str_replace('\\', '/', (string) $dependencyPath), '/');
          if ($dependencyPath !== '' && !isset($seen[$dependencyPath])) {
            $queue[] = $dependencyPath;
          }
        }
      }
      if (count($seen) > 500) {
        throw new \RuntimeException('Dependency closure is too large. Review YAML dependencies before reverting.');
      }
    }

    return array_keys($seen);
  }

  private function buildYamlDependencyIndex(YamlFileStorage $storage): array {
    $index = [];
    foreach ($this->getAllHandlers() as $handler) {
      $directory = trim((string) $handler->getDirectory(), '/');
      foreach ($storage->readDirectory($handler->getDirectory()) as $filename => $file) {
        $relative = $directory === '' ? (string) $filename : $directory . '/' . (string) $filename;
        foreach ($this->namesFromYamlFile((array) $file) as $name) {
          $index[$handler->getType()][(string) $name][] = $relative;
        }
      }
    }
    return $index;
  }

  private function resolveHandlerPath(string $relativePath): ?array {
    foreach ($this->getAllHandlers() as $handler) {
      $parts = $this->handlerPathParts($handler, $relativePath);
      if ($parts) {
        $parts['handler'] = $handler;
        return $parts;
      }
    }
    return NULL;
  }

  private function handlerPathParts($handler, string $relativePath): ?array {
    $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
    $directory = trim((string) $handler->getDirectory(), '/');
    $prefix = $directory === '' ? '' : $directory . '/';
    if ($directory !== '' && strpos($relativePath, $prefix) !== 0) {
      return NULL;
    }
    $filename = $directory === '' ? $relativePath : substr($relativePath, strlen($prefix));
    if ($filename === '' || strpos($filename, '..') !== FALSE) {
      return NULL;
    }
    return ['directory' => $handler->getDirectory(), 'filename' => $filename];
  }

  private function relativePathForHandlerFile($handler, string $filename): string {
    $directory = trim((string) $handler->getDirectory(), '/');
    return $directory === '' ? $filename : $directory . '/' . ltrim($filename, '/');
  }

  public function addIgnorePathRule(string $relativePath): array {
    $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath === '' || strpos($relativePath, '..') !== FALSE) {
      throw new \RuntimeException('Invalid ignore path.');
    }
    $patterns = (array) \Civi::settings()->get('civicfg_ignore_paths');
    $patterns = array_values(array_unique(array_filter(array_map(function($value) {
      return trim(str_replace('\\', '/', (string) $value), '/');
    }, $patterns))));
    if (!in_array($relativePath, $patterns, TRUE)) {
      $patterns[] = $relativePath;
      sort($patterns, SORT_NATURAL | SORT_FLAG_CASE);
      \Civi::settings()->set('civicfg_ignore_paths', $patterns);
    }
    return $patterns;
  }

  public function addIgnoreValueRules(string $relativePath, array $valuePaths): array {
    $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath === '' || strpos($relativePath, '..') !== FALSE) {
      throw new \RuntimeException('Invalid ignore path.');
    }
    $existing = (array) \Civi::settings()->get('civicfg_ignore_values');
    $rules = [];
    foreach ($existing as $rule) {
      $rule = trim(str_replace('\\', '/', (string) $rule));
      if ($rule !== '') {
        $rules[$rule] = TRUE;
      }
    }
    foreach ($valuePaths as $valuePath) {
      $valuePath = trim((string) $valuePath);
      if ($valuePath === '' || strpos($valuePath, '..') !== FALSE) {
        continue;
      }
      $rules[$relativePath . ':' . $valuePath] = TRUE;
    }
    $values = array_keys($rules);
    sort($values, SORT_NATURAL | SORT_FLAG_CASE);
    \Civi::settings()->set('civicfg_ignore_values', $values);
    return $values;
  }


  /**
   * Return cached health without scanning handlers or calling diff().
   *
   * This method is safe for hook_civicrm_check() and ordinary page requests.
   * Expensive comparison work happens only in explicit diff/watch operations.
   */
  public function getHealth(): array {
    $syncDir = $this->getSyncDir();
    $yamlRuntime = SimpleYaml::runtimeStatus();
    if (empty($yamlRuntime['available'])) {
      return [
        'level' => 'warning',
        'title' => 'Configuration Manager: YAML runtime dependency missing',
        'message' => (string) ($yamlRuntime['reason'] ?? 'No YAML parser is available.'),
        'sync_dir' => $syncDir,
        'changed' => 0,
        'in_civicrm' => 0,
        'in_yaml' => 0,
      ];
    }

    // Health/status hooks must never discover handlers. Scope-aware diff()
    // caches the two no-managed-scope states explicitly; those cached states
    // are safe to return even when no manifest exists yet. Other cached states
    // remain subordinate to the manifest check so a removed YAML tree cannot
    // leave a stale "In sync" result visible.
    $cached = \Civi::settings()->get('civicfg_last_health');
    if (is_array($cached) && in_array((string) ($cached['title'] ?? ''), [
      'Configuration Manager: Setup required',
      'Configuration Manager: Monitoring only',
    ], TRUE)) {
      $cached['sync_dir'] = $syncDir;
      return $cached;
    }

    if (!is_dir($syncDir)) {
      return [
        'level' => 'warning',
        'title' => 'Configuration Manager: Initial export required',
        'message' => 'Create the initial YAML export before using Configuration Manager as a configuration source.',
        'sync_dir' => $syncDir,
        'changed' => 0,
        'in_civicrm' => 0,
        'in_yaml' => 0,
      ];
    }

    // Keep hook_civicrm_check() cheap. A current alpha58 export always writes
    // manifest.yml, so checking this one marker file avoids recursively walking
    // a large YAML tree from an ordinary CiviCRM status request. Check the
    // marker before cached health so deleting/moving the YAML tree cannot leave
    // a stale "In sync" status visible indefinitely.
    $manifestPath = rtrim($syncDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'manifest.yml';
    if (!is_file($manifestPath)) {
      return [
        'level' => 'warning',
        'title' => 'Configuration Manager: Initial export required',
        'message' => 'Create the initial YAML export, or run Synchronize to inspect an older configuration tree.',
        'sync_dir' => $syncDir,
        'changed' => 0,
        'in_civicrm' => 0,
        'in_yaml' => 0,
      ];
    }

    if (is_array($cached) && !empty($cached['title'])) {
      $cached['sync_dir'] = $syncDir;
      return $cached;
    }

    return [
      'level' => 'info',
      'title' => 'Configuration Manager: Status not scanned yet',
      'message' => 'YAML configuration exists. Run Synchronize or civicfg diff to refresh the last-known configuration status.',
      'sync_dir' => $syncDir,
      'changed' => 0,
      'in_civicrm' => 0,
      'in_yaml' => 0,
    ];
  }

  private function cacheHealthFromDiff(array $diff): void {
    $syncDir = (string) ($diff['sync_dir'] ?? $this->getSyncDir());
    if (!empty($diff['no_managed_scope'])) {
      $watchOnly = !empty($diff['watch_only']);
      $health = [
        'level' => 'warning',
        'title' => $watchOnly
          ? 'Configuration Manager: Monitoring only'
          : 'Configuration Manager: Setup required',
        'message' => $watchOnly
          ? 'Watch-only configuration is enabled, but no configuration is currently managed in YAML.'
          : 'Choose configuration to manage before creating the initial YAML export.',
        'sync_dir' => $syncDir,
        'changed' => 0,
        'in_civicrm' => 0,
        'in_yaml' => 0,
        'scanned_at' => date('c'),
      ];
      \Civi::settings()->set('civicfg_last_health', $health);
      return;
    }
    if (!empty($diff['initial_export_required'])) {
      $health = [
        'level' => 'warning',
        'title' => 'Configuration Manager: Initial export required',
        'message' => 'Create the initial YAML export before configuration differences can be calculated.',
        'sync_dir' => $syncDir,
        'changed' => 0,
        'in_civicrm' => 0,
        'in_yaml' => 0,
        'scanned_at' => date('c'),
      ];
      \Civi::settings()->set('civicfg_last_health', $health);
      return;
    }

    $changed = 0;
    $inCivicrm = 0;
    $inYaml = 0;
    foreach ((array) ($diff['items'] ?? []) as $item) {
      $changed += count((array) ($item['changed'] ?? []));
      $inCivicrm += count((array) ($item['new_in_db'] ?? []));
      $inYaml += count((array) ($item['missing_in_db'] ?? []));
    }
    $total = $changed + $inCivicrm + $inYaml;
    $health = [
      'level' => $total > 0 ? 'warning' : 'info',
      'title' => $total > 0 ? 'Configuration Manager: Pending configuration changes' : 'Configuration Manager: In sync',
      'message' => $total > 0
        ? sprintf('Last scan found %d pending difference(s): %d changed, %d only in CiviCRM, and %d only in YAML.', $total, $changed, $inCivicrm, $inYaml)
        : 'The last Configuration Manager scan found no differences between managed YAML and active CiviCRM.',
      'sync_dir' => $syncDir,
      'changed' => $changed,
      'in_civicrm' => $inCivicrm,
      'in_yaml' => $inYaml,
      'scanned_at' => date('c'),
    ];
    \Civi::settings()->set('civicfg_last_health', $health);
  }

  /**
   * Explicitly scan watch-only configuration and persist local fingerprints.
   */
  public function scanWatched(array $typeFilter = []): array {
    $normalisedFilter = $this->normaliseTypeFilter($typeFilter);
    $baseFilter = $this->baseTypesFromFilter($normalisedFilter);
    $store = new StateStore();
    $identityService = new ConfigIdentity();
    $canonicalizer = new Canonicalizer();
    $storage = new YamlFileStorage($this->getSyncDir());
    $manifest = $this->readManifest($storage);
    $summary = [
      'ok' => TRUE,
      'scanned_at' => date('c'),
      'watched' => 0,
      'baseline' => 0,
      'new' => 0,
      'changed' => 0,
      'missing' => 0,
      'items' => [],
      'errors' => [],
    ];

    foreach ($this->getAllHandlers() as $handler) {
      $type = (string) $handler->getType();
      if ($baseFilter && !in_array($type, $baseFilter, TRUE)) {
        continue;
      }
      if (!$this->scope->isWatchedType($type)) {
        continue;
      }
      $this->prepareHandlerForTypeFilter($handler, $normalisedFilter);
      try {
        $exported = $handler->export();
        $selectorMap = $this->scope->portableSelectorMapFromManifest($manifest, $type);
        $partition = $this->scope->partition($type, $exported, FALSE, $selectorMap);
        $watched = (array) ($partition['watched'] ?? []);
        $allActiveHashes = [];
        foreach ($exported as $file) {
          $file = (array) $file;
          $filename = (string) ($file['filename'] ?? '');
          if ($filename === '') {
            continue;
          }
          $identity = $identityService->identify($type, (array) ($file['data'] ?? []), $filename);
          $allActiveHashes[(string) $identity['identity_hash']] = TRUE;
        }

        $previousRows = $store->getWatchStatesByType($type);
        $previousByHash = [];
        foreach ($previousRows as $row) {
          $previousByHash[(string) ($row['identity_hash'] ?? '')] = $row;
        }
        $firstTypeScan = !$previousRows;
        $seenWatched = [];

        foreach ($watched as $file) {
          $file = (array) $file;
          $filename = (string) ($file['filename'] ?? '');
          $data = (array) ($file['data'] ?? []);
          if ($filename === '') {
            continue;
          }
          $identity = $identityService->identify($type, $data, $filename);
          $hash = $canonicalizer->hash($data, $handler->getCanonicalizationOptions());
          $identityHash = (string) $identity['identity_hash'];
          $previous = $previousByHash[$identityHash] ?? NULL;
          $status = 'unchanged';
          if ($previous === NULL) {
            $status = $firstTypeScan ? 'baseline' : 'new';
          }
          elseif ((string) ($previous['active_hash'] ?? '') !== $hash || ($previous['watch_status'] ?? '') === 'missing') {
            $status = 'changed';
          }
          $label = $this->displayLabelForConfigFile($type, $file);
          $store->upsertWatchState($type, $identity, $filename, $label, $hash, $data, $status);
          $seenWatched[$identityHash] = TRUE;
          $summary['watched']++;
          if (isset($summary[$status])) {
            $summary[$status]++;
          }
          if (in_array($status, ['new', 'changed'], TRUE)) {
            $summary['items'][] = [
              'type' => $type,
              'label' => $label,
              'path' => trim((string) $handler->getDirectory(), '/') . '/' . $filename,
              'status' => $status,
            ];
          }
        }

        foreach ($previousRows as $previous) {
          $identityHash = (string) ($previous['identity_hash'] ?? '');
          if ($identityHash === '' || isset($seenWatched[$identityHash])) {
            continue;
          }
          if (isset($allActiveHashes[$identityHash])) {
            // The object still exists but is no longer watched (for example it
            // was promoted into managed scope). Remove stale watch state.
            $store->deleteWatchState((string) $previous['provider_key'], $identityHash);
            continue;
          }
          $identity = [
            'provider_key' => (string) $previous['provider_key'],
            'config_key' => (string) $previous['config_key'],
            'identity_hash' => $identityHash,
          ];
          $store->upsertWatchState(
            $type,
            $identity,
            (string) ($previous['filename'] ?? ''),
            (string) ($previous['display_label'] ?? ''),
            NULL,
            (array) ($previous['active_data'] ?? []),
            'missing'
          );
          if (($previous['watch_status'] ?? '') !== 'missing') {
            $summary['missing']++;
            $summary['items'][] = [
              'type' => $type,
              'label' => (string) ($previous['display_label'] ?? $previous['filename'] ?? $type),
              'path' => trim((string) $handler->getDirectory(), '/') . '/' . (string) ($previous['filename'] ?? ''),
              'status' => 'missing',
            ];
          }
        }
      }
      catch (\Throwable $e) {
        $summary['ok'] = FALSE;
        $summary['errors'][] = ['type' => $type, 'message' => $e->getMessage()];
      }
    }

    $history = $this->appendWatchHistory($summary);
    $summary['history_count'] = count($history);
    \Civi::settings()->set('civicfg_watch_summary', $summary);
    return $summary;
  }

  public function getWatchSummary(): array {
    $summary = \Civi::settings()->get('civicfg_watch_summary');
    return is_array($summary) ? $summary : [];
  }

  /**
   * Return recent detected watch-only changes, newest first.
   *
   * History is local operational data. It deliberately survives later no-op
   * watch scans so an administrator can review changes detected across several
   * scans instead of losing the previous finding as soon as another item
   * changes.
   */
  public function getWatchHistory(): array {
    $history = \Civi::settings()->get('civicfg_watch_history');
    if (!is_array($history)) {
      return [];
    }
    $rows = [];
    foreach ($history as $row) {
      if (!is_array($row)) {
        continue;
      }
      $status = (string) ($row['status'] ?? '');
      if (!in_array($status, ['new', 'changed', 'missing'], TRUE)) {
        continue;
      }
      $rows[] = [
        'detected_at' => (string) ($row['detected_at'] ?? ''),
        'type' => (string) ($row['type'] ?? ''),
        'label' => (string) ($row['label'] ?? ''),
        'path' => (string) ($row['path'] ?? ''),
        'status' => $status,
      ];
      if (count($rows) >= self::WATCH_HISTORY_LIMIT) {
        break;
      }
    }
    return $rows;
  }

  public function clearWatchHistory(): void {
    \Civi::settings()->set('civicfg_watch_history', []);
  }

  private function appendWatchHistory(array $summary): array {
    $history = $this->getWatchHistory();
    $detectedAt = (string) ($summary['scanned_at'] ?? date('c'));
    $newRows = [];
    foreach ((array) ($summary['items'] ?? []) as $item) {
      $item = (array) $item;
      $status = (string) ($item['status'] ?? '');
      if (!in_array($status, ['new', 'changed', 'missing'], TRUE)) {
        continue;
      }
      $newRows[] = [
        'detected_at' => $detectedAt,
        'type' => (string) ($item['type'] ?? ''),
        'label' => (string) ($item['label'] ?? ''),
        'path' => (string) ($item['path'] ?? ''),
        'status' => $status,
      ];
    }
    if ($newRows) {
      $history = array_merge(array_reverse($newRows), $history);
      $history = array_slice($history, 0, self::WATCH_HISTORY_LIMIT);
      \Civi::settings()->set('civicfg_watch_history', $history);
    }
    return $history;
  }

  private function displayLabelForConfigFile(string $type, array $file): string {
    $data = (array) ($file['data'] ?? []);

    // Message Template workflow names are machine identifiers (for example
    // case_activity). Prefer the administrator-facing template title in scope
    // pickers/watch summaries while retaining the stable workflow identity
    // internally for cross-environment matching.
    if ($type === 'message-templates') {
      $template = (array) ($data['template'] ?? []);
      if (!empty($template['msg_title']) && is_scalar($template['msg_title'])) {
        return (string) $template['msg_title'];
      }
    }

    foreach (['name', 'label', 'title', 'key'] as $field) {
      if (!empty($data[$field]) && is_scalar($data[$field])) {
        return (string) $data[$field];
      }
    }
    foreach (['item', 'template', 'group', 'extension', 'processor', 'financial_type', 'rule', 'token'] as $container) {
      $row = (array) ($data[$container] ?? []);
      foreach (['label', 'title', 'msg_title', 'name', 'workflow_name', 'key', 'name_a_b'] as $field) {
        if (!empty($row[$field]) && is_scalar($row[$field])) {
          return (string) $row[$field];
        }
      }
    }
    $filename = (string) ($file['filename'] ?? $type);
    return ucwords(str_replace(['_', '-', '.yml', '.yaml'], [' ', ' ', '', ''], basename($filename)));
  }

  private function hasManagedYamlFiles(string $dir): bool {
    if (!is_dir($dir)) {
      return FALSE;
    }

    $manifestPath = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'manifest.yml';
    if (is_file($manifestPath)) {
      try {
        $manifest = \Civi\ConfigManager\Storage\SimpleYaml::parseFile($manifestPath);
        if (($manifest['extension'] ?? '') === Version::EXTENSION_KEY && array_key_exists('managed_scope', $manifest)) {
          return TRUE;
        }
      }
      catch (\Throwable $e) {
        // Fall through to legacy YAML detection. Validation will report a
        // malformed manifest when the operator explicitly runs it.
      }
    }

    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
      if (!$file->isFile() || !preg_match('/\.ya?ml$/i', $file->getFilename())) {
        continue;
      }
      $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen(rtrim($dir, DIRECTORY_SEPARATOR)))), '/');
      if ($relative !== 'manifest.yml') {
        return TRUE;
      }
    }
    return FALSE;
  }

  private function hasYamlFiles(string $dir): bool {
    if (!is_dir($dir)) {
      return FALSE;
    }
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
      if ($file->isFile() && preg_match('/\.ya?ml$/i', $file->getFilename())) {
        return TRUE;
      }
    }
    return FALSE;
  }

  private function getManifestData(array $managedScope = []): array {
    $siteId = $this->getSiteIdentifier();
    $manifest = [
      'schema_version' => 1,
      'extension' => Version::EXTENSION_KEY,
      'format' => 'yaml',
      'exported_with' => Version::get(),
      'civicrm_min_version' => '5.0',
      'created_by' => 'Configuration Manager',
    ];
    if ($managedScope) {
      ksort($managedScope, SORT_NATURAL | SORT_FLAG_CASE);
      $manifest['managed_scope'] = $managedScope;
    }
    if ($siteId !== '') {
      $manifest['site_id'] = $siteId;
      $manifest['site_policy'] = 'same-site-environments';
    }
    return $manifest;
  }

}
