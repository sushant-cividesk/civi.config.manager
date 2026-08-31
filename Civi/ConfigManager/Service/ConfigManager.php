<?php
namespace Civi\ConfigManager\Service;

use Civi\ConfigManager\Handler\ScopePickerHintProviderInterface;
use Civi\ConfigManager\Handler\ChunkedStreamingHandlerInterface;
use Civi\ConfigManager\Handler\StreamingHandlerInterface;
use Civi\ConfigManager\Handler\StreamingImportHandlerInterface;
use Civi\ConfigManager\Storage\YamlFileStorage;
use Civi\ConfigManager\Util\SimpleYaml;
use Civi\ConfigManager\Version;

class ConfigManager {
  private const WATCH_HISTORY_LIMIT = 200;

  /**
   * Universal runtime-only YAML values excluded from portable configuration.
   *
   * These fields are maintained by CiviCRM while jobs/tokens run and are not
   * meaningful deployment configuration. They are always ignored in addition
   * to any administrator-defined Config Ignore Values.
   */
  private const BUILT_IN_IGNORE_VALUE_RULES = [
    'extensions/*/api3/Job/*.yml:item.last_run',
    'extensions/*/api3/Job/*.yml:item.last_run_end',
    'scheduled-jobs/*.yml:item.scheduled_run_date',
    'site-tokens/*.yml:item.modified_date',
  ];

  /**
   * Universal whole-file rules owned by the extension.
   *
   * Configuration Manager must never manage its own extension-status YAML
   * while it is executing an import.
   */
  private const BUILT_IN_IGNORE_FILE_RULES = [
    'extensions/civi.config.manager.yml',
  ];
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
    // Ask the active CiviCRM UF implementation first. This is the only source
    // here which understands the actual CMS root across Drupal, WordPress, and
    // Standalone in both HTTP and CLI contexts. In particular, WordPress CLI
    // does not reliably populate DOCUMENT_ROOT and civicrm.settings.php often
    // lives under wp-content/uploads/civicrm, which is not the project root.
    try {
      if (class_exists('CRM_Utils_System')) {
        // CRM_Utils_System proxies CMS-specific methods to the active UF
        // implementation. WordPress/Drupal each implement cmsRootPath().
        $cmsRoot = \CRM_Utils_System::cmsRootPath();
        if (is_string($cmsRoot) && trim($cmsRoot) !== '' && is_dir($cmsRoot)) {
          return $this->normalizePath($cmsRoot);
        }
      }
    }
    catch (\Throwable $e) {
      // Keep deterministic fallbacks for early bootstrap/test environments.
    }

    foreach ($this->getProjectRootCandidates() as $candidate) {
      if ($candidate !== '' && is_dir($candidate)) {
        return $this->normalizePath($candidate);
      }
    }

    try {
      $config = \CRM_Core_Config::singleton();
      if (!empty($config->configAndLogDir)) {
        return $this->normalizePath(dirname((string) $config->configAndLogDir));
      }
    }
    catch (\Throwable $e) {
      // Fall through to the process working directory.
    }

    return $this->normalizePath((string) getcwd());
  }

  private function getProjectRootCandidates(): array {
    $candidates = [];

    // These constants are CMS-aware and remain useful when ConfigManager is
    // instantiated in a partial/test bootstrap without a complete userSystem.
    if (defined('CIVICRM_CMSDIR')) {
      $candidates[] = (string) CIVICRM_CMSDIR;
    }
    if (defined('ABSPATH')) {
      $candidates[] = rtrim((string) ABSPATH, '/\\');
    }
    if (defined('DRUPAL_ROOT')) {
      $candidates[] = (string) DRUPAL_ROOT;
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

    // Scope dependencies are guidance for building a portable deployable set.
    // They deliberately do not force a mode: an administrator may knowingly
    // rely on configuration that already exists on the target environment.
    $rules = $this->getScopeDependencyRules();
    $labels = [];
    foreach ($rows as $row) {
      $labels[(string) $row['type']] = (string) $row['label'];
    }
    $dependents = [];
    foreach ($rules as $sourceType => $dependencies) {
      foreach ($dependencies as $dependency) {
        $dependencyType = (string) ($dependency['type'] ?? '');
        if ($dependencyType !== '' && isset($labels[$sourceType], $labels[$dependencyType])) {
          $dependents[$dependencyType][] = [
            'type' => (string) $sourceType,
            'label' => (string) $labels[$sourceType],
          ];
        }
      }
    }

    foreach ($rows as &$row) {
      $type = (string) $row['type'];
      $dependencies = [];
      foreach ((array) ($rules[$type] ?? []) as $dependency) {
        $dependencyType = (string) ($dependency['type'] ?? '');
        if ($dependencyType === '' || !isset($labels[$dependencyType])) {
          continue;
        }
        $dependencies[] = [
          'type' => $dependencyType,
          'label' => (string) $labels[$dependencyType],
          'reason' => (string) ($dependency['reason'] ?? ''),
        ];
      }
      $row['scope_dependencies'] = $dependencies;
      $row['scope_dependency_types'] = implode(',', array_map(static function($dependency) {
        return (string) $dependency['type'];
      }, $dependencies));
      $row['scope_dependents'] = array_values((array) ($dependents[$type] ?? []));
    }
    unset($row);

    return $rows;
  }

  /**
   * Describe configuration types that are commonly referenced by another type.
   *
   * These are deployment-safety relationships, not hard database constraints.
   * Import validation still checks the actual YAML dependencies and accepts a
   * dependency that is already present in active CiviCRM. Keeping this map as
   * guidance avoids silently widening scope while still making risky scope
   * combinations visible before an export or import is attempted.
   */
  public function getScopeDependencyRules(): array {
    return [
      'custom-data' => [
        ['type' => 'option-groups', 'reason' => 'Custom fields can use option groups for choice values.'],
        ['type' => 'contact-types', 'reason' => 'Custom groups can be limited to contact types or subtypes.'],
        ['type' => 'site-tokens', 'reason' => 'Portable custom configuration can reference site-provided tokens.'],
      ],
      'relationship-types' => [
        ['type' => 'contact-types', 'reason' => 'Relationship types can be limited to contact types or subtypes.'],
      ],
      'searchkit-displays' => [
        ['type' => 'searchkit-saved-searches', 'reason' => 'SearchKit displays belong to saved searches.'],
      ],
      'formbuilder-afforms' => [
        ['type' => 'searchkit-saved-searches', 'reason' => 'Afforms can embed or depend on SearchKit saved searches.'],
        ['type' => 'searchkit-displays', 'reason' => 'Afforms can embed SearchKit displays.'],
      ],
      'civirules' => [
        ['type' => 'extensions', 'reason' => 'CiviRules configuration requires the CiviRules extension/provider to exist on the target.'],
      ],
      'site-tokens' => [
        ['type' => 'extensions', 'reason' => 'Site token providers can be supplied by extensions that must exist on the target.'],
      ],
    ];
  }

  /**
   * Return warnings for the currently saved scope dependency combination.
   *
   * @return array<int,array<string,string>>
   */
  public function getScopeDependencyWarnings(): array {
    $rows = $this->getScopeTypeOptions();
    $byType = [];
    foreach ($rows as $row) {
      $byType[(string) $row['type']] = $row;
    }

    $warnings = [];
    foreach ($rows as $row) {
      $sourceType = (string) $row['type'];
      $sourcePolicy = $this->scope->getPolicy($sourceType);
      $sourceMode = (string) ($sourcePolicy['mode'] ?? ConfigScope::MODE_IGNORE);
      if (!in_array($sourceMode, [ConfigScope::MODE_ALL, ConfigScope::MODE_SELECTED], TRUE)) {
        continue;
      }
      if ($sourceMode === ConfigScope::MODE_SELECTED && empty($sourcePolicy['selectors'])) {
        continue;
      }

      foreach ((array) ($row['scope_dependencies'] ?? []) as $dependency) {
        $dependencyType = (string) ($dependency['type'] ?? '');
        if ($dependencyType === '' || !isset($byType[$dependencyType])) {
          continue;
        }
        $dependencyRow = $byType[$dependencyType];
        $dependencyPolicy = $this->scope->getPolicy($dependencyType);
        $dependencyMode = (string) ($dependencyPolicy['mode'] ?? ConfigScope::MODE_IGNORE);
        $sourceLabel = (string) $row['label'];
        $dependencyLabel = (string) $dependencyRow['label'];
        $reason = trim((string) ($dependency['reason'] ?? ''));

        if ((string) ($dependencyRow['capability'] ?? '') === 'unavailable') {
          $warnings[] = [
            'level' => 'error',
            'source_type' => $sourceType,
            'dependency_type' => $dependencyType,
            'message' => $sourceLabel . ' can reference ' . $dependencyLabel . ', but ' . $dependencyLabel . ' is unavailable on this site.' . ($reason !== '' ? ' ' . $reason : ''),
          ];
          continue;
        }
        if ($dependencyMode === ConfigScope::MODE_IGNORE) {
          $warnings[] = [
            'level' => 'warning',
            'source_type' => $sourceType,
            'dependency_type' => $dependencyType,
            'message' => $sourceLabel . ' can reference ' . $dependencyLabel . ', but ' . $dependencyLabel . ' is ignored and will not be deployed in managed YAML.' . ($reason !== '' ? ' ' . $reason : ''),
          ];
        }
        elseif ($dependencyMode === ConfigScope::MODE_WATCH) {
          $warnings[] = [
            'level' => 'warning',
            'source_type' => $sourceType,
            'dependency_type' => $dependencyType,
            'message' => $sourceLabel . ' can reference ' . $dependencyLabel . ', but ' . $dependencyLabel . ' is monitor-only and will not be restored/imported.' . ($reason !== '' ? ' ' . $reason : ''),
          ];
        }
        elseif ($dependencyMode === ConfigScope::MODE_SELECTED) {
          $dependencySelectors = (array) ($dependencyPolicy['selectors'] ?? []);
          $emptySelected = !$dependencySelectors;
          $warnings[] = [
            'level' => $emptySelected ? 'warning' : 'review',
            'source_type' => $sourceType,
            'dependency_type' => $dependencyType,
            'message' => $emptySelected
              ? $dependencyLabel . ' uses selected-item scope but no items are selected, so referenced dependencies will not be deployed.' . ($reason !== '' ? ' ' . $reason : '')
              : $dependencyLabel . ' uses selected-item scope. Verify that every item referenced by ' . $sourceLabel . ' is included before promotion.' . ($reason !== '' ? ' ' . $reason : ''),
          ];
        }
      }
    }

    return $warnings;
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

    if (method_exists($handler, 'getRuntimeAvailability')) {
      $availability = (array) $handler->getRuntimeAvailability();
      if (array_key_exists('available', $availability) && empty($availability['available'])) {
        return [
          'type' => $type,
          'label' => (string) $handler->getLabel(),
          'policy' => $this->scope->getPolicy($type),
          'available' => FALSE,
          'unavailable_reason' => trim((string) ($availability['reason'] ?? 'Required runtime provider is not available on this site.')),
          'items' => [],
        ];
      }
    }

    $storage = new YamlFileStorage($this->getSyncDir());
    $exported = $this->attachScopeRelativePaths($handler, $handler->export());
    $pickerHints = [];
    if ($handler instanceof ScopePickerHintProviderInterface) {
      try {
        $pickerHints = (array) $handler->getScopePickerHints($exported);
      }
      catch (\Throwable $e) {
        // Picker recommendations are optional UX metadata. Never make scope
        // selection unavailable merely because a recommendation cannot be
        // calculated on a particular CiviCRM/provider version.
        $pickerHints = [];
      }
    }
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
      $pickerHint = (array) ($pickerHints[$filename] ?? []);
      $items[] = [
        'selector' => 'key:' . $configKey,
        'config_key' => $configKey,
        'label' => $this->displayLabelForConfigFile($type, $file),
        'path' => $relative,
        'source_id' => isset($file['source_id']) && is_scalar($file['source_id']) ? (string) $file['source_id'] : '',
        'selected' => isset($selectedKeys[$configKey]),
        'write_safe' => !empty($identity['write_safe']),
        'identity_confidence' => (string) ($identity['identity_confidence'] ?? ''),
        'recommended' => !empty($pickerHint['recommended']),
        'reference' => !empty($pickerHint['reference']),
        'recommendation' => trim((string) ($pickerHint['recommendation'] ?? '')),
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
      $recommendedCmp = ((int) !empty($b['recommended'])) <=> ((int) !empty($a['recommended']));
      if ($recommendedCmp !== 0) {
        return $recommendedCmp;
      }
      $missingCmp = ((int) !empty($a['missing'])) <=> ((int) !empty($b['missing']));
      if ($missingCmp !== 0) {
        return $missingCmp;
      }
      // Reserved workflow reference templates are useful for advanced review,
      // but CiviCRM itself hides them from the normal Message Templates list.
      // Keep them selectable while placing them after live/user templates.
      $referenceCmp = ((int) !empty($a['reference'])) <=> ((int) !empty($b['reference']));
      if ($referenceCmp !== 0) {
        return $referenceCmp;
      }
      return strnatcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
    });

    return [
      'type' => $type,
      'label' => (string) $handler->getLabel(),
      'policy' => $this->scope->getPolicy($type),
      'available' => TRUE,
      'unavailable_reason' => '',
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
        if (($availability['management_capability'] ?? '') === 'export_only') {
          return [
            'key' => 'export_only',
            'label' => 'Export + compare',
            'help' => trim((string) ($availability['reason'] ?? 'The provider can be read, but safe automatic restore/import is not available on this site.')),
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
    $type = (string) $handler->getType();
    $policy = $this->scope->getPolicy($type);

    // Manage-everything is the high-volume path and does not need per-item
    // selector matching or a second copy of the exported file array. Avoiding
    // attachScopeRelativePaths()+ConfigScope::partition() here substantially
    // lowers peak memory on large real sites while preserving identical scope
    // semantics.
    if (($policy['mode'] ?? ConfigScope::MODE_ALL) === ConfigScope::MODE_ALL) {
      return [
        'policy' => $policy,
        'managed' => $files,
        'watched' => [],
        'ignored' => [],
        'managed_config_keys' => [],
        'matched_selectors' => [],
        'selector_config_keys' => [],
        'unresolved_selectors' => [],
        'missing_selectors' => [],
      ];
    }

    $manifest = $this->readManifest($storage);
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
    return iterator_to_array($this->iterateManagedYamlFilesForHandler($handler, $storage), TRUE);
  }

  /**
   * Import one handler without materializing its full managed YAML set when
   * the handler supports the streaming import contract.
   */
  private function importManagedYamlForHandler($handler, YamlFileStorage $storage, bool $dryRun): array {
    if ($handler instanceof StreamingImportHandlerInterface) {
      return $handler->importIterable($this->iterateManagedYamlFilesForHandler($handler, $storage), $dryRun);
    }
    return $handler->import($this->loadManagedYamlFiles($handler, $storage), $dryRun);
  }

  /**
   * Validate large streaming handlers one document at a time.
   *
   * Core streaming handlers perform file-local schema/identity validation;
   * cross-file dependencies are checked separately from compact metadata in
   * addDependencyWarningsFromMetadata(). This keeps validation bounded while
   * preserving the complete preflight barrier.
   */
  private function validateManagedYamlForHandler($handler, YamlFileStorage $storage): array {
    if (!($handler instanceof StreamingHandlerInterface)) {
      return $handler->validate($this->loadManagedYamlFiles($handler, $storage));
    }

    $result = [
      'type' => (string) $handler->getType(),
      'valid' => TRUE,
      'warnings' => [],
      'errors' => [],
      'count' => 0,
    ];
    foreach ($this->iterateManagedYamlFilesForHandler($handler, $storage) as $filename => $file) {
      $validation = (array) $handler->validate([(string) $filename => (array) $file]);
      $result['count']++;
      foreach ((array) ($validation['warnings'] ?? []) as $warning) {
        $result['warnings'][] = $warning;
      }
      foreach ((array) ($validation['errors'] ?? []) as $error) {
        $result['errors'][] = $error;
      }
      if (empty($validation['valid'])) {
        $result['valid'] = FALSE;
      }
    }
    $result['valid'] = $result['valid'] && empty($result['errors']);
    return $result;
  }

  /**
   * Yield only YAML owned by the handler's effective managed scope.
   *
   * Unlike loadManagedYamlFiles(), this never parses the complete handler
   * directory into one PHP array. Selected scope is resolved from the portable
   * config_keys already recorded in manifest.yml; if those keys are missing,
   * it fails closed and yields no documents.
   *
   * @return \Generator<string,array<string,mixed>>
   */
  private function iterateManagedYamlFilesForHandler($handler, YamlFileStorage $storage): \Generator {
    $policy = $this->scope->getPolicy((string) $handler->getType());
    $mode = (string) ($policy['mode'] ?? ConfigScope::MODE_ALL);
    if (!in_array($mode, [ConfigScope::MODE_ALL, ConfigScope::MODE_SELECTED], TRUE)) {
      return;
    }

    $portableSelectorMap = [];
    if ($mode === ConfigScope::MODE_SELECTED) {
      $manifest = $this->readManifest($storage);
      $portableSelectorMap = $this->scope->portableSelectorMapFromManifest($manifest, (string) $handler->getType());
      unset($manifest);
    }

    $directory = trim((string) $handler->getDirectory(), '/');
    foreach ($storage->iterateDirectory((string) $handler->getDirectory()) as $filename => $data) {
      $filename = (string) $filename;
      $relative = $directory === '' ? $filename : $directory . '/' . ltrim($filename, '/');
      if ($this->isIgnoredPath($relative)) {
        continue;
      }
      $filtered = $this->applyHandlerFileFilter($handler, [$filename => (array) $data]);
      if (!array_key_exists($filename, $filtered)) {
        continue;
      }
      $data = $this->applyIgnoredValueRules($relative, (array) $filtered[$filename]);
      if ($mode === ConfigScope::MODE_SELECTED) {
        // Evaluate one YAML document at a time using the same selector aliases,
        // resolved selector map, and portable manifest aliases as the original
        // partition() path. Looking only at manifest config_keys breaks archives
        // after identity-format migrations and ignores a currently configured
        // selector which still matches the YAML by name/path.
        $partition = $this->scope->partition((string) $handler->getType(), [[
          'filename' => $filename,
          'relative_path' => $relative,
          'data' => $data,
        ]], FALSE, $portableSelectorMap);
        if (empty($partition['managed'])) {
          continue;
        }
      }
      yield $filename => $data;
    }
  }

  public function export(bool $dryRun = TRUE, array $typeFilter = [], ?callable $progress = NULL): array {
    $storage = new YamlFileStorage($this->getSyncDir());
    $operationLock = OperationLock::acquire($storage->getRoot());
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

    $workspace = new StagedExportWorkspace($storage);
    $successfulHandlers = [];
    $scopeManifestUpdates = [];
    $resolvedPartitions = [];
    $stagedActiveFingerprints = [];
    $eligibleHandlers = [];
    foreach ($this->getHandlers() as $candidateHandler) {
      if (!$effectiveTypes || in_array($candidateHandler->getType(), $effectiveTypes, TRUE)) {
        $eligibleHandlers[] = $candidateHandler;
      }
    }
    $totalSteps = max(1, count($eligibleHandlers) + 3);
    $completedSteps = 0;
    $processedItems = 0;
    $this->reportProgress($progress, $completedSteps, $totalSteps, 'Preparing export', 'Building a staged configuration snapshot.', $processedItems);

    try {
      // Scope modes which do not need resolved selected-item keys can be
      // represented before provider discovery. The live manifest is not touched
      // until the complete staged snapshot has passed every handler.
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

      foreach ($eligibleHandlers as $handler) {
        $this->prepareHandlerForTypeFilter($handler, $requestedTypes);
        $handlerType = (string) $handler->getType();
        $handlerPolicy = $this->scope->getPolicy($handlerType);
        $handlerLabel = (string) $handler->getLabel();
        $this->reportProgress($progress, $completedSteps, $totalSteps, 'Exporting ' . $handlerLabel, 'Streaming active configuration to the staged snapshot.', $processedItems);

        try {
          // Manage Everything is the large-site path. Stream each document
          // directly to disk so there is never a handler-wide export array or a
          // site-wide queue of complete YAML documents in memory.
          if (($handlerPolicy['mode'] ?? ConfigScope::MODE_ALL) === ConfigScope::MODE_ALL
            && $handler instanceof StreamingHandlerInterface) {
            $partition = [
              'policy' => $handlerPolicy,
              'managed_config_keys' => [],
              'matched_selectors' => [],
              'selector_config_keys' => [],
              'unresolved_selectors' => [],
              'missing_selectors' => [],
            ];
            foreach ($handler->iterateExport() as $file) {
              $this->stageExportFile($workspace, $handler, (array) $file, $summary);
              $processedItems++;
              if (($processedItems % 25) === 0) {
                $this->reportProgress($progress, $completedSteps, $totalSteps, 'Exporting ' . $handlerLabel, 'Streaming active configuration to the staged snapshot.', $processedItems);
              }
            }
          }
          else {
            // Selected/watch/ignore and legacy third-party handlers retain the
            // compatibility contract. They are staged transactionally even if
            // their old HandlerInterface::export() implementation materializes
            // one handler collection.
            $exported = $handler->export();
            $partition = $this->scopePartition($handler, $exported, $storage, TRUE);
            foreach ((array) ($partition['managed'] ?? []) as $file) {
              $this->stageExportFile($workspace, $handler, (array) $file, $summary);
              $processedItems++;
            }
            unset($exported);
          }

          $handlerExportErrors = $this->consumeHandlerExportErrors($handler, $summary['errors']);
          if ($handlerExportErrors === 0 || (string) ($handlerPolicy['mode'] ?? '') !== ConfigScope::MODE_SELECTED) {
            $scopeManifestUpdates[$handlerType] = $this->scope->manifestEntry($handlerType, $partition);
          }

          foreach ((array) ($partition['unresolved_selectors'] ?? []) as $selector) {
            $summary['warnings'][] = [
              'type' => $handlerType,
              'message' => 'Configured scope selector has never resolved to an active CiviCRM object: ' . (string) $selector . '.',
            ];
          }
          foreach ((array) ($partition['missing_selectors'] ?? []) as $selector) {
            $summary['warnings'][] = [
              'type' => $handlerType,
              'message' => 'Configured managed object is currently missing from CiviCRM: ' . (string) $selector . '. Existing YAML backup is preserved for review or restore.',
            ];
          }

          if ($handlerExportErrors === 0) {
            $successfulHandlers[] = $handler;
            $resolvedPartitions[$handlerType] = [
              'policy' => (array) ($partition['policy'] ?? $handlerPolicy),
              'matched_selectors' => (array) ($partition['matched_selectors'] ?? []),
              'selector_config_keys' => (array) ($partition['selector_config_keys'] ?? []),
              'managed_config_keys' => array_values(array_map('strval', (array) ($partition['managed_config_keys'] ?? []))),
            ];
          }
          unset($partition);
          $completedSteps++;
          $this->reportProgress($progress, $completedSteps, $totalSteps, 'Exported ' . $handlerLabel, 'Handler snapshot completed.', $processedItems);
        }
        catch (\Throwable $e) {
          $summary['errors'][] = [
            'type' => $handlerType,
            'message' => $e->getMessage(),
          ];
        }
      }

      // A staged export is an all-or-nothing snapshot. Provider failure,
      // duplicate path, or any other handler error leaves the previous live
      // YAML tree untouched instead of publishing a partial snapshot.
      if ($summary['errors']) {
        $summary['ok'] = FALSE;
        $summary['message'] = 'Export staging failed. The previous YAML snapshot was left unchanged.';
        return $summary;
      }

      // Freeze a compact fingerprint of the active-derived staged documents
      // before reverse dependency metadata is added. We re-scan active CiviCRM
      // immediately before publish; a manual/admin change during staging must
      // never produce a mixed snapshot.
      foreach ($successfulHandlers as $handler) {
        $policy = $this->scope->getPolicy((string) $handler->getType());
        if (($policy['mode'] ?? ConfigScope::MODE_ALL) !== ConfigScope::MODE_ALL) {
          continue;
        }
        $stagedActiveFingerprints[(string) $handler->getType()] = $this->compactSnapshotFromYamlStorage(
          $handler,
          $workspace->getStageStorage()
        );
      }

      $this->reportProgress($progress, $completedSteps, $totalSteps, 'Finalizing staged YAML', 'Rebuilding provider indexes and reverse dependency metadata.', $processedItems);
      $this->pruneExtensionIndexesInStagedExport($workspace);
      $this->addReverseDependencyMetadataToStagedExport($workspace);
      $completedSteps++;
      $this->reportProgress($progress, $completedSteps, $totalSteps, 'Staged YAML finalized', 'Calculating the publish and stale-file plan.', $processedItems);

      $existingManifest = $this->readManifest($storage);
      $existingScope = (array) ($existingManifest['managed_scope'] ?? []);
      foreach ($scopeManifestUpdates as $type => $scopeEntry) {
        $existingScope[$type] = $scopeEntry;
      }
      ksort($existingScope, SORT_NATURAL | SORT_FLAG_CASE);
      $workspace->stage('__manifest__', '', 'manifest.yml', $this->getManifestData($existingScope));

      $stalePaths = $this->findStaleYamlPathsForStagedExport(
        $storage,
        $workspace,
        $successfulHandlers,
        $requestedTypes
      );
      $preview = $workspace->preview($stalePaths);

      $this->reportProgress($progress, $completedSteps, $totalSteps, 'Verifying active snapshot', 'Checking that CiviCRM configuration did not change while export was staged.', $processedItems);
      foreach ($successfulHandlers as $handler) {
        $type = (string) $handler->getType();
        if (!array_key_exists($type, $stagedActiveFingerprints)) {
          continue;
        }
        $this->assertActiveSnapshotMatches($handler, $stagedActiveFingerprints[$type]);
      }

      if ($dryRun) {
        foreach ($preview['write'] as $relative) {
          $summary['planned'][] = $relative;
        }
        foreach ($preview['delete'] as $relative) {
          $summary['delete_planned'][] = $relative;
          $summary['planned'][] = $relative . ' (delete stale YAML)';
        }
        foreach ($preview['skip'] as $relative) {
          $summary['skipped'][] = $relative;
        }
      }
      else {
        $this->reportProgress($progress, $completedSteps, $totalSteps, 'Publishing YAML snapshot', 'Atomically committing staged files; manifest.yml will be written last.', $processedItems);
        $published = $workspace->publish($stalePaths);
        $summary['written'] = $published['written'];
        $summary['deleted'] = $published['deleted'];
        $summary['skipped'] = array_values(array_unique(array_merge($summary['skipped'], $published['skipped'])));

        // Persist selected-scope aliases only after the filesystem commit.
        foreach ($resolvedPartitions as $type => $partition) {
          $this->scope->persistResolvedMatches((string) $type, (array) $partition);
        }
        $completedSteps++;
        $this->reportProgress($progress, $completedSteps, $totalSteps, 'YAML snapshot committed', 'Updating local synchronization baseline.', $processedItems);

        // Accept baseline state one staged YAML document at a time. This keeps
        // canonicalization memory bounded and ensures the baseline represents
        // the exact snapshot which was just committed.
        try {
          $stateManager = new ConfigStateManager();
          $stageStorage = $workspace->getStageStorage();
          foreach ($successfulHandlers as $handler) {
            $directory = trim((string) $handler->getDirectory(), '/');
            foreach ($stageStorage->iterateDirectory($directory) as $filename => $data) {
              $stateManager->acceptYamlBaselineItem($handler, (string) $filename, (array) $data, 'export');
            }
          }
        }
        catch (\Throwable $e) {
          $summary['warnings'][] = ['type' => 'state', 'message' => 'YAML export succeeded, but local baseline state could not be updated: ' . $e->getMessage()];
        }
      }
      if ($dryRun) {
        $completedSteps++;
      }
      $completedSteps = $totalSteps;
      $this->reportProgress($progress, $completedSteps, $totalSteps, $dryRun ? 'Export preview complete' : 'Export complete', 'Configuration snapshot processing finished.', $processedItems);
    }
    catch (\Throwable $e) {
      $summary['errors'][] = ['type' => 'export', 'message' => $e->getMessage()];
      $summary['ok'] = FALSE;
      $summary['message'] = 'Export failed. The previous YAML snapshot was preserved or rolled back.';
      return $summary;
    }
    finally {
      $workspace->cleanup();
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
   * Build the durable alpha63 web-export plan.
   *
   * Staging work is split by handler and, where supported, by provider/bucket.
   * The final optimistic verification remains immediately adjacent to publish so
   * a manual CiviCRM edit cannot slip between a per-handler verification task
   * and live YAML publication.
   *
   * @return array<int,array<string,mixed>>
   */
  public function buildQueuedExportPlan(array $typeFilter = []): array {
    $requestedTypes = $this->normaliseTypeFilter($typeFilter);
    $effectiveTypes = $this->getEffectiveExportTypeFilter($requestedTypes);
    $handlers = [];
    foreach ($this->getHandlers() as $handler) {
      if ($effectiveTypes && !in_array((string) $handler->getType(), $effectiveTypes, TRUE)) {
        continue;
      }
      $this->prepareHandlerForTypeFilter($handler, $requestedTypes);
      $handlers[] = $handler;
    }

    $tasks = [[
      'key' => 'export:prepare',
      'action' => 'export_prepare',
      'phase' => 'prepare',
      'phase_index' => 1,
      'phase_total' => 6,
      'label' => 'Preparing export workspace',
      'message' => 'Creating a private temporary workspace. Active CiviCRM and live YAML are unchanged.',
      'retry_safe' => TRUE,
    ]];

    foreach ($handlers as $handler) {
      $type = (string) $handler->getType();
      $policy = $this->scope->getPolicy($type);
      if (($policy['mode'] ?? ConfigScope::MODE_ALL) === ConfigScope::MODE_ALL
        && $handler instanceof ChunkedStreamingHandlerInterface) {
        foreach ($handler->getExportUnits() as $unit) {
          $unit = (array) $unit;
          $unitKey = (string) ($unit['key'] ?? '');
          if ($unitKey === '') {
            continue;
          }
          $tasks[] = [
            'key' => 'export:stage:' . $type . ':' . substr(hash('sha256', $unitKey), 0, 16),
            'action' => 'export_stage',
            'phase' => 'stage',
            'phase_index' => 2,
            'phase_total' => 6,
            'handler_type' => $type,
            'unit_key' => $unitKey,
            'label' => 'Scanning active CiviCRM — ' . (string) ($unit['label'] ?? $handler->getLabel()),
            'message' => 'Reading this configuration group once and building its temporary YAML snapshot. Live YAML is unchanged.',
            'retry_safe' => TRUE,
          ];
        }
      }
      else {
        $tasks[] = [
          'key' => 'export:stage:' . $type,
          'action' => 'export_stage',
          'phase' => 'stage',
          'phase_index' => 2,
          'phase_total' => 6,
          'handler_type' => $type,
          'unit_key' => '__handler__',
          'label' => 'Scanning active CiviCRM — ' . $handler->getLabel(),
          'message' => (($policy['mode'] ?? ConfigScope::MODE_ALL) === ConfigScope::MODE_SELECTED)
            ? 'Resolving the selected configuration scope and building temporary YAML. Live YAML is unchanged.'
            : 'Reading active configuration and building temporary YAML. Live YAML is unchanged.',
          'retry_safe' => TRUE,
        ];
      }
    }

    $tasks[] = [
      'key' => 'export:metadata',
      'action' => 'export_metadata',
      'phase' => 'metadata',
      'phase_index' => 3,
      'phase_total' => 6,
      'label' => 'Finalizing temporary YAML metadata',
      'message' => 'Rebuilding extension indexes, dependency links, manifest scope, and stale-file plan from compact staging metadata.',
      'retry_safe' => TRUE,
    ];
    $tasks[] = [
      'key' => 'export:verify-publish',
      'action' => 'export_verify_publish',
      'phase' => 'verify_publish',
      'phase_index' => 4,
      'phase_total' => 6,
      'label' => 'Safety verification before publishing YAML',
      'message' => 'Rechecking active CiviCRM immediately before publication. If anything changed, live YAML will remain untouched.',
      'retry_safe' => FALSE,
    ];
    foreach ($handlers as $handler) {
      $tasks[] = [
        'key' => 'export:baseline:' . (string) $handler->getType(),
        'action' => 'export_baseline',
        'phase' => 'baseline',
        'phase_index' => 5,
        'phase_total' => 6,
        'handler_type' => (string) $handler->getType(),
        'label' => 'Recording synchronization baseline — ' . $handler->getLabel(),
        'message' => 'Recording the published YAML state for future three-way synchronization checks.',
        'retry_safe' => TRUE,
      ];
    }
    $tasks[] = [
      'key' => 'export:complete',
      'action' => 'export_complete',
      'phase' => 'complete',
      'phase_index' => 6,
      'phase_total' => 6,
      'label' => 'Completing export',
      'message' => 'Finalizing the durable export result and cleaning the temporary workspace.',
      'retry_safe' => TRUE,
    ];
    return $tasks;
  }

  /** @return array<string,mixed> */
  public function queuedExportPrepare(int $jobId, string $syncRootHash, array $typeFilter = []): array {
    $storage = new YamlFileStorage($this->getSyncDir());
    $operationLock = OperationLock::acquire($storage->getRoot(), $jobId);
    $workspaceState = new OperationWorkspace($jobId, $syncRootHash);
    // Prepare is retry-safe and is always first; reset any incomplete staging
    // left by an interrupted prepare attempt.
    $workspaceState->cleanup();
    $workspaceState = new OperationWorkspace($jobId, $syncRootHash);
    $exportWorkspace = new StagedExportWorkspace($storage, $workspaceState->getExportRoot(), FALSE);

    $requestedTypes = $this->normaliseTypeFilter($typeFilter);
    $effectiveTypes = $this->getEffectiveExportTypeFilter($requestedTypes);
    $dependencyTypes = $requestedTypes ? array_values(array_diff($effectiveTypes, $requestedTypes)) : [];
    $scopeManifestUpdates = [];
    foreach ($this->getScopeTypeOptions() as $scopeType) {
      $type = (string) ($scopeType['type'] ?? '');
      if ($type === '') {
        continue;
      }
      $policy = $this->scope->getPolicy($type);
      if (in_array((string) ($policy['mode'] ?? ''), [ConfigScope::MODE_ALL, ConfigScope::MODE_WATCH, ConfigScope::MODE_IGNORE], TRUE)) {
        $scopeManifestUpdates[$type] = $this->scope->manifestEntry($type, ['policy' => $policy]);
      }
    }

    $state = [
      'operation' => 'export',
      'requested_types' => $requestedTypes,
      'effective_types' => $effectiveTypes,
      'dependency_types' => $dependencyTypes,
      'scope_manifest_updates' => $scopeManifestUpdates,
      'resolved_partitions' => [],
      'stage_unit_results' => [],
      'processed_items' => 0,
      'monitor_only' => 0,
      'warnings' => [],
      'skipped_stage' => [],
      'published' => FALSE,
      'published_result' => [],
      'baseline_warnings' => [],
    ];
    $workspaceState->saveState($state);
    $exportWorkspace->persistIndex();
    return ['ok' => TRUE, 'processed_items' => 0, 'monitor_only' => 0];
  }

  /** @return array<string,mixed> */
  public function queuedExportStage(int $jobId, string $syncRootHash, string $handlerType, string $unitKey, ?callable $progress = NULL): array {
    $storage = new YamlFileStorage($this->getSyncDir());
    $operationLock = OperationLock::acquire($storage->getRoot(), $jobId);
    $stateStore = new OperationWorkspace($jobId, $syncRootHash);
    $state = $stateStore->loadState();
    if (($state['operation'] ?? '') !== 'export') {
      throw new \RuntimeException('Queued export workspace is missing or belongs to another operation.');
    }
    $handler = $this->handlerByType($handlerType);
    if ($handler === NULL) {
      throw new \RuntimeException('Queued export handler is no longer registered: ' . $handlerType);
    }
    $requestedTypes = array_values(array_map('strval', (array) ($state['requested_types'] ?? [])));
    $this->prepareHandlerForTypeFilter($handler, $requestedTypes);
    $policy = $this->scope->getPolicy($handlerType);
    $workspace = new StagedExportWorkspace($storage, $stateStore->getExportRoot(), FALSE);
    // Safe staging retries remove only files produced by this exact unit.
    $workspace->removeUnit($handlerType, $unitKey);

    $local = ['available' => [], 'skipped' => [], 'warnings' => [], 'errors' => [], 'monitor_only' => 0];
    $processed = 0;
    $partition = NULL;
    if (($policy['mode'] ?? ConfigScope::MODE_ALL) === ConfigScope::MODE_ALL
      && $handler instanceof ChunkedStreamingHandlerInterface
      && $unitKey !== '__handler__') {
      $rows = $handler->iterateExportUnit($unitKey, function(array $event) use ($progress, $state): void {
        if ($progress === NULL) { return; }
        $progress([
          'processed_items' => (int) ($state['processed_items'] ?? 0) + (int) ($event['processed'] ?? 0),
          'item_completed' => (int) ($event['processed'] ?? 0),
          'item_total' => 0,
          'progress_known' => FALSE,
          'message' => (string) ($event['message'] ?? 'Scanning active configuration.'),
        ]);
      });
      foreach ($rows as $file) {
        $this->stageExportFile($workspace, $handler, (array) $file, $local, $unitKey);
        $processed++;
        if (($processed % 50) === 0 && $progress !== NULL) {
          $progress([
            'processed_items' => (int) ($state['processed_items'] ?? 0) + $processed,
            'item_completed' => $processed,
            'item_total' => 0,
            'progress_known' => FALSE,
            'message' => 'Temporary YAML is being built from active ' . $handler->getLabel() . ' configuration. ' . $processed . ' record(s) processed in this work unit; live YAML is unchanged.',
          ]);
        }
      }
      $errors = [];
      if ($this->consumeHandlerExportErrors($handler, $errors) > 0) {
        throw new \RuntimeException((string) (($errors[0]['message'] ?? '') ?: 'Provider scan was incomplete.'));
      }
    }
    elseif (($policy['mode'] ?? ConfigScope::MODE_ALL) === ConfigScope::MODE_ALL
      && $handler instanceof StreamingHandlerInterface) {
      foreach ($handler->iterateExport() as $file) {
        $this->stageExportFile($workspace, $handler, (array) $file, $local, $unitKey);
        $processed++;
      }
      $errors = [];
      if ($this->consumeHandlerExportErrors($handler, $errors) > 0) {
        throw new \RuntimeException((string) (($errors[0]['message'] ?? '') ?: 'Provider scan was incomplete.'));
      }
    }
    else {
      $exported = $handler->export();
      $errors = [];
      if ($this->consumeHandlerExportErrors($handler, $errors) > 0) {
        throw new \RuntimeException((string) (($errors[0]['message'] ?? '') ?: 'Provider scan was incomplete.'));
      }
      $partition = $this->scopePartition($handler, $exported, $storage, TRUE);
      foreach ((array) ($partition['managed'] ?? []) as $file) {
        $this->stageExportFile($workspace, $handler, (array) $file, $local, $unitKey);
        $processed++;
      }
      unset($exported);
      $state['scope_manifest_updates'][$handlerType] = $this->scope->manifestEntry($handlerType, $partition);
      $state['resolved_partitions'][$handlerType] = [
        'policy' => (array) ($partition['policy'] ?? $policy),
        'matched_selectors' => (array) ($partition['matched_selectors'] ?? []),
        'selector_config_keys' => (array) ($partition['selector_config_keys'] ?? []),
        'managed_config_keys' => array_values(array_map('strval', (array) ($partition['managed_config_keys'] ?? []))),
      ];
      foreach ((array) ($partition['unresolved_selectors'] ?? []) as $selector) {
        $local['warnings'][] = ['type' => $handlerType, 'message' => 'Configured scope selector has never resolved to an active CiviCRM object: ' . (string) $selector . '.'];
      }
      foreach ((array) ($partition['missing_selectors'] ?? []) as $selector) {
        $local['warnings'][] = ['type' => $handlerType, 'message' => 'Configured managed object is currently missing from CiviCRM: ' . (string) $selector . '. Existing YAML backup is preserved for review or restore.'];
      }
    }

    $workspace->persistIndex();

    // Work-unit accounting is keyed, not increment-only. If PHP completed the
    // unit and saved workspace state but died before CiviCRM Queue recorded the
    // item as complete, a safe retry replaces this unit's counters instead of
    // double-counting processed/monitor-only records or warnings.
    $stageResultKey = $handlerType . '|' . $unitKey;
    $state['stage_unit_results'][$stageResultKey] = [
      'processed' => $processed,
      'monitor_only' => (int) ($local['monitor_only'] ?? 0),
      'warnings' => array_values((array) ($local['warnings'] ?? [])),
      'skipped' => array_values((array) ($local['skipped'] ?? [])),
    ];
    $state['processed_items'] = 0;
    $state['monitor_only'] = 0;
    $state['warnings'] = [];
    $state['skipped_stage'] = [];
    foreach ((array) ($state['stage_unit_results'] ?? []) as $unitResult) {
      $unitResult = (array) $unitResult;
      $state['processed_items'] += (int) ($unitResult['processed'] ?? 0);
      $state['monitor_only'] += (int) ($unitResult['monitor_only'] ?? 0);
      $state['warnings'] = array_merge($state['warnings'], (array) ($unitResult['warnings'] ?? []));
      $state['skipped_stage'] = array_merge($state['skipped_stage'], (array) ($unitResult['skipped'] ?? []));
    }
    $stateStore->saveState($state);
    return [
      'ok' => TRUE,
      'processed_items' => (int) $state['processed_items'],
      'unit_processed' => $processed,
      'monitor_only' => (int) $state['monitor_only'],
    ];
  }

  /** @return array<string,mixed> */
  public function queuedExportFinalizeMetadata(int $jobId, string $syncRootHash): array {
    $storage = new YamlFileStorage($this->getSyncDir());
    $operationLock = OperationLock::acquire($storage->getRoot(), $jobId);
    $stateStore = new OperationWorkspace($jobId, $syncRootHash);
    $state = $stateStore->loadState();
    $workspace = new StagedExportWorkspace($storage, $stateStore->getExportRoot(), FALSE);

    $this->pruneExtensionIndexesInStagedExport($workspace);
    $this->addReverseDependencyMetadataToStagedExport($workspace);

    // Retry-safe metadata task replaces its own manifest document.
    $workspace->removeUnit('__manifest__', 'metadata');
    $existingManifest = $this->readManifest($storage);
    $existingScope = (array) ($existingManifest['managed_scope'] ?? []);
    foreach ((array) ($state['scope_manifest_updates'] ?? []) as $type => $scopeEntry) {
      $existingScope[(string) $type] = (array) $scopeEntry;
    }
    ksort($existingScope, SORT_NATURAL | SORT_FLAG_CASE);
    $workspace->stage('__manifest__', '', 'manifest.yml', $this->getManifestData($existingScope), [
      'unit_key' => 'metadata',
      'document_type' => 'manifest',
      'names' => [],
      'dependencies' => [],
    ]);

    $handlers = $this->handlersForTypes((array) ($state['effective_types'] ?? []), (array) ($state['requested_types'] ?? []));
    $stalePaths = $this->findStaleYamlPathsForStagedExport($storage, $workspace, $handlers, array_values(array_map('strval', (array) ($state['requested_types'] ?? []))));
    $preview = $workspace->preview($stalePaths);
    $expected = [];
    foreach ($handlers as $handler) {
      $policy = $this->scope->getPolicy((string) $handler->getType());
      if (($policy['mode'] ?? ConfigScope::MODE_ALL) !== ConfigScope::MODE_ALL) {
        continue;
      }
      $expected[(string) $handler->getType()] = $this->compactSnapshotFromWorkspace($handler, $workspace);
    }
    $state['stale_paths'] = $stalePaths;
    $state['preview'] = $preview;
    $state['expected_fingerprints'] = $expected;
    $workspace->persistIndex();
    $stateStore->saveState($state);
    return [
      'ok' => TRUE,
      'processed_items' => (int) ($state['processed_items'] ?? 0),
      'write_count' => count((array) ($preview['write'] ?? [])),
      'delete_count' => count((array) ($preview['delete'] ?? [])),
      'skip_count' => count((array) ($preview['skip'] ?? [])),
      'monitor_only' => (int) ($state['monitor_only'] ?? 0),
    ];
  }

  /** @return array<string,mixed> */
  public function queuedExportVerifyAndPublish(int $jobId, string $syncRootHash, ?callable $progress = NULL): array {
    $storage = new YamlFileStorage($this->getSyncDir());
    $operationLock = OperationLock::acquire($storage->getRoot(), $jobId);
    $stateStore = new OperationWorkspace($jobId, $syncRootHash);
    $state = $stateStore->loadState();
    $workspace = new StagedExportWorkspace($storage, $stateStore->getExportRoot(), FALSE);
    $handlers = $this->handlersForTypes((array) ($state['effective_types'] ?? []), (array) ($state['requested_types'] ?? []));
    $expected = (array) ($state['expected_fingerprints'] ?? []);

    $verified = 0;
    $verifyTotal = max(1, count($expected));
    foreach ($handlers as $handler) {
      $type = (string) $handler->getType();
      if (!array_key_exists($type, $expected)) {
        continue;
      }
      if ($progress !== NULL) {
        $progress([
          'progress_known' => FALSE,
          'item_completed' => $verified,
          'item_total' => $verifyTotal,
          'message' => 'Safety verification — ' . $handler->getLabel() . '. Re-reading active CiviCRM immediately before publication; live YAML is still unchanged.',
        ]);
      }
      $this->assertActiveSnapshotMatches($handler, (array) $expected[$type]);
      $verified++;
    }

    if ($progress !== NULL) {
      $progress([
        'progress_known' => FALSE,
        'item_completed' => $verified,
        'item_total' => $verifyTotal,
        'message' => 'Safety verification passed. Publishing the verified staged YAML snapshot now; manifest.yml is written last.',
      ]);
    }
    $published = $workspace->publish(array_values(array_map('strval', (array) ($state['stale_paths'] ?? []))));
    foreach ((array) ($state['resolved_partitions'] ?? []) as $type => $partition) {
      $this->scope->persistResolvedMatches((string) $type, (array) $partition);
    }
    $state['published'] = TRUE;
    $state['published_result'] = $published;
    $stateStore->saveState($state);
    return [
      'ok' => TRUE,
      'processed_items' => (int) ($state['processed_items'] ?? 0),
      'written' => count((array) ($published['written'] ?? [])),
      'deleted' => count((array) ($published['deleted'] ?? [])),
      'skipped' => count((array) ($published['skipped'] ?? [])),
      'monitor_only' => (int) ($state['monitor_only'] ?? 0),
    ];
  }

  /**
   * Recover a hard-interrupted alpha63 YAML publication before the job is
   * blocked for operator review. This never resumes the export automatically.
   *
   * @return array{ok:bool,recovered:bool,errors:string[]}
   */
  public function recoverQueuedExportPublish(int $jobId, string $syncRootHash): array {
    $storage = new YamlFileStorage($this->getSyncDir());
    $operationLock = OperationLock::acquire($storage->getRoot(), $jobId);
    $stateStore = new OperationWorkspace($jobId, $syncRootHash);
    $workspace = new StagedExportWorkspace($storage, $stateStore->getExportRoot(), FALSE);
    $recovery = $workspace->recoverIncompletePublish();
    return [
      'ok' => empty($recovery['errors']),
      'recovered' => !empty($recovery['recovered']),
      'errors' => array_values(array_map('strval', (array) ($recovery['errors'] ?? []))),
    ];
  }

  /** @return array<string,mixed> */
  public function queuedExportBaseline(int $jobId, string $syncRootHash, string $handlerType): array {
    $storage = new YamlFileStorage($this->getSyncDir());
    $operationLock = OperationLock::acquire($storage->getRoot(), $jobId);
    $stateStore = new OperationWorkspace($jobId, $syncRootHash);
    $state = $stateStore->loadState();
    if (empty($state['published'])) {
      throw new \RuntimeException('Cannot record export baseline before the YAML snapshot is published.');
    }
    $handler = $this->handlerByType($handlerType);
    if ($handler === NULL) {
      throw new \RuntimeException('Baseline handler is no longer registered: ' . $handlerType);
    }
    $workspace = new StagedExportWorkspace($storage, $stateStore->getExportRoot(), FALSE);
    $directory = trim((string) $handler->getDirectory(), '/');
    $count = 0;
    try {
      $stateManager = new ConfigStateManager();
      foreach ($workspace->getStageStorage()->iterateDirectory($directory) as $filename => $data) {
        $stateManager->acceptYamlBaselineItem($handler, (string) $filename, (array) $data, 'export');
        $count++;
      }
    }
    catch (\Throwable $e) {
      // Live YAML is already safely published. Baseline failure is non-fatal and
      // remains a warning, matching the synchronous export contract.
      $state['baseline_warnings'][] = ['type' => $handlerType, 'message' => 'YAML export succeeded, but local baseline state could not be updated: ' . $e->getMessage()];
    }
    $stateStore->saveState($state);
    return ['ok' => TRUE, 'processed_items' => (int) ($state['processed_items'] ?? 0), 'baseline_items' => $count];
  }

  /** @return array<string,mixed> */
  public function queuedExportComplete(int $jobId, string $syncRootHash): array {
    $stateStore = new OperationWorkspace($jobId, $syncRootHash);
    $state = $stateStore->loadState();
    if (empty($state['published'])) {
      throw new \RuntimeException('Queued export cannot complete because no verified YAML snapshot was published.');
    }
    $published = (array) ($state['published_result'] ?? []);
    $result = [
      'ok' => TRUE,
      'dry_run' => FALSE,
      'sync_dir' => $this->getSyncDir(),
      'requested_types' => array_values(array_map('strval', (array) ($state['requested_types'] ?? []))),
      'effective_types' => array_values(array_map('strval', (array) ($state['effective_types'] ?? []))),
      'dependency_types' => array_values(array_map('strval', (array) ($state['dependency_types'] ?? []))),
      'written' => (array) ($published['written'] ?? []),
      'deleted' => (array) ($published['deleted'] ?? []),
      'skipped' => array_values(array_unique(array_merge((array) ($published['skipped'] ?? []), (array) ($state['skipped_stage'] ?? [])))),
      'warnings' => array_merge((array) ($state['warnings'] ?? []), (array) ($state['baseline_warnings'] ?? [])),
      'errors' => [],
      'monitor_only' => (int) ($state['monitor_only'] ?? 0),
      'processed_items' => (int) ($state['processed_items'] ?? 0),
    ];
    if (!$result['written'] && !$result['deleted']) {
      $result['message'] = 'No files written. YAML files already match active CiviCRM configuration.';
    }
    else {
      $result['message'] = 'Export complete. The verified YAML snapshot was published successfully.';
    }
    return $result;
  }

  /** @return object|null */
  private function handlerByType(string $type) {
    foreach ($this->getHandlers() as $handler) {
      if ((string) $handler->getType() === $type) {
        return $handler;
      }
    }
    return NULL;
  }

  /** @return object[] */
  private function handlersForTypes(array $effectiveTypes, array $requestedTypes): array {
    $effectiveTypes = array_values(array_map('strval', $effectiveTypes));
    $requestedTypes = array_values(array_map('strval', $requestedTypes));
    $handlers = [];
    foreach ($this->getHandlers() as $handler) {
      if ($effectiveTypes && !in_array((string) $handler->getType(), $effectiveTypes, TRUE)) {
        continue;
      }
      $this->prepareHandlerForTypeFilter($handler, $requestedTypes);
      $handlers[] = $handler;
    }
    return $handlers;
  }

  /**
   * Build the pre-enrichment active fingerprint from compact staging metadata.
   */
  private function compactSnapshotFromWorkspace($handler, StagedExportWorkspace $workspace): array {
    $groups = [];
    $type = (string) $handler->getType();
    foreach ($workspace->files() as $relative => $metadata) {
      $metadata = (array) $metadata;
      if ((string) ($metadata['type'] ?? '') !== $type || empty($metadata['config_key'])) {
        continue;
      }
      $row = [
        'filename' => (string) ($metadata['filename'] ?? basename((string) $relative)),
        'path' => (string) $relative,
        'identity' => (array) ($metadata['identity'] ?? []),
        'hash' => (string) ($metadata['hash'] ?? ''),
        'rename_signature' => '',
      ];
      $groups[(string) $metadata['config_key']][] = $row;
    }
    $indexed = $this->indexCompactDiffGroups($groups);
    $result = [];
    foreach ($indexed as $key => $row) {
      $result[(string) $key] = (string) ($row['hash'] ?? '');
    }
    ksort($result, SORT_STRING);
    return $result;
  }

  private function reportProgress(?callable $progress, int $completed, int $total, string $label, string $message, int $processedItems = 0): void {
    if ($progress === NULL) {
      return;
    }
    $total = max(1, $total);
    $completed = max(0, min($completed, $total));
    $progress([
      'completed' => $completed,
      'total' => $total,
      'percent' => (int) floor(($completed / $total) * 100),
      'label' => $label,
      'message' => $message,
      'processed_items' => max(0, $processedItems),
    ]);
  }

  /**
   * Build compact identity/hash state from staged or live YAML documents.
   * Whole documents are released after each hash is calculated.
   */
  private function compactSnapshotFromYamlStorage($handler, YamlFileStorage $storage): array {
    $identityService = new ConfigIdentity();
    $canonicalizer = new Canonicalizer();
    $options = method_exists($handler, 'getCanonicalizationOptions') ? (array) $handler->getCanonicalizationOptions() : [];
    $directory = trim((string) $handler->getDirectory(), '/');
    $groups = [];
    foreach ($storage->iterateDirectory($directory) as $filename => $data) {
      $filename = ltrim((string) $filename, '/');
      $relative = $directory === '' ? $filename : $directory . '/' . $filename;
      if ($this->isIgnoredPath($relative)) {
        continue;
      }
      $row = $this->compactDiffRow((string) $handler->getType(), $filename, $relative, (array) $data, $identityService, $canonicalizer, $options);
      $groups[(string) $row['identity']['config_key']][] = $row;
      unset($row, $data);
    }
    $indexed = $this->indexCompactDiffGroups($groups);
    $result = [];
    foreach ($indexed as $key => $row) {
      $result[(string) $key] = (string) ($row['hash'] ?? '');
    }
    ksort($result, SORT_STRING);
    return $result;
  }

  /**
   * Re-scan active CiviCRM and fail closed if it changed after export staging.
   */
  private function assertActiveSnapshotMatches($handler, array $expected): void {
    $identityService = new ConfigIdentity();
    $canonicalizer = new Canonicalizer();
    $options = method_exists($handler, 'getCanonicalizationOptions') ? (array) $handler->getCanonicalizationOptions() : [];
    $directory = trim((string) $handler->getDirectory(), '/');
    $groups = [];
    $rows = $handler instanceof StreamingHandlerInterface ? $handler->iterateExport() : $handler->export();
    foreach ($rows as $file) {
      $file = (array) $file;
      $filename = ltrim((string) ($file['filename'] ?? ''), '/');
      if ($filename === '') {
        continue;
      }
      $relative = $directory === '' ? $filename : $directory . '/' . $filename;
      if ($this->isIgnoredPath($relative)) {
        continue;
      }
      $data = $this->applyIgnoredValueRules($relative, (array) ($file['data'] ?? []));
      $row = $this->compactDiffRow((string) $handler->getType(), $filename, $relative, $data, $identityService, $canonicalizer, $options);
      $groups[(string) $row['identity']['config_key']][] = $row;
      unset($data, $row);
    }
    $errors = [];
    if ($this->consumeHandlerExportErrors($handler, $errors) > 0) {
      throw new \RuntimeException('Active provider verification failed after export staging: ' . (string) ($errors[0]['message'] ?? 'provider scan incomplete'));
    }
    $indexed = $this->indexCompactDiffGroups($groups);
    $actual = [];
    foreach ($indexed as $key => $row) {
      $actual[(string) $key] = (string) ($row['hash'] ?? '');
    }
    ksort($actual, SORT_STRING);
    if ($actual !== $expected) {
      throw new \RuntimeException('Active CiviCRM configuration changed while export was being staged. Export was aborted before publish; the previous YAML snapshot remains unchanged.');
    }
  }

  /**
   * Compact current active state for the handler's managed import scope.
   */
  private function compactManagedActiveSnapshot($handler, YamlFileStorage $storage): array {
    $identityService = new ConfigIdentity();
    $canonicalizer = new Canonicalizer();
    $options = method_exists($handler, 'getCanonicalizationOptions') ? (array) $handler->getCanonicalizationOptions() : [];
    $directory = trim((string) $handler->getDirectory(), '/');
    $groups = [];
    $policy = $this->scope->getPolicy((string) $handler->getType());

    if ($handler instanceof StreamingHandlerInterface && (($policy['mode'] ?? ConfigScope::MODE_ALL) === ConfigScope::MODE_ALL)) {
      $files = $handler->iterateExport();
    }
    else {
      $partition = $this->scopePartition($handler, $handler->export(), $storage, FALSE);
      $files = (array) ($partition['managed'] ?? []);
    }

    foreach ($files as $file) {
      $file = (array) $file;
      $filename = ltrim((string) ($file['filename'] ?? ''), '/');
      if ($filename === '') {
        continue;
      }
      $relative = $directory === '' ? $filename : $directory . '/' . $filename;
      if ($this->isIgnoredPath($relative)) {
        continue;
      }
      $data = $this->applyIgnoredValueRules($relative, (array) ($file['data'] ?? []));
      $row = $this->compactDiffRow((string) $handler->getType(), $filename, $relative, $data, $identityService, $canonicalizer, $options);
      $groups[(string) $row['identity']['config_key']][] = $row;
      unset($data, $row);
    }

    $errors = [];
    if ($this->consumeHandlerExportErrors($handler, $errors) > 0) {
      throw new \RuntimeException((string) ($errors[0]['message'] ?? 'Active provider scan was incomplete.'));
    }
    $indexed = $this->indexCompactDiffGroups($groups);
    $result = [];
    foreach ($indexed as $key => $row) {
      $result[(string) $key] = (string) ($row['hash'] ?? '');
    }
    ksort($result, SORT_STRING);
    return $result;
  }

  private function assertManagedActiveSnapshotMatches($handler, YamlFileStorage $storage, array $expected, string $message): void {
    if ($this->compactManagedActiveSnapshot($handler, $storage) !== $expected) {
      throw new \RuntimeException($message);
    }
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
    $files = [];
    foreach ($this->iterateManagedYamlArchiveFiles() as $relative => $data) {
      $files[(string) $relative] = (array) $data;
    }
    return $files;
  }

  /**
   * Yield managed YAML archive documents one handler at a time.
   *
   * ZIP download uses this iterator directly so thousands of parsed YAML
   * documents do not need to coexist in memory. The array-returning method
   * above remains for API/backward compatibility.
   *
   * @return \Generator<string,array<string,mixed>>
   */
  public function iterateManagedYamlArchiveFiles(): \Generator {
    $storage = new YamlFileStorage($this->getSyncDir());
    $manifest = $this->readManifest($storage);
    if ($manifest) {
      yield 'manifest.yml' => $manifest;
    }
    unset($manifest);

    foreach ($this->getHandlers() as $handler) {
      foreach ($this->iterateManagedYamlFilesForHandler($handler, $storage) as $filename => $data) {
        $relative = trim((string) $handler->getDirectory(), '/') . '/' . ltrim((string) $filename, '/');
        if ($relative !== '' && !$this->isIgnoredPath($relative)) {
          yield $relative => (array) $data;
        }
      }
    }
  }

  /**
   * Stage one exported document and retain compact metadata which later export
   * phases can reuse without reparsing the YAML file.
   */
  public function stageExportFile(StagedExportWorkspace $workspace, $handler, array $file, array &$summary, string $unitKey = ''): void {
    $filename = ltrim((string) ($file['filename'] ?? ''), '/');
    if ($filename === '') {
      throw new \RuntimeException('Handler ' . $handler->getType() . ' returned an export row without a filename.');
    }
    $directory = trim((string) $handler->getDirectory(), '/');
    $relative = $directory === '' ? $filename : $directory . '/' . $filename;
    if ($this->isIgnoredPath($relative)) {
      $summary['skipped'][] = $relative . ' (ignored)';
      return;
    }

    $data = $this->applyIgnoredValueRules($relative, (array) ($file['data'] ?? []));
    $identityService = new ConfigIdentity();
    $canonicalizer = new Canonicalizer();
    $options = method_exists($handler, 'getCanonicalizationOptions') ? (array) $handler->getCanonicalizationOptions() : [];
    $compact = $this->compactDiffRow((string) $handler->getType(), $filename, $relative, $data, $identityService, $canonicalizer, $options);
    $metadata = [
      'unit_key' => $unitKey,
      'names' => $this->namesFromYamlFile($data),
      'dependencies' => $this->extractDependenciesFromYamlFile($data),
      'config_key' => (string) ($compact['identity']['config_key'] ?? ''),
      'identity' => (array) ($compact['identity'] ?? []),
      'hash' => (string) ($compact['hash'] ?? ''),
      'monitor_only' => !empty($data['monitor_only']) || (($data['identity_confidence'] ?? '') === ConfigIdentity::AMBIGUOUS),
      'document_type' => (string) ($data['type'] ?? ''),
    ];
    if (($data['type'] ?? '') === 'extension_config.item') {
      $metadata['extension'] = (string) ($data['extension'] ?? '');
      $metadata['api'] = (string) ($data['api'] ?? '');
      $metadata['entity'] = (string) ($data['entity'] ?? '');
      $metadata['identity_confidence'] = (string) ($data['identity_confidence'] ?? '');
      $metadata['capabilities'] = (array) ($data['capabilities'] ?? []);
    }
    elseif (($data['type'] ?? '') === 'extension.item') {
      $extensionData = (array) ($data['extension'] ?? []);
      $metadata['extension'] = (string) ($extensionData['key'] ?? ($data['key'] ?? ''));
    }

    $workspace->stage((string) $handler->getType(), $directory, $filename, $data, $metadata);
    if (!isset($summary['monitor_only'])) {
      $summary['monitor_only'] = 0;
    }
    if (!empty($metadata['monitor_only'])) {
      $summary['monitor_only']++;
    }
    // Keep response metadata compact on large exports. Existing callers only
    // need path/type/label and do not need a duplicate YAML body in memory.
    $summary['available'][] = [
      'type' => (string) $handler->getType(),
      'label' => (string) $handler->getLabel(),
      'directory' => $directory,
      'file' => $filename,
      'path' => $relative,
      'monitor_only' => !empty($metadata['monitor_only']),
    ];
  }

  /**
   * Rebuild extension provider indexes from compact staged metadata.
   *
   * This avoids two complete YAML parser passes. Mixed providers retain safe
   * CRUD/delete capability for unique identities while ambiguous identities
   * remain monitor-only and individually protected.
   */
  public function pruneExtensionIndexesInStagedExport(StagedExportWorkspace $workspace): void {
    $stage = $workspace->getStageStorage();
    $providers = [];
    $statusFiles = [];
    foreach ($workspace->files() as $relative => $metadata) {
      $metadata = (array) $metadata;
      if (($metadata['document_type'] ?? '') === 'extension_config.item') {
        $extension = (string) ($metadata['extension'] ?? '');
        $api = (string) ($metadata['api'] ?? '');
        $entity = (string) ($metadata['entity'] ?? '');
        if ($extension === '' || $api === '' || $entity === '') {
          continue;
        }
        $key = $api . ':' . $entity;
        if (!isset($providers[$extension][$key])) {
          $providers[$extension][$key] = [
            'api' => $api,
            'entity' => $entity,
            'directory' => dirname(ltrim((string) $metadata['filename'], '/')),
            'count' => 0,
            'portable_count' => 0,
            'monitor_only_count' => 0,
            'delete_capable' => FALSE,
          ];
        }
        $providers[$extension][$key]['count']++;
        if (!empty($metadata['monitor_only']) || (($metadata['identity_confidence'] ?? '') === ConfigIdentity::AMBIGUOUS)) {
          $providers[$extension][$key]['monitor_only_count']++;
        }
        else {
          $providers[$extension][$key]['portable_count']++;
          $capabilities = (array) ($metadata['capabilities'] ?? []);
          if (!empty($capabilities['delete'])) {
            $providers[$extension][$key]['delete_capable'] = TRUE;
          }
        }
      }
      elseif (($metadata['document_type'] ?? '') === 'extension.item') {
        $extension = (string) ($metadata['extension'] ?? '');
        if ($extension !== '') {
          $statusFiles[$extension] = (string) $relative;
        }
      }
    }

    foreach ($statusFiles as $extensionKey => $relative) {
      $data = $stage->readFile($relative);
      $index = [];
      foreach ((array) ($providers[$extensionKey] ?? []) as $row) {
        $row = (array) $row;
        $portable = (int) ($row['portable_count'] ?? 0);
        $monitorOnly = (int) ($row['monitor_only_count'] ?? 0);
        $index[] = [
          'api' => (string) $row['api'],
          'entity' => (string) $row['entity'],
          'directory' => (string) $row['directory'],
          'count' => (int) $row['count'],
          'portable_count' => $portable,
          'monitor_only_count' => $monitorOnly,
          'identity_safety' => $monitorOnly > 0 ? ($portable > 0 ? 'MIXED' : 'UNSAFE') : ($portable > 0 ? 'SAFE' : 'UNVERIFIED'),
          // Per-identity delete safety: a mixed provider can still authorize
          // cleanup for unique portable identities. Monitor-only desired keys
          // are recorded separately by ExtensionHandler import.
          'delete_safe' => !empty($row['delete_capable']) && $portable > 0,
        ];
      }
      usort($index, static function(array $a, array $b): int {
        return strcmp((string) $a['api'] . ':' . (string) $a['entity'], (string) $b['api'] . ':' . (string) $b['entity']);
      });
      if ($index) {
        $data['config_index'] = $index;
      }
      else {
        unset($data['config_index']);
      }
      $workspace->rewrite($relative, $data);
    }
    $workspace->persistIndex();
  }

  /**
   * Add reverse dependency metadata from compact staging metadata.
   *
   * Only YAML documents which actually receive a required_by section are
   * reparsed/re-written; names and dependency edges were captured while the
   * document was initially staged.
   */
  public function addReverseDependencyMetadataToStagedExport(StagedExportWorkspace $workspace): void {
    $stage = $workspace->getStageStorage();
    $files = $workspace->files();
    $nameIndex = [];

    foreach ($files as $relative => $metadata) {
      if ($relative === 'manifest.yml') {
        continue;
      }
      foreach ((array) ($metadata['names'] ?? []) as $name) {
        $name = (string) $name;
        if ($name !== '') {
          $nameIndex[(string) ($metadata['type'] ?? '')][$name][] = $relative;
        }
      }
    }

    $requiredBy = [];
    foreach ($files as $relative => $metadata) {
      if ($relative === 'manifest.yml') {
        continue;
      }
      $sourceNames = array_values(array_filter(array_map('strval', (array) ($metadata['names'] ?? []))));
      $sourceName = $sourceNames[0] ?? $relative;
      foreach ((array) ($metadata['dependencies'] ?? []) as $dependency) {
        $dependency = (array) $dependency;
        $dependencyType = (string) ($dependency['type'] ?? '');
        $dependencyName = (string) ($dependency['name'] ?? '');
        if ($dependencyType === '' || $dependencyName === '') {
          continue;
        }
        foreach ((array) ($nameIndex[$dependencyType][$dependencyName] ?? []) as $targetRelative) {
          if ($targetRelative === $relative) {
            continue;
          }
          $requiredBy[$targetRelative][] = [
            'type' => (string) ($metadata['type'] ?? ''),
            'name' => (string) $sourceName,
            'path' => $relative,
            'reason' => (string) ($dependency['reason'] ?? 'This YAML item depends on this configuration.'),
          ];
        }
      }
    }

    foreach ($requiredBy as $relative => $rows) {
      $data = $stage->readFile((string) $relative);
      $existing = isset($data['required_by']) && is_array($data['required_by']) ? (array) $data['required_by'] : [];
      $data['required_by'] = $this->uniqueDependencyLikeRows(array_merge($existing, (array) $rows));
      $workspace->rewrite((string) $relative, $data);
    }
    $workspace->persistIndex();
  }

  /**
   * Find stale files by path after a complete successful handler stage.
   * Selected/provider-subset exports never authorize delete-missing.
   *
   * @param object[] $handlers
   * @param string[] $requestedTypes
   * @return string[]
   */
  private function findStaleYamlPathsForStagedExport(
    YamlFileStorage $storage,
    StagedExportWorkspace $workspace,
    array $handlers,
    array $requestedTypes
  ): array {
    $stale = [];
    $allStaged = $workspace->files();
    foreach ($handlers as $handler) {
      $type = (string) $handler->getType();
      if (!$this->scope->allowsDeleteMissing($type)) {
        continue;
      }
      // Dependency expansion and provider-specific filters are intentionally
      // non-destructive. Delete-missing is authorized only for a full export or
      // when the complete handler type itself was explicitly requested.
      if ($requestedTypes && !in_array($type, $requestedTypes, TRUE)) {
        continue;
      }
      $desired = $workspace->pathSetForType($type);
      foreach ($storage->iterateYamlPaths((string) $handler->getDirectory()) as $relative) {
        $relative = (string) $relative;
        if ($relative === '' || isset($allStaged[$relative]) || isset($desired[$relative]) || $this->isIgnoredPath($relative)) {
          continue;
        }
        $stale[$relative] = TRUE;
      }
    }
    $paths = array_keys($stale);
    sort($paths, SORT_NATURAL | SORT_FLAG_CASE);
    return $paths;
  }

  private function findStaleYamlFilesForExport(YamlFileStorage $storage, array $handlers, array $queue, array $queueIndexesByType): array {
    $stale = [];

    foreach ($handlers as $handler) {
      if (!$this->scope->allowsDeleteMissing((string) $handler->getType())) {
        continue;
      }
      $directory = trim((string) $handler->getDirectory(), '/');
      $files = $this->filterIgnoredFiles($handler->getDirectory(), $storage->readDirectory($handler->getDirectory()));
      $files = $this->applyHandlerFileFilter($handler, $files);
      $files = $this->filterIgnoredValuesInFiles($handler->getDirectory(), $files);
      $exported = [];
      foreach ((array) ($queueIndexesByType[(string) $handler->getType()] ?? []) as $queueIndex) {
        $queued = (array) ($queue[$queueIndex] ?? []);
        if (empty($queued['filename'])) {
          continue;
        }
        $exported[] = [
          'filename' => (string) $queued['filename'],
          'data' => (array) ($queued['data'] ?? []),
        ];
      }
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
      unset($files, $exported, $diff);
    }
    ksort($stale);
    return array_values($stale);
  }


  /**
   * Keep a compact queue index instead of duplicating every exported document
   * into additional per-type arrays. The queue itself remains the single owner
   * of the full YAML data during an export request.
   *
   * @return array<string,int[]>
   */
  private function queueIndexesByType(array $queue): array {
    $index = [];
    foreach ($queue as $i => $file) {
      $type = (string) ($file['type'] ?? '');
      if ($type !== '') {
        $index[$type][] = (int) $i;
      }
    }
    return $index;
  }


  private function pruneExtensionIndexesForIgnoredOrFilteredConfig(array &$queue): void {
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
  }

  private function addReverseDependencyMetadataToExportQueue(array &$queue): void {
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
        $policy = $this->scope->getPolicy((string) $handler->getType());
        $useCompactStreaming = $handler instanceof StreamingHandlerInterface
          && (($policy['mode'] ?? ConfigScope::MODE_ALL) === ConfigScope::MODE_ALL);

        if ($useCompactStreaming) {
          $compact = $this->buildCompactStreamingDiff($handler, $storage, $result['errors']);
          $item = $compact['item'];
          if ($stateManager !== NULL) {
            try {
              $item = $stateManager->enrichCompactDiff($handler, $compact['active'], $compact['desired'], $item);
            }
            catch (\Throwable $e) {
              $result['state_warning'] = 'Configuration diff succeeded, but local state/baseline tracking could not be updated: ' . $e->getMessage();
              $stateManager = NULL;
            }
          }
          $item = $this->filterIgnoredDiffItem($item, $handler->getDirectory());
          if (($item['status'] ?? '') !== 'in_sync' || !empty($item['files'])) {
            $result['items'][] = $item;
          }
          unset($compact, $item);
          continue;
        }

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
        unset($activeFiles, $partition, $exported, $files, $item);
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
   * Build a summary-first diff for streaming handlers without keeping full
   * active/YAML documents or field-level diffs in memory.
   *
   * @return array{item:array<string,mixed>,active:array<string,array<string,mixed>>,desired:array<string,array<string,mixed>>}
   */
  private function buildCompactStreamingDiff($handler, YamlFileStorage $storage, array &$errors): array {
    $identityService = new ConfigIdentity();
    $canonicalizer = new Canonicalizer();
    $options = method_exists($handler, 'getCanonicalizationOptions') ? (array) $handler->getCanonicalizationOptions() : [];
    $directory = trim((string) $handler->getDirectory(), '/');
    $activeGroups = [];
    $desiredGroups = [];

    foreach ($handler->iterateExport() as $file) {
      $file = (array) $file;
      $filename = ltrim((string) ($file['filename'] ?? ''), '/');
      if ($filename === '') {
        continue;
      }
      $relative = $directory === '' ? $filename : $directory . '/' . $filename;
      if ($this->isIgnoredPath($relative)) {
        continue;
      }
      $data = $this->applyIgnoredValueRules($relative, (array) ($file['data'] ?? []));
      $row = $this->compactDiffRow((string) $handler->getType(), $filename, $relative, $data, $identityService, $canonicalizer, $options);
      $activeGroups[(string) $row['identity']['config_key']][] = $row;
      unset($data, $row);
    }

    $exportErrorCount = $this->consumeHandlerExportErrors($handler, $errors);
    if ($exportErrorCount > 0) {
      throw new \RuntimeException('Active provider scan was incomplete. Diff was aborted for this configuration type instead of presenting a partial result.');
    }

    foreach ($this->iterateManagedYamlFilesForHandler($handler, $storage) as $filename => $data) {
      $filename = ltrim((string) $filename, '/');
      $relative = $directory === '' ? $filename : $directory . '/' . $filename;
      $row = $this->compactDiffRow((string) $handler->getType(), $filename, $relative, (array) $data, $identityService, $canonicalizer, $options);
      $desiredGroups[(string) $row['identity']['config_key']][] = $row;
      unset($row, $data);
    }

    $active = $this->indexCompactDiffGroups($activeGroups);
    $desired = $this->indexCompactDiffGroups($desiredGroups);
    unset($activeGroups, $desiredGroups);

    $activeKeys = array_keys($active);
    $desiredKeys = array_keys($desired);
    $newKeys = array_values(array_diff($activeKeys, $desiredKeys));
    $missingKeys = array_values(array_diff($desiredKeys, $activeKeys));
    $commonKeys = array_values(array_intersect($activeKeys, $desiredKeys));
    $changed = [];
    $newInDb = [];
    $missingInDb = [];
    $renamed = [];
    $files = [];

    foreach ($commonKeys as $key) {
      $a = $active[$key];
      $y = $desired[$key];
      if ((string) $a['filename'] !== (string) $y['filename']) {
        $renamed[] = ['config_key' => (string) $y['identity']['config_key'], 'from' => (string) $y['filename'], 'to' => (string) $a['filename']];
      }
      if ((string) $a['hash'] === (string) $y['hash']) {
        continue;
      }
      $changed[] = (string) $y['filename'];
      $files[] = $this->compactDiffFile($y, $a, 'changed', $key);
    }

    foreach ($newKeys as $key) {
      $row = $active[$key];
      $newInDb[] = (string) $row['filename'];
      $files[] = $this->compactDiffFile(NULL, $row, 'new_in_db', $key);
    }
    foreach ($missingKeys as $key) {
      $row = $desired[$key];
      $missingInDb[] = (string) $row['filename'];
      $files[] = $this->compactDiffFile($row, NULL, 'missing_in_db', $key);
    }

    $possibleRenames = $this->compactPossibleRenameCandidates($newKeys, $missingKeys, $active, $desired);
    usort($files, static function(array $a, array $b): int {
      return strnatcasecmp((string) ($a['path'] ?? ''), (string) ($b['path'] ?? ''));
    });

    $item = [
      'type' => (string) $handler->getType(),
      'label' => (string) $handler->getLabel(),
      'db_count' => count($active),
      'file_count' => count($desired),
      'status' => ($changed || $newInDb || $missingInDb) ? 'changed' : 'in_sync',
      'changed' => $changed,
      'new_in_db' => $newInDb,
      'missing_in_db' => $missingInDb,
      'renamed' => $renamed,
      'possible_renames' => $possibleRenames,
      'files' => $files,
      'summary_first' => TRUE,
      'details_lazy' => TRUE,
    ];

    return ['item' => $item, 'active' => $active, 'desired' => $desired];
  }

  private function compactDiffRow(string $handlerType, string $filename, string $relative, array $data, ConfigIdentity $identityService, Canonicalizer $canonicalizer, array $options): array {
    $identity = $identityService->identify($handlerType, $data, $filename);
    $canonical = $canonicalizer->canonicalize($data, $options);
    $hash = $canonicalizer->hashCanonical($canonical);

    // A second compact fingerprint deliberately ignores the common machine
    // identity fields. It is used only to suggest a possible rename and never
    // grants write permission.
    $rename = $canonical;
    if (is_array($rename)) {
      unset($rename['key'], $rename['name']);
      if (isset($rename['item']) && is_array($rename['item'])) {
        unset($rename['item']['key'], $rename['item']['machine_name'], $rename['item']['name'], $rename['item']['name_a_b'], $rename['item']['workflow_name']);
      }
    }
    $renameSignature = $canonicalizer->hashCanonical($rename);
    unset($canonical, $rename);

    return [
      'filename' => $filename,
      'path' => $relative,
      'identity' => $identity,
      'hash' => $hash,
      'rename_signature' => $renameSignature,
    ];
  }

  /**
   * Preserve every duplicate semantic identity under a deterministic synthetic
   * key. Compact scans must never silently overwrite one occurrence with the
   * next.
   */
  private function indexCompactDiffGroups(array $groups): array {
    $index = [];
    foreach ($groups as $configKey => $rows) {
      $rows = array_values((array) $rows);
      if (count($rows) === 1) {
        $index[(string) $configKey] = $rows[0];
        continue;
      }
      $occurrences = [];
      foreach ($rows as $row) {
        $base = (string) $configKey
          . '|duplicate=' . rawurlencode((string) $row['filename'])
          . '|fingerprint=' . (string) $row['hash'];
        $occurrence = ($occurrences[$base] ?? 0) + 1;
        $occurrences[$base] = $occurrence;
        $key = $base . '|occurrence=' . $occurrence;
        $row['identity']['config_key'] = $key;
        $row['identity']['identity_hash'] = hash('sha256', $key);
        $row['identity']['identity_method'] = 'duplicate_identity_fallback';
        $row['identity']['identity_confidence'] = ConfigIdentity::AMBIGUOUS;
        $row['identity']['write_safe'] = FALSE;
        $index[$key] = $row;
      }
    }
    ksort($index, SORT_STRING);
    return $index;
  }

  private function compactDiffFile(?array $yaml, ?array $active, string $status, string $compactKey): array {
    $row = $yaml ?: $active;
    $identity = (array) ($row['identity'] ?? []);
    $filename = (string) ($row['filename'] ?? '');
    $path = (string) ($row['path'] ?? $filename);
    return [
      'file' => $filename,
      'path' => $path,
      'status' => $status,
      'config_key' => (string) ($identity['config_key'] ?? ''),
      'identity_hash' => (string) ($identity['identity_hash'] ?? ''),
      'identity_method' => (string) ($identity['identity_method'] ?? ''),
      'identity_confidence' => (string) ($identity['identity_confidence'] ?? ''),
      'write_safe' => !empty($identity['write_safe']),
      'yaml_hash' => $yaml['hash'] ?? NULL,
      'active_hash' => $active['hash'] ?? NULL,
      'canonical_version' => Canonicalizer::VERSION,
      'change_count' => $status === 'changed' ? 1 : 0,
      'changes' => [],
      'diff' => 'Field-level details are calculated on demand.',
      'details_lazy' => TRUE,
      '_compact_key' => $compactKey,
    ];
  }

  private function compactPossibleRenameCandidates(array $newKeys, array $missingKeys, array $active, array $desired): array {
    $buckets = [];
    foreach ($newKeys as $key) {
      $row = (array) ($active[$key] ?? []);
      $provider = (string) (($row['identity']['provider_key'] ?? ''));
      $signature = (string) ($row['rename_signature'] ?? '');
      if ($provider !== '' && $signature !== '') {
        $buckets[$provider][$signature][] = $key;
      }
    }
    $candidates = [];
    foreach ($missingKeys as $oldKey) {
      $old = (array) ($desired[$oldKey] ?? []);
      $provider = (string) (($old['identity']['provider_key'] ?? ''));
      $signature = (string) ($old['rename_signature'] ?? '');
      foreach ((array) ($buckets[$provider][$signature] ?? []) as $newKey) {
        $new = (array) ($active[$newKey] ?? []);
        $candidates[] = [
          'provider_key' => $provider,
          'old_config_key' => (string) ($old['identity']['config_key'] ?? ''),
          'new_config_key' => (string) ($new['identity']['config_key'] ?? ''),
          'old_identity_hash' => (string) ($old['identity']['identity_hash'] ?? ''),
          'new_identity_hash' => (string) ($new['identity']['identity_hash'] ?? ''),
          'from' => (string) ($old['filename'] ?? ''),
          'to' => (string) ($new['filename'] ?? ''),
          'changes' => [],
          'requires_confirmation' => TRUE,
          'details_lazy' => TRUE,
        ];
      }
    }
    return $candidates;
  }

  /**
   * Calculate field-level details for one managed path on demand.
   *
   * Normal Synchronize scans keep only compact hashes. This endpoint loads at
   * most one YAML document and one matching active object, so large sites do
   * not pay the field-diff memory cost until an administrator opens Details.
   */
  public function getDiffDetail(string $relativePath): array {
    $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath === '' || strpos($relativePath, '..') !== FALSE || $this->isIgnoredPath($relativePath)) {
      throw new \RuntimeException('Invalid or ignored Configuration Manager detail path.');
    }

    $resolved = $this->resolveHandlerPath($relativePath);
    if (!$resolved) {
      throw new \RuntimeException('No managed configuration handler owns path: ' . $relativePath);
    }
    $handler = $resolved['handler'];
    $filename = (string) $resolved['filename'];
    $storage = new YamlFileStorage($this->getSyncDir());
    $identityService = new ConfigIdentity();
    $yaml = [];
    $targetConfigKey = '';

    if ($storage->exists((string) $resolved['directory'], $filename)) {
      $data = $this->applyIgnoredValueRules($relativePath, $storage->readFile($relativePath));
      $filtered = $this->applyHandlerFileFilter($handler, [$filename => $data]);
      if (array_key_exists($filename, $filtered)) {
        $data = (array) $filtered[$filename];
        $yaml[$filename] = $data;
        $targetConfigKey = (string) $identityService->identify((string) $handler->getType(), $data, $filename)['config_key'];
      }
    }

    $matches = [];
    $exported = $handler instanceof StreamingHandlerInterface ? $handler->iterateExport() : $handler->export();
    foreach ($exported as $file) {
      $file = (array) $file;
      $activeFilename = ltrim((string) ($file['filename'] ?? ''), '/');
      if ($activeFilename === '') {
        continue;
      }
      $activeRelative = $this->relativePathForHandlerFile($handler, $activeFilename);
      if ($this->isIgnoredPath($activeRelative)) {
        continue;
      }
      $activeData = $this->applyIgnoredValueRules($activeRelative, (array) ($file['data'] ?? []));
      $activeIdentity = $identityService->identify((string) $handler->getType(), $activeData, $activeFilename);
      if ($activeFilename === $filename || ($targetConfigKey !== '' && (string) $activeIdentity['config_key'] === $targetConfigKey)) {
        $matches[] = ['filename' => $activeFilename, 'data' => $activeData];
      }
      if ($activeFilename === $filename) {
        // Exact path is stronger than a semantic rename match.
        break;
      }
    }
    $detailErrors = [];
    if ($this->consumeHandlerExportErrors($handler, $detailErrors) > 0) {
      throw new \RuntimeException((string) ($detailErrors[0]['message'] ?? 'Active provider scan was incomplete.'));
    }

    if (count($matches) > 1) {
      $exact = array_values(array_filter($matches, static function(array $row) use ($filename): bool {
        return (string) $row['filename'] === $filename;
      }));
      if (count($exact) === 1) {
        $matches = $exact;
      }
      else {
        throw new \RuntimeException('More than one active object matches this semantic identity. Field details are ambiguous and automatic writes remain blocked.');
      }
    }

    $active = [];
    if ($matches) {
      $active[] = ['filename' => (string) $matches[0]['filename'], 'data' => (array) $matches[0]['data']];
    }
    $diff = $handler->diffFromExports($active, $yaml);
    $files = (array) ($diff['files'] ?? []);
    if (!$files && !empty($diff['renamed'])) {
      return [
        'ok' => TRUE,
        'type' => (string) $handler->getType(),
        'label' => (string) $handler->getLabel(),
        'path' => $relativePath,
        'renamed' => (array) $diff['renamed'],
        'file' => NULL,
      ];
    }

    $file = $files ? (array) reset($files) : NULL;
    return [
      'ok' => TRUE,
      'type' => (string) $handler->getType(),
      'label' => (string) $handler->getLabel(),
      'path' => $relativePath,
      'file' => $file,
    ];
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
    $availableNames = [];
    $dependencyMetadata = [];
    $normalisedFilter = $this->normaliseTypeFilter($typeFilter);
    $baseFilter = $this->baseTypesFromFilter($normalisedFilter);

    foreach ($this->getHandlers() as $handler) {
      if ($baseFilter && !in_array($handler->getType(), $baseFilter, TRUE)) {
        continue;
      }
      $this->prepareHandlerForTypeFilter($handler, $normalisedFilter);
      try {
        $handlerType = (string) $handler->getType();
        foreach ($this->iterateManagedYamlFilesForHandler($handler, $storage) as $filename => $file) {
          $file = (array) $file;
          foreach ($this->namesFromYamlFile($file) as $name) {
            $availableNames[$handlerType][(string) $name] = TRUE;
          }
          $dependencies = $this->extractDependenciesFromYamlFile($file);
          $requiredBy = $this->extractRequiredByFromYamlFile($file);
          if ($dependencies || $requiredBy) {
            $dependencyMetadata[$handlerType][(string) $filename] = [
              'dependencies' => $dependencies,
              'required_by' => $requiredBy,
            ];
          }
        }
        $validation = $this->validateManagedYamlForHandler($handler, $storage);
        $result['items'][] = $validation;
        if (empty($validation['valid'])) {
          $result['ok'] = FALSE;
        }
        // Handler validation is complete; retain only compact dependency/name
        // metadata for the cross-type pass instead of every parsed YAML body.
      }
      catch (\Throwable $e) {
        $result['errors'][] = ['type' => $handler->getType(), 'message' => $e->getMessage()];
      }
    }
    $this->addDependencyWarningsFromMetadata($result, $availableNames, $dependencyMetadata);
    try {
      $this->addManifestValidation($result, $storage->readFile('manifest.yml'));
    }
    catch (\Throwable $e) {
      $result['errors'][] = ['type' => 'manifest', 'message' => $e->getMessage()];
    }
    $result['ok'] = $result['ok'] && empty($result['errors']);
    return $result;
  }

  private function addDependencyWarningsFromMetadata(array &$result, array $available, array $dependencyMetadata): void {
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

    foreach ($dependencyMetadata as $type => $files) {
      if (!isset($itemIndex[$type])) {
        continue;
      }
      foreach ($files as $filename => $metadata) {
        foreach ((array) ($metadata['dependencies'] ?? []) as $dependency) {
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
        foreach ((array) ($metadata['required_by'] ?? []) as $requiredBy) {
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
          $rows = $handler instanceof StreamingHandlerInterface ? $handler->iterateExport() : $handler->export();
          foreach ($rows as $file) {
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

  public function import(bool $dryRun = TRUE, bool $yes = FALSE, array $typeFilter = [], ?callable $progress = NULL): array {
    $storage = new YamlFileStorage($this->getSyncDir());
    $operationLock = OperationLock::acquire($storage->getRoot());
    $requestedTypes = $this->normaliseTypeFilter($typeFilter);
    $effectiveTypes = $this->getEffectiveExportTypeFilter($requestedTypes);
    $validationTypes = $this->getImportValidationTypeFilter($requestedTypes, $effectiveTypes);
    $applyTypes = $this->getImportApplyTypeFilter($requestedTypes, $effectiveTypes);

    $handlers = [];
    foreach ($this->getHandlers() as $handler) {
      if ($applyTypes && !in_array($handler->getType(), $applyTypes, TRUE)) {
        continue;
      }
      $this->prepareHandlerForTypeFilter($handler, $requestedTypes);
      $handlers[] = $handler;
    }

    $handlerCount = count($handlers);
    $willApply = !$dryRun && $yes;
    $totalSteps = max(1, 2 + ($handlerCount * ($willApply ? 5 : 2)));
    $completedSteps = 0;
    $processedItems = 0;
    $this->reportProgress($progress, 0, $totalSteps, 'Preparing import', 'Reading managed YAML and building dependency context.', 0);

    // Every dry-run sees the complete managed YAML dependency set. This lets a
    // dependent type recognize prerequisites which are absent from the target
    // DB today but are planned earlier in the same import (for example a new
    // Option Group followed by a Custom Field which uses it).
    $plannedDependencyNames = $this->collectImportPlannedDependencyNames($handlers, $storage);
    foreach ($handlers as $handler) {
      $this->setHandlerPlannedDependencyNames($handler, $plannedDependencyNames);
    }
    $completedSteps++;
    $this->reportProgress($progress, $completedSteps, $totalSteps, 'Dependency plan ready', 'Validating the complete YAML set before any write.', $processedItems);

    // Static validation and the handler dry-run are intentionally both run.
    // A foreign site_id, a YAML/dependency problem, and a handler-level safety
    // problem must be reported together in one preflight instead of forcing an
    // operator through one blocker at a time.
    $validation = $this->validate($validationTypes);
    $completedSteps++;
    $this->reportProgress($progress, $completedSteps, $totalSteps, 'YAML validation complete', 'Checking rename safety and handler dry-runs.', $processedItems);
    $preflight = $this->buildImportPreflight($handlers, $storage, $validation, function(array $event) use (&$completedSteps, $totalSteps, &$processedItems, $progress) {
      $completedSteps++;
      $processedItems += (int) ($event['processed_items'] ?? 0);
      $this->reportProgress(
        $progress,
        $completedSteps,
        $totalSteps,
        (string) ($event['label'] ?? 'Import preflight'),
        (string) ($event['message'] ?? 'Checking import safety.'),
        $processedItems
      );
    }, $willApply);

    if ($dryRun || !$yes) {
      $this->reportProgress($progress, $totalSteps, $totalSteps, 'Import preview complete', 'Complete non-writing preflight finished.', $processedItems);
      return $preflight;
    }

    if (empty($preflight['ok'])) {
      $preflight['dry_run'] = FALSE;
      $preflight['applied'] = FALSE;
      $preflight['message'] = 'Import stopped before writes because the complete preflight found blocking errors. Resolve all listed blockers and preview again.';
      $this->reportProgress($progress, $totalSteps, $totalSteps, 'Import blocked safely', 'Preflight found blocking errors; zero writes were performed.', $processedItems);
      return $preflight;
    }

    // The complete preflight can contain every managed YAML document's
    // validation/dry-run diagnostics. Once it is green, retain only its compact
    // outcome during the write phases; keeping the full preview alive alongside
    // active provider collections needlessly doubles peak import memory.
    $preflightFingerprints = (array) ($preflight['_active_fingerprints'] ?? []);
    $result = [
      'ok' => TRUE,
      'dry_run' => FALSE,
      'applied' => TRUE,
      'items' => [],
      'preflight' => [
        'ok' => TRUE,
        'summary_message' => (string) ($preflight['summary_message'] ?? ''),
      ],
    ];
    unset($preflight, $validation);
    $postWriteFingerprints = [];

    // Apply create/update first for every type. Never enter delete-missing if
    // any write fails: destructive cleanup must not run after a partial
    // prerequisite/update phase.
    foreach ($handlers as $handler) {
      $handlerLabel = (string) $handler->getLabel();
      $this->reportProgress($progress, $completedSteps, $totalSteps, 'Applying ' . $handlerLabel, 'Create/update phase. Delete-missing has not started.', $processedItems);
      $this->setHandlerImportPhase($handler, TRUE, FALSE);
      try {
        $type = (string) $handler->getType();
        if (isset($preflightFingerprints[$type])) {
          $this->assertManagedActiveSnapshotMatches($handler, $storage, (array) $preflightFingerprints[$type], 'Import conflict: active CiviCRM changed after preflight. No write was performed for this handler.');
        }
        $item = $this->importManagedYamlForHandler($handler, $storage, FALSE);
        if (empty($item['errors']) && (!array_key_exists('ok', $item) || !empty($item['ok']))) {
          $postWriteFingerprints[$type] = $this->compactManagedActiveSnapshot($handler, $storage);
        }
      }
      catch (\Throwable $e) {
        $item = [
          'type' => (string) $handler->getType(),
          'status' => 'applied',
          'dry_run' => FALSE,
          'errors' => [['message' => $e->getMessage()]],
          'warnings' => [],
        ];
      }
      $item['phase'] = 'create_update';
      $result['items'][] = $item;
      $processedItems += $this->countImportItemActivity($item);
      $completedSteps++;
      $this->reportProgress($progress, $completedSteps, $totalSteps, 'Applied ' . $handlerLabel, 'Create/update phase step complete.', $processedItems);
      if (!empty($item['errors']) || (array_key_exists('ok', $item) && empty($item['ok']))) {
        $result['ok'] = FALSE;
      }
    }

    if (empty($result['ok'])) {
      foreach ($handlers as $handler) {
        $this->setHandlerImportPhase($handler, TRUE, TRUE);
      }
      $result['partial_apply'] = TRUE;
      $result['delete_phase_skipped'] = TRUE;
      $result['message'] = 'Import stopped after a create/update runtime failure. Delete-missing was not started. Review the errors and restore/retry from the pre-import database backup if required.';
      $result['summary_message'] = $this->buildImportSummaryMessage($result);
      $this->reportProgress($progress, $totalSteps, $totalSteps, 'Import stopped safely', 'A create/update failed; delete-missing was not started.', $processedItems);
      return $result;
    }

    foreach (array_reverse($handlers) as $handler) {
      $handlerLabel = (string) $handler->getLabel();
      $this->reportProgress($progress, $completedSteps, $totalSteps, 'Cleaning ' . $handlerLabel, 'Delete-missing phase after all create/update steps succeeded.', $processedItems);
      $this->setHandlerImportPhase($handler, FALSE, TRUE);
      try {
        $type = (string) $handler->getType();
        if (isset($postWriteFingerprints[$type])) {
          $this->assertManagedActiveSnapshotMatches($handler, $storage, (array) $postWriteFingerprints[$type], 'Import conflict: active CiviCRM changed after create/update. Delete-missing was not started for this handler.');
        }
        $item = $this->importManagedYamlForHandler($handler, $storage, FALSE);
      }
      catch (\Throwable $e) {
        $item = [
          'type' => (string) $handler->getType(),
          'status' => 'applied',
          'dry_run' => FALSE,
          'errors' => [['message' => $e->getMessage()]],
          'warnings' => [],
        ];
      }
      $item['phase'] = 'delete_missing';
      $result['items'][] = $item;
      $processedItems += $this->countImportItemActivity($item);
      $completedSteps++;
      $this->reportProgress($progress, $completedSteps, $totalSteps, 'Cleaned ' . $handlerLabel, 'Delete-missing phase step complete.', $processedItems);
      if (!empty($item['errors']) || (array_key_exists('ok', $item) && empty($item['ok']))) {
        $result['ok'] = FALSE;
      }
      $this->setHandlerImportPhase($handler, TRUE, TRUE);
    }

    if (!empty($result['ok'])) {
      try {
        $stateManager = new ConfigStateManager();
        foreach ($handlers as $handler) {
          $directory = trim((string) $handler->getDirectory(), '/');
          foreach ($storage->iterateDirectory($directory) as $filename => $data) {
            if ($this->isIgnoredPath(($directory === '' ? '' : $directory . '/') . (string) $filename)) {
              continue;
            }
            $stateManager->acceptYamlBaselineItem($handler, (string) $filename, (array) $data, 'import');
          }
          $completedSteps++;
          $this->reportProgress($progress, $completedSteps, $totalSteps, 'Recording ' . $handler->getLabel() . ' baseline', 'Synchronization baseline updated from committed YAML.', $processedItems);
        }
      }
      catch (\Throwable $e) {
        $result['state_warning'] = 'Import was applied successfully, but local baseline state could not be updated: ' . $e->getMessage();
      }
    }
    else {
      $result['partial_apply'] = TRUE;
    }

    $result['summary_message'] = $this->buildImportSummaryMessage($result);
    $this->reportProgress($progress, $totalSteps, $totalSteps, !empty($result['ok']) ? 'Import complete' : 'Import completed with errors', 'Import processing finished.', $processedItems);
    return $result;
  }


  /** @return array<int,array<string,mixed>> */
  public function buildQueuedImportPlan(array $typeFilter = []): array {
    $requestedTypes = $this->normaliseTypeFilter($typeFilter);
    $effectiveTypes = $this->getEffectiveExportTypeFilter($requestedTypes);
    $applyTypes = $this->getImportApplyTypeFilter($requestedTypes, $effectiveTypes);
    $handlers = [];
    foreach ($this->getHandlers() as $handler) {
      if ($applyTypes && !in_array((string) $handler->getType(), $applyTypes, TRUE)) {
        continue;
      }
      $this->prepareHandlerForTypeFilter($handler, $requestedTypes);
      $handlers[] = $handler;
    }

    $tasks = [[
      'key' => 'import:preflight',
      'action' => 'import_preflight',
      'phase' => 'preflight',
      'phase_index' => 1,
      'phase_total' => 5,
      'label' => 'Import preflight — checking all managed configuration',
      'message' => 'Validating YAML, dependencies, rename safety, provider capabilities, and active-state fingerprints. No CiviCRM writes are allowed in this phase.',
      'retry_safe' => TRUE,
    ]];
    foreach ($handlers as $handler) {
      $tasks[] = [
        'key' => 'import:write:' . (string) $handler->getType(),
        'action' => 'import_create_update',
        'phase' => 'create_update',
        'phase_index' => 2,
        'phase_total' => 5,
        'handler_type' => (string) $handler->getType(),
        'label' => 'Applying YAML create/update — ' . $handler->getLabel(),
        'message' => 'Applying only create/update operations for this configuration type. Delete-missing has not started.',
        'retry_safe' => FALSE,
      ];
    }
    foreach (array_reverse($handlers) as $handler) {
      $tasks[] = [
        'key' => 'import:delete:' . (string) $handler->getType(),
        'action' => 'import_delete_missing',
        'phase' => 'delete_missing',
        'phase_index' => 3,
        'phase_total' => 5,
        'handler_type' => (string) $handler->getType(),
        'label' => 'Applying safe delete-missing — ' . $handler->getLabel(),
        'message' => 'All create/update work units succeeded. Removing only identities whose full managed scope and delete safety are proven.',
        'retry_safe' => FALSE,
      ];
    }
    foreach ($handlers as $handler) {
      $tasks[] = [
        'key' => 'import:baseline:' . (string) $handler->getType(),
        'action' => 'import_baseline',
        'phase' => 'baseline',
        'phase_index' => 4,
        'phase_total' => 5,
        'handler_type' => (string) $handler->getType(),
        'label' => 'Recording synchronization baseline — ' . $handler->getLabel(),
        'message' => 'Recording the applied YAML state for future synchronization checks.',
        'retry_safe' => TRUE,
      ];
    }
    $tasks[] = [
      'key' => 'import:complete',
      'action' => 'import_complete',
      'phase' => 'complete',
      'phase_index' => 5,
      'phase_total' => 5,
      'label' => 'Completing import',
      'message' => 'Finalizing the durable import result and cleaning temporary job state.',
      'retry_safe' => TRUE,
    ];
    return $tasks;
  }

  /** @return array<string,mixed> */
  public function queuedImportPreflight(int $jobId, string $syncRootHash, array $typeFilter = [], ?callable $progress = NULL): array {
    $storage = new YamlFileStorage($this->getSyncDir());
    $operationLock = OperationLock::acquire($storage->getRoot(), $jobId);
    $stateStore = new OperationWorkspace($jobId, $syncRootHash);
    $stateStore->cleanup();
    $stateStore = new OperationWorkspace($jobId, $syncRootHash);

    $requestedTypes = $this->normaliseTypeFilter($typeFilter);
    $effectiveTypes = $this->getEffectiveExportTypeFilter($requestedTypes);
    $validationTypes = $this->getImportValidationTypeFilter($requestedTypes, $effectiveTypes);
    $applyTypes = $this->getImportApplyTypeFilter($requestedTypes, $effectiveTypes);
    $handlers = [];
    foreach ($this->getHandlers() as $handler) {
      if ($applyTypes && !in_array((string) $handler->getType(), $applyTypes, TRUE)) {
        continue;
      }
      $this->prepareHandlerForTypeFilter($handler, $requestedTypes);
      $handlers[] = $handler;
    }

    $plannedDependencyNames = $this->collectImportPlannedDependencyNames($handlers, $storage);
    foreach ($handlers as $handler) {
      $this->setHandlerPlannedDependencyNames($handler, $plannedDependencyNames);
    }
    if ($progress !== NULL) {
      $progress([
        'progress_known' => FALSE,
        'message' => 'Dependency context is ready. Validating every managed YAML document before any active CiviCRM write.',
      ]);
    }
    $validation = $this->validate($validationTypes);
    $processed = 0;
    $preflight = $this->buildImportPreflight($handlers, $storage, $validation, function(array $event) use (&$processed, $progress) {
      $processed += (int) ($event['processed_items'] ?? 0);
      if ($progress !== NULL) {
        $progress([
          'progress_known' => FALSE,
          'processed_items' => $processed,
          'message' => (string) ($event['label'] ?? 'Import preflight') . '. ' . (string) ($event['message'] ?? 'No writes performed.'),
        ]);
      }
    }, TRUE);

    $state = [
      'operation' => 'import',
      'requested_types' => $requestedTypes,
      'effective_types' => $effectiveTypes,
      'apply_types' => array_values(array_map(static function($handler): string { return (string) $handler->getType(); }, $handlers)),
      'planned_dependency_names' => $plannedDependencyNames,
      'preflight_fingerprints' => (array) ($preflight['_active_fingerprints'] ?? []),
      'post_write_fingerprints' => [],
      'preflight_summary' => (string) ($preflight['summary_message'] ?? ''),
      'items' => [],
      'processed_items' => $processed,
      'state_warning' => '',
    ];
    $stateStore->saveState($state);

    if (empty($preflight['ok'])) {
      unset($preflight['_active_fingerprints']);
      $preflight['dry_run'] = FALSE;
      $preflight['applied'] = FALSE;
      $preflight['message'] = 'Import stopped before writes because the complete preflight found blocking errors. Zero writes were performed.';
      return $preflight;
    }
    return [
      'ok' => TRUE,
      'dry_run' => TRUE,
      'applied' => FALSE,
      'summary_message' => (string) ($preflight['summary_message'] ?? ''),
      'processed_items' => $processed,
    ];
  }

  /** @return array<string,mixed> */
  public function queuedImportCreateUpdate(int $jobId, string $syncRootHash, string $handlerType): array {
    $storage = new YamlFileStorage($this->getSyncDir());
    $operationLock = OperationLock::acquire($storage->getRoot(), $jobId);
    $stateStore = new OperationWorkspace($jobId, $syncRootHash);
    $state = $stateStore->loadState();
    $handler = $this->handlerByType($handlerType);
    if ($handler === NULL) {
      throw new \RuntimeException('Queued import handler is no longer registered: ' . $handlerType);
    }
    $this->prepareHandlerForTypeFilter($handler, array_values(array_map('strval', (array) ($state['requested_types'] ?? []))));
    $this->setHandlerPlannedDependencyNames($handler, (array) ($state['planned_dependency_names'] ?? []));
    $this->setHandlerImportPhase($handler, TRUE, FALSE);
    try {
      if (isset($state['preflight_fingerprints'][$handlerType])) {
        $this->assertManagedActiveSnapshotMatches($handler, $storage, (array) $state['preflight_fingerprints'][$handlerType], 'Import conflict: active CiviCRM changed after preflight. No write was performed for this handler.');
      }
      $item = $this->importManagedYamlForHandler($handler, $storage, FALSE);
      if (empty($item['errors']) && (!array_key_exists('ok', $item) || !empty($item['ok']))) {
        $state['post_write_fingerprints'][$handlerType] = $this->compactManagedActiveSnapshot($handler, $storage);
      }
    }
    catch (\Throwable $e) {
      $item = [
        'type' => $handlerType,
        'status' => 'applied',
        'dry_run' => FALSE,
        'errors' => [['message' => $e->getMessage()]],
        'warnings' => [],
      ];
    }
    $item['phase'] = 'create_update';
    $state['items'][] = $item;
    $state['processed_items'] = (int) ($state['processed_items'] ?? 0) + $this->countImportItemActivity($item);
    $stateStore->saveState($state);
    $ok = empty($item['errors']) && (!array_key_exists('ok', $item) || !empty($item['ok']));
    $result = ['ok' => $ok, 'item' => $item, 'processed_items' => (int) $state['processed_items']];
    if (!$ok) {
      $result['partial_apply'] = TRUE;
      $result['delete_phase_skipped'] = TRUE;
      $result['message'] = 'Import stopped after a create/update work-unit failure. No delete-missing work unit will run. Review the applied handlers and restore/retry from the pre-import database backup if needed.';
    }
    return $result;
  }

  /** @return array<string,mixed> */
  public function queuedImportDeleteMissing(int $jobId, string $syncRootHash, string $handlerType): array {
    $storage = new YamlFileStorage($this->getSyncDir());
    $operationLock = OperationLock::acquire($storage->getRoot(), $jobId);
    $stateStore = new OperationWorkspace($jobId, $syncRootHash);
    $state = $stateStore->loadState();
    $handler = $this->handlerByType($handlerType);
    if ($handler === NULL) {
      throw new \RuntimeException('Queued import handler is no longer registered: ' . $handlerType);
    }
    $this->prepareHandlerForTypeFilter($handler, array_values(array_map('strval', (array) ($state['requested_types'] ?? []))));
    $this->setHandlerPlannedDependencyNames($handler, (array) ($state['planned_dependency_names'] ?? []));
    $this->setHandlerImportPhase($handler, FALSE, TRUE);
    try {
      if (isset($state['post_write_fingerprints'][$handlerType])) {
        $this->assertManagedActiveSnapshotMatches($handler, $storage, (array) $state['post_write_fingerprints'][$handlerType], 'Import conflict: active CiviCRM changed after create/update. Delete-missing was not started for this handler.');
      }
      $item = $this->importManagedYamlForHandler($handler, $storage, FALSE);
    }
    catch (\Throwable $e) {
      $item = [
        'type' => $handlerType,
        'status' => 'applied',
        'dry_run' => FALSE,
        'errors' => [['message' => $e->getMessage()]],
        'warnings' => [],
      ];
    }
    finally {
      $this->setHandlerImportPhase($handler, TRUE, TRUE);
    }
    $item['phase'] = 'delete_missing';
    $state['items'][] = $item;
    $state['processed_items'] = (int) ($state['processed_items'] ?? 0) + $this->countImportItemActivity($item);
    $stateStore->saveState($state);
    $ok = empty($item['errors']) && (!array_key_exists('ok', $item) || !empty($item['ok']));
    $result = ['ok' => $ok, 'item' => $item, 'processed_items' => (int) $state['processed_items']];
    if (!$ok) {
      $result['partial_apply'] = TRUE;
      $result['message'] = 'Import delete-missing stopped with an error after earlier create/update work units had succeeded. Remaining queue work was not continued.';
    }
    return $result;
  }

  /** @return array<string,mixed> */
  public function queuedImportBaseline(int $jobId, string $syncRootHash, string $handlerType): array {
    $storage = new YamlFileStorage($this->getSyncDir());
    $operationLock = OperationLock::acquire($storage->getRoot(), $jobId);
    $stateStore = new OperationWorkspace($jobId, $syncRootHash);
    $state = $stateStore->loadState();
    $handler = $this->handlerByType($handlerType);
    if ($handler === NULL) {
      throw new \RuntimeException('Queued import baseline handler is no longer registered: ' . $handlerType);
    }
    $count = 0;
    try {
      $stateManager = new ConfigStateManager();
      $directory = trim((string) $handler->getDirectory(), '/');
      foreach ($storage->iterateDirectory($directory) as $filename => $data) {
        if ($this->isIgnoredPath(($directory === '' ? '' : $directory . '/') . (string) $filename)) {
          continue;
        }
        $stateManager->acceptYamlBaselineItem($handler, (string) $filename, (array) $data, 'import');
        $count++;
      }
    }
    catch (\Throwable $e) {
      $state['state_warning'] = 'Import was applied successfully, but local baseline state could not be fully updated: ' . $e->getMessage();
    }
    $stateStore->saveState($state);
    return ['ok' => TRUE, 'baseline_items' => $count, 'processed_items' => (int) ($state['processed_items'] ?? 0)];
  }

  /** @return array<string,mixed> */
  public function queuedImportComplete(int $jobId, string $syncRootHash): array {
    $stateStore = new OperationWorkspace($jobId, $syncRootHash);
    $state = $stateStore->loadState();
    $result = [
      'ok' => TRUE,
      'dry_run' => FALSE,
      'applied' => TRUE,
      'items' => (array) ($state['items'] ?? []),
      'preflight' => [
        'ok' => TRUE,
        'summary_message' => (string) ($state['preflight_summary'] ?? ''),
      ],
    ];
    if (!empty($state['state_warning'])) {
      $result['state_warning'] = (string) $state['state_warning'];
    }
    foreach ($result['items'] as $item) {
      if (!empty($item['errors']) || (array_key_exists('ok', $item) && empty($item['ok']))) {
        $result['ok'] = FALSE;
        $result['partial_apply'] = TRUE;
      }
    }
    $result['summary_message'] = $this->buildImportSummaryMessage($result);
    $result['processed_items'] = (int) ($state['processed_items'] ?? 0);
    return $result;
  }

  private function buildImportPreflight(array $handlers, YamlFileStorage $storage, array $validation, ?callable $stepProgress = NULL, bool $captureFingerprints = FALSE): array {
    $result = [
      'ok' => !empty($validation['ok']),
      'dry_run' => TRUE,
      'applied' => FALSE,
      'validation' => $validation,
      'items' => [],
      'errors' => [],
    ];

    try {
      $possibleRenames = $this->findPossibleRenameCandidates($handlers, $storage, function($handler) use ($stepProgress) {
        if ($stepProgress !== NULL) {
          $stepProgress([
            'label' => 'Checked ' . $handler->getLabel() . ' identities',
            'message' => 'Rename/identity safety check complete.',
            'processed_items' => 0,
          ]);
        }
      });
      if ($possibleRenames) {
        $result['possible_renames'] = $possibleRenames;
        $result['ok'] = FALSE;
        foreach ($possibleRenames as $candidate) {
          $result['errors'][] = [
            'type' => (string) ($candidate['type'] ?? 'import'),
            'message' => 'Possible configuration identity rename requires review before import: '
              . (string) ($candidate['old_config_key'] ?? '[old identity]') . ' -> '
              . (string) ($candidate['new_config_key'] ?? '[new identity]') . '.',
          ];
        }
      }
    }
    catch (\Throwable $e) {
      $result['ok'] = FALSE;
      $result['errors'][] = ['type' => 'import', 'message' => 'Rename preflight could not be completed: ' . $e->getMessage()];
    }

    foreach ($handlers as $handler) {
      $this->setHandlerImportPhase($handler, TRUE, TRUE);
      try {
        $item = $this->importManagedYamlForHandler($handler, $storage, TRUE);
      }
      catch (\Throwable $e) {
        $item = [
          'type' => (string) $handler->getType(),
          'status' => 'dry_run',
          'dry_run' => TRUE,
          'errors' => [['message' => $e->getMessage()]],
          'warnings' => [],
        ];
      }
      $result['items'][] = $item;
      if ($captureFingerprints && empty($item['errors']) && (!array_key_exists('ok', $item) || !empty($item['ok']))) {
        try {
          $result['_active_fingerprints'][(string) $handler->getType()] = $this->compactManagedActiveSnapshot($handler, $storage);
        }
        catch (\Throwable $e) {
          $result['ok'] = FALSE;
          $result['errors'][] = [
            'type' => (string) $handler->getType(),
            'message' => 'Could not capture the preflight active fingerprint: ' . $e->getMessage(),
          ];
        }
      }
      if ($stepProgress !== NULL) {
        $stepProgress([
          'label' => 'Preflighted ' . $handler->getLabel(),
          'message' => 'Handler dry-run completed with no writes.',
          'processed_items' => $this->countImportItemActivity($item),
        ]);
      }
      if (!empty($item['errors']) || (array_key_exists('ok', $item) && empty($item['ok']))) {
        $result['ok'] = FALSE;
      }
    }

    $result['summary_message'] = $this->buildImportSummaryMessage($result);
    return $result;
  }

  private function collectImportPlannedDependencyNames(array $handlers, YamlFileStorage $storage): array {
    $available = [];
    foreach ($handlers as $handler) {
      $type = (string) $handler->getType();
      try {
        foreach ($this->iterateManagedYamlFilesForHandler($handler, $storage) as $file) {
          foreach ($this->namesFromYamlFile((array) $file) as $name) {
            $available[$type][(string) $name] = TRUE;
          }
        }
      }
      catch (\Throwable $e) {
        if (!isset($available[$type])) {
          $available[$type] = [];
        }
      }
    }
    return $available;
  }

  private function setHandlerPlannedDependencyNames($handler, array $plannedDependencyNames): void {
    if (method_exists($handler, 'setPlannedDependencyNames')) {
      $handler->setPlannedDependencyNames($plannedDependencyNames);
    }
  }

  private function findPossibleRenameCandidates(array $handlers, YamlFileStorage $storage, ?callable $stepProgress = NULL): array {
    $candidates = [];
    foreach ($handlers as $handler) {
      $policy = $this->scope->getPolicy((string) $handler->getType());
      if ($handler instanceof StreamingHandlerInterface && (($policy['mode'] ?? ConfigScope::MODE_ALL) === ConfigScope::MODE_ALL)) {
        $errors = [];
        $compact = $this->buildCompactStreamingDiff($handler, $storage, $errors);
        if ($errors) {
          throw new \RuntimeException((string) ($errors[0]['message'] ?? 'Active provider scan failed during rename preflight.'));
        }
        $diff = (array) ($compact['item'] ?? []);
        unset($compact);
      }
      else {
        $files = $this->loadManagedYamlFiles($handler, $storage);
        $partition = $this->scopePartition($handler, $handler->export(), $storage, FALSE);
        $exported = $this->filterIgnoredValuesInExportFiles($handler->getDirectory(), (array) ($partition['managed'] ?? []));
        $diff = $handler->diffFromExports($exported, $files);
        unset($files, $partition, $exported);
      }
      foreach ((array) ($diff['possible_renames'] ?? []) as $candidate) {
        if (!is_array($candidate)) {
          continue;
        }
        $candidate['type'] = $handler->getType();
        $candidate['label'] = $handler->getLabel();
        $candidates[] = $candidate;
      }
      unset($diff);
      if ($stepProgress !== NULL) {
        $stepProgress($handler);
      }
    }
    return $candidates;
  }

  private function countImportItemActivity(array $item): int {
    $count = 0;
    foreach (['create', 'update', 'delete', 'skip', 'install', 'enable', 'disable'] as $key) {
      $count += (int) ($item[$key] ?? 0);
    }
    foreach (['groups', 'values', 'settings', 'config'] as $group) {
      foreach (['create', 'update', 'delete', 'skip'] as $key) {
        $count += (int) (($item[$group][$key] ?? 0));
      }
    }
    return $count;
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
    return array_values(array_filter($this->getIgnoreRules(), static function(string $rule): bool {
      return strpos($rule, ':') === FALSE;
    }));
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


  /**
   * Return universal runtime-only ignore rules owned by the extension.
   *
   * @return string[]
   */
  public function getBuiltInIgnoreValueRules(): array {
    return self::BUILT_IN_IGNORE_VALUE_RULES;
  }

  /**
   * Return every universal rule shown in the single Config Ignore control.
   *
   * @return string[]
   */
  public function getBuiltInIgnoreRules(): array {
    return array_merge(self::BUILT_IN_IGNORE_FILE_RULES, self::BUILT_IN_IGNORE_VALUE_RULES);
  }

  /**
   * Return administrator-defined whole-file and field-level ignore rules.
   *
   * civicfg_ignore_values is still read for upgrade compatibility. New writes
   * use civicfg_ignore_paths as the one canonical Config Ignore setting.
   *
   * @return string[]
   */
  public function getConfiguredIgnoreRules(): array {
    $configured = array_merge(
      (array) \Civi::settings()->get('civicfg_ignore_paths'),
      (array) \Civi::settings()->get('civicfg_ignore_values')
    );
    $rules = [];
    foreach ($configured as $rule) {
      $normalized = $this->normalizeIgnoreRule((string) $rule);
      if ($normalized === NULL || in_array($normalized, $this->getBuiltInIgnoreRules(), TRUE)) {
        continue;
      }
      $rules[$normalized] = TRUE;
    }
    $values = array_keys($rules);
    sort($values, SORT_NATURAL | SORT_FLAG_CASE);
    return $values;
  }

  /**
   * Return the complete effective Config Ignore rule list.
   *
   * A rule without ':' ignores a whole YAML file/path. A rule with ':' keeps
   * the file managed and ignores only the named dot-path value.
   *
   * @return string[]
   */
  public function getIgnoreRules(): array {
    return array_values(array_unique(array_merge($this->getBuiltInIgnoreRules(), $this->getConfiguredIgnoreRules())));
  }

  /**
   * Save the one administrator-facing Config Ignore rule list.
   *
   * Built-in rules are never persisted because they are always effective.
   * Legacy civicfg_ignore_values data is migrated into civicfg_ignore_paths.
   *
   * @param array<int, mixed> $rules
   * @return string[]
   */
  public function setConfiguredIgnoreRules(array $rules): array {
    $configured = [];
    foreach ($rules as $rule) {
      $normalized = $this->normalizeIgnoreRule((string) $rule);
      if ($normalized === NULL || in_array($normalized, $this->getBuiltInIgnoreRules(), TRUE)) {
        continue;
      }
      $configured[$normalized] = TRUE;
    }
    $values = array_keys($configured);
    sort($values, SORT_NATURAL | SORT_FLAG_CASE);
    \Civi::settings()->set('civicfg_ignore_paths', $values);
    \Civi::settings()->set('civicfg_ignore_values', []);
    return $values;
  }

  /**
   * Return administrator-defined ignore rules only.
   *
   * @return string[]
   */
  public function getConfiguredIgnoreValueRules(): array {
    return array_values(array_filter($this->getConfiguredIgnoreRules(), static function(string $rule): bool {
      return strpos($rule, ':') !== FALSE;
    }));
  }

  /**
   * Return the effective built-in + administrator Config Ignore Values.
   */
  public function getIgnoreValuePatterns(): array {
    $rules = [];
    foreach ($this->getIgnoreRules() as $rule) {
      if (strpos($rule, ':') === FALSE) {
        continue;
      }
      [$path, $valuePath] = array_map('trim', explode(':', (string) $rule, 2));
      $raw = trim($path, '/') . ':' . trim($valuePath);
      $rules[$raw] = [
        'path' => trim($path, '/'),
        'value_path' => trim($valuePath),
        'raw' => $raw,
      ];
    }
    return array_values($rules);
  }

  private function normalizeIgnoreRule(string $rule): ?string {
    $rule = trim(str_replace('\\', '/', $rule));
    if ($rule === '') {
      return NULL;
    }

    if (strpos($rule, ':') === FALSE) {
      $path = trim($rule, '/');
      if ($path === '' || strpos($path, '..') !== FALSE) {
        return NULL;
      }
      return $path;
    }

    [$path, $valuePath] = array_map('trim', explode(':', $rule, 2));
    $path = trim($path, '/');
    if ($path === '' || $valuePath === '' || strpos($path, '..') !== FALSE || strpos($valuePath, '..') !== FALSE) {
      return NULL;
    }
    return $path . ':' . $valuePath;
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
    $available = [];
    foreach ($this->getHandlers() as $handler) {
      $type = (string) $handler->getType();
      $directory = trim((string) $handler->getDirectory(), '/');
      foreach ($storage->iterateDirectory((string) $handler->getDirectory()) as $filename => $file) {
        $relative = $directory === '' ? (string) $filename : $directory . '/' . (string) $filename;
        if ($this->isIgnoredPath($relative)) {
          continue;
        }
        foreach ($this->namesFromYamlFile((array) $file) as $name) {
          $available[$type][(string) $name] = TRUE;
        }
      }
    }
    foreach ($this->getHandlers() as $handler) {
      $type = (string) $handler->getType();
      $directory = trim((string) $handler->getDirectory(), '/');
      foreach ($storage->iterateDirectory((string) $handler->getDirectory()) as $filename => $file) {
        $relative = $directory === '' ? (string) $filename : $directory . '/' . (string) $filename;
        if ($this->isIgnoredPath($relative)) {
          continue;
        }
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
    $operationLock = OperationLock::acquire($storage->getRoot());
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
      foreach ($storage->iterateDirectory($handler->getDirectory()) as $filename => $file) {
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

  /**
   * True when an uploaded YAML path belongs to a currently registered handler.
   * Custom handlers registered through the normal hook are therefore accepted
   * without maintaining a hard-coded directory allowlist.
   */
  public function ownsManagedYamlPath(string $relativePath): bool {
    $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath === 'manifest.yml') {
      return TRUE;
    }
    return $relativePath !== '' && $this->resolveHandlerPath($relativePath) !== NULL;
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
    $rules = $this->getConfiguredIgnoreRules();
    $rules[] = $relativePath;
    return $this->setConfiguredIgnoreRules($rules);
  }

  public function addIgnoreValueRules(string $relativePath, array $valuePaths): array {
    $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath === '' || strpos($relativePath, '..') !== FALSE) {
      throw new \RuntimeException('Invalid ignore path.');
    }
    $rules = $this->getConfiguredIgnoreRules();
    foreach ($valuePaths as $valuePath) {
      $valuePath = trim((string) $valuePath);
      if ($valuePath === '' || strpos($valuePath, '..') !== FALSE) {
        continue;
      }
      $rules[] = $relativePath . ':' . $valuePath;
    }
    return $this->setConfiguredIgnoreRules($rules);
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
        'level' => 'error',
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
        $manifest = SimpleYaml::parseFile($manifestPath);
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
