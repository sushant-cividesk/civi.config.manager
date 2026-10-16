<?php
namespace Civi\ConfigManager\Service;

use Civi\ConfigManager\Storage\YamlFileStorage;

/**
 * ConfigManagerQueuedImport methods from ConfigManager.
 *
 * Methods run on the original owning object and retain the same state and
 * access modifiers. Do not instantiate this trait separately.
 */
trait ConfigManagerQueuedImportTrait {

  /**
   * @return array<int,array<string,mixed>> */
  public function buildQueuedImportPlan(array $typeFilter = [], string $reviewPlanId = ''): array {
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

    if ($reviewPlanId === '') {
      throw new \RuntimeException('Import apply requires the reviewed Import plan ID. Build and review a fresh Import preview first.');
    }

    $tasks = [[
      'key' => 'import:preflight',
      'action' => 'import_preflight',
      'review_plan_id' => $reviewPlanId,
      'phase' => 'preflight',
      'phase_index' => 1,
      'phase_total' => 5,
      'label' => 'Checking import safety',
      'message' => 'Checking Saved Configs and required related configuration before making any CiviCRM changes.',
      'retry_safe' => TRUE,
    ],
    ];
    foreach ($handlers as $handler) {
      $tasks[] = [
        'key' => 'import:write:' . (string) $handler->getType(),
        'action' => 'import_create_update',
        'phase' => 'create_update',
        'phase_index' => 2,
        'phase_total' => 5,
        'handler_type' => (string) $handler->getType(),
        'label' => 'Updating ' . $handler->getLabel(),
        'message' => 'Creating or updating this configuration type. Nothing is removed during this part.',
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
        'label' => 'Checking removals — ' . $handler->getLabel(),
        'message' => 'Earlier updates are complete. Only configuration proven safe to remove can be removed now.',
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
        'label' => 'Saving comparison baseline — ' . $handler->getLabel(),
        'message' => 'Saving the new comparison point for future Synchronize checks.',
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
      'message' => 'Finishing the import and saving the result.',
      'retry_safe' => TRUE,
    ];
    foreach ($tasks as &$task) {
      $task['review_plan_id'] = $reviewPlanId;
    }
    unset($task);
    return $tasks;
  }

  /**
   * @return array<string,mixed> */
  public function queuedImportPreflight(int $jobId, string $syncRootHash, string $reviewPlanId, array $typeFilter = [], ?callable $progress = NULL): array {
    if ($reviewPlanId === '') {
      throw new \RuntimeException('Import apply requires a reviewed Import plan. Build and review a fresh preview first.');
    }
    $reviewPlan = $this->validateImportReviewPlan($reviewPlanId, $typeFilter, TRUE);
    $typeFilter = array_values(array_map('strval', (array) ($reviewPlan['requested_types'] ?? [])));
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
        'message' => 'Dependency context is ready. Validating every managed Saved Config before any Current CiviCRM write.',
      ]);
    }
    $validation = $this->validate($validationTypes);
    $processed = 0;
    $preflight = $this->buildImportPreflight($handlers, $storage, $validation, function (array $event) use (&$processed, $progress) {
      $processed += (int) ($event['processed_items'] ?? 0);
      if ($progress !== NULL) {
        $progress([
          'progress_known' => FALSE,
          'processed_items' => $processed,
          'message' => (string) ($event['label'] ?? 'Import preflight') . '. ' . (string) ($event['message'] ?? 'No writes performed.'),
        ]);
      }
    }, TRUE);

    if (!empty($preflight['ok'])) {
      $this->assertReviewPlanPreflightMatches($reviewPlan, $preflight);
    }

    $state = [
      'operation' => 'import',
      'review_plan_id' => $reviewPlanId,
      'review_plan' => $reviewPlan,
      'requested_types' => $requestedTypes,
      'effective_types' => $effectiveTypes,
      'apply_types' => array_values(array_map(static function ($handler): string {
        return (string) $handler->getType();
      }, $handlers)),
      'planned_dependency_names' => $plannedDependencyNames,
      'preflight_fingerprints' => (array) ($preflight['_active_fingerprints'] ?? []),
      'post_write_fingerprints' => [],
      'preflight_summary' => (string) ($preflight['summary_message'] ?? ''),
      'items' => [],
      'processed_items' => $processed,
      'state_warning' => '',
      'excluded_components' => (array) ($reviewPlan['excluded_components'] ?? []),
    ];
    $stateStore->saveState($state);

    if (empty($preflight['ok'])) {
      unset($preflight['_active_fingerprints']);
      $preflight['dry_run'] = FALSE;
      $preflight['applied'] = FALSE;
      $preflight['message'] = 'Import stopped before writes because the complete preflight found blocking errors. Zero writes were performed.';
      return $this->importResultAccounting->attach($preflight, (array) ($reviewPlan['excluded_components'] ?? []));
    }
    return $this->importResultAccounting->attach([
      'ok' => TRUE,
      'dry_run' => TRUE,
      'applied' => FALSE,
      'summary_message' => (string) ($preflight['summary_message'] ?? ''),
      'processed_items' => $processed,
      'items' => (array) ($preflight['items'] ?? []),
    ], (array) ($reviewPlan['excluded_components'] ?? []));
  }

  /**
   * @return array<string,mixed> */
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
      $reviewPlan = $this->validateQueuedReviewPlan($state);
      $this->assertReviewPlanHandlerYamlMatches($reviewPlan, $handler, $storage);
      if (isset($state['preflight_fingerprints'][$handlerType])) {
        $this->assertManagedActiveSnapshotMatches($handler, $storage, (array) $state['preflight_fingerprints'][$handlerType], 'Import conflict: Current CiviCRM changed after preflight. No write was performed for this handler.');
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
      $result['errors'] = (array) ($item['errors'] ?? []);
      $result['partial_apply'] = TRUE;
      $result['delete_phase_skipped'] = TRUE;
      $result['message'] = 'Import stopped after a create/update work-unit failure. No delete-missing work unit will run. Review the applied handlers and restore/retry from the pre-import database backup if needed.';
      $result['items'] = (array) ($state['items'] ?? []);
    }
    return $this->importResultAccounting->attach($result, (array) ($state['excluded_components'] ?? []));
  }

  /**
   * @return array<string,mixed> */
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
      $reviewPlan = $this->validateQueuedReviewPlan($state);
      $this->assertReviewPlanHandlerYamlMatches($reviewPlan, $handler, $storage);
      if (isset($state['post_write_fingerprints'][$handlerType])) {
        $this->assertManagedActiveSnapshotMatches($handler, $storage, (array) $state['post_write_fingerprints'][$handlerType], 'Import conflict: Current CiviCRM changed after create/update. Delete-missing was not started for this handler.');
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
      $result['errors'] = (array) ($item['errors'] ?? []);
      $result['partial_apply'] = TRUE;
      $result['message'] = 'Import delete-missing stopped with an error after earlier create/update work units had succeeded. Remaining queue work was not continued.';
      $result['items'] = (array) ($state['items'] ?? []);
    }
    return $this->importResultAccounting->attach($result, (array) ($state['excluded_components'] ?? []));
  }

  /**
   * @return array<string,mixed> */
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

  /**
   * @return array<string,mixed> */
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
    $result['processed_items'] = (int) ($state['processed_items'] ?? 0);
    $result = $this->importResultAccounting->attach(
      $result,
      (array) ($state['excluded_components'] ?? []),
      $this->verifiedPostImportDiff()
    );
    $reviewPlanId = trim((string) ($state['review_plan_id'] ?? ''));
    if ($reviewPlanId !== '') {
      $this->importPlanGuard->discard($reviewPlanId);
    }
    return $result;
  }

}
