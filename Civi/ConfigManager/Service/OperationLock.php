<?php
namespace Civi\ConfigManager\Service;

/**
 * RAII wrapper for CiviCRM's scoped worker lock.
 *
 * The lock name is shared by export/import/revert for one sync root so two
 * administrators or a CLI/web overlap cannot mutate the same configuration
 * snapshot concurrently. Destructor release also covers early returns/errors.
 */
class OperationLock {
  /** @var object|null */
  private $lock;

  private function __construct($lock) {
    $this->lock = $lock;
  }

  public static function acquire(string $syncRoot): self {
    if (!class_exists('Civi') || !method_exists('Civi', 'lockManager')) {
      return new self(NULL);
    }
    $name = 'worker.civicfg.' . substr(hash('sha256', $syncRoot), 0, 32);
    $lock = \Civi::lockManager()->acquire($name);
    if (!is_object($lock) || !method_exists($lock, 'isAcquired') || !$lock->isAcquired()) {
      if (is_object($lock) && method_exists($lock, 'release')) {
        $lock->release();
      }
      throw new \RuntimeException('Another Configuration Manager export/import operation is already running for this sync directory. Wait for it to finish before starting another one.');
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
