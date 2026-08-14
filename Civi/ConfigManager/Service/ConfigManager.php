<?php
namespace Civi\ConfigManager\Service;

use Civi\ConfigManager\Storage\YamlFileStorage;
use Civi\ConfigManager\Version;

class ConfigManager {
  private HandlerRegistry $registry;

  public function __construct(?HandlerRegistry $registry = NULL) {
    $this->registry = $registry ?: new HandlerRegistry();
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
    $handlers = $this->registry->getHandlers();
    $enabled = (array) \Civi::settings()->get('civicfg_enabled_types');
    $enabled = array_values(array_filter(array_map('strval', $enabled)));
    if (!$enabled) {
      return $handlers;
    }
    $enabledBase = $this->baseTypesFromFilter($enabled);
    return array_values(array_filter($handlers, function($handler) use ($enabledBase) {
      return in_array($handler->getType(), $enabledBase, TRUE);
    }));
  }

  public function getAllHandlers(): array {
    return $this->registry->getHandlers();
  }

  public function getManagedTypeOptions(): array {
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
    return $rows;
  }

  public function status(): array {
    $dir = $this->getSyncDir();
    $exists = is_dir($dir);
    $parent = dirname($dir);
    $writable = $exists ? is_writable($dir) : (is_dir($parent) && is_writable($parent));
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
      'skipped' => [],
      'warnings' => [],
      'errors' => [],
      'message' => NULL,
    ];

    if (!$dryRun) {
      $manifest = $this->getManifestData();
      if (!$storage->isSame('', 'manifest.yml', $manifest)) {
        $summary['written'][] = $storage->write('', 'manifest.yml', $manifest);
      }
      else {
        $summary['skipped'][] = 'manifest.yml';
      }
    }

    $queue = [];
    $successfulHandlers = [];
    foreach ($this->getHandlers() as $handler) {
      if ($effectiveTypes && !in_array($handler->getType(), $effectiveTypes, TRUE)) {
        continue;
      }
      $this->prepareHandlerForTypeFilter($handler, $requestedTypes);
      try {
        foreach ($handler->export() as $file) {
          $relative = trim($handler->getDirectory(), '/') . '/' . $file['filename'];
          if ($this->isIgnoredPath($relative)) {
            $summary['skipped'][] = $relative . ' (ignored)';
            continue;
          }
          $queue[] = [
            'type' => $handler->getType(),
            'directory' => $handler->getDirectory(),
            'filename' => $file['filename'],
            'relative' => $relative,
            'data' => $this->applyIgnoredValueRules($relative, (array) ($file['data'] ?? [])),
          ];
        }
        $successfulHandlers[] = $handler;
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
    $stateManager = NULL;
    try {
      $stateManager = new ConfigStateManager();
      if (!$typeFilter) {
        // A full scan replaces the rebuildable object-state index. Filtered
        // scans update only what they inspect and leave other provider rows.
        $stateManager->rebuildObjectState();
      }
    }
    catch (\Throwable $e) {
      $result['state_warning'] = 'Configuration diff is available, but local state/baseline tracking could not be initialized: ' . $e->getMessage();
      $stateManager = NULL;
    }

    foreach ($this->getHandlers() as $handler) {
      $normalisedFilter = $this->normaliseTypeFilter($typeFilter);
      $baseFilter = $this->baseTypesFromFilter($normalisedFilter);
      if ($baseFilter && !in_array($handler->getType(), $baseFilter, TRUE)) {
        continue;
      }
      $this->prepareHandlerForTypeFilter($handler, $normalisedFilter);
      try {
        $files = $this->filterIgnoredFiles($handler->getDirectory(), $storage->readDirectory($handler->getDirectory()));
        $files = $this->applyHandlerFileFilter($handler, $files);
        $files = $this->filterIgnoredValuesInFiles($handler->getDirectory(), $files);
        $exported = $this->filterIgnoredValuesInExportFiles($handler->getDirectory(), $handler->export());
        $item = $handler->diffFromExports($exported, $files);
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
        if (($item['status'] ?? '') !== 'in_sync' || !empty($item['files'])) {
          $result['items'][] = $item;
        }
      }
      catch (\Throwable $e) {
        $result['errors'][] = ['type' => $handler->getType(), 'message' => $e->getMessage()];
      }
    }
    $result['ok'] = empty($result['errors']);
    return $result;
  }

  public function validate(array $typeFilter = []): array {
    $storage = new YamlFileStorage($this->getSyncDir());
    $result = ['ok' => TRUE, 'sync_dir' => $storage->getRoot(), 'items' => [], 'errors' => []];
    $yamlByType = [];
    foreach ($this->getHandlers() as $handler) {
      $normalisedFilter = $this->normaliseTypeFilter($typeFilter);
      $baseFilter = $this->baseTypesFromFilter($normalisedFilter);
      if ($baseFilter && !in_array($handler->getType(), $baseFilter, TRUE)) {
        continue;
      }
      $this->prepareHandlerForTypeFilter($handler, $normalisedFilter);
      try {
        $files = $this->filterIgnoredFiles($handler->getDirectory(), $storage->readDirectory($handler->getDirectory()));
        $files = $this->applyHandlerFileFilter($handler, $files);
        $files = $this->filterIgnoredValuesInFiles($handler->getDirectory(), $files);
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
          if (!isset($managedTypes[$dependencyType])) {
            // Non-managed runtime dependencies such as api-entity are informational.
            continue;
          }
          if (!isset($available[$dependencyType][$dependencyName])) {
            $result['ok'] = FALSE;
            $reason = (string) ($dependency['reason'] ?? 'This YAML item references another managed config item.');
            $ignoredHint = $this->ignoredDependencyHint($dependencyType, $dependencyName);
            $message = $this->formatMissingDependencyMessage($filename, $type, $dependencyType, $dependencyName, $reason, $ignoredHint);
            $result['items'][$itemIndex[$type]]['errors'][] = [
              'file' => $filename,
              'message' => $message,
            ];
          }
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


  private function formatMissingDependencyMessage(string $filename, string $ownerType, string $dependencyType, string $dependencyName, string $reason, string $ignoredHint = ''): string {
    $prefix = sprintf('Cannot import %s/%s: missing dependency %s "%s".', $ownerType, $filename, $dependencyType, $dependencyName);
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

  public function import(bool $dryRun = TRUE, bool $yes = FALSE, array $typeFilter = []): array {
    $storage = new YamlFileStorage($this->getSyncDir());
    $requestedTypes = $this->normaliseTypeFilter($typeFilter);
    $effectiveTypes = $this->getEffectiveExportTypeFilter($requestedTypes);
    $validation = $this->validate($requestedTypes);
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
      if ($effectiveTypes && !in_array($handler->getType(), $effectiveTypes, TRUE)) {
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
      // reverse order. This avoids deleting a parent SavedSearch while a child
      // SearchDisplay still exists and is scheduled for deletion in the same run.
      foreach ($handlers as $handler) {
        $this->setHandlerImportPhase($handler, TRUE, FALSE);
        $files = $this->filterIgnoredFiles($handler->getDirectory(), $storage->readDirectory($handler->getDirectory()));
        $files = $this->applyHandlerFileFilter($handler, $files);
        $files = $this->filterIgnoredValuesInFiles($handler->getDirectory(), $files);
        $item = $handler->import($files, FALSE);
        $item['phase'] = 'create_update';
        $result['items'][] = $item;
        if (!empty($item['errors'])) {
          $result['ok'] = FALSE;
        }
      }
      foreach (array_reverse($handlers) as $handler) {
        $this->setHandlerImportPhase($handler, FALSE, TRUE);
        $files = $this->filterIgnoredFiles($handler->getDirectory(), $storage->readDirectory($handler->getDirectory()));
        $files = $this->applyHandlerFileFilter($handler, $files);
        $files = $this->filterIgnoredValuesInFiles($handler->getDirectory(), $files);
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
            $files = $this->filterIgnoredFiles($handler->getDirectory(), $storage->readDirectory($handler->getDirectory()));
            $files = $this->applyHandlerFileFilter($handler, $files);
            $files = $this->filterIgnoredValuesInFiles($handler->getDirectory(), $files);
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
      $files = $this->filterIgnoredFiles($handler->getDirectory(), $storage->readDirectory($handler->getDirectory()));
      $files = $this->applyHandlerFileFilter($handler, $files);
      $files = $this->filterIgnoredValuesInFiles($handler->getDirectory(), $files);
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
      $files = $this->filterIgnoredFiles($handler->getDirectory(), $storage->readDirectory($handler->getDirectory()));
      $files = $this->applyHandlerFileFilter($handler, $files);
      $files = $this->filterIgnoredValuesInFiles($handler->getDirectory(), $files);
      $exported = $this->filterIgnoredValuesInExportFiles($handler->getDirectory(), $handler->export());
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
      $handler->setDeleteMissingEnabled($deleteEnabled);
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


  public function getHealth(): array {
    $status = $this->status();
    $syncDir = (string) ($status['sync_dir'] ?? $this->getSyncDir());
    $exists = !empty($status['exists']);
    $hasYaml = $exists && $this->hasYamlFiles($syncDir);

    if (!$exists) {
      return [
        'level' => 'warning',
        'title' => 'Configuration Manager: Initial export required',
        'message' => 'The sync directory does not exist yet. Run an export from Configuration Manager to create the initial YAML source files.',
        'sync_dir' => $syncDir,
        'changed' => 0,
        'in_civicrm' => 0,
        'in_yaml' => 0,
      ];
    }

    if (!$hasYaml) {
      return [
        'level' => 'warning',
        'title' => 'Configuration Manager: Initial export required',
        'message' => 'The sync directory exists but no YAML files were found. Run an export from Configuration Manager before using import as a source of truth.',
        'sync_dir' => $syncDir,
        'changed' => 0,
        'in_civicrm' => 0,
        'in_yaml' => 0,
      ];
    }

    $diff = $this->diff();
    $changed = 0;
    $inCivicrm = 0;
    $inYaml = 0;
    foreach (($diff['items'] ?? []) as $item) {
      $changed += !empty($item['changed']) ? count($item['changed']) : 0;
      $inCivicrm += !empty($item['new_in_db']) ? count($item['new_in_db']) : 0;
      $inYaml += !empty($item['missing_in_db']) ? count($item['missing_in_db']) : 0;
    }

    $total = $changed + $inCivicrm + $inYaml;
    if ($total > 0) {
      return [
        'level' => 'warning',
        'title' => 'Configuration Manager: Pending export/import changes',
        'message' => sprintf('There are %d pending configuration difference(s): %d changed, %d in CiviCRM, and %d in YAML. Review Configuration Manager and either export the CiviCRM changes to YAML or import YAML to CiviCRM.', $total, $changed, $inCivicrm, $inYaml),
        'sync_dir' => $syncDir,
        'changed' => $changed,
        'in_civicrm' => $inCivicrm,
        'in_yaml' => $inYaml,
      ];
    }

    return [
      'level' => 'info',
      'title' => 'Configuration Manager: In sync',
      'message' => 'CiviCRM configuration matches the YAML files in the sync directory.',
      'sync_dir' => $syncDir,
      'changed' => 0,
      'in_civicrm' => 0,
      'in_yaml' => 0,
    ];
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

  private function getManifestData(): array {
    $siteId = $this->getSiteIdentifier();
    $manifest = [
      'schema_version' => 1,
      'extension' => Version::EXTENSION_KEY,
      'format' => 'yaml',
      'exported_with' => Version::get(),
      'civicrm_min_version' => '5.0',
      'created_by' => 'Configuration Manager',
    ];
    if ($siteId !== '') {
      $manifest['site_id'] = $siteId;
      $manifest['site_policy'] = 'same-site-environments';
    }
    return $manifest;
  }
}
