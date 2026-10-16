<?php
namespace Civi\ConfigManager\Service;

use Civi\ConfigManager\Storage\YamlFileStorage;
use Civi\ConfigManager\Util\SimpleYaml;
use Civi\ConfigManager\Version;

/**
 * ConfigManagerMonitoring methods from ConfigManager.
 *
 * Methods run on the original owning object and retain the same state and
 * access modifiers. Do not instantiate this trait separately.
 */
trait ConfigManagerMonitoringTrait {

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
      'message' => 'Saved Configs exist. Run Synchronize or civicfg diff to refresh the last-known configuration status.',
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
        : 'The last Configuration Manager scan found no differences between managed Saved Configs and Current CiviCRM.',
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
}
