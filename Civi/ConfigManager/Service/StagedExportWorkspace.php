<?php
namespace Civi\ConfigManager\Service;

use Civi\ConfigManager\Storage\YamlFileStorage;

/**
 * Disk-backed export snapshot with journaled publish/rollback.
 *
 * Alpha63 can keep this workspace across several queue requests. Full YAML
 * documents stay on disk and only compact path/identity/dependency metadata is
 * retained in PHP. The persistent index is outside the managed YAML tree.
 */
class StagedExportWorkspace {
  private YamlFileStorage $live;
  private YamlFileStorage $stage;
  private YamlFileStorage $rollback;
  private string $root;
  private string $indexPath;
  private string $publishStatePath;
  private bool $cleanupOnDestruct;
  /** @var array<string,array<string,mixed>> */
  private array $files = [];

  public function __construct(YamlFileStorage $live, ?string $root = NULL, bool $cleanupOnDestruct = TRUE) {
    $this->live = $live;
    $this->cleanupOnDestruct = $cleanupOnDestruct;
    if ($root === NULL || trim($root) === '') {
      $suffix = $this->randomSuffix();
      $root = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'civicfg-export-' . $suffix;
    }
    $this->root = rtrim($root, DIRECTORY_SEPARATOR);
    $this->indexPath = $this->root . DIRECTORY_SEPARATOR . 'workspace-index.json';
    $this->publishStatePath = $this->root . DIRECTORY_SEPARATOR . 'publish-state.json';
    $this->stage = new YamlFileStorage($this->root . DIRECTORY_SEPARATOR . 'stage');
    $this->rollback = new YamlFileStorage($this->root . DIRECTORY_SEPARATOR . 'rollback');
    $this->stage->ensureRoot();
    $this->rollback->ensureRoot();
    $this->loadIndex();
  }

  public function getRoot(): string {
    return $this->root;
  }

  public function getStageStorage(): YamlFileStorage {
    return $this->stage;
  }

  /**
   * @param array<string,mixed> $data
   * @param array<string,mixed> $metadata
   */
  public function stage(string $type, string $directory, string $filename, array $data, array $metadata = []): string {
    $relative = $this->relative($directory, $filename);
    if (isset($this->files[$relative])) {
      $existing = (array) $this->files[$relative];
      $existingIdentity = trim((string) ($existing['config_key'] ?? ''));
      $incomingIdentity = trim((string) ($metadata['config_key'] ?? ''));
      $existingSource = trim((string) ($existing['source_id'] ?? ''));
      $incomingSource = trim((string) ($metadata['source_id'] ?? ''));
      $details = [];
      if ($existingIdentity !== '' || $incomingIdentity !== '') {
        $details[] = 'existing identity=' . ($existingIdentity !== '' ? $existingIdentity : '[unknown]')
          . '; incoming identity=' . ($incomingIdentity !== '' ? $incomingIdentity : '[unknown]');
      }
      if ($existingSource !== '' || $incomingSource !== '') {
        $details[] = 'existing source ID=' . ($existingSource !== '' ? $existingSource : '[unknown]')
          . '; incoming source ID=' . ($incomingSource !== '' ? $incomingSource : '[unknown]');
      }
      $suffix = $details ? ' ' . implode('. ', $details) . '.' : '';
      throw new \RuntimeException('Duplicate export path detected: ' . $relative . '. Two active configuration objects cannot share one YAML path.' . $suffix . ' No live YAML was changed.');
    }
    $this->stage->write($directory, $filename, $data);
    $this->files[$relative] = array_merge([
      'type' => $type,
      'directory' => trim($directory, '/'),
      'filename' => ltrim($filename, '/'),
    ], $metadata);
    return $relative;
  }

  /** Persist compact workspace metadata after a queue work unit completes. */
  public function persistIndex(): void {
    $dir = dirname($this->indexPath);
    if (!is_dir($dir) && !mkdir($dir, 0700, TRUE) && !is_dir($dir)) {
      throw new \RuntimeException('Could not create Configuration Manager export workspace directory.');
    }
    $json = json_encode($this->files, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    if ($json === FALSE) {
      throw new \RuntimeException('Could not encode Configuration Manager export workspace index.');
    }
    $tmp = $this->indexPath . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === FALSE || !rename($tmp, $this->indexPath)) {
      @unlink($tmp);
      throw new \RuntimeException('Could not persist Configuration Manager export workspace index.');
    }
    @chmod($this->indexPath, 0600);
  }

  /** Remove every staged document recorded for one durable queue unit. */
  public function removeUnit(string $type, string $unitKey): void {
    foreach (array_keys($this->files) as $relative) {
      $file = (array) $this->files[$relative];
      if ((string) ($file['type'] ?? '') !== $type || (string) ($file['unit_key'] ?? '') !== $unitKey) {
        continue;
      }
      [$directory, $filename] = $this->splitRelative($relative);
      $this->stage->delete($directory, $filename);
      unset($this->files[$relative]);
    }
    $this->persistIndex();
  }

  /**
   * Remove staged paths belonging to one queue unit before a safe retry.
   *
   * A prefix is relative to the handler directory. It may point to a directory
   * (provider/bucket unit) or an exact filename.
   */
  public function removeTypePrefix(string $type, string $prefix = ''): void {
    $prefix = trim(str_replace('\\', '/', $prefix), '/');
    foreach (array_keys($this->files) as $relative) {
      $file = (array) $this->files[$relative];
      if ((string) ($file['type'] ?? '') !== $type) {
        continue;
      }
      $candidate = ltrim((string) ($file['filename'] ?? ''), '/');
      if ($prefix !== '' && $candidate !== $prefix && strpos($candidate, $prefix . '/') !== 0) {
        continue;
      }
      [$directory, $filename] = $this->splitRelative($relative);
      $this->stage->delete($directory, $filename);
      unset($this->files[$relative]);
    }
    $this->persistIndex();
  }

  /**
   * Rewrite a staged document after dependency/index enrichment.
   *
   * @param array<string,mixed> $data
   * @param array<string,mixed>|null $metadata
   */
  public function rewrite(string $relative, array $data, ?array $metadata = NULL): void {
    if (!isset($this->files[$relative])) {
      throw new \RuntimeException('Cannot rewrite unstaged export path: ' . $relative);
    }
    $file = $this->files[$relative];
    $this->stage->write((string) $file['directory'], (string) $file['filename'], $data);
    if ($metadata !== NULL) {
      $this->files[$relative] = array_merge($file, $metadata);
    }
  }

  /** @return array<string,array<string,mixed>> */
  public function files(): array {
    return $this->files;
  }

  /** @return array<string,bool> */
  public function pathSetForType(string $type): array {
    $paths = [];
    foreach ($this->files as $relative => $file) {
      if (($file['type'] ?? '') === $type) {
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
    if (is_file($this->publishStatePath)) {
      throw new \RuntimeException('An incomplete YAML publication journal already exists. Recover that publication before attempting another publish.');
    }

    $result = ['written' => [], 'deleted' => [], 'skipped' => []];
    $journal = [];
    $newPaths = [];
    $this->persistPublishState($journal, $newPaths);

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
        $this->persistPublishState($journal, $newPaths);
        [$directory, $filename] = $this->splitRelative($relative);
        $this->live->delete($directory, $filename);
        $result['deleted'][] = $this->live->getPath($directory, $filename);
      }

      if (isset($this->files['manifest.yml'])) {
        $this->publishOne('manifest.yml', $journal, $newPaths, $result);
      }
      $this->clearPublishState();
    }
    catch (\Throwable $e) {
      $rollbackErrors = $this->restoreJournal($journal, $newPaths);
      if (!$rollbackErrors) {
        $this->clearPublishState();
      }
      $message = 'Staged export publish failed and live YAML was rolled back: ' . $e->getMessage();
      if ($rollbackErrors) {
        $message .= ' Rollback also reported: ' . implode('; ', $rollbackErrors) . '. The durable publication journal was kept for recovery.';
      }
      throw new \RuntimeException($message, 0, $e);
    }

    return $result;
  }

  /**
   * Recover a publication whose PHP worker stopped after live YAML mutation
   * began but before a terminal result could be recorded.
   *
   * @return array{recovered:bool,errors:string[]}
   */
  public function recoverIncompletePublish(): array {
    if (!is_file($this->publishStatePath)) {
      return ['recovered' => FALSE, 'errors' => []];
    }
    $raw = file_get_contents($this->publishStatePath);
    $state = $raw !== FALSE ? json_decode($raw, TRUE) : NULL;
    if (!is_array($state)) {
      return ['recovered' => FALSE, 'errors' => ['The durable publication journal is unreadable. Manual review is required before another export.']];
    }
    $journal = [];
    foreach ((array) ($state['journal'] ?? []) as $relative) {
      $relative = trim((string) $relative);
      if ($relative !== '') {
        $journal[$relative] = TRUE;
      }
    }
    $newPaths = [];
    foreach ((array) ($state['new_paths'] ?? []) as $relative) {
      $relative = trim((string) $relative);
      if ($relative !== '') {
        $newPaths[$relative] = TRUE;
      }
    }
    $errors = $this->restoreJournal($journal, $newPaths);
    if (!$errors) {
      $this->clearPublishState();
    }
    return ['recovered' => !$errors, 'errors' => $errors];
  }

  public function cleanup(): void {
    $this->removeTree($this->root);
    $this->files = [];
  }

  public function __destruct() {
    if ($this->cleanupOnDestruct) {
      $this->cleanup();
    }
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
    // Persist rollback intent before the live write so a hard-killed worker can
    // restore the previous coherent snapshot on the next reviewed operation.
    $this->persistPublishState($journal, $newPaths);
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

  /** @param array<string,bool> $journal @param array<string,bool> $newPaths */
  private function persistPublishState(array $journal, array $newPaths): void {
    $state = [
      'status' => 'publishing',
      'journal' => array_values(array_keys($journal)),
      'new_paths' => array_values(array_keys($newPaths)),
      'updated_at' => gmdate('c'),
    ];
    $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === FALSE) {
      throw new \RuntimeException('Could not encode durable YAML publication journal.');
    }
    $tmp = $this->publishStatePath . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === FALSE || !rename($tmp, $this->publishStatePath)) {
      @unlink($tmp);
      throw new \RuntimeException('Could not persist durable YAML publication journal.');
    }
    @chmod($this->publishStatePath, 0600);
  }

  private function clearPublishState(): void {
    @unlink($this->publishStatePath);
    @unlink($this->publishStatePath . '.tmp');
  }

  private function loadIndex(): void {
    if (!is_file($this->indexPath)) {
      return;
    }
    $raw = file_get_contents($this->indexPath);
    if ($raw === FALSE || trim($raw) === '') {
      return;
    }
    $decoded = json_decode($raw, TRUE);
    if (!is_array($decoded)) {
      throw new \RuntimeException('Configuration Manager export workspace index is invalid.');
    }
    $this->files = $decoded;
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
