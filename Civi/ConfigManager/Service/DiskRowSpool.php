<?php
namespace Civi\ConfigManager\Service;

/**
 * Small JSON-lines disk spool used by high-volume provider exporters.
 *
 * The first provider pass can calculate compact identity multiplicities while
 * rows are written to disk. The second phase reads the spool rather than
 * calling the remote/provider API again, reducing provider work without
 * retaining complete configuration collections in PHP memory.
 */
class DiskRowSpool {
  private string $path;
  /** @var resource|null */
  private $handle;

  public function __construct() {
    $path = tempnam(sys_get_temp_dir(), 'civicfg-spool-');
    if ($path === FALSE) {
      throw new \RuntimeException('Could not create Configuration Manager provider spool.');
    }
    $handle = fopen($path, 'w+b');
    if ($handle === FALSE) {
      @unlink($path);
      throw new \RuntimeException('Could not open Configuration Manager provider spool.');
    }
    $this->path = $path;
    $this->handle = $handle;
  }

  /** @param array<string,mixed> $row */
  public function append(array $row): void {
    if (!is_resource($this->handle)) {
      throw new \RuntimeException('Configuration Manager provider spool is closed.');
    }
    $json = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    if ($json === FALSE || fwrite($this->handle, $json . "\n") === FALSE) {
      throw new \RuntimeException('Could not write Configuration Manager provider spool.');
    }
  }

  /** @return \Generator<int,array<string,mixed>> */
  public function iterate(): \Generator {
    if (!is_resource($this->handle)) {
      return;
    }
    fflush($this->handle);
    rewind($this->handle);
    while (($line = fgets($this->handle)) !== FALSE) {
      $decoded = json_decode($line, TRUE);
      if (!is_array($decoded)) {
        throw new \RuntimeException('Configuration Manager provider spool contains invalid JSON.');
      }
      yield $decoded;
    }
  }

  public function close(): void {
    if (is_resource($this->handle)) {
      fclose($this->handle);
      $this->handle = NULL;
    }
    if ($this->path !== '') {
      @unlink($this->path);
      $this->path = '';
    }
  }

  public function __destruct() {
    $this->close();
  }
}
