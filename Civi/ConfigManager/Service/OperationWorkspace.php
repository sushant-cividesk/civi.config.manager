<?php
namespace Civi\ConfigManager\Service;

/**
 * Persistent per-job filesystem state outside the managed YAML tree.
 *
 * The SQL job row is authoritative for lifecycle/status. This workspace stores
 * larger compact planning data (fingerprints, handler results and staged file
 * indexes) which should not bloat queue payloads or PHP sessions.
 */
class OperationWorkspace {
  private int $jobId;
  private string $syncRootHash;
  private string $root;
  private string $statePath;

  public function __construct(int $jobId, string $syncRootHash) {
    if ($jobId <= 0 || !preg_match('/^[a-f0-9]{64}$/', $syncRootHash)) {
      throw new \RuntimeException('Invalid Configuration Manager operation workspace identifier.');
    }
    $this->jobId = $jobId;
    $this->syncRootHash = $syncRootHash;
    $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'civicfg-alpha63';
    $this->root = $base . DIRECTORY_SEPARATOR . substr($syncRootHash, 0, 20) . DIRECTORY_SEPARATOR . 'job-' . $jobId;
    $this->statePath = $this->root . DIRECTORY_SEPARATOR . 'state.json';
    $this->ensureRoot();
  }

  public function getRoot(): string {
    return $this->root;
  }

  public function getExportRoot(): string {
    return $this->root . DIRECTORY_SEPARATOR . 'export';
  }

  /** @return array<string,mixed> */
  public function loadState(): array {
    if (!is_file($this->statePath)) {
      return [];
    }
    $raw = file_get_contents($this->statePath);
    if ($raw === FALSE || trim($raw) === '') {
      return [];
    }
    $decoded = json_decode($raw, TRUE);
    if (!is_array($decoded)) {
      throw new \RuntimeException('Configuration Manager operation workspace state is invalid.');
    }
    return $decoded;
  }

  /** @param array<string,mixed> $state */
  public function saveState(array $state): void {
    $this->ensureRoot();
    $state['job_id'] = $this->jobId;
    $state['sync_root_hash'] = $this->syncRootHash;
    $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    if ($json === FALSE) {
      throw new \RuntimeException('Could not encode Configuration Manager operation workspace state.');
    }
    $tmp = $this->statePath . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === FALSE || !rename($tmp, $this->statePath)) {
      @unlink($tmp);
      throw new \RuntimeException('Could not persist Configuration Manager operation workspace state.');
    }
    @chmod($this->statePath, 0600);
  }

  public function cleanup(): void {
    $this->removeTree($this->root);
  }

  private function ensureRoot(): void {
    if (!is_dir($this->root) && !mkdir($this->root, 0700, TRUE) && !is_dir($this->root)) {
      throw new \RuntimeException('Could not create Configuration Manager operation workspace.');
    }
    @chmod($this->root, 0700);
  }

  private function removeTree(string $path): void {
    if ($path === '' || !is_dir($path)) {
      return;
    }
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
      if ($item->isLink() || $item->isFile()) {
        @unlink($item->getPathname());
      }
      elseif ($item->isDir()) {
        @rmdir($item->getPathname());
      }
    }
    @rmdir($path);
  }
}
