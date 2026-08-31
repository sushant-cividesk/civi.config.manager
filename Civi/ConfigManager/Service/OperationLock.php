<?php
namespace Civi\ConfigManager\Service;

/**
 * RAII wrapper for Configuration Manager operation locking.
 *
 * Alpha63 combines a persistent logical job lock (SQL job row) with CiviCRM's
 * short worker lock. Normal CLI/synchronous mutations are rejected while a web
 * job is queued/running; a queue work unit may pass its own job ID.
 */
class OperationLock {
  /** @var object|null */
  private $lock;

  private function __construct($lock) {
    $this->lock = $lock;
  }

  public static function acquire(string $syncRoot, ?int $jobId = NULL): self {
    $syncRootHash = hash('sha256', $syncRoot);
    if (class_exists('CRM_Core_DAO')) {
      try {
        $active = (new OperationStore())->findActiveJob($syncRootHash);
        if ($active !== NULL && (int) ($active['id'] ?? 0) !== (int) ($jobId ?? 0)) {
          throw new \RuntimeException('Another persistent Configuration Manager job is already active for this sync directory. Wait for it to finish or resolve the saved job before starting another mutation.');
        }
      }
      catch (\RuntimeException $e) {
        throw $e;
      }
      catch (\Throwable $e) {
        // If operation-state storage itself is unavailable, the existing Civi
        // worker lock still protects same-request concurrency. Do not make
        // legacy CLI runtimes unusable solely because the optional job table is
        // unavailable before extension schema installation.
      }
    }

    if (!class_exists('Civi') || !method_exists('Civi', 'lockManager')) {
      return new self(NULL);
    }
    $name = 'worker.civicfg.' . substr($syncRootHash, 0, 32);
    $lock = \Civi::lockManager()->acquire($name);
    if (!is_object($lock) || !method_exists($lock, 'isAcquired') || !$lock->isAcquired()) {
      if (is_object($lock) && method_exists($lock, 'release')) {
        $lock->release();
      }
      throw new \RuntimeException('Another Configuration Manager worker is already running for this sync directory. Wait for it to finish before starting another operation.');
    }
    return new self($lock);
  }

  public function release(): void {
    if ($this->lock !== NULL && method_exists($this->lock, 'release')) {
      $this->lock->release();
      $this->lock = NULL;
    }
  }

  public function __destruct() {
    $this->release();
  }
}
