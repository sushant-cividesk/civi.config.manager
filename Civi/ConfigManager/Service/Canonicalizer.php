<?php
namespace Civi\ConfigManager\Service;

/**
 * Produces deterministic, type-preserving portable configuration values.
 */
class Canonicalizer {
  public const VERSION = 1;
  public const HASH_ALGORITHM = 'sha256';

  public function hash(array $data, array $options = []): string {
    $canonical = $this->canonicalize($data, $options);
    $json = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    if ($json === FALSE) {
      throw new \RuntimeException('Could not encode canonical configuration for fingerprinting.');
    }
    return hash(self::HASH_ALGORITHM, $json);
  }

  public function canonicalize($data, array $options = [], string $path = '') {
    $runtime = array_values((array) ($options['runtime_fields'] ?? []));
    $sensitive = array_values((array) ($options['sensitive_fields'] ?? []));
    $ignored = array_values((array) ($options['ignored_fields'] ?? []));
    $unordered = array_values((array) ($options['unordered_paths'] ?? []));

    // Operational metadata describes Configuration Manager/provider behavior,
    // not the portable configuration value itself. Keep these exact paths out
    // of fingerprints so capability/discovery changes do not look like config
    // drift. Provider-declared paths are appended below.
    $ignored = array_values(array_unique(array_merge([
      'schema_version',
      'type',
      'entity',
      'key',
      'key_fields',
      'identity_field',
      'identity_confidence',
      'capabilities',
      'dependencies',
      'required_by',
      'config_index',
      'item.required_by',
    ], $ignored, $runtime, $sensitive)));

    if ($path === '' && is_array($data)) {
      $data = $this->removePaths($data, $ignored);
    }

    if (!is_array($data)) {
      if (is_string($data)) {
        return str_replace(["\r\n", "\r"], "\n", $data);
      }
      return $data;
    }

    $isList = $this->isList($data);
    $result = [];
    foreach ($data as $key => $value) {
      $childPath = $path === '' ? (string) $key : $path . '.' . (string) $key;
      $result[$key] = $this->canonicalize($value, $options, $childPath);
    }

    if ($isList) {
      if ($this->pathIsListed($path, $unordered)) {
        usort($result, function($a, $b) {
          return strcmp($this->stableJson($a), $this->stableJson($b));
        });
      }
      return array_values($result);
    }

    ksort($result, SORT_STRING);
    return $result;
  }

  private function removePaths(array $data, array $paths): array {
    foreach ($paths as $path) {
      $path = trim((string) $path);
      if ($path === '') {
        continue;
      }
      $segments = array_values(array_filter(explode('.', $path), 'strlen'));
      if ($segments) {
        $this->unsetPath($data, $segments);
      }
    }
    return $data;
  }

  private function unsetPath(array &$data, array $segments): void {
    $segment = array_shift($segments);
    if ($segment === NULL) {
      return;
    }

    if ($segment === '*') {
      if (!$segments) {
        foreach (array_keys($data) as $key) {
          unset($data[$key]);
        }
        return;
      }
      foreach ($data as &$value) {
        if (is_array($value)) {
          $this->unsetPath($value, $segments);
        }
      }
      unset($value);
      return;
    }

    if (!array_key_exists($segment, $data)) {
      return;
    }
    if (!$segments) {
      unset($data[$segment]);
      return;
    }
    if (is_array($data[$segment])) {
      $this->unsetPath($data[$segment], $segments);
    }
  }

  private function pathIsListed(string $path, array $patterns): bool {
    foreach ($patterns as $pattern) {
      if ((string) $pattern === $path) {
        return TRUE;
      }
    }
    return FALSE;
  }

  private function isList(array $data): bool {
    if ($data === []) {
      return TRUE;
    }
    return array_keys($data) === range(0, count($data) - 1);
  }

  private function stableJson($value): string {
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    return $json === FALSE ? '' : $json;
  }
}
