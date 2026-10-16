<?php
namespace Civi\ConfigManager\Service;

use Civi\ConfigManager\Handler\ChunkedStreamingHandlerInterface;
use Civi\ConfigManager\Handler\StreamingHandlerInterface;
use Civi\ConfigManager\Storage\YamlFileStorage;

/**
 * ConfigManagerQueuedExport methods from ConfigManager.
 *
 * Methods run on the original owning object and retain the same state and
 * access modifiers. Do not instantiate this trait separately.
 */
trait ConfigManagerQueuedExportTrait {


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
      'label' => 'Preparing export',
      'message' => 'Getting ready. Current CiviCRM and existing Saved Configs are unchanged.',
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
            'label' => 'Checking ' . (string) ($unit['label'] ?? $handler->getLabel()),
            'message' => 'Reading this configuration group once and preparing its temporary Saved Config snapshot. Existing Saved Configs are unchanged.',
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
          'label' => 'Checking ' . $handler->getLabel(),
          'message' => (($policy['mode'] ?? ConfigScope::MODE_ALL) === ConfigScope::MODE_SELECTED)
            ? 'Checking the selected Current CiviCRM items and preparing Saved Configs. Existing Saved Configs are unchanged until the final safety check passes.'
            : 'Checking Current CiviCRM and preparing Saved Configs. Existing Saved Configs are unchanged until the final safety check passes.',
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
      'label' => 'Preparing Saved Config details',
      'message' => 'Preparing the saved configuration details needed to finish this export safely.',
      'retry_safe' => TRUE,
    ];
    $tasks[] = [
      'key' => 'export:verify-publish',
      'action' => 'export_verify_publish',
      'phase' => 'verify_publish',
      'phase_index' => 4,
      'phase_total' => 6,
      'label' => 'Final safety check',
      'message' => 'Checking Current CiviCRM one last time before saving changes. If anything changed, existing Saved Configs stay untouched.',
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
        'label' => 'Saving comparison baseline — ' . $handler->getLabel(),
        'message' => 'Saving the completed state so future changes can be compared safely.',
        'retry_safe' => TRUE,
      ];
    }
    $tasks[] = [
      'key' => 'export:complete',
      'action' => 'export_complete',
      'phase' => 'complete',
      'phase_index' => 6,
      'phase_total' => 6,
      'label' => 'Finishing export',
      'message' => 'Saving the export result and cleaning temporary files.',
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
          'message' => (string) ($event['message'] ?? 'Checking configuration items.'),
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
            'message' => 'Checking ' . $handler->getLabel() . '. ' . $processed . ' items checked; existing Saved Configs are unchanged.',
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
        $local['warnings'][] = ['type' => $handlerType, 'message' => 'Configured scope selector has never resolved to a Current CiviCRM object: ' . (string) $selector . '.'];
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
      'review_only_items' => array_values((array) ($local['review_only_items'] ?? [])),
      'warnings' => array_values((array) ($local['warnings'] ?? [])),
      'skipped' => array_values((array) ($local['skipped'] ?? [])),
    ];
    $state['processed_items'] = 0;
    $state['monitor_only'] = 0;
    $state['review_only_items'] = [];
    $state['warnings'] = [];
    $state['skipped_stage'] = [];
    foreach ((array) ($state['stage_unit_results'] ?? []) as $unitResult) {
      $unitResult = (array) $unitResult;
      $state['processed_items'] += (int) ($unitResult['processed'] ?? 0);
      $state['monitor_only'] += (int) ($unitResult['monitor_only'] ?? 0);
      $state['review_only_items'] = array_merge(
        $state['review_only_items'],
        (array) ($unitResult['review_only_items'] ?? [])
      );
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
    $createdPaths = array_values(array_map('strval', (array) ($preview['create'] ?? [])));
    $updatedPaths = array_values(array_map('strval', (array) ($preview['update'] ?? [])));
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
    $state['created_paths'] = $createdPaths;
    $state['updated_paths'] = $updatedPaths;
    $state['expected_fingerprints'] = $expected;
    $workspace->persistIndex();
    $stateStore->saveState($state);
    return [
      'ok' => TRUE,
      'processed_items' => (int) ($state['processed_items'] ?? 0),
      'write_count' => count((array) ($preview['write'] ?? [])),
      'created_count' => count($createdPaths),
      'updated_count' => count($updatedPaths),
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
          'message' => 'Final safety check for ' . $handler->getLabel() . '. Current CiviCRM is being checked again before Saved Configs are updated.',
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
        'message' => 'Safety check passed. Saving the verified configuration now.',
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
      throw new \RuntimeException('Cannot record the export baseline before the Saved Config snapshot is published.');
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
      throw new \RuntimeException('Queued export cannot complete because no verified Saved Config snapshot was published.');
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
      'created_count' => count((array) ($state['created_paths'] ?? [])),
      'updated_count' => count((array) ($state['updated_paths'] ?? [])),
      'deleted' => (array) ($published['deleted'] ?? []),
      'skipped' => array_values(array_unique(array_merge((array) ($published['skipped'] ?? []), (array) ($state['skipped_stage'] ?? [])))),
      'warnings' => array_merge((array) ($state['warnings'] ?? []), (array) ($state['baseline_warnings'] ?? [])),
      'errors' => [],
      'monitor_only' => (int) ($state['monitor_only'] ?? 0),
      'review_only_items' => array_values((array) ($state['review_only_items'] ?? [])),
      'processed_items' => (int) ($state['processed_items'] ?? 0),
    ];
    if (!$result['written'] && !$result['deleted']) {
      $result['message'] = 'No files written. Saved Configs already match Current CiviCRM.';
    }
    else {
      $result['message'] = 'Export complete. The verified Saved Config snapshot was published successfully.';
    }
    return $result;
  }
}
