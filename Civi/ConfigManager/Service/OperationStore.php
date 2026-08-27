<?php
namespace Civi\ConfigManager\Service;

/**
 * Persistent, compact state for long-running Configuration Manager operations.
 *
 * Portable YAML remains on disk. These tables intentionally store only job
 * metadata, hashes, counters, and small results required for safe retry/status.
 */
class OperationStore {
  public const JOB_TABLE = 'civicrm_civicfg_job';
  public const ITEM_TABLE = 'civicrm_civicfg_job_item';

  private static bool $schemaEnsured = FALSE;

  public function isAvailable(): bool {
    return class_exists('CRM_Core_DAO');
  }

  public function ensureSchema(): void {
    if (!$this->isAvailable() || self::$schemaEnsured) {
      return;
    }

    \CRM_Core_DAO::executeQuery('CREATE TABLE IF NOT EXISTS ' . self::JOB_TABLE . ' (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      operation VARCHAR(32) NOT NULL,
      phase VARCHAR(64) NOT NULL,
      status VARCHAR(32) NOT NULL,
      queue_name VARCHAR(191) NOT NULL,
      sync_root_hash CHAR(64) NOT NULL,
      site_identifier VARCHAR(191) NOT NULL,
      initiating_user INT UNSIGNED NULL,
      types_json TEXT NULL,
      progress_completed INT UNSIGNED NOT NULL DEFAULT 0,
      progress_total INT UNSIGNED NOT NULL DEFAULT 1,
      processed_items INT UNSIGNED NOT NULL DEFAULT 0,
      progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
      current_handler VARCHAR(191) NULL,
      current_message TEXT NULL,
      result_json MEDIUMTEXT NULL,
      error_message TEXT NULL,
      memory_current BIGINT UNSIGNED NOT NULL DEFAULT 0,
      memory_peak BIGINT UNSIGNED NOT NULL DEFAULT 0,
      retry_count INT UNSIGNED NOT NULL DEFAULT 0,
      started_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      heartbeat_at DATETIME NULL,
      finished_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY civicfg_job_queue (queue_name),
      KEY civicfg_job_root_status (sync_root_hash, status),
      KEY civicfg_job_updated (updated_at)
    ) ENGINE=InnoDB');

    \CRM_Core_DAO::executeQuery('CREATE TABLE IF NOT EXISTS ' . self::ITEM_TABLE . ' (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      job_id INT UNSIGNED NOT NULL,
      item_key VARCHAR(191) NOT NULL,
      handler_type VARCHAR(191) NULL,
      provider_key VARCHAR(191) NULL,
      phase VARCHAR(64) NOT NULL,
      action VARCHAR(64) NULL,
      config_key TEXT NULL,
      relative_path TEXT NULL,
      yaml_hash CHAR(64) NULL,
      active_hash CHAR(64) NULL,
      preflight_active_hash CHAR(64) NULL,
      status VARCHAR(32) NOT NULL,
      result_json MEDIUMTEXT NULL,
      error_message TEXT NULL,
      started_at DATETIME NULL,
      updated_at DATETIME NOT NULL,
      finished_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY civicfg_job_item_key (job_id, item_key),
      KEY civicfg_job_item_status (job_id, status),
      CONSTRAINT fk_civicfg_job_item_job FOREIGN KEY (job_id) REFERENCES ' . self::JOB_TABLE . ' (id) ON DELETE CASCADE
    ) ENGINE=InnoDB');

    self::$schemaEnsured = TRUE;
  }

  public function dropSchema(): void {
    if (!$this->isAvailable()) {
      return;
    }
    \CRM_Core_DAO::executeQuery('DROP TABLE IF EXISTS ' . self::ITEM_TABLE);
    \CRM_Core_DAO::executeQuery('DROP TABLE IF EXISTS ' . self::JOB_TABLE);
    self::$schemaEnsured = FALSE;
  }

  /** @param string[] $types */
  public function createJob(string $operation, string $queueName, string $syncRootHash, string $siteIdentifier, array $types = []): int {
    $this->ensureSchema();
    $now = date('Y-m-d H:i:s');
    $userId = class_exists('CRM_Core_Session') ? \CRM_Core_Session::getLoggedInContactID() : NULL;
    $typesJson = $this->encodeJson(array_values(array_unique(array_map('strval', $types))));
    $params = [
      1 => [$operation, 'String'],
      2 => ['queued', 'String'],
      3 => ['queued', 'String'],
      4 => [$queueName, 'String'],
      5 => [$syncRootHash, 'String'],
      6 => [$siteIdentifier, 'String'],
      7 => [(int) ($userId ?: 0), 'Integer'],
      8 => [$typesJson, 'String'],
      9 => [$now, 'String'],
    ];
    \CRM_Core_DAO::executeQuery('INSERT INTO ' . self::JOB_TABLE . '
      (operation, phase, status, queue_name, sync_root_hash, site_identifier, initiating_user, types_json, started_at, updated_at)
      VALUES (%1, %2, %3, %4, %5, %6, NULLIF(%7, 0), %8, %9, %9)', $params);
    return (int) \CRM_Core_DAO::singleValueQuery('SELECT LAST_INSERT_ID()');
  }

  public function createItem(int $jobId, string $itemKey, string $phase, ?string $handlerType = NULL, ?string $action = NULL): void {
    $this->ensureSchema();
    $now = date('Y-m-d H:i:s');
    $params = [
      1 => [$jobId, 'Integer'],
      2 => [$itemKey, 'String'],
      3 => [$handlerType ?? '', 'String'],
      4 => [$phase, 'String'],
      5 => [$action ?? '', 'String'],
      6 => ['queued', 'String'],
      7 => [$now, 'String'],
    ];
    \CRM_Core_DAO::executeQuery('INSERT INTO ' . self::ITEM_TABLE . '
      (job_id, item_key, handler_type, phase, action, status, updated_at)
      VALUES (%1, %2, NULLIF(%3, \'\'), %4, NULLIF(%5, \'\'), %6, %7)
      ON DUPLICATE KEY UPDATE handler_type = VALUES(handler_type), phase = VALUES(phase), action = VALUES(action), updated_at = VALUES(updated_at)', $params);
  }

  public function findActiveJob(string $syncRootHash): ?array {
    $this->ensureSchema();
    $dao = \CRM_Core_DAO::executeQuery('SELECT * FROM ' . self::JOB_TABLE . ' WHERE sync_root_hash = %1 AND status IN (\'queued\', \'running\') ORDER BY id DESC LIMIT 1', [
      1 => [$syncRootHash, 'String'],
    ]);
    if (!$dao->fetch()) {
      return NULL;
    }
    return $this->jobFromDao($dao);
  }

  public function getJob(int $jobId): ?array {
    $this->ensureSchema();
    $dao = \CRM_Core_DAO::executeQuery('SELECT * FROM ' . self::JOB_TABLE . ' WHERE id = %1 LIMIT 1', [1 => [$jobId, 'Integer']]);
    if (!$dao->fetch()) {
      return NULL;
    }
    return $this->jobFromDao($dao);
  }

  public function getItem(int $jobId, string $itemKey): ?array {
    $this->ensureSchema();
    $dao = \CRM_Core_DAO::executeQuery('SELECT * FROM ' . self::ITEM_TABLE . ' WHERE job_id = %1 AND item_key = %2 LIMIT 1', [
      1 => [$jobId, 'Integer'],
      2 => [$itemKey, 'String'],
    ]);
    if (!$dao->fetch()) {
      return NULL;
    }
    return $this->itemFromDao($dao);
  }

  /** @return array<int,array<string,mixed>> */
  public function getItems(int $jobId): array {
    $this->ensureSchema();
    $dao = \CRM_Core_DAO::executeQuery('SELECT * FROM ' . self::ITEM_TABLE . ' WHERE job_id = %1 ORDER BY id ASC', [1 => [$jobId, 'Integer']]);
    $items = [];
    while ($dao->fetch()) {
      $items[] = $this->itemFromDao($dao);
    }
    return $items;
  }

  public function markRunning(int $jobId, string $phase = 'running'): void {
    $this->ensureSchema();
    $now = date('Y-m-d H:i:s');
    \CRM_Core_DAO::executeQuery('UPDATE ' . self::JOB_TABLE . '
      SET status = %1, phase = %2, heartbeat_at = %3, updated_at = %3, retry_count = retry_count + 1
      WHERE id = %4', [
      1 => ['running', 'String'],
      2 => [$phase, 'String'],
      3 => [$now, 'String'],
      4 => [$jobId, 'Integer'],
    ]);
  }

  /** @param array<string,mixed> $event */
  public function updateProgress(int $jobId, array $event): void {
    $this->ensureSchema();
    $completed = max(0, (int) ($event['completed'] ?? 0));
    $total = max(1, (int) ($event['total'] ?? 1));
    $processed = max(0, (int) ($event['processed_items'] ?? 0));
    $percent = isset($event['percent']) ? (int) $event['percent'] : (int) floor(($completed / $total) * 100);
    $percent = max(0, min(100, $percent));
    $label = trim((string) ($event['label'] ?? ($event['current'] ?? '')));
    $message = trim((string) ($event['message'] ?? ''));
    $phase = trim((string) ($event['phase'] ?? 'running')) ?: 'running';
    $now = date('Y-m-d H:i:s');
    \CRM_Core_DAO::executeQuery('UPDATE ' . self::JOB_TABLE . '
      SET phase = %1, progress_completed = %2, progress_total = %3, processed_items = %4,
          progress_percent = %5, current_handler = NULLIF(%6, \'\'), current_message = NULLIF(%7, \'\'),
          memory_current = %8, memory_peak = %9, heartbeat_at = %10, updated_at = %10
      WHERE id = %11', [
      1 => [$phase, 'String'],
      2 => [$completed, 'Integer'],
      3 => [$total, 'Integer'],
      4 => [$processed, 'Integer'],
      5 => [$percent, 'Integer'],
      6 => [$label, 'String'],
      7 => [$message, 'String'],
      8 => [memory_get_usage(TRUE), 'Integer'],
      9 => [memory_get_peak_usage(TRUE), 'Integer'],
      10 => [$now, 'String'],
      11 => [$jobId, 'Integer'],
    ]);
  }

  /** @param array<string,mixed> $result */
  public function completeJob(int $jobId, array $result, string $message = ''): void {
    $this->finishJob($jobId, !empty($result['ok']) || !array_key_exists('ok', $result) ? 'complete' : 'failed', $result, $message);
  }

  public function failJob(int $jobId, string $message, array $result = []): void {
    $this->finishJob($jobId, 'failed', $result, $message);
  }

  /**
   * Mark a job blocked when its previous worker outcome is indeterminate.
   *
   * A killed PHP request may have performed part of a mutating handler before
   * losing the HTTP response. Until alpha62 has per-object persisted cursors,
   * automatically replaying that same item would be less safe than stopping.
   */
  public function blockJob(int $jobId, string $message, array $result = []): void {
    $this->finishJob($jobId, 'blocked', $result, $message);
  }

  /** @param array<string,mixed> $result */
  private function finishJob(int $jobId, string $status, array $result, string $message): void {
    $this->ensureSchema();
    $now = date('Y-m-d H:i:s');
    $encoded = $this->encodeJson($result);
    \CRM_Core_DAO::executeQuery('UPDATE ' . self::JOB_TABLE . '
      SET status = %1, phase = %1, progress_percent = CASE WHEN %1 = \'complete\' THEN 100 ELSE progress_percent END,
          result_json = %2, error_message = NULLIF(%3, \'\'), memory_current = %4, memory_peak = %5,
          heartbeat_at = %6, updated_at = %6, finished_at = %6
      WHERE id = %7', [
      1 => [$status, 'String'],
      2 => [$encoded, 'String'],
      3 => [$message, 'String'],
      4 => [memory_get_usage(TRUE), 'Integer'],
      5 => [memory_get_peak_usage(TRUE), 'Integer'],
      6 => [$now, 'String'],
      7 => [$jobId, 'Integer'],
    ]);
  }

  /** @param array<string,mixed> $result */
  public function finishItem(int $jobId, string $itemKey, string $status, array $result = [], string $error = ''): void {
    $this->ensureSchema();
    $now = date('Y-m-d H:i:s');
    \CRM_Core_DAO::executeQuery('UPDATE ' . self::ITEM_TABLE . '
      SET status = %1, result_json = %2, error_message = NULLIF(%3, \'\'), updated_at = %4, finished_at = %4
      WHERE job_id = %5 AND item_key = %6', [
      1 => [$status, 'String'],
      2 => [$this->encodeJson($result), 'String'],
      3 => [$error, 'String'],
      4 => [$now, 'String'],
      5 => [$jobId, 'Integer'],
      6 => [$itemKey, 'String'],
    ]);
  }

  public function markItemRunning(int $jobId, string $itemKey): void {
    $this->ensureSchema();
    $now = date('Y-m-d H:i:s');
    \CRM_Core_DAO::executeQuery('UPDATE ' . self::ITEM_TABLE . '
      SET status = %1, started_at = COALESCE(started_at, %2), updated_at = %2
      WHERE job_id = %3 AND item_key = %4', [
      1 => ['running', 'String'],
      2 => [$now, 'String'],
      3 => [$jobId, 'Integer'],
      4 => [$itemKey, 'String'],
    ]);
  }

  /** @return array<string,mixed> */
  private function jobFromDao($dao): array {
    return [
      'id' => (int) $dao->id,
      'operation' => (string) $dao->operation,
      'phase' => (string) $dao->phase,
      'status' => (string) $dao->status,
      'queue_name' => (string) $dao->queue_name,
      'sync_root_hash' => (string) $dao->sync_root_hash,
      'site_identifier' => (string) $dao->site_identifier,
      'initiating_user' => $dao->initiating_user !== NULL ? (int) $dao->initiating_user : NULL,
      'types' => $this->decodeJson((string) ($dao->types_json ?? ''), []),
      'completed' => (int) $dao->progress_completed,
      'total' => max(1, (int) $dao->progress_total),
      'processed_items' => (int) $dao->processed_items,
      'percent' => (int) $dao->progress_percent,
      'current' => (string) ($dao->current_handler ?? ''),
      'message' => (string) ($dao->current_message ?? ''),
      'result' => $this->decodeJson((string) ($dao->result_json ?? ''), []),
      'error' => (string) ($dao->error_message ?? ''),
      'memory_current' => (int) $dao->memory_current,
      'memory_peak' => (int) $dao->memory_peak,
      'retry_count' => (int) $dao->retry_count,
      'started_at' => (string) $dao->started_at,
      'updated_at' => (string) $dao->updated_at,
      'heartbeat_at' => (string) ($dao->heartbeat_at ?? ''),
      'finished_at' => (string) ($dao->finished_at ?? ''),
    ];
  }

  /** @return array<string,mixed> */
  private function itemFromDao($dao): array {
    return [
      'id' => (int) $dao->id,
      'job_id' => (int) $dao->job_id,
      'item_key' => (string) $dao->item_key,
      'handler_type' => (string) ($dao->handler_type ?? ''),
      'provider_key' => (string) ($dao->provider_key ?? ''),
      'phase' => (string) $dao->phase,
      'action' => (string) ($dao->action ?? ''),
      'config_key' => (string) ($dao->config_key ?? ''),
      'relative_path' => (string) ($dao->relative_path ?? ''),
      'yaml_hash' => (string) ($dao->yaml_hash ?? ''),
      'active_hash' => (string) ($dao->active_hash ?? ''),
      'preflight_active_hash' => (string) ($dao->preflight_active_hash ?? ''),
      'status' => (string) $dao->status,
      'result' => $this->decodeJson((string) ($dao->result_json ?? ''), []),
      'error' => (string) ($dao->error_message ?? ''),
      'started_at' => (string) ($dao->started_at ?? ''),
      'updated_at' => (string) $dao->updated_at,
      'finished_at' => (string) ($dao->finished_at ?? ''),
    ];
  }

  private function encodeJson($value): string {
    $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    if ($encoded === FALSE) {
      throw new \RuntimeException('Could not encode Configuration Manager operation state.');
    }
    return $encoded;
  }

  private function decodeJson(string $value, $fallback) {
    if ($value === '') {
      return $fallback;
    }
    $decoded = json_decode($value, TRUE);
    return is_array($decoded) ? $decoded : $fallback;
  }
}
