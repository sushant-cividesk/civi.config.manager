<?php
namespace Civi\ConfigManager\Service;

use Civi\ConfigManager\Storage\YamlFileStorage;

/**
 * ConfigManagerExportStaging methods from ConfigManager.
 *
 * Methods run on the original owning object and retain the same state and
 * access modifiers. Do not instantiate this trait separately.
 */
trait ConfigManagerExportStagingTrait {

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
      'source_id' => isset($file['source_id']) && is_scalar($file['source_id']) ? (string) $file['source_id'] : '',
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
      if (!isset($summary['review_only_items'])) {
        $summary['review_only_items'] = [];
      }
      $summary['review_only_items'][] = [
        'type' => (string) $handler->getType(),
        'label' => (string) $handler->getLabel(),
        'path' => $relative,
      ];
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
}
