<?php
namespace Civi\ConfigManager\Service;

/**
 * Persistent CiviCRM Queue orchestration for alpha63.
 *
 * Export and Import are planned as durable, purpose-labelled work units rather
 * than one giant HTTP task. Safe read/stage/baseline units may be retried after
 * a dead worker; indeterminate live-mutating units fail closed.
 */
class QueuedOperationService {
  private OperationStore $store;
  private ConfigManager $manager;

  public function __construct(?OperationStore $store = NULL, ?ConfigManager $manager = NULL) {
    $this->store = $store ?: new OperationStore();
    $this->manager = $manager ?: new ConfigManager();
  }

  /**
   * @param string[] $types
   * @return array<string,mixed>
   */
  public function start(string $operation, array $types = []): array {
    if (!in_array($operation, ['export', 'import'], TRUE)) {
      throw new \RuntimeException('Unsupported Configuration Manager queued operation.');
    }
    if (!class_exists('Civi') || !class_exists('CRM_Queue_Task')) {
      throw new \RuntimeException('CiviCRM persistent Queue is unavailable in this runtime.');
    }

    $types = array_values(array_unique(array_filter(array_map('strval', $types))));
    $syncRootHash = hash('sha256', $this->manager->getSyncDir());
    $active = $this->store->findActiveJob($syncRootHash);
    if ($active !== NULL) {
      $this->assertJobOwner($active, FALSE);
      if ((string) ($active['operation'] ?? '') !== $operation) {
        throw new \RuntimeException('A ' . (string) $active['operation'] . ' job is already active for this sync directory. Finish or resolve that job before starting ' . $operation . '.');
      }
      return $active + ['reconnected' => TRUE];
    }

    $plan = $operation === 'export'
      ? $this->manager->buildQueuedExportPlan($types)
      : $this->manager->buildQueuedImportPlan($types);
    if (!$plan) {
      throw new \RuntimeException('Configuration Manager could not build an operation plan.');
    }

    $queueName = 'civicfg_' . substr($syncRootHash, 0, 12) . '_' . $this->nonce();
    $jobId = $this->store->createJob(
      $operation,
      $queueName,
      $syncRootHash,
      $this->manager->getSiteIdentifier(),
      $types
    );

    $queue = \Civi::queue($queueName, [
      'type' => 'Sql',
      'reset' => TRUE,
      'is_persistent' => TRUE,
      'error' => 'abort',
    ]);
    $sequence = 0;
    foreach ($plan as $task) {
      $task = (array) $task;
      $itemKey = trim((string) ($task['key'] ?? ''));
      if ($itemKey === '') {
        throw new \RuntimeException('Configuration Manager operation plan contains an item without a key.');
      }
      $sequence++;
      $this->store->createItem(
        $jobId,
        $itemKey,
        (string) ($task['phase'] ?? 'running'),
        isset($task['handler_type']) ? (string) $task['handler_type'] : NULL,
        (string) ($task['action'] ?? ''),
        $task,
        !empty($task['retry_safe']),
        $sequence
      );
      $queue->createItem(new \CRM_Queue_Task(
        [self::class, 'runJobTask'],
        [$jobId, $itemKey],
        (string) ($task['label'] ?? ('Configuration Manager ' . ucfirst($operation)))
      ));
    }

    $this->store->updateProgress($jobId, [
      'completed' => 0,
      'total' => count($plan),
      'progress_known' => FALSE,
      'phase' => 'queued',
      'phase_index' => 0,
      'phase_total' => (int) ($plan[0]['phase_total'] ?? 0),
      'label' => ucfirst($operation) . ' queued',
      'message' => 'Waiting to start. Current CiviCRM and existing Saved Configs have not been changed.',
      'processed_items' => 0,
    ]);

    $job = $this->store->getJob($jobId);
    if ($job === NULL) {
      throw new \RuntimeException('Configuration Manager operation job could not be reloaded after creation.');
    }
    return $job;
  }

  /**
   * Advance at most one persistent queue item.
   *
   * @return array<string,mixed>
   */
  public function advance(int $jobId): array {
    $job = $this->requireJob($jobId);
    $this->assertJobOwner($job, TRUE);
    if ($this->isTerminal($job)) {
      return $job;
    }

    $workerLock = \Civi::lockManager()->acquire('worker.civicfg.queue.' . $jobId);
    if (!is_object($workerLock) || !method_exists($workerLock, 'isAcquired') || !$workerLock->isAcquired()) {
      $job['worker_busy'] = TRUE;
      return $job;
    }

    try {
      $job = $this->requireJob($jobId);
      if ($this->isTerminal($job)) {
        return $job;
      }
      if (hash('sha256', $this->manager->getSyncDir()) !== (string) $job['sync_root_hash']) {
        $this->store->failJob($jobId, 'Configuration Manager sync directory changed after this operation was queued. Start a new operation.');
        return $this->requireJob($jobId);
      }

      // WordPress/CiviCRM may hold the PHP session lock for the request. Release
      // it before the potentially long worker unit so parallel read-only status
      // polls can see persisted heartbeats/progress instead of appearing frozen.
      if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
      }

      $queue = \Civi::queue((string) $job['queue_name']);
      $runner = new \CRM_Queue_Runner([
        'title' => 'Configuration Manager ' . ucfirst((string) $job['operation']) . ' #' . $jobId,
        'queue' => $queue,
        'errorMode' => \CRM_Queue_Runner::ERROR_ABORT,
      ]);
      $result = $runner->runNext(FALSE);
      $job = $this->requireJob($jobId);

      if (isset($result['is_continue']) && !$result['is_continue'] && !$this->isTerminal($job)) {
        $this->store->failJob($jobId, 'The persistent queue ended before Configuration Manager recorded a terminal job state.');
        $job = $this->requireJob($jobId);
      }
      return $job;
    }
    finally {
      if (is_object($workerLock) && method_exists($workerLock, 'release')) {
        $workerLock->release();
      }
    }
  }

  /** @return array<string,mixed> */
  public function status(int $jobId): array {
    $job = $this->requireJob($jobId);
    $this->assertJobOwner($job, TRUE);
    return $job;
  }

  /**
   * Persistent queue callback. The queue task stores only job ID + durable item
   * key; task semantics are loaded from civicrm_civicfg_job_item.payload_json.
   */
  public static function runJobTask(\CRM_Queue_TaskContext $ctx, int $jobId, string $itemKey): bool {
    $store = new OperationStore();
    $manager = new ConfigManager();
    $job = $store->getJob($jobId);
    if ($job === NULL) {
      throw new \RuntimeException('Configuration Manager queued job #' . $jobId . ' no longer exists.');
    }
    if (in_array((string) ($job['status'] ?? ''), ['complete', 'failed', 'blocked'], TRUE)) {
      return TRUE;
    }

    $item = $store->getItem($jobId, $itemKey);
    if ($item === NULL) {
      throw new \RuntimeException('Configuration Manager queue item was not found: ' . $itemKey);
    }
    if ((string) $item['status'] === 'complete') {
      // Reconcile the tiny crash window where the final work unit result was
      // durably recorded but the job terminal row was not yet updated.
      $completedAction = (string) ($item['action'] ?? '');
      if (in_array($completedAction, ['export_complete', 'import_complete'], TRUE)
        && !in_array((string) ($job['status'] ?? ''), ['complete', 'failed', 'blocked'], TRUE)) {
        $store->completeJob($jobId, (array) ($item['result'] ?? []));
        try {
          (new OperationWorkspace($jobId, (string) $job['sync_root_hash']))->cleanup();
        }
        catch (\Throwable $cleanupError) {
          // Terminal result is already durable; abandoned private temp state can
          // be cleaned by a later maintenance pass without invalidating it.
        }
      }
      return TRUE;
    }
    $payload = (array) ($item['payload'] ?? []);
    $action = (string) ($payload['action'] ?? $item['action'] ?? '');
    if ((string) $item['status'] === 'running') {
      if (!empty($item['retry_safe'])) {
        // The per-job worker lock proves the old PHP worker is no longer active
        // if we reached this callback again. Staging/read/baseline units are
        // explicitly designed to be replay-safe and clean their own partial
        // temporary output before re-execution.
        $store->incrementRetry($jobId);
        $store->resetItemForRetry($jobId, $itemKey);
        $item = $store->getItem($jobId, $itemKey) ?: $item;
        $payload = (array) ($item['payload'] ?? $payload);
        $action = (string) ($payload['action'] ?? $item['action'] ?? $action);
      }
      else {
        $recoveryMessage = '';
        if ($action === 'export_verify_publish') {
          try {
            $recovery = $manager->recoverQueuedExportPublish($jobId, (string) $job['sync_root_hash']);
            if (!empty($recovery['recovered'])) {
              $recoveryMessage = ' The durable YAML publication journal was recovered and the previous live YAML snapshot was restored before blocking the job.';
            }
            elseif (!empty($recovery['errors'])) {
              $recoveryMessage = ' Automatic YAML rollback reported: ' . implode('; ', (array) $recovery['errors']) . ' Manual filesystem review is required.';
            }
          }
          catch (\Throwable $recoveryError) {
            $recoveryMessage = ' Automatic YAML rollback could not be completed: ' . $recoveryError->getMessage() . ' Manual filesystem review is required.';
          }
        }
        $message = 'A previous Configuration Manager worker stopped during a live-mutating work unit before recording a terminal result. The job was blocked instead of replaying an indeterminate mutation.' . $recoveryMessage . ' Review current CiviCRM/YAML state and start a fresh reviewed operation.';
        $store->finishItem($jobId, $itemKey, 'blocked', [], $message);
        $store->blockJob($jobId, $message);
        return TRUE;
      }
    }
    $store->markRunning($jobId, (string) ($payload['phase'] ?? $item['phase'] ?? 'running'));
    $store->markItemRunning($jobId, $itemKey);

    $allItems = $store->getItems($jobId);
    $totalTasks = max(1, count($allItems));
    $completedBefore = 0;
    foreach ($allItems as $row) {
      if ((string) ($row['status'] ?? '') === 'complete') {
        $completedBefore++;
      }
    }
    $processedBase = (int) ($job['processed_items'] ?? 0);
    $logger = class_exists('Civi') ? \Civi::log('civicfg') : NULL;

    $baseEvent = [
      'completed' => $completedBefore,
      'total' => $totalTasks,
      'progress_known' => FALSE,
      'phase' => (string) ($payload['phase'] ?? 'running'),
      'phase_index' => (int) ($payload['phase_index'] ?? 0),
      'phase_total' => (int) ($payload['phase_total'] ?? 0),
      'label' => (string) ($payload['label'] ?? 'Configuration Manager task'),
      'message' => (string) ($payload['message'] ?? 'Processing saved Configuration Manager work.'),
      'processed_items' => $processedBase,
      'item_completed' => 0,
      'item_total' => 0,
    ];
    $store->updateProgress($jobId, $baseEvent);

    $progress = static function(array $event) use ($store, $jobId, $baseEvent, $logger): void {
      $merged = array_merge($baseEvent, $event);
      // Internal callbacks normally do not know overall queue completion; keep
      // the durable task counts from the base event unless explicitly supplied.
      if (!array_key_exists('completed', $event)) {
        $merged['completed'] = $baseEvent['completed'];
      }
      if (!array_key_exists('total', $event)) {
        $merged['total'] = $baseEvent['total'];
      }
      if (!array_key_exists('label', $event)) {
        $merged['label'] = $baseEvent['label'];
      }
      $store->updateProgress($jobId, $merged);
      if ($logger !== NULL) {
        $logger->info('Configuration Manager job progress', [
          'job' => $jobId,
          'phase' => (string) ($merged['phase'] ?? 'running'),
          'work_unit' => (string) ($merged['label'] ?? ''),
          'processed' => (int) ($merged['processed_items'] ?? 0),
          'memory_current_mb' => round(memory_get_usage(TRUE) / 1048576, 1),
          'memory_peak_mb' => round(memory_get_peak_usage(TRUE) / 1048576, 1),
        ]);
      }
    };

    try {
      $types = array_values(array_map('strval', (array) ($job['types'] ?? [])));
      $syncRootHash = (string) $job['sync_root_hash'];
      $handlerType = (string) ($payload['handler_type'] ?? $item['handler_type'] ?? '');
      $unitKey = (string) ($payload['unit_key'] ?? '');

      switch ($action) {
        case 'export_prepare':
          $result = $manager->queuedExportPrepare($jobId, $syncRootHash, $types);
          break;
        case 'export_stage':
          $result = $manager->queuedExportStage($jobId, $syncRootHash, $handlerType, $unitKey, $progress);
          break;
        case 'export_metadata':
          $result = $manager->queuedExportFinalizeMetadata($jobId, $syncRootHash);
          break;
        case 'export_verify_publish':
          $result = $manager->queuedExportVerifyAndPublish($jobId, $syncRootHash, $progress);
          break;
        case 'export_baseline':
          $result = $manager->queuedExportBaseline($jobId, $syncRootHash, $handlerType);
          break;
        case 'export_complete':
          $result = $manager->queuedExportComplete($jobId, $syncRootHash);
          break;
        case 'import_preflight':
          $result = $manager->queuedImportPreflight($jobId, $syncRootHash, $types, $progress);
          break;
        case 'import_create_update':
          $result = $manager->queuedImportCreateUpdate($jobId, $syncRootHash, $handlerType);
          break;
        case 'import_delete_missing':
          $result = $manager->queuedImportDeleteMissing($jobId, $syncRootHash, $handlerType);
          break;
        case 'import_baseline':
          $result = $manager->queuedImportBaseline($jobId, $syncRootHash, $handlerType);
          break;
        case 'import_complete':
          $result = $manager->queuedImportComplete($jobId, $syncRootHash);
          break;
        default:
          throw new \RuntimeException('Unknown Configuration Manager queue work-unit action: ' . $action);
      }

      $ok = !array_key_exists('ok', $result) || !empty($result['ok']);
      if (!$ok) {
        $message = self::firstResultError($result);
        $store->finishItem($jobId, $itemKey, 'failed', $result, $message);
        $store->failJob($jobId, $message, $result);
        return TRUE;
      }

      $store->finishItem($jobId, $itemKey, 'complete', $result);
      $completedAfter = $completedBefore + 1;
      if (in_array($action, ['export_complete', 'import_complete'], TRUE)) {
        $store->completeJob($jobId, $result);
        try {
          (new OperationWorkspace($jobId, (string) $job['sync_root_hash']))->cleanup();
        }
        catch (\Throwable $cleanupError) {
          if ($logger !== NULL) {
            $logger->warning('Configuration Manager terminal workspace cleanup deferred', [
              'job' => $jobId,
              'error' => $cleanupError->getMessage(),
            ]);
          }
        }
      }
      else {
        $latest = $store->getJob($jobId) ?: $job;
        $store->updateProgress($jobId, [
          'completed' => $completedAfter,
          'total' => $totalTasks,
          'progress_known' => FALSE,
          'phase' => (string) ($payload['phase'] ?? 'running'),
          'phase_index' => (int) ($payload['phase_index'] ?? 0),
          'phase_total' => (int) ($payload['phase_total'] ?? 0),
          'label' => (string) ($payload['label'] ?? 'Task done'),
          'message' => 'This part is done. Progress was saved and the next part can start.',
          'processed_items' => (int) ($result['processed_items'] ?? $latest['processed_items'] ?? 0),
          'item_completed' => (int) ($result['unit_processed'] ?? $result['baseline_items'] ?? 0),
          'item_total' => 0,
        ]);
      }
      return TRUE;
    }
    catch (\Throwable $e) {
      $store->finishItem($jobId, $itemKey, 'failed', [], $e->getMessage());
      $store->failJob($jobId, $e->getMessage());
      if ($logger !== NULL) {
        $logger->error('Configuration Manager queued work unit failed', [
          'job' => $jobId,
          'action' => $action,
          'phase' => (string) ($payload['phase'] ?? ''),
          'handler' => (string) ($payload['handler_type'] ?? ''),
          'error' => $e->getMessage(),
          'memory_peak_mb' => round(memory_get_peak_usage(TRUE) / 1048576, 1),
        ]);
      }
      return TRUE;
    }
  }

  /** @return array<string,mixed> */
  private function requireJob(int $jobId): array {
    if ($jobId <= 0) {
      throw new \RuntimeException('Invalid Configuration Manager operation job ID.');
    }
    $job = $this->store->getJob($jobId);
    if ($job === NULL) {
      throw new \RuntimeException('Configuration Manager operation job was not found.');
    }
    return $job;
  }

  private function assertJobOwner(array $job, bool $allowOtherAdmin): void {
    $owner = (int) ($job['initiating_user'] ?? 0);
    $current = class_exists('CRM_Core_Session') ? (int) \CRM_Core_Session::getLoggedInContactID() : 0;
    if ($owner > 0 && $current > 0 && $owner !== $current && !$allowOtherAdmin) {
      throw new \RuntimeException('Another administrator already has a Configuration Manager operation running for this sync directory.');
    }
  }

  private function isTerminal(array $job): bool {
    return in_array((string) ($job['status'] ?? ''), ['complete', 'failed', 'blocked'], TRUE);
  }

  public static function firstResultError(array $result): string {
    foreach ((array) ($result['errors'] ?? []) as $error) {
      if (is_array($error) && trim((string) ($error['message'] ?? '')) !== '') {
        return (string) $error['message'];
      }
      if (is_string($error) && trim($error) !== '') {
        return $error;
      }
    }
    foreach ((array) ($result['items'] ?? []) as $item) {
      foreach ((array) (($item['errors'] ?? [])) as $error) {
        if (is_array($error) && trim((string) ($error['message'] ?? '')) !== '') {
          return (string) $error['message'];
        }
        if (is_string($error) && trim($error) !== '') {
          return $error;
        }
      }
    }
    return trim((string) ($result['message'] ?? '')) ?: 'Configuration Manager operation failed.';
  }

  private function nonce(): string {
    try {
      return bin2hex(random_bytes(6));
    }
    catch (\Throwable $e) {
      return substr(hash('sha256', uniqid('', TRUE) . microtime(TRUE)), 0, 12);
    }
  }
}
