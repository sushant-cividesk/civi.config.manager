<?php
namespace Civi\ConfigManager\Handler;

/**
 * ExtensionProviderUtility methods from ExtensionHandler.
 *
 * Methods run on the original owning object and retain the same state and
 * access modifiers. Do not instantiate this trait separately.
 */
trait ExtensionProviderUtilityTrait {

  private function isSafeSettingName(string $name): bool {
    return (bool) preg_match('/^[A-Za-z0-9_.:-]+$/', $name);
  }

  private function isSensitiveSettingName(string $name): bool {
    return (bool) preg_match('/(password|passwd|secret|credential|private|token|api[_-]?key|key)$/i', $name);
  }

  /**
   * Resolve YAML provider metadata to the canonical runtime provider.
   *
   * SQLTasks exports from older Configuration Manager builds can reference the
   * API3 Sqltask provider. SQLTasks 3.x also exposes native API4 SqlTask and
   * Configuration Manager deliberately prefers API4. Treat the old API3
   * metadata as a compatibility alias when API4 is available, while retaining
   * the reviewed API3/BAO implementation as fallback on older sites.
   *
   * @return array{key:string,definition:array}|null
   */
  private function resolveConfigDefinition(array $definitions, string $extension, string $api, string $entity): ?array {
    if (
      strtolower($extension) === 'de.systopia.sqltasks'
      && strtolower($api) === 'api3'
      && strtolower($entity) === 'sqltask'
    ) {
      foreach ($definitions as $key => $definition) {
        if (
          strtolower((string) ($definition['extension'] ?? '')) === 'de.systopia.sqltasks'
          && strtolower((string) ($definition['api'] ?? '')) === 'api4'
          && strtolower((string) ($definition['entity'] ?? '')) === 'sqltask'
        ) {
          return ['key' => (string) $key, 'definition' => (array) $definition];
        }
      }
    }

    $key = $this->definitionKey($extension, $api, $entity);
    if (!isset($definitions[$key])) {
      return NULL;
    }
    return ['key' => $key, 'definition' => (array) $definitions[$key]];
  }

  private function definitionKey(string $extension, string $api, string $entity): string {
    return strtolower($extension . '|' . $api . '|' . $entity);
  }

  private function identityKey(string $field, string $value): string {
    return $field . ':' . $value;
  }

  private function uniqueDependencies(array $dependencies): array {
    $seen = [];
    $unique = [];
    foreach ($dependencies as $dependency) {
      $key = json_encode($dependency);
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = TRUE;
      $unique[] = $dependency;
    }
    return $unique;
  }

  private function isEntityConflictException(\Throwable $e): bool {
    $message = $e->getMessage();
    return stripos($message, 'already exists') !== FALSE || stripos($message, 'duplicate') !== FALSE;
  }

  private function formatEntityImportException(\Throwable $e, array $definition, string $identity): string {
    $message = $e->getMessage();
    if (stripos($message, 'already exists') !== FALSE || stripos($message, 'duplicate') !== FALSE) {
      return sprintf('Target already has a conflicting bundled extension config record for %s %s / %s. Import skipped this item to avoid creating a duplicate. Original error: %s', $definition['api'], $definition['entity'], $identity, $message);
    }
    return $message;
  }

  private function callManager($manager, string $method, array $keys): void {
    if (!method_exists($manager, $method)) {
      throw new \RuntimeException('Extension manager does not support method ' . $method . ' on this CiviCRM version.');
    }
    $manager->{$method}($keys);
    self::$api3ActionsByEntity = [];
    self::$api3ListActionByEntity = [];
    self::$api3DeleteActionByEntity = [];
    $this->discoveredEntityDefinitions = NULL;
    $this->identityRowsByDefinition = [];
  }

  private function safeName(string $name): string {
    $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name);
    return trim((string) $safe, '-') ?: sha1($name);
  }

}
