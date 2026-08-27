<?php
namespace Civi\ConfigManager\Service;

/**
 * Persistent web-operation bridge backed by CiviCRM's SQL Queue.
 *
 * The browser never owns operation state. It starts a compact job, advances at
 * most one queue item per request, and polls the persisted job row. A separate
 * short-lived worker lock prevents two browser requests from consuming the
 * same queue concurrently after a timeout/reconnect.
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

    $syncRootHash = hash('sha256', $this->manager->getSyncDir());
    $active = $this->store->findActiveJob($syncRootHash);
    if ($active !== NULL) {
      $this->assertJobOwner($active, FALSE);
      return $active + ['reconnected' => TRUE];
    }

    $queueName = 'civicfg_' . substr($syncRootHash, 0, 12) . '_' . $this->nonce();
    $jobId = $this->store->createJob(
      $operation,
      $queueName,
      $syncRootHash,
      $this->manager->getSiteIdentifier(),
      array_values(array_unique(array_map('strval', $types)))
    );
    $this->store->createItem($jobId, 'execute', 'execute', NULL, $operation);

    $queue = \Civi::queue($queueName, [
      'type' => 'Sql',
      'reset' => TRUE,
      'is_persistent' => TRUE,
      'error' => 'abort',
    ]);
    $queue->createItem(new \CRM_Queue_Task(
      [self::class, 'runJobTask'],
      [$jobId],
      'Configuration Manager ' . ucfirst($operation) . ' #' . $jobId
    ));

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
    if (in_array((string) $job['status'], ['complete', 'failed', 'blocked'], TRUE)) {
      return $job;
    }

    $workerLock = \Civi::lockManager()->acquire('worker.civicfg.queue.' . $jobId);
    if (!method_exists($workerLock, 'isAcquired') || !$workerLock->isAcquired()) {
      $job['worker_busy'] = TRUE;
      return $job;
    }

    try {
      // Re-read after locking; another request may have completed immediately
      // before this one acquired the worker lock.
      $job = $this->requireJob($jobId);
      if (in_array((string) $job['status'], ['complete', 'failed', 'blocked'], TRUE)) {
        return $job;
      }
      if (hash('sha256', $this->manager->getSyncDir()) !== (string) $job['sync_root_hash']) {
        $this->store->failJob($jobId, 'Configuration Manager sync directory changed after this operation was queued. Start a new operation.');
        return $this->requireJob($jobId);
      }

      $queue = \Civi::queue((string) $job['queue_name']);
      $runner = new \CRM_Queue_Runner([
        'title' => 'Configuration Manager ' . ucfirst((string) $job['operation']) . ' #' . $jobId,
        'queue' => $queue,
        'errorMode' => \CRM_Queue_Runner::ERROR_ABORT,
      ]);
      $result = $runner->runNext(FALSE);
      $job = $this->requireJob($jobId);

      // A queue with no remaining work should always have reached one of our
      // terminal job states. Fail closed rather than showing an eternal spinner.
      if (isset($result['is_continue']) && !$result['is_continue'] && !in_array((string) $job['status'], ['complete', 'failed', 'blocked'], TRUE)) {
        $this->store->failJob($jobId, 'The persistent queue ended before Configuration Manager recorded a terminal job state.');
        $job = $this->requireJob($jobId);
      }
      return $job;
    }
    finally {
      if (method_exists($workerLock, 'release')) {
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
   * Persistent queue callback. The serialized task contains only the job ID.
   */
  public static function runJobTask(\CRM_Queue_TaskContext $ctx, int $jobId): bool {
    $store = new OperationStore();
    $manager = new ConfigManager();
    $job = $store->getJob($jobId);
    if ($job === NULL) {
      throw new \RuntimeException('Configuration Manager queued job #' . $jobId . ' no longer exists.');
    }

    $item = $store->getItem($jobId, 'execute');
    if ($item !== NULL && (string) $item['status'] === 'complete') {
      // Queue retry after a lost HTTP response: the durable item proves that
      // the operation already completed, so do not execute it a second time.
      return TRUE;
    }
    if ($item !== NULL && (string) $item['status'] === 'running') {
      // The previous PHP worker started this mutating task but never persisted a
      // terminal result. Replaying it blindly could overwrite a manual change
      // made after the crash. Fail closed until the operation is re-planned from
      // a fresh preflight. Completed items remain retry-idempotent above.
      $message = 'A previous Configuration Manager worker stopped before recording a terminal result. The operation was blocked instead of replaying an indeterminate mutating task. Review the current CiviCRM/YAML state and start a fresh operation.';
      $store->finishItem($jobId, 'execute', 'blocked', [], $message);
      $store->blockJob($jobId, $message);
      return TRUE;
    }

    $store->markRunning($jobId, 'execute');
    $store->markItemRunning($jobId, 'execute');
    $logger = class_exists('Civi') ? \Civi::log('civicfg') : NULL;
    $progress = static function(array $event) use ($store, $jobId, $logger): void {
      $store->updateProgress($jobId, $event);
      if ($logger !== NULL) {
        $logger->info('Configuration Manager job progress', [
          'job' => $jobId,
          'phase' => (string) ($event['phase'] ?? 'running'),
          'handler' => (string) ($event['label'] ?? ''),
          'processed' => (int) ($event['processed_items'] ?? 0),
          'completed' => (int) ($event['completed'] ?? 0),
          'total' => (int) ($event['total'] ?? 1),
          'memory_current_mb' => round(memory_get_usage(TRUE) / 1048576, 1),
          'memory_peak_mb' => round(memory_get_peak_usage(TRUE) / 1048576, 1),
        ]);
      }
    };

    try {
      $types = array_values(array_map('strval', (array) ($job['types'] ?? [])));
      if ((string) $job['operation'] === 'export') {
        $result = $manager->export(FALSE, $types, $progress);
      }
      elseif ((string) $job['operation'] === 'import') {
        $result = $manager->import(FALSE, TRUE, $types, $progress);
      }
      else {
        throw new \RuntimeException('Unknown queued Configuration Manager operation: ' . (string) $job['operation']);
      }

      $ok = !array_key_exists('ok', $result) || !empty($result['ok']);
      $store->finishItem($jobId, 'execute', $ok ? 'complete' : 'failed', $result, $ok ? '' : self::firstResultError($result));
      if ($ok) {
        $store->completeJob($jobId, $result);
      }
      else {
        $store->failJob($jobId, self::firstResultError($result), $result);
      }
      return TRUE;
    }
    catch (\Throwable $e) {
      $store->finishItem($jobId, 'execute', 'failed', [], $e->getMessage());
      $store->failJob($jobId, $e->getMessage());
      if ($logger !== NULL) {
        $logger->error('Configuration Manager queued job failed', [
          'job' => $jobId,
          'operation' => (string) $job['operation'],
          'error' => $e->getMessage(),
          'memory_peak_mb' => round(memory_get_peak_usage(TRUE) / 1048576, 1),
        ]);
      }
      // Failure is persisted as the authoritative terminal state. Returning
      // TRUE removes the task and prevents the generic runner from offering an
      // unsafe blind Retry which could overlap the original timed-out worker.
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

  private static function firstResultError(array $result): string {
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
