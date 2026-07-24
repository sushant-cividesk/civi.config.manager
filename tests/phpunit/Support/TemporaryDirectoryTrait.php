<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Support;

trait TemporaryDirectoryTrait {
  private array $temporaryDirectories = [];

  protected function createTemporaryDirectory(string $prefix = 'civicfg-test-'): string {
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(6));
    if (!mkdir($path, 0775, TRUE) && !is_dir($path)) {
      throw new \RuntimeException('Could not create temporary test directory: ' . $path);
    }
    $this->temporaryDirectories[] = $path;
    return $path;
  }

  protected function removeTemporaryDirectories(): void {
    foreach (array_reverse($this->temporaryDirectories) as $path) {
      $this->removeDirectory($path);
    }
    $this->temporaryDirectories = [];
  }

  private function removeDirectory(string $path): void {
    if (!file_exists($path) && !is_link($path)) {
      return;
    }
    if (is_link($path) || is_file($path)) {
      @unlink($path);
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
      else {
        @rmdir($item->getPathname());
      }
    }
    @rmdir($path);
  }
}
