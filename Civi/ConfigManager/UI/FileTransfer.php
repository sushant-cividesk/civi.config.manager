<?php
namespace Civi\ConfigManager\UI;

use Civi\ConfigManager\Service\ConfigManager;
use Civi\ConfigManager\Util\SimpleYaml;
use RuntimeException;
use Throwable;
use ZipArchive;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use FilesystemIterator;

/**
 * Handles import/export file transfers and single-file preview/downloads.
 */
class FileTransfer {
  private const MAX_UPLOAD_FILES = 500;
  private const MAX_YAML_FILE_BYTES = 5242880;
  private const MAX_ARCHIVE_BYTES = 52428800;



  /**
   * Build the single-file chooser from an export preview already performed in
   * this request. This avoids exporting every handler a second time merely to
   * populate the UI select list.
   */
  public function buildExportItemsFromPreview(array $preview): array {
    $items = [];
    foreach ((array) ($preview['available'] ?? []) as $file) {
      $file = (array) $file;
      $type = (string) ($file['type'] ?? '');
      $filename = (string) ($file['file'] ?? '');
      $path = (string) ($file['path'] ?? '');
      if ($type === '' || $filename === '' || $path === '') {
        continue;
      }
      $items[] = [
        'key' => $type . '::' . $filename,
        'type' => $type,
        'label' => (string) ($file['label'] ?? $type),
        'directory' => (string) ($file['directory'] ?? ''),
        'file' => $filename,
        'path' => $path,
      ];
    }
    usort($items, static function($a, $b) {
      return strcmp((string) $a['path'], (string) $b['path']);
    });
    return $items;
  }

  public function buildExportItems(ConfigManager $manager, array $typeFilter = []): array {
    $items = [];
    $effectiveTypes = $manager->getEffectiveExportTypeFilter($typeFilter);
    foreach ($manager->getHandlers() as $handler) {
      if ($effectiveTypes && !in_array($handler->getType(), $effectiveTypes, TRUE)) {
        continue;
      }
      try {
        foreach ($handler->export() as $file) {
          if (empty($file['filename'])) {
            continue;
          }
          $relativePath = trim($handler->getDirectory(), '/') . '/' . $file['filename'];
          if ($manager->shouldIgnorePath($relativePath)) {
            continue;
          }
          $key = $handler->getType() . '::' . $file['filename'];
          $items[] = [
            'key' => $key,
            'type' => $handler->getType(),
            'label' => $handler->getLabel(),
            'directory' => $handler->getDirectory(),
            'file' => $file['filename'],
            'path' => $relativePath,
          ];
        }
      }
      catch (\Exception $e) {
        // Keep the export page available even if one handler has an error.
      }
    }
    usort($items, function($a, $b) {
      return strcmp($a['path'], $b['path']);
    });
    return $items;
  }

  public function loadSingleExport(ConfigManager $manager, string $key): array {
    [$type, $filename] = $this->splitExportKey($key);
    $file = $manager->getManagedActiveExportFile($type, $filename);
    $yaml = SimpleYaml::dump((array) ($file['data'] ?? []));
    return [
      'key' => $key,
      'type' => $type,
      'label' => (string) ($file['label'] ?? $type),
      'directory' => (string) ($file['directory'] ?? ''),
      'file' => $filename,
      'path' => (string) ($file['relative'] ?? ''),
      'yaml' => $yaml,
      'download_url' => \CRM_Utils_System::url('civicrm/admin/config-manager', 'reset=1&op=download-single&export_item=' . rawurlencode($key)),
    ];
  }

  public function jsonSingleExport(ConfigManager $manager): void {
    try {
      $key = isset($_REQUEST['export_item']) ? trim((string) $_REQUEST['export_item']) : '';
      if ($key === '') {
        throw new RuntimeException('Choose a configuration item to preview.');
      }
      $item = $this->loadSingleExport($manager, $key);
      $payload = [
        'ok' => TRUE,
        'key' => $item['key'],
        'type' => $item['type'],
        'label' => $item['label'],
        'file' => $item['file'],
        'path' => $item['path'],
        'yaml' => $item['yaml'],
        'download_url' => $item['download_url'],
      ];
    }
    catch (Throwable $e) {
      $payload = [
        'ok' => FALSE,
        'error' => $e->getMessage(),
      ];
    }

    \CRM_Utils_System::setHttpHeader('Content-Type', 'application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    \CRM_Utils_System::civiExit();
  }

  public function downloadSingleExport(ConfigManager $manager): void {
    $key = isset($_REQUEST['export_item']) ? trim((string) $_REQUEST['export_item']) : '';
    if ($key === '') {
      throw new RuntimeException('Choose a configuration item before downloading a single YAML file.');
    }
    $item = $this->loadSingleExport($manager, $key);
    \CRM_Utils_System::setHttpHeader('Content-Type', 'text/yaml; charset=utf-8');
    \CRM_Utils_System::setHttpHeader('Content-Disposition', 'attachment; filename="' . basename($item['file']) . '"');
    echo $item['yaml'];
    \CRM_Utils_System::civiExit();
  }

  public function uploadSingleYaml(ConfigManager $manager): string {
    $type = trim((string) ($_POST['single_type'] ?? ''));
    $filename = trim((string) ($_POST['single_filename'] ?? ''));
    if ($type === '') {
      throw new RuntimeException('Choose a configuration type before uploading a YAML file.');
    }
    if (empty($_FILES['single_yaml']['tmp_name']) || !is_uploaded_file($_FILES['single_yaml']['tmp_name'])) {
      throw new RuntimeException('Choose a YAML file to upload.');
    }
    if ($filename === '') {
      $filename = basename((string) ($_FILES['single_yaml']['name'] ?? ''));
    }
    if (!$this->isSafeRelativeYamlPath($filename)) {
      throw new RuntimeException('The YAML filename must be a relative .yml or .yaml path without .. segments.');
    }
    $handler = $this->getHandlerByType($manager, $type);
    if (!$handler) {
      throw new RuntimeException('Unknown configuration type: ' . $type);
    }
    $uploadSize = (int) ($_FILES['single_yaml']['size'] ?? 0);
    if ($uploadSize > self::MAX_YAML_FILE_BYTES) {
      throw new RuntimeException('The uploaded YAML file is too large.');
    }
    $parsed = SimpleYaml::parseFile($_FILES['single_yaml']['tmp_name']);
    if (!is_array($parsed) || !$parsed) {
      throw new RuntimeException('The uploaded YAML file could not be parsed.');
    }
    $targetRoot = rtrim($manager->getSyncDir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . trim($handler->getDirectory(), DIRECTORY_SEPARATOR);
    $target = $targetRoot . DIRECTORY_SEPARATOR . $filename;
    $this->safeWriteUploadedFile($_FILES['single_yaml']['tmp_name'], $target, $manager->getSyncDir());
    return ts('YAML file uploaded to %1. Review Synchronize before importing.', [1 => trim($handler->getDirectory(), '/') . '/' . $filename]);
  }

  public function uploadZipArchive(ConfigManager $manager): string {
    if (!class_exists('ZipArchive')) {
      throw new RuntimeException('ZipArchive is not available in PHP.');
    }
    if (empty($_FILES['zip_archive']['tmp_name']) || !is_uploaded_file($_FILES['zip_archive']['tmp_name'])) {
      throw new RuntimeException('Choose a ZIP archive to upload.');
    }
    $uploadSize = (int) ($_FILES['zip_archive']['size'] ?? 0);
    if ($uploadSize > self::MAX_ARCHIVE_BYTES) {
      throw new RuntimeException('The uploaded ZIP archive is too large.');
    }
    $zip = new ZipArchive();
    if ($zip->open($_FILES['zip_archive']['tmp_name']) !== TRUE) {
      throw new RuntimeException('Could not open the uploaded ZIP archive.');
    }
    if ($zip->numFiles > self::MAX_UPLOAD_FILES) {
      $zip->close();
      throw new RuntimeException('The ZIP archive contains too many files.');
    }

    $syncRoot = rtrim($manager->getSyncDir(), DIRECTORY_SEPARATOR);
    $written = 0;
    $skipped = 0;
    $totalBytes = 0;
    $seen = [];
    try {
      for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if ($name === '' || substr($name, -1) === '/') {
          continue;
        }
        $normalisedName = str_replace('\\', '/', $name);
        $identity = strtolower($normalisedName);
        if (isset($seen[$identity]) || !$this->isSafeRelativeYamlPath($normalisedName) || $this->zipEntryIsSymlink($zip, $i)) {
          $skipped++;
          continue;
        }
        $seen[$identity] = TRUE;

        $stat = $zip->statIndex($i);
        $declaredSize = is_array($stat) ? (int) ($stat['size'] ?? 0) : 0;
        if ($declaredSize > self::MAX_YAML_FILE_BYTES || ($totalBytes + $declaredSize) > self::MAX_ARCHIVE_BYTES) {
          $skipped++;
          continue;
        }

        $stream = $zip->getStream($name);
        if (!$stream) {
          $skipped++;
          continue;
        }
        try {
          $contents = stream_get_contents($stream, self::MAX_YAML_FILE_BYTES + 1);
        }
        finally {
          fclose($stream);
        }
        if ($contents === FALSE || strlen($contents) > self::MAX_YAML_FILE_BYTES) {
          $skipped++;
          continue;
        }
        $totalBytes += strlen($contents);
        if ($totalBytes > self::MAX_ARCHIVE_BYTES) {
          $skipped++;
          continue;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'civicfg-yml-');
        if ($tmp === FALSE || file_put_contents($tmp, $contents, LOCK_EX) === FALSE) {
          if (is_string($tmp)) {
            @unlink($tmp);
          }
          throw new RuntimeException('Could not create a temporary YAML file for archive validation.');
        }
        try {
          $parsed = SimpleYaml::parseFile($tmp);
          if (!is_array($parsed) || !$parsed) {
            $skipped++;
            continue;
          }
          $target = $syncRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalisedName);
          $this->safeWriteContents($contents, $target, $syncRoot);
          $written++;
        }
        catch (Throwable $e) {
          $skipped++;
        }
        finally {
          @unlink($tmp);
        }
      }
    }
    finally {
      $zip->close();
    }
    if ($written === 0) {
      throw new RuntimeException('No YAML files were imported from the ZIP archive.');
    }
    return ts('Archive uploaded. %1 YAML file(s) staged; %2 file(s) skipped. Review Synchronize before importing.', [1 => $written, 2 => $skipped]);
  }

  public function downloadArchive(ConfigManager $manager): void {
    if (!class_exists('ZipArchive')) {
      throw new RuntimeException('ZipArchive is not available in PHP.');
    }

    // Build the archive from the *effective managed YAML set*, not by blindly
    // copying every YAML file left on disk. Selected-scope backups may be kept
    // locally after deselection for safety, but they must not leak back into a
    // managed export archive.
    $managedFiles = $manager->getManagedYamlArchiveFiles();
    if (!$managedFiles) {
      throw new RuntimeException('No managed YAML files are available for download.');
    }

    $temporary = tempnam(sys_get_temp_dir(), 'civicfg-');
    if ($temporary === FALSE) {
      throw new RuntimeException('Could not create a temporary archive path.');
    }
    @unlink($temporary);
    $zipPath = $temporary . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
      throw new RuntimeException('Could not create archive.');
    }
    $added = 0;
    try {
      foreach ($managedFiles as $relative => $data) {
        $relative = str_replace('\\', '/', (string) $relative);
        if (!$this->isSafeRelativeYamlPath($relative) && $relative !== 'manifest.yml') {
          continue;
        }
        if ($relative !== 'manifest.yml' && $manager->shouldIgnorePath($relative)) {
          continue;
        }
        if ($zip->addFromString($relative, SimpleYaml::dump((array) $data))) {
          $added++;
        }
      }
    }
    finally {
      $zip->close();
    }
    if ($added === 0 || !is_file($zipPath)) {
      @unlink($zipPath);
      throw new RuntimeException('No valid managed YAML files are available for download.');
    }

    \CRM_Utils_System::setHttpHeader('Content-Type', 'application/zip');
    \CRM_Utils_System::setHttpHeader('Content-Disposition', 'attachment; filename="civicrm-config.zip"');
    \CRM_Utils_System::setHttpHeader('Content-Length', (string) filesize($zipPath));
    readfile($zipPath);
    @unlink($zipPath);
    \CRM_Utils_System::civiExit();
  }

  private function splitExportKey(string $key): array {
    $parts = explode('::', $key, 2);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
      throw new RuntimeException('Invalid export item selection.');
    }
    if (!preg_match('/^[A-Za-z0-9_.-]+$/', $parts[0]) || !$this->isSafeRelativeYamlPath($parts[1])) {
      throw new RuntimeException('Invalid export item path.');
    }
    return $parts;
  }

  private function getHandlerByType(ConfigManager $manager, string $type) {
    foreach ($manager->getAllHandlers() as $handler) {
      if ($handler->getType() === $type) {
        return $handler;
      }
    }
    return NULL;
  }

  private function isSafeRelativeYamlPath(string $path): bool {
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '' || strpos($path, "\0") !== FALSE || $path[0] === '/' || preg_match('/^[A-Za-z]:\//', $path)) {
      return FALSE;
    }
    if (!preg_match('/\.ya?ml$/i', $path)) {
      return FALSE;
    }
    $segments = explode('/', $path);
    foreach ($segments as $segment) {
      if ($segment === '' || $segment === '.' || $segment === '..' || !preg_match('/^[A-Za-z0-9_.-]+$/', $segment)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  private function safeWriteUploadedFile(string $tmp, string $target, string $root): void {
    $contents = file_get_contents($tmp);
    if ($contents === FALSE) {
      throw new RuntimeException('Could not read the uploaded YAML file.');
    }
    if (strlen($contents) > self::MAX_YAML_FILE_BYTES) {
      throw new RuntimeException('The uploaded YAML file is too large.');
    }
    $this->safeWriteContents($contents, $target, $root);
  }

  private function safeWriteContents(string $contents, string $target, string $root): void {
    $root = rtrim($root, DIRECTORY_SEPARATOR);
    if ($root === '') {
      throw new RuntimeException('The sync directory is not a safe filesystem root.');
    }
    if (!is_dir($root) && !mkdir($root, 0775, TRUE) && !is_dir($root)) {
      throw new RuntimeException('Could not create the sync directory.');
    }
    $realRoot = realpath($root);
    if ($realRoot === FALSE || !is_dir($realRoot)) {
      throw new RuntimeException('Could not resolve the sync directory.');
    }

    $dir = dirname($target);
    if (!is_dir($dir) && !mkdir($dir, 0775, TRUE) && !is_dir($dir)) {
      throw new RuntimeException('Could not create the target YAML directory.');
    }
    $realDir = realpath($dir);
    if ($realDir === FALSE || !$this->pathIsWithinRoot($realDir, $realRoot)) {
      throw new RuntimeException('Refusing to write outside the sync directory.');
    }
    if (is_link($target)) {
      throw new RuntimeException('Refusing to overwrite a symbolic link.');
    }

    $temporary = tempnam($realDir, '.civicfg-upload-');
    if ($temporary === FALSE) {
      throw new RuntimeException('Could not create a temporary upload file.');
    }
    try {
      if (file_put_contents($temporary, $contents, LOCK_EX) === FALSE) {
        throw new RuntimeException('Could not stage the uploaded YAML file.');
      }
      @chmod($temporary, 0664);
      if (!@rename($temporary, $target)) {
        throw new RuntimeException('Could not atomically save the uploaded YAML file.');
      }
    }
    finally {
      if (is_file($temporary)) {
        @unlink($temporary);
      }
    }
  }

  private function pathIsWithinRoot(string $path, string $root): bool {
    $path = rtrim($path, DIRECTORY_SEPARATOR);
    $root = rtrim($root, DIRECTORY_SEPARATOR);
    return $path === $root || strpos($path, $root . DIRECTORY_SEPARATOR) === 0;
  }

  private function zipEntryIsSymlink(ZipArchive $zip, int $index): bool {
    $operations = 0;
    $attributes = 0;
    if (!$zip->getExternalAttributesIndex($index, $operations, $attributes)) {
      return FALSE;
    }
    if ($operations !== ZipArchive::OPSYS_UNIX) {
      return FALSE;
    }
    $mode = ($attributes >> 16) & 0170000;
    return $mode === 0120000;
  }

}
