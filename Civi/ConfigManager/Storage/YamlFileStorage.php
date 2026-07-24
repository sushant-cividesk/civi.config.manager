<?php
namespace Civi\ConfigManager\Storage;

use Civi\ConfigManager\Util\SimpleYaml;

class YamlFileStorage {
  private string $root;

  public function __construct(string $root) {
    $this->root = rtrim($root, DIRECTORY_SEPARATOR);
  }

  public function getRoot(): string {
    return $this->root;
  }

  public function ensureRoot(): void {
    if ($this->root === '') {
      throw new \RuntimeException('Config directory cannot be empty.');
    }
    if (!is_dir($this->root)) {
      if (!mkdir($this->root, 0775, TRUE) && !is_dir($this->root)) {
        throw new \RuntimeException('Could not create config directory: ' . $this->root);
      }
    }
    $root = realpath($this->root);
    if ($root === FALSE || !is_dir($root)) {
      throw new \RuntimeException('Could not resolve config directory: ' . $this->root);
    }
    if (!is_writable($root)) {
      throw new \RuntimeException('Config directory is not writable: ' . $this->root);
    }
  }

  public function getPath(string $directory, string $filename): string {
    $directory = $this->normaliseRelativePath($directory, TRUE);
    $filename = $this->normaliseRelativePath($filename, FALSE);
    if ($directory === '') {
      return $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $filename);
    }
    return $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory . '/' . $filename);
  }

  public function exists(string $directory, string $filename): bool {
    $path = $this->getPath($directory, $filename);
    $this->assertPathInsideRoot($path);
    return !is_link($path) && is_file($path);
  }

  public function dump(array $data): string {
    return SimpleYaml::dump($data);
  }

  public function isSame(string $directory, string $filename, array $data): bool {
    $path = $this->getPath($directory, $filename);
    $this->assertPathInsideRoot($path);
    if (is_link($path)) {
      throw new \RuntimeException('Refusing to read a symbolic link: ' . $path);
    }
    if (!is_file($path)) {
      return FALSE;
    }
    $current = file_get_contents($path);
    if ($current === FALSE) {
      throw new \RuntimeException('Could not read config file: ' . $path);
    }
    $new = $this->dump($data);
    return $this->normalise($current) === $this->normalise($new);
  }

  public function write(string $directory, string $filename, array $data): string {
    $this->ensureRoot();
    $path = $this->getPath($directory, $filename);
    $dir = dirname($path);
    $this->ensureDirectoryInsideRoot($dir);

    if (is_link($path)) {
      throw new \RuntimeException('Refusing to overwrite a symbolic link: ' . $path);
    }

    $temporary = tempnam($dir, '.civicfg-');
    if ($temporary === FALSE) {
      throw new \RuntimeException('Could not create a temporary config file in: ' . $dir);
    }

    try {
      $bytes = file_put_contents($temporary, $this->dump($data), LOCK_EX);
      if ($bytes === FALSE) {
        throw new \RuntimeException('Could not write temporary config file: ' . $temporary);
      }
      @chmod($temporary, 0664);
      if (!@rename($temporary, $path)) {
        throw new \RuntimeException('Could not atomically replace config file: ' . $path);
      }
    }
    finally {
      if (is_file($temporary)) {
        @unlink($temporary);
      }
    }

    return $path;
  }

  public function delete(string $directory, string $filename): string {
    $path = $this->getPath($directory, $filename);
    $this->assertPathInsideRoot($path);
    if (is_link($path)) {
      throw new \RuntimeException('Refusing to delete a symbolic link: ' . $path);
    }
    if (!is_file($path)) {
      return $path;
    }
    if (!@unlink($path)) {
      throw new \RuntimeException('Could not delete stale config file: ' . $path);
    }
    $this->removeEmptyParentDirectories(dirname($path));
    return $path;
  }

  private function removeEmptyParentDirectories(string $dir): void {
    $root = realpath($this->root) ?: rtrim($this->root, DIRECTORY_SEPARATOR);
    $dir = realpath($dir) ?: rtrim($dir, DIRECTORY_SEPARATOR);
    while ($dir !== '' && $dir !== $root && $this->pathIsWithinRoot($dir, $root) && is_dir($dir)) {
      $items = @scandir($dir);
      if ($items === FALSE || count(array_diff($items, ['.', '..'])) > 0) {
        break;
      }
      @rmdir($dir);
      $dir = dirname($dir);
    }
  }

  public function readFile(string $relativePath): array {
    $relativePath = $this->normaliseRelativePath($relativePath, FALSE);
    $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $this->assertPathInsideRoot($path);
    if (is_link($path)) {
      throw new \RuntimeException('Refusing to read a symbolic link: ' . $path);
    }
    if (!is_file($path)) {
      return [];
    }
    return SimpleYaml::parseFile($path);
  }

  public function readDirectory(string $directory): array {
    $directory = $this->normaliseRelativePath($directory, TRUE);
    $path = $directory === ''
      ? $this->root
      : $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory);
    if (!is_dir($path)) {
      return [];
    }

    $root = realpath($this->root);
    $realPath = realpath($path);
    if ($root === FALSE || $realPath === FALSE || !$this->pathIsWithinRoot($realPath, $root)) {
      throw new \RuntimeException('Refusing to read outside the config directory.');
    }

    $items = [];
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($realPath, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO)
    );
    foreach ($iterator as $file) {
      if ($file->isLink()) {
        continue;
      }
      if ($file->isFile() && preg_match('/\.ya?ml$/i', $file->getFilename())) {
        $relative = substr($file->getPathname(), strlen($realPath) + 1);
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
        $items[$relative] = SimpleYaml::parseFile($file->getPathname());
      }
    }
    ksort($items);
    return $items;
  }

  private function ensureDirectoryInsideRoot(string $dir): void {
    if (!is_dir($dir) && !mkdir($dir, 0775, TRUE) && !is_dir($dir)) {
      throw new \RuntimeException('Could not create config subdirectory: ' . $dir);
    }

    $root = realpath($this->root);
    $realDir = realpath($dir);
    if ($root === FALSE || $realDir === FALSE || !$this->pathIsWithinRoot($realDir, $root)) {
      throw new \RuntimeException('Refusing to write outside the config directory.');
    }
  }

  private function normaliseRelativePath(string $path, bool $allowEmpty): string {
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '') {
      if ($allowEmpty) {
        return '';
      }
      throw new \InvalidArgumentException('Config filename cannot be empty.');
    }
    if (strpos($path, "\0") !== FALSE || $path[0] === '/' || preg_match('/^[A-Za-z]:\//', $path)) {
      throw new \InvalidArgumentException('Config paths must be relative to the sync directory.');
    }

    $segments = explode('/', trim($path, '/'));
    foreach ($segments as $segment) {
      if ($segment === '' || $segment === '.' || $segment === '..') {
        throw new \InvalidArgumentException('Config paths cannot contain empty, current, or parent directory segments.');
      }
      if (!preg_match('/^[A-Za-z0-9_.-]+$/', $segment)) {
        throw new \InvalidArgumentException('Config paths contain unsupported characters: ' . $path);
      }
    }
    return implode('/', $segments);
  }

  private function assertPathInsideRoot(string $path): void {
    if ($this->root === '') {
      throw new \RuntimeException('Config directory is not a safe filesystem root: ' . $this->root);
    }
    if (!file_exists($this->root)) {
      return;
    }

    $root = realpath($this->root);
    if ($root === FALSE || !is_dir($root)) {
      throw new \RuntimeException('Could not resolve config directory: ' . $this->root);
    }

    $ancestor = dirname($path);
    while (!file_exists($ancestor) && dirname($ancestor) !== $ancestor) {
      $ancestor = dirname($ancestor);
    }
    $realAncestor = realpath($ancestor);
    if ($realAncestor === FALSE || !$this->pathIsWithinRoot($realAncestor, $root)) {
      throw new \RuntimeException('Refusing to access a path outside the config directory.');
    }
  }

  private function pathIsWithinRoot(string $path, string $root): bool {
    $path = rtrim($path, DIRECTORY_SEPARATOR);
    $root = rtrim($root, DIRECTORY_SEPARATOR);
    return $path === $root || strpos($path, $root . DIRECTORY_SEPARATOR) === 0;
  }

  private function normalise(string $yaml): string {
    return rtrim(str_replace(["\r\n", "\r"], "\n", $yaml)) . "\n";
  }
}
