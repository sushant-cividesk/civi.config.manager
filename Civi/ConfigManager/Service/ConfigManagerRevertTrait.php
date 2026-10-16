<?php
namespace Civi\ConfigManager\Service;

use Civi\ConfigManager\Storage\YamlFileStorage;

/**
 * ConfigManagerRevert methods from ConfigManager.
 *
 * Methods run on the original owning object and retain the same state and
 * access modifiers. Do not instantiate this trait separately.
 */
trait ConfigManagerRevertTrait {


  public function revertCiviFromYaml(string $relativePath): array {
    $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath === '' || strpos($relativePath, '..') !== FALSE || $this->isIgnoredPath($relativePath)) {
      throw new \RuntimeException('Cannot revert an empty, unsafe, or ignored YAML path.');
    }

    $storage = new YamlFileStorage($this->getSyncDir());
    $operationLock = OperationLock::acquire($storage->getRoot());
    $selected = $this->resolveHandlerPath($relativePath);
    if (!$selected) {
      throw new \RuntimeException('No managed handler owns YAML path: ' . $relativePath);
    }

    $affectedPaths = $this->collectRevertDependencyPaths($storage, [$relativePath]);
    if (!in_array($relativePath, $affectedPaths, TRUE)) {
      array_unshift($affectedPaths, $relativePath);
    }

    $items = [];
    $errors = [];
    $warnings = [];
    $appliedPaths = [];

    foreach ($this->getAllHandlers() as $handler) {
      $ownedPaths = [];
      foreach ($affectedPaths as $path) {
        $parts = $this->handlerPathParts($handler, $path);
        if ($parts) {
          $ownedPaths[$path] = $parts;
        }
      }
      if (!$ownedPaths) {
        continue;
      }

      $desired = [];
      foreach ($handler->export() as $file) {
        if (empty($file['filename'])) {
          continue;
        }
        $filename = (string) $file['filename'];
        $relative = $this->relativePathForHandlerFile($handler, $filename);
        $desired[$filename] = $this->applyIgnoredValueRules($relative, (array) ($file['data'] ?? []));
      }

      foreach ($ownedPaths as $path => $parts) {
        $filename = (string) $parts['filename'];
        if ($storage->exists((string) $parts['directory'], $filename)) {
          $desired[$filename] = $this->applyIgnoredValueRules($path, $storage->readFile($path));
        }
        else {
          unset($desired[$filename]);
        }
        $appliedPaths[$path] = TRUE;
      }
      ksort($desired);

      try {
        $validation = $handler->validate($desired);
        if (empty($validation['valid'])) {
          foreach ((array) ($validation['errors'] ?? []) as $error) {
            $errors[] = [
              'type' => $handler->getType(),
              'message' => (string) ($error['message'] ?? json_encode($error)),
              'file' => (string) ($error['file'] ?? ''),
            ];
          }
          continue;
        }
        foreach ((array) ($validation['warnings'] ?? []) as $warning) {
          $warnings[] = [
            'type' => $handler->getType(),
            'message' => (string) ($warning['message'] ?? json_encode($warning)),
            'file' => (string) ($warning['file'] ?? ''),
          ];
        }

        $this->setHandlerImportPhase($handler, TRUE, TRUE);
        $item = $handler->import($desired, FALSE);
        $item['revert_paths'] = array_keys($ownedPaths);
        $items[] = $item;
        foreach ((array) ($item['warnings'] ?? []) as $warning) {
          $warnings[] = [
            'type' => $handler->getType(),
            'message' => (string) ($warning['message'] ?? json_encode($warning)),
            'file' => (string) ($warning['file'] ?? ''),
          ];
        }
        foreach ((array) ($item['errors'] ?? []) as $error) {
          $errors[] = [
            'type' => $handler->getType(),
            'message' => (string) ($error['message'] ?? json_encode($error)),
            'file' => (string) ($error['file'] ?? ''),
          ];
        }
      }
      finally {
        $this->setHandlerImportPhase($handler, TRUE, TRUE);
      }
    }

    $summaryMessage = $this->buildImportSummaryMessage(['items' => $items]);
    $pathCount = count($appliedPaths);
    $dependencyNote = $pathCount > 1 ? sprintf(' The selected Saved Config and %d dependent Saved Config file(s) were applied.', $pathCount - 1) : ' The selected Saved Config was applied.';

    return [
      'ok' => empty($errors),
      'path' => $relativePath,
      'paths' => array_keys($appliedPaths),
      'items' => $items,
      'warnings' => $warnings,
      'errors' => $errors,
      'message' => empty($errors)
        ? 'Current CiviCRM was restored from the Saved Config.' . $dependencyNote . ' ' . $summaryMessage
        : 'Revert from YAML found problems. ' . $summaryMessage,
    ];
  }

  private function collectRevertDependencyPaths(YamlFileStorage $storage, array $seedPaths): array {
    $index = $this->buildYamlDependencyIndex($storage);
    $queue = array_values(array_unique(array_filter(array_map(function($path) {
      return trim(str_replace('\\', '/', (string) $path), '/');
    }, $seedPaths))));
    $seen = [];

    while ($queue) {
      $path = array_shift($queue);
      if ($path === '' || isset($seen[$path])) {
        continue;
      }
      $seen[$path] = TRUE;
      $file = $storage->readFile($path);
      if (!$file) {
        continue;
      }
      foreach ($this->extractDependenciesFromYamlFile((array) $file) as $dependency) {
        $type = (string) ($dependency['type'] ?? '');
        $name = (string) ($dependency['name'] ?? '');
        if ($type === '' || $name === '') {
          continue;
        }
        $paths = $index[$type][$name] ?? [];
        if (!$paths) {
          foreach ($this->dependencyCandidatePaths($type, $name) as $candidate) {
            if ($storage->readFile($candidate)) {
              $paths[] = $candidate;
            }
          }
        }
        foreach ($paths as $dependencyPath) {
          $dependencyPath = trim(str_replace('\\', '/', (string) $dependencyPath), '/');
          if ($dependencyPath !== '' && !isset($seen[$dependencyPath])) {
            $queue[] = $dependencyPath;
          }
        }
      }
      if (count($seen) > 500) {
        throw new \RuntimeException('Dependency closure is too large. Review YAML dependencies before reverting.');
      }
    }

    return array_keys($seen);
  }

  private function buildYamlDependencyIndex(YamlFileStorage $storage): array {
    $index = [];
    foreach ($this->getAllHandlers() as $handler) {
      $directory = trim((string) $handler->getDirectory(), '/');
      foreach ($storage->iterateDirectory($handler->getDirectory()) as $filename => $file) {
        $relative = $directory === '' ? (string) $filename : $directory . '/' . (string) $filename;
        foreach ($this->namesFromYamlFile((array) $file) as $name) {
          $index[$handler->getType()][(string) $name][] = $relative;
        }
      }
    }
    return $index;
  }

  private function resolveHandlerPath(string $relativePath): ?array {
    foreach ($this->getAllHandlers() as $handler) {
      $parts = $this->handlerPathParts($handler, $relativePath);
      if ($parts) {
        $parts['handler'] = $handler;
        return $parts;
      }
    }
    return NULL;
  }

  /**
   * True when an uploaded YAML path belongs to a currently registered handler.
   * Custom handlers registered through the normal hook are therefore accepted
   * without maintaining a hard-coded directory allowlist.
   */
  public function ownsManagedYamlPath(string $relativePath): bool {
    $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath === 'manifest.yml') {
      return TRUE;
    }
    return $relativePath !== '' && $this->resolveHandlerPath($relativePath) !== NULL;
  }

  private function handlerPathParts($handler, string $relativePath): ?array {
    $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
    $directory = trim((string) $handler->getDirectory(), '/');
    $prefix = $directory === '' ? '' : $directory . '/';
    if ($directory !== '' && strpos($relativePath, $prefix) !== 0) {
      return NULL;
    }
    $filename = $directory === '' ? $relativePath : substr($relativePath, strlen($prefix));
    if ($filename === '' || strpos($filename, '..') !== FALSE) {
      return NULL;
    }
    return ['directory' => $handler->getDirectory(), 'filename' => $filename];
  }

  private function relativePathForHandlerFile($handler, string $filename): string {
    $directory = trim((string) $handler->getDirectory(), '/');
    return $directory === '' ? $filename : $directory . '/' . ltrim($filename, '/');
  }

  public function addIgnorePathRule(string $relativePath): array {
    $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath === '' || strpos($relativePath, '..') !== FALSE) {
      throw new \RuntimeException('Invalid ignore path.');
    }
    $rules = $this->getConfiguredIgnoreRules();
    $rules[] = $relativePath;
    return $this->setConfiguredIgnoreRules($rules);
  }

  public function addIgnoreValueRules(string $relativePath, array $valuePaths): array {
    $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath === '' || strpos($relativePath, '..') !== FALSE) {
      throw new \RuntimeException('Invalid ignore path.');
    }
    $rules = $this->getConfiguredIgnoreRules();
    foreach ($valuePaths as $valuePath) {
      $valuePath = trim((string) $valuePath);
      if ($valuePath === '' || strpos($valuePath, '..') !== FALSE) {
        continue;
      }
      $rules[] = $relativePath . ':' . $valuePath;
    }
    return $this->setConfiguredIgnoreRules($rules);
  }
}
