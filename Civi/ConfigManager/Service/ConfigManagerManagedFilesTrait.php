<?php
namespace Civi\ConfigManager\Service;

use Civi\ConfigManager\Handler\StreamingHandlerInterface;
use Civi\ConfigManager\Handler\StreamingImportHandlerInterface;
use Civi\ConfigManager\Storage\YamlFileStorage;
use Civi\ConfigManager\Util\SimpleYaml;

/**
 * ConfigManagerManagedFiles methods from ConfigManager.
 *
 * Methods run on the original owning object and retain the same state and
 * access modifiers. Do not instantiate this trait separately.
 */
trait ConfigManagerManagedFilesTrait {

  public function status(): array {
    $dir = $this->getSyncDir();
    $exists = is_dir($dir);
    $parent = dirname($dir);
    $writable = $exists ? is_writable($dir) : (is_dir($parent) && is_writable($parent));
    $yamlRuntime = SimpleYaml::runtimeStatus();
    $handlerDirectories = [];
    foreach ($this->getHandlers() as $handler) {
      $handlerDirectories[(string) $handler->getType()] = (string) $handler->getDirectory();
    }
    $inventory = (new SavedConfigInventory())->count(
      $exists ? (new YamlFileStorage($dir))->iterateYamlPaths() : [],
      $handlerDirectories
    );
    $savedConfigCount = (int) $inventory['total'];
    $savedConfigCounts = (array) $inventory['by_type'];
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
      'saved_config_count' => $savedConfigCount,
      'saved_config_counts' => $savedConfigCounts,
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

    $map = $this->importDependencyPlanner->relatedTypeMap();
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
      // phpcs:ignore -- Early return ends this Generator without yielding a value.
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
        ],
        ], FALSE, $portableSelectorMap);
        if (empty($partition['managed'])) {
          continue;
        }
      }
      yield $filename => $data;
    }
  }

}
