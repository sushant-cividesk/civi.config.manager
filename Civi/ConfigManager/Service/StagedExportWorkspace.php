<?php
namespace Civi\ConfigManager\Service;

use Civi\ConfigManager\Storage\YamlFileStorage;

/**
 * Disk-backed export snapshot with journaled publish/rollback.
 *
 * Full YAML documents live on disk in the staging area. PHP retains only path
 * strings and small metadata, which keeps peak memory independent of the total
 * size of the managed configuration set.
 */
class StagedExportWorkspace {
  private YamlFileStorage $live;
  private YamlFileStorage $stage;
  private YamlFileStorage $rollback;
  private string $root;
  /** @var array<string,array{type:string,directory:string,filename:string}> */
  private array $files = [];

  public function __construct(YamlFileStorage $live) {
    $this->live = $live;
    $suffix = $this->randomSuffix();
    $this->root = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'civicfg-export-' . $suffix;
    $this->stage = new YamlFileStorage($this->root . DIRECTORY_SEPARATOR . 'stage');
    $this->rollback = new YamlFileStorage($this->root . DIRECTORY_SEPARATOR . 'rollback');
    $this->stage->ensureRoot();
    $this->rollback->ensureRoot();
  }

  public function getStageStorage(): YamlFileStorage {
    return $this->stage;
  }

  /**
   * @param array<string,mixed> $data
   */
  public function stage(string $type, string $directory, string $filename, array $data): string {
    $relative = $this->relative($directory, $filename);
    if (isset($this->files[$relative])) {
      throw new \RuntimeException('Duplicate export path detected: ' . $relative . '. Two active configuration objects cannot share one YAML path. No live YAML was changed.');
    }
    $this->stage->write($directory, $filename, $data);
    $this->files[$relative] = [
      'type' => $type,
      'directory' => trim($directory, '/'),
      'filename' => ltrim($filename, '/'),
    ];
    return $relative;
  }

  /**
   * Rewrite a staged document after dependency/index enrichment.
   *
   * @param array<string,mixed> $data
   */
  public function rewrite(string $relative, array $data): void {
    if (!isset($this->files[$relative])) {
      throw new \RuntimeException('Cannot rewrite unstaged export path: ' . $relative);
    }
    $file = $this->files[$relative];
    $this->stage->write($file['directory'], $file['filename'], $data);
  }

  /** @return array<string,array{type:string,directory:string,filename:string}> */
  public function files(): array {
    return $this->files;
  }

  /** @return array<string,bool> */
  public function pathSetForType(string $type): array {
    $paths = [];
    foreach ($this->files as $relative => $file) {
      if ($file['type'] === $type) {
        $paths[$relative] = TRUE;
      }
    }
    return $paths;
  }

  /**
   * Compare staged bytes with live bytes without parsing either full snapshot.
   *
   * @param string[] $stalePaths
   * @return array{write:string[],delete:string[],skip:string[]}
   */
  public function preview(array $stalePaths): array {
    $result = ['write' => [], 'delete' => [], 'skip' => []];
    $paths = array_keys($this->files);
    sort($paths, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($paths as $relative) {
      $desired = $this->stage->readRaw($relative);
      if ($desired === NULL) {
        throw new \RuntimeException('Staged YAML disappeared before preview: ' . $relative);
      }
      $current = $this->live->readRaw($relative);
      if ($current !== NULL && $this->contentFingerprint($current) === $this->contentFingerprint($desired)) {
        $result['skip'][] = $relative;
      }
      else {
        $result['write'][] = $relative;
      }
    }
    foreach (array_values(array_unique(array_filter(array_map('strval', $stalePaths)))) as $relative) {
      if ($relative !== 'manifest.yml' && !isset($this->files[$relative]) && $this->live->readRaw($relative) !== NULL) {
        $result['delete'][] = $relative;
      }
    }
    sort($result['delete'], SORT_NATURAL | SORT_FLAG_CASE);
    return $result;
  }

  /**
   * Publish every staged YAML document and remove confirmed stale files.
   * manifest.yml is always committed last as the snapshot marker.
   *
   * @param string[] $stalePaths
   * @return array{written:string[],deleted:string[],skipped:string[]}
   */
  public function publish(array $stalePaths): array {
    $result = ['written' => [], 'deleted' => [], 'skipped' => []];
    $journal = [];
    $newPaths = [];

    try {
      $paths = array_keys($this->files);
      sort($paths, SORT_NATURAL | SORT_FLAG_CASE);
      $manifestIndex = array_search('manifest.yml', $paths, TRUE);
      if ($manifestIndex !== FALSE) {
        unset($paths[$manifestIndex]);
      }

      foreach ($paths as $relative) {
        $this->publishOne($relative, $journal, $newPaths, $result);
      }

      $stalePaths = array_values(array_unique(array_filter(array_map('strval', $stalePaths))));
      sort($stalePaths, SORT_NATURAL | SORT_FLAG_CASE);
      foreach ($stalePaths as $relative) {
        if ($relative === 'manifest.yml' || isset($this->files[$relative])) {
          continue;
        }
        $current = $this->live->readRaw($relative);
        if ($current === NULL) {
          continue;
        }
        $this->backup($relative, $current, $journal);
        [$directory, $filename] = $this->splitRelative($relative);
        $this->live->delete($directory, $filename);
        $result['deleted'][] = $this->live->getPath($directory, $filename);
      }

      if (isset($this->files['manifest.yml'])) {
        $this->publishOne('manifest.yml', $journal, $newPaths, $result);
      }
    }
    catch (\Throwable $e) {
      $rollbackErrors = $this->restoreJournal($journal, $newPaths);
      $message = 'Staged export publish failed and live YAML was rolled back: ' . $e->getMessage();
      if ($rollbackErrors) {
        $message .= ' Rollback also reported: ' . implode('; ', $rollbackErrors);
      }
      throw new \RuntimeException($message, 0, $e);
    }

    return $result;
  }

  public function cleanup(): void {
    $this->removeTree($this->root);
  }

  public function __destruct() {
    $this->cleanup();
  }

  /**
   * @param array<string,bool> $journal
   * @param array<string,bool> $newPaths
   * @param array{written:string[],deleted:string[],skipped:string[]} $result
   */
  private function publishOne(string $relative, array &$journal, array &$newPaths, array &$result): void {
    $desired = $this->stage->readRaw($relative);
    if ($desired === NULL) {
      throw new \RuntimeException('Staged YAML disappeared before publish: ' . $relative);
    }
    $current = $this->live->readRaw($relative);
    if ($current !== NULL && $this->contentFingerprint($current) === $this->contentFingerprint($desired)) {
      $result['skipped'][] = $relative;
      return;
    }

    if ($current === NULL) {
      $newPaths[$relative] = TRUE;
    }
    else {
      $this->backup($relative, $current, $journal);
    }
    [$directory, $filename] = $this->splitRelative($relative);
    $result['written'][] = $this->live->writeRaw($directory, $filename, $desired);
  }

  /** @param array<string,bool> $journal */
  private function backup(string $relative, string $contents, array &$journal): void {
    if (isset($journal[$relative])) {
      return;
    }
    [$directory, $filename] = $this->splitRelative($relative);
    $this->rollback->writeRaw($directory, $filename, $contents);
    $journal[$relative] = TRUE;
  }

  /**
   * @param array<string,bool> $journal
   * @param array<string,bool> $newPaths
   * @return string[]
   */
  private function restoreJournal(array $journal, array $newPaths): array {
    $errors = [];
    foreach (array_keys($newPaths) as $relative) {
      try {
        [$directory, $filename] = $this->splitRelative($relative);
        $this->live->delete($directory, $filename);
      }
      catch (\Throwable $e) {
        $errors[] = $relative . ': ' . $e->getMessage();
      }
    }
    foreach (array_keys($journal) as $relative) {
      try {
        $contents = $this->rollback->readRaw($relative);
        if ($contents === NULL) {
          throw new \RuntimeException('rollback copy is missing');
        }
        [$directory, $filename] = $this->splitRelative($relative);
        $this->live->writeRaw($directory, $filename, $contents);
      }
      catch (\Throwable $e) {
        $errors[] = $relative . ': ' . $e->getMessage();
      }
    }
    return $errors;
  }

  private function relative(string $directory, string $filename): string {
    $directory = trim(str_replace('\\', '/', $directory), '/');
    $filename = ltrim(str_replace('\\', '/', $filename), '/');
    return $directory === '' ? $filename : $directory . '/' . $filename;
  }

  /** @return array{0:string,1:string} */
  private function splitRelative(string $relative): array {
    $relative = trim(str_replace('\\', '/', $relative), '/');
    $pos = strrpos($relative, '/');
    if ($pos === FALSE) {
      return ['', $relative];
    }
    return [substr($relative, 0, $pos), substr($relative, $pos + 1)];
  }

  private function contentFingerprint(string $contents): string {
    return hash('sha256', str_replace("\r\n", "\n", trim($contents)));
  }

  private function randomSuffix(): string {
    try {
      return bin2hex(random_bytes(8));
    }
    catch (\Throwable $e) {
      return sha1(uniqid('', TRUE) . microtime(TRUE));
    }
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
