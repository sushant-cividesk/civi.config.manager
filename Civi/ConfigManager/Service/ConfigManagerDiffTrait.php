<?php
namespace Civi\ConfigManager\Service;

use Civi\ConfigManager\Handler\StreamingHandlerInterface;
use Civi\ConfigManager\Storage\YamlFileStorage;

/**
 * ConfigManagerDiff methods from ConfigManager.
 *
 * Methods run on the original owning object and retain the same state and
 * access modifiers. Do not instantiate this trait separately.
 */
trait ConfigManagerDiffTrait {

  public function diff(array $typeFilter = []): array {
    $storage = new YamlFileStorage($this->getSyncDir());
    $result = ['ok' => TRUE, 'sync_dir' => $storage->getRoot(), 'items' => [], 'errors' => [], 'baseline_unverified' => 0];
    $scopeState = $this->getScopeSetupState();
    if (empty($scopeState['managed'])) {
      $result['no_managed_scope'] = TRUE;
      $result['watch_only'] = !empty($scopeState['watch_only']);
      $result['setup_required'] = !empty($scopeState['setup_required']);
      $result['message'] = !empty($scopeState['watch_only'])
        ? 'Watch-only configuration is enabled, but no configuration is currently managed in YAML.'
        : 'Configuration scope setup is required before Saved Config synchronization can begin.';
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
          $result['baseline_unverified'] += (int) ($item['state_summary']['NO_BASELINE'] ?? 0);
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
          $item['scope_warnings'] = array_values(array_map(static function ($selector) {
            return 'Configured managed selector is not present in Current CiviCRM: ' . (string) $selector . '. YAML backup remains managed.';
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
        $result['baseline_unverified'] += (int) ($item['state_summary']['NO_BASELINE'] ?? 0);
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
    // A filtered Synchronize call is not an authoritative site-wide scan.
    // Do not overwrite the last full health result with a partial success.
    if (!$typeFilter) {
      $this->cacheHealthFromDiff($result);
    }
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
    usort($files, static function (array $a, array $b): int {
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
      $exact = array_values(array_filter($matches, static function (array $row) use ($filename): bool {
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

}
