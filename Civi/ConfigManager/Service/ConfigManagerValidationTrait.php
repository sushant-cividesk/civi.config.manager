<?php
namespace Civi\ConfigManager\Service;

use Civi\ConfigManager\Handler\StreamingHandlerInterface;
use Civi\ConfigManager\Storage\YamlFileStorage;

/**
 * ConfigManagerValidation methods from ConfigManager.
 *
 * Methods run on the original owning object and retain the same state and
 * access modifiers. Do not instantiate this trait separately.
 */
trait ConfigManagerValidationTrait {

  public function validate(array $typeFilter = []): array {
    // Active dependency lookups are cached only for one validation pass. A
    // long-lived ConfigManager instance may have applied configuration since a
    // previous validation, so never reuse target-state fingerprints here.
    $this->activeDependencyNamesCache = [];
    $storage = new YamlFileStorage($this->getSyncDir());
    $result = ['ok' => TRUE, 'sync_dir' => $storage->getRoot(), 'items' => [], 'errors' => []];
    $availableNames = [];
    $dependencyMetadata = [];
    $normalisedFilter = $this->normaliseTypeFilter($typeFilter);
    $baseFilter = $this->baseTypesFromFilter($normalisedFilter);

    foreach ($this->getHandlers() as $handler) {
      if ($baseFilter && !in_array($handler->getType(), $baseFilter, TRUE)) {
        continue;
      }
      $this->prepareHandlerForTypeFilter($handler, $normalisedFilter);
      try {
        $handlerType = (string) $handler->getType();
        foreach ($this->iterateManagedYamlFilesForHandler($handler, $storage) as $filename => $file) {
          $file = (array) $file;
          foreach ($this->namesFromYamlFile($file) as $name) {
            $availableNames[$handlerType][(string) $name] = TRUE;
          }
          $dependencies = $this->extractDependenciesFromYamlFile($file);
          $requiredBy = $this->extractRequiredByFromYamlFile($file);
          if ($dependencies || $requiredBy) {
            $dependencyMetadata[$handlerType][(string) $filename] = [
              'dependencies' => $dependencies,
              'required_by' => $requiredBy,
            ];
          }
        }
        $validation = $this->validateManagedYamlForHandler($handler, $storage);
        $result['items'][] = $validation;
        if (empty($validation['valid'])) {
          $result['ok'] = FALSE;
        }
        // Handler validation is complete; retain only compact dependency/name
        // metadata for the cross-type pass instead of every parsed YAML body.
      }
      catch (\Throwable $e) {
        $result['errors'][] = ['type' => $handler->getType(), 'message' => $e->getMessage()];
      }
    }
    $this->addDependencyWarningsFromMetadata($result, $availableNames, $dependencyMetadata);
    try {
      $this->addManifestValidation($result, $storage->readFile('manifest.yml'));
    }
    catch (\Throwable $e) {
      $result['errors'][] = ['type' => 'manifest', 'message' => $e->getMessage()];
    }
    $result['ok'] = $result['ok'] && empty($result['errors']);
    return $result;
  }

  private function addDependencyWarningsFromMetadata(array &$result, array $available, array $dependencyMetadata): void {
    $registeredTypes = [];
    foreach ($this->getAllHandlers() as $handler) {
      $registeredTypes[(string) $handler->getType()] = (string) $handler->getLabel();
    }
    $managedTypes = [];
    foreach ($this->getHandlers() as $handler) {
      $managedTypes[(string) $handler->getType()] = (string) $handler->getLabel();
    }

    $analysis = $this->importDependencyPlanner->analyze(
      $dependencyMetadata,
      $available,
      $registeredTypes,
      $managedTypes,
      fn(string $type, string $name): bool => $this->activeDependencyExists($type, $name),
      fn(string $type, string $name): string => $this->ignoredDependencyHint($type, $name)
    );

    $itemIndex = [];
    foreach ($result['items'] as $index => $item) {
      if (!empty($item['type'])) {
        $itemIndex[(string) $item['type']] = $index;
      }
    }
    foreach ((array) ($analysis['dependency_blockers'] ?? []) as $blocker) {
      $type = (string) ($blocker['source_type'] ?? '');
      if (!isset($itemIndex[$type])) {
        continue;
      }
      $result['ok'] = FALSE;
      $result['items'][$itemIndex[$type]]['errors'][] = [
        'file' => (string) ($blocker['source_file'] ?? ''),
        'message' => (string) ($blocker['message'] ?? 'Import dependency is missing.'),
      ];
    }
    foreach ((array) ($analysis['warnings_by_type'] ?? []) as $type => $warnings) {
      if (isset($itemIndex[$type])) {
        $result['items'][$itemIndex[$type]]['warnings'] = array_merge(
          (array) ($result['items'][$itemIndex[$type]]['warnings'] ?? []),
          (array) $warnings
        );
      }
    }
    $result['dependency_edges'] = (array) ($analysis['dependency_edges'] ?? []);
    $result['dependency_blockers'] = (array) ($analysis['dependency_blockers'] ?? []);
    $result['dependency_components'] = (array) ($analysis['dependency_components'] ?? []);
  }

  private function activeDependencyExists(string $type, string $name): bool {
    if (!array_key_exists($type, $this->activeDependencyNamesCache)) {
      $names = [];
      foreach ($this->getAllHandlers() as $handler) {
        if ((string) $handler->getType() !== $type) {
          continue;
        }
        try {
          $rows = $handler instanceof StreamingHandlerInterface ? $handler->iterateExport() : $handler->export();
          foreach ($rows as $file) {
            $file = (array) $file;
            foreach ($this->namesFromYamlFile((array) ($file['data'] ?? [])) as $activeName) {
              $names[(string) $activeName] = TRUE;
            }
          }
        }
        catch (\Throwable $e) {
          // Fail closed: if active state cannot be verified, validation should
          // continue to report the missing portable dependency below.
          $names = [];
        }
        break;
      }
      $this->activeDependencyNamesCache[$type] = $names;
    }
    return isset($this->activeDependencyNamesCache[$type][$name]);
  }

  private function ignoredDependencyHint(string $type, string $name): string {
    foreach ($this->dependencyCandidatePaths($type, $name) as $path) {
      if ($this->isIgnoredPath($path)) {
        return $path;
      }
    }
    return '';
  }

  private function dependencyCandidatePaths(string $type, string $name): array {
    $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name);
    $safe = trim((string) $safe, '-') ?: sha1($name);
    $map = [
      'extensions' => ['extensions/' . $safe . '.yml'],
      'searchkit-saved-searches' => ['searchkit/saved-searches/' . $safe . '.yml'],
      'searchkit-displays' => ['searchkit/displays/' . $safe . '.yml', 'searchkit/displays/*__' . $safe . '.yml'],
      'formbuilder-afforms' => ['formbuilder/afforms/' . $safe . '.yml'],
      'scheduled-jobs' => ['scheduled-jobs/' . $safe . '.yml'],
      'site-tokens' => ['site-tokens/' . $safe . '.yml'],
      'civirules' => ['civirules/' . $safe . '.yml', 'civirules/*/' . $safe . '.yml'],
    ];
    return $map[$type] ?? [$type . '/' . $safe . '.yml'];
  }

  private function collectManagedYamlNames(array $yamlByType): array {
    $available = [];
    foreach ($yamlByType as $type => $files) {
      foreach ($files as $file) {
        $file = (array) $file;
        foreach ($this->namesFromYamlFile($file) as $name) {
          $available[$type][(string) $name] = TRUE;
        }
      }
    }
    return $available;
  }

  private function namesFromYamlFile(array $file): array {
    $names = [];
    if (!empty($file['name'])) {
      $names[] = (string) $file['name'];
    }
    if (!empty($file['key'])) {
      $names[] = (string) $file['key'];
    }
    if (!empty($file['extension']) && is_string($file['extension'])) {
      $names[] = (string) $file['extension'];
    }
    if (!empty($file['extension']) && is_array($file['extension']) && !empty($file['extension']['key'])) {
      $names[] = (string) $file['extension']['key'];
    }
    if (!empty($file['item']) && is_array($file['item'])) {
      foreach (['name', 'title', 'label', 'name_a_b'] as $key) {
        if (!empty($file['item'][$key])) {
          $names[] = (string) $file['item'][$key];
        }
      }
    }
    foreach (($file['items'] ?? []) as $row) {
      if (is_array($row)) {
        foreach (['name', 'title', 'label', 'name_a_b'] as $key) {
          if (!empty($row[$key])) {
            $names[] = (string) $row[$key];
          }
        }
      }
    }
    return array_values(array_unique($names));
  }

  private function extractDependenciesFromYamlFile(array $file): array {
    $dependencies = [];
    foreach (($file['dependencies'] ?? []) as $dependency) {
      if (is_array($dependency)) {
        $dependencies[] = $dependency;
      }
    }
    foreach (($file['item']['dependencies'] ?? []) as $dependency) {
      if (is_array($dependency)) {
        $dependencies[] = $dependency;
      }
    }
    return $dependencies;
  }

  private function extractRequiredByFromYamlFile(array $file): array {
    $requiredBy = [];
    foreach (($file['required_by'] ?? []) as $row) {
      if (is_array($row)) {
        $requiredBy[] = $row;
      }
    }
    foreach (($file['item']['required_by'] ?? []) as $row) {
      if (is_array($row)) {
        $requiredBy[] = $row;
      }
    }
    return $requiredBy;
  }

  /**
   * Preserve virtual provider filters while validating imports.
   *
   * A virtual type such as extensions:de.systopia.sqltasks:api3:Sqltask
   * selects one contributed provider inside the Extensions handler. Replacing
   * it with the base `extensions` type would make validation inspect every
   * contributed provider YAML file and allow an unrelated provider failure to
   * block the requested import.
   */
  private function getImportValidationTypeFilter(array $requestedTypes, array $effectiveTypes): array {
    if (!$requestedTypes) {
      return [];
    }
    if ($this->isExtensionSubtypeOnlyFilter($requestedTypes)) {
      return $requestedTypes;
    }
    return array_values(array_unique(array_merge($effectiveTypes, $requestedTypes)));
  }

  /**
   * A provider-subtype-only import applies only the Extensions handler.
   *
   * Related-type expansion is useful for normal grouped imports, but a user
   * explicitly selecting one contributed extension provider must not also
   * apply unrelated Message Templates, Custom Data, Contact Types, etc.
   */
  private function getImportApplyTypeFilter(array $requestedTypes, array $effectiveTypes): array {
    if ($this->isExtensionSubtypeOnlyFilter($requestedTypes)) {
      return ['extensions'];
    }
    return $effectiveTypes;
  }

  private function isExtensionSubtypeOnlyFilter(array $requestedTypes): bool {
    if (!$requestedTypes) {
      return FALSE;
    }
    foreach ($requestedTypes as $type) {
      if (strpos((string) $type, 'extensions:') !== 0) {
        return FALSE;
      }
    }
    return TRUE;
  }

  private function addManifestValidation(array &$result, array $manifest): void {
    if (!$manifest) {
      return;
    }
    $manifestSite = trim((string) ($manifest['site_id'] ?? ''));
    $localSite = $this->getSiteIdentifier();
    $allowCrossSite = (bool) \Civi::settings()->get('civicfg_allow_cross_site_import');
    if ($manifestSite !== '' && $localSite !== '' && $manifestSite !== $localSite && !$allowCrossSite) {
      $result['ok'] = FALSE;
      $result['errors'][] = [
        'type' => 'manifest',
        'message' => sprintf('Manifest site_id "%s" does not match this site_id "%s". This usually means the YAML belongs to a different site family. Dev/stage/prod for the same project should share this automatically generated identifier through the database. Enable experimental cross-site import only for a reviewed one-off migration.', $manifestSite, $localSite),
      ];
    }
  }

  public function getIgnoredDependencyWarnings(): array {
    $storage = new YamlFileStorage($this->getSyncDir());
    $warnings = [];
    $available = [];
    foreach ($this->getHandlers() as $handler) {
      $type = (string) $handler->getType();
      $directory = trim((string) $handler->getDirectory(), '/');
      foreach ($storage->iterateDirectory((string) $handler->getDirectory()) as $filename => $file) {
        $relative = $directory === '' ? (string) $filename : $directory . '/' . (string) $filename;
        if ($this->isIgnoredPath($relative)) {
          continue;
        }
        foreach ($this->namesFromYamlFile((array) $file) as $name) {
          $available[$type][(string) $name] = TRUE;
        }
      }
    }
    foreach ($this->getHandlers() as $handler) {
      $type = (string) $handler->getType();
      $directory = trim((string) $handler->getDirectory(), '/');
      foreach ($storage->iterateDirectory((string) $handler->getDirectory()) as $filename => $file) {
        $relative = $directory === '' ? (string) $filename : $directory . '/' . (string) $filename;
        if ($this->isIgnoredPath($relative)) {
          continue;
        }
        foreach ($this->extractDependenciesFromYamlFile((array) $file) as $dependency) {
          $dependencyType = (string) ($dependency['type'] ?? '');
          $dependencyName = (string) ($dependency['name'] ?? '');
          if ($dependencyType === '' || $dependencyName === '') {
            continue;
          }
          if (!empty($available[$dependencyType][$dependencyName])) {
            continue;
          }
          $ignoredHint = $this->ignoredDependencyHint($dependencyType, $dependencyName);
          if ($ignoredHint !== '') {
            $warnings[] = sprintf('%s depends on ignored YAML %s. Remove or narrow the ignore rule before importing related configuration.', $filename, $ignoredHint);
          }
        }
      }
    }
    return array_values(array_unique($warnings));
  }

}
