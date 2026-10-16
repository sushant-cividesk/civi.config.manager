<?php
namespace Civi\ConfigManager\Service;

use Civi\ConfigManager\Handler\StreamingHandlerInterface;
use Civi\ConfigManager\Storage\YamlFileStorage;

/**
 * ConfigManagerSnapshot methods from ConfigManager.
 *
 * Methods run on the original owning object and retain the same state and
 * access modifiers. Do not instantiate this trait separately.
 */
trait ConfigManagerSnapshotTrait {

  /** @return object|null */
  private function handlerByType(string $type) {
    foreach ($this->getHandlers() as $handler) {
      if ((string) $handler->getType() === $type) {
        return $handler;
      }
    }
    return NULL;
  }

  /** @return object[] */
  private function handlersForTypes(array $effectiveTypes, array $requestedTypes): array {
    $effectiveTypes = array_values(array_map('strval', $effectiveTypes));
    $requestedTypes = array_values(array_map('strval', $requestedTypes));
    $handlers = [];
    foreach ($this->getHandlers() as $handler) {
      if ($effectiveTypes && !in_array((string) $handler->getType(), $effectiveTypes, TRUE)) {
        continue;
      }
      $this->prepareHandlerForTypeFilter($handler, $requestedTypes);
      $handlers[] = $handler;
    }
    return $handlers;
  }

  /**
   * Build the pre-enrichment active fingerprint from compact staging metadata.
   */
  private function compactSnapshotFromWorkspace($handler, StagedExportWorkspace $workspace): array {
    $groups = [];
    $type = (string) $handler->getType();
    foreach ($workspace->files() as $relative => $metadata) {
      $metadata = (array) $metadata;
      if ((string) ($metadata['type'] ?? '') !== $type || empty($metadata['config_key'])) {
        continue;
      }
      $row = [
        'filename' => (string) ($metadata['filename'] ?? basename((string) $relative)),
        'path' => (string) $relative,
        'identity' => (array) ($metadata['identity'] ?? []),
        'hash' => (string) ($metadata['hash'] ?? ''),
        'rename_signature' => '',
      ];
      $groups[(string) $metadata['config_key']][] = $row;
    }
    $indexed = $this->indexCompactDiffGroups($groups);
    $result = [];
    foreach ($indexed as $key => $row) {
      $result[(string) $key] = (string) ($row['hash'] ?? '');
    }
    ksort($result, SORT_STRING);
    return $result;
  }

  private function reportProgress(?callable $progress, int $completed, int $total, string $label, string $message, int $processedItems = 0): void {
    if ($progress === NULL) {
      return;
    }
    $total = max(1, $total);
    $completed = max(0, min($completed, $total));
    $progress([
      'completed' => $completed,
      'total' => $total,
      'percent' => (int) floor(($completed / $total) * 100),
      'label' => $label,
      'message' => $message,
      'processed_items' => max(0, $processedItems),
    ]);
  }

  /**
   * Build compact identity/hash state from staged or live YAML documents.
   * Whole documents are released after each hash is calculated.
   */
  private function compactSnapshotFromYamlStorage($handler, YamlFileStorage $storage): array {
    $identityService = new ConfigIdentity();
    $canonicalizer = new Canonicalizer();
    $options = method_exists($handler, 'getCanonicalizationOptions') ? (array) $handler->getCanonicalizationOptions() : [];
    $directory = trim((string) $handler->getDirectory(), '/');
    $groups = [];
    foreach ($storage->iterateDirectory($directory) as $filename => $data) {
      $filename = ltrim((string) $filename, '/');
      $relative = $directory === '' ? $filename : $directory . '/' . $filename;
      if ($this->isIgnoredPath($relative)) {
        continue;
      }
      $row = $this->compactDiffRow((string) $handler->getType(), $filename, $relative, (array) $data, $identityService, $canonicalizer, $options);
      $groups[(string) $row['identity']['config_key']][] = $row;
      unset($row, $data);
    }
    $indexed = $this->indexCompactDiffGroups($groups);
    $result = [];
    foreach ($indexed as $key => $row) {
      $result[(string) $key] = (string) ($row['hash'] ?? '');
    }
    ksort($result, SORT_STRING);
    return $result;
  }

  /**
   * Re-scan active CiviCRM and fail closed if it changed after export staging.
   */
  private function assertActiveSnapshotMatches($handler, array $expected): void {
    $identityService = new ConfigIdentity();
    $canonicalizer = new Canonicalizer();
    $options = method_exists($handler, 'getCanonicalizationOptions') ? (array) $handler->getCanonicalizationOptions() : [];
    $directory = trim((string) $handler->getDirectory(), '/');
    $groups = [];
    $rows = $handler instanceof StreamingHandlerInterface ? $handler->iterateExport() : $handler->export();
    foreach ($rows as $file) {
      $file = (array) $file;
      $filename = ltrim((string) ($file['filename'] ?? ''), '/');
      if ($filename === '') {
        continue;
      }
      $relative = $directory === '' ? $filename : $directory . '/' . $filename;
      if ($this->isIgnoredPath($relative)) {
        continue;
      }
      $data = $this->applyIgnoredValueRules($relative, (array) ($file['data'] ?? []));
      $row = $this->compactDiffRow((string) $handler->getType(), $filename, $relative, $data, $identityService, $canonicalizer, $options);
      $groups[(string) $row['identity']['config_key']][] = $row;
      unset($data, $row);
    }
    $errors = [];
    if ($this->consumeHandlerExportErrors($handler, $errors) > 0) {
      throw new \RuntimeException('Active provider verification failed after export staging: ' . (string) ($errors[0]['message'] ?? 'provider scan incomplete'));
    }
    $indexed = $this->indexCompactDiffGroups($groups);
    $actual = [];
    foreach ($indexed as $key => $row) {
      $actual[(string) $key] = (string) ($row['hash'] ?? '');
    }
    ksort($actual, SORT_STRING);
    if ($actual !== $expected) {
      throw new \RuntimeException('Current CiviCRM configuration changed while export was being prepared. Export was aborted before publish; the previous Saved Config snapshot remains unchanged.');
    }
  }

  /**
   * Compact current active state for the handler's managed import scope.
   */
  private function compactManagedActiveSnapshot($handler, YamlFileStorage $storage): array {
    $identityService = new ConfigIdentity();
    $canonicalizer = new Canonicalizer();
    $options = method_exists($handler, 'getCanonicalizationOptions') ? (array) $handler->getCanonicalizationOptions() : [];
    $directory = trim((string) $handler->getDirectory(), '/');
    $groups = [];
    $policy = $this->scope->getPolicy((string) $handler->getType());

    if ($handler instanceof StreamingHandlerInterface && (($policy['mode'] ?? ConfigScope::MODE_ALL) === ConfigScope::MODE_ALL)) {
      $files = $handler->iterateExport();
    }
    else {
      $partition = $this->scopePartition($handler, $handler->export(), $storage, FALSE);
      $files = (array) ($partition['managed'] ?? []);
    }

    foreach ($files as $file) {
      $file = (array) $file;
      $filename = ltrim((string) ($file['filename'] ?? ''), '/');
      if ($filename === '') {
        continue;
      }
      $relative = $directory === '' ? $filename : $directory . '/' . $filename;
      if ($this->isIgnoredPath($relative)) {
        continue;
      }
      $data = $this->applyIgnoredValueRules($relative, (array) ($file['data'] ?? []));
      $row = $this->compactDiffRow((string) $handler->getType(), $filename, $relative, $data, $identityService, $canonicalizer, $options);
      $groups[(string) $row['identity']['config_key']][] = $row;
      unset($data, $row);
    }

    $errors = [];
    if ($this->consumeHandlerExportErrors($handler, $errors) > 0) {
      throw new \RuntimeException((string) ($errors[0]['message'] ?? 'Active provider scan was incomplete.'));
    }
    $indexed = $this->indexCompactDiffGroups($groups);
    $result = [];
    foreach ($indexed as $key => $row) {
      $result[(string) $key] = (string) ($row['hash'] ?? '');
    }
    ksort($result, SORT_STRING);
    return $result;
  }

  private function assertManagedActiveSnapshotMatches($handler, YamlFileStorage $storage, array $expected, string $message): void {
    if ($this->compactManagedActiveSnapshot($handler, $storage) !== $expected) {
      throw new \RuntimeException($message);
    }
  }

  /**
   * Return one currently managed active export item.
   *
   * This is used by the single-file preview/download endpoints so a crafted
   * request cannot bypass selected/watch/ignore scope.
   */
  public function getManagedActiveExportFile(string $type, string $filename): array {
    $storage = new YamlFileStorage($this->getSyncDir());
    foreach ($this->getHandlers() as $handler) {
      if ((string) $handler->getType() !== $type) {
        continue;
      }
      $partition = $this->scopePartition($handler, $handler->export(), $storage, TRUE);
      foreach ((array) ($partition['managed'] ?? []) as $file) {
        $file = (array) $file;
        if ((string) ($file['filename'] ?? '') !== $filename) {
          continue;
        }
        $relative = trim((string) $handler->getDirectory(), '/') . '/' . $filename;
        if ($this->isIgnoredPath($relative)) {
          throw new \RuntimeException('Selected export item is ignored by Config Ignore: ' . $relative);
        }
        return [
          'type' => $type,
          'label' => (string) $handler->getLabel(),
          'directory' => (string) $handler->getDirectory(),
          'filename' => $filename,
          'relative' => $relative,
          'data' => $this->applyIgnoredValueRules($relative, (array) ($file['data'] ?? [])),
        ];
      }
      throw new \RuntimeException('Selected export item is outside the current managed scope or was not found: ' . $filename);
    }
    throw new \RuntimeException('Unknown or unmanaged configuration type: ' . $type);
  }

  /**
   * Read the current portable YAML set for ZIP download without scanning active
   * CiviCRM. Stale backups from deselected/watch/ignored scope stay on disk for
   * safety but are not part of the managed archive.
   *
   * @return array<string,array<string,mixed>> Relative path => YAML document.
   */
  public function getManagedYamlArchiveFiles(): array {
    $files = [];
    foreach ($this->iterateManagedYamlArchiveFiles() as $relative => $data) {
      $files[(string) $relative] = (array) $data;
    }
    return $files;
  }

  /**
   * Yield managed YAML archive documents one handler at a time.
   *
   * ZIP download uses this iterator directly so thousands of parsed YAML
   * documents do not need to coexist in memory. The array-returning method
   * above remains for API/backward compatibility.
   *
   * @return \Generator<string,array<string,mixed>>
   */
  public function iterateManagedYamlArchiveFiles(): \Generator {
    $storage = new YamlFileStorage($this->getSyncDir());
    $manifest = $this->readManifest($storage);
    if ($manifest) {
      yield 'manifest.yml' => $manifest;
    }
    unset($manifest);

    foreach ($this->getHandlers() as $handler) {
      foreach ($this->iterateManagedYamlFilesForHandler($handler, $storage) as $filename => $data) {
        $relative = trim((string) $handler->getDirectory(), '/') . '/' . ltrim((string) $filename, '/');
        if ($relative !== '' && !$this->isIgnoredPath($relative)) {
          yield $relative => (array) $data;
        }
      }
    }
  }
}
