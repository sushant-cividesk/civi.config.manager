<?php
namespace Civi\ConfigManager\Service;

/**
 * Owns Config Ignore rule persistence, normalization and filtering.
 *
 * CiviCRM supplies settings at runtime; the public ConfigManager API remains
 * the entry point for existing callers. Do not suppress configuration changes
 * when comparing Saved Config with Current CiviCRM.
 */
final class ConfigIgnoreRules {
  /**
   * Universal runtime-only YAML values excluded from portable configuration.
   *
   * These fields are maintained by CiviCRM while jobs/tokens run and are not
   * meaningful deployment configuration. They are always ignored in addition
   * to any administrator-defined Config Ignore Values.
   */
  private const BUILT_IN_IGNORE_VALUE_RULES = [
    'extensions/*/api3/Job/*.yml:item.last_run',
    'extensions/*/api3/Job/*.yml:item.last_run_end',
    'scheduled-jobs/*.yml:item.scheduled_run_date',
    'site-tokens/*.yml:item.modified_date',
  ];

  /**
   * Universal whole-file rules owned by the extension.
   *
   * Configuration Manager must never manage its own extension-status YAML
   * while it is executing an import.
   */
  private const BUILT_IN_IGNORE_FILE_RULES = [
    'extensions/civi.config.manager.yml',
  ];
  public function getIgnorePatterns(): array {
    return array_values(array_filter($this->getIgnoreRules(), static function(string $rule): bool {
      return strpos($rule, ':') === FALSE;
    }));
  }

  public function filterIgnoredFiles(string $directory, array $files): array {
    $filtered = [];
    foreach ($files as $filename => $data) {
      $relative = trim($directory, '/') . '/' . ltrim((string) $filename, '/');
      if ($this->isIgnoredPath($relative)) {
        continue;
      }
      $filtered[$filename] = $data;
    }
    return $filtered;
  }

  public function filterIgnoredDiffItem(array $item, string $directory = ''): array {
    $ignoredFiles = [];
    $files = [];
    foreach (($item['files'] ?? []) as $file) {
      $path = (string) ($file['path'] ?? '');
      if ($path !== '' && $this->isIgnoredPath($path)) {
        $ignoredFiles[(string) ($file['file'] ?? basename($path))] = TRUE;
        continue;
      }
      $files[] = $file;
    }
    $item['files'] = $files;

    foreach (['changed', 'new_in_db', 'missing_in_db'] as $bucket) {
      $values = [];
      foreach (($item[$bucket] ?? []) as $filename) {
        $filename = (string) $filename;
        $relative = trim($directory, '/') !== '' ? trim($directory, '/') . '/' . ltrim($filename, '/') : $filename;
        if (!isset($ignoredFiles[$filename]) && !$this->isIgnoredPath($relative)) {
          $values[] = $filename;
        }
      }
      $item[$bucket] = $values;
    }

    if (empty($item['changed']) && empty($item['new_in_db']) && empty($item['missing_in_db'])) {
      $item['status'] = 'in_sync';
    }
    return $item;
  }

  public function shouldIgnorePath(string $relativePath): bool {
    return $this->isIgnoredPath($relativePath);
  }

  private function isIgnoredPath(string $relativePath): bool {
    $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
    foreach ($this->getIgnorePatterns() as $pattern) {
      $pattern = trim(str_replace('\\', '/', (string) $pattern), '/');
      if ($pattern === '') {
        continue;
      }
      if ($relativePath === $pattern) {
        return TRUE;
      }
      $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';
      if (preg_match($regex, $relativePath)) {
        return TRUE;
      }
    }
    return FALSE;
  }


  /**
   * Return universal runtime-only ignore rules owned by the extension.
   *
   * @return string[]
   */
  public function getBuiltInIgnoreValueRules(): array {
    return self::BUILT_IN_IGNORE_VALUE_RULES;
  }

  /**
   * Return every universal rule shown in the single Config Ignore control.
   *
   * @return string[]
   */
  public function getBuiltInIgnoreRules(): array {
    return array_merge(self::BUILT_IN_IGNORE_FILE_RULES, self::BUILT_IN_IGNORE_VALUE_RULES);
  }

  /**
   * Return administrator-defined whole-file and field-level ignore rules.
   *
   * civicfg_ignore_values is still read for upgrade compatibility. New writes
   * use civicfg_ignore_paths as the one canonical Config Ignore setting.
   *
   * @return string[]
   */
  public function getConfiguredIgnoreRules(): array {
    $configured = array_merge(
      (array) \Civi::settings()->get('civicfg_ignore_paths'),
      (array) \Civi::settings()->get('civicfg_ignore_values')
    );
    $rules = [];
    foreach ($configured as $rule) {
      $normalized = $this->normalizeIgnoreRule((string) $rule);
      if ($normalized === NULL || in_array($normalized, $this->getBuiltInIgnoreRules(), TRUE)) {
        continue;
      }
      $rules[$normalized] = TRUE;
    }
    $values = array_keys($rules);
    sort($values, SORT_NATURAL | SORT_FLAG_CASE);
    return $values;
  }

  /**
   * Return the complete effective Config Ignore rule list.
   *
   * A rule without ':' ignores a whole YAML file/path. A rule with ':' keeps
   * the file managed and ignores only the named dot-path value.
   *
   * @return string[]
   */
  public function getIgnoreRules(): array {
    return array_values(array_unique(array_merge($this->getBuiltInIgnoreRules(), $this->getConfiguredIgnoreRules())));
  }

  /**
   * Save the one administrator-facing Config Ignore rule list.
   *
   * Built-in rules are never persisted because they are always effective.
   * Legacy civicfg_ignore_values data is migrated into civicfg_ignore_paths.
   *
   * @param array<int, mixed> $rules
   * @return string[]
   */
  public function setConfiguredIgnoreRules(array $rules): array {
    $configured = [];
    foreach ($rules as $rule) {
      $normalized = $this->normalizeIgnoreRule((string) $rule);
      if ($normalized === NULL || in_array($normalized, $this->getBuiltInIgnoreRules(), TRUE)) {
        continue;
      }
      $configured[$normalized] = TRUE;
    }
    $values = array_keys($configured);
    sort($values, SORT_NATURAL | SORT_FLAG_CASE);
    \Civi::settings()->set('civicfg_ignore_paths', $values);
    \Civi::settings()->set('civicfg_ignore_values', []);
    return $values;
  }

  /**
   * Return administrator-defined ignore rules only.
   *
   * @return string[]
   */
  public function getConfiguredIgnoreValueRules(): array {
    return array_values(array_filter($this->getConfiguredIgnoreRules(), static function(string $rule): bool {
      return strpos($rule, ':') !== FALSE;
    }));
  }

  /**
   * Return the effective built-in + administrator Config Ignore Values.
   */
  public function getIgnoreValuePatterns(): array {
    $rules = [];
    foreach ($this->getIgnoreRules() as $rule) {
      if (strpos($rule, ':') === FALSE) {
        continue;
      }
      [$path, $valuePath] = array_map('trim', explode(':', (string) $rule, 2));
      $raw = trim($path, '/') . ':' . trim($valuePath);
      $rules[$raw] = [
        'path' => trim($path, '/'),
        'value_path' => trim($valuePath),
        'raw' => $raw,
      ];
    }
    return array_values($rules);
  }

  private function normalizeIgnoreRule(string $rule): ?string {
    $rule = trim(str_replace('\\', '/', $rule));
    if ($rule === '') {
      return NULL;
    }

    if (strpos($rule, ':') === FALSE) {
      $path = trim($rule, '/');
      if ($path === '' || strpos($path, '..') !== FALSE) {
        return NULL;
      }
      return $path;
    }

    [$path, $valuePath] = array_map('trim', explode(':', $rule, 2));
    $path = trim($path, '/');
    if ($path === '' || $valuePath === '' || strpos($path, '..') !== FALSE || strpos($valuePath, '..') !== FALSE) {
      return NULL;
    }
    return $path . ':' . $valuePath;
  }

  public function filterIgnoredValuesInFiles(string $directory, array $files): array {
    $filtered = [];
    foreach ($files as $filename => $data) {
      $relative = trim($directory, '/') . '/' . ltrim((string) $filename, '/');
      $filtered[$filename] = $this->applyIgnoredValueRules($relative, (array) $data);
    }
    return $filtered;
  }

  public function filterIgnoredValuesInExportFiles(string $directory, array $files): array {
    foreach ($files as &$file) {
      if (empty($file['filename'])) {
        continue;
      }
      $relative = trim($directory, '/') . '/' . ltrim((string) $file['filename'], '/');
      $file['data'] = $this->applyIgnoredValueRules($relative, (array) ($file['data'] ?? []));
    }
    return $files;
  }

  public function applyIgnoredValueRules(string $relativePath, array $data): array {
    foreach ($this->getIgnoreValuePatterns() as $rule) {
      if (!$this->pathMatchesPattern($relativePath, (string) $rule['path'])) {
        continue;
      }
      $data = $this->removeValuePath($data, (string) $rule['value_path']);
    }
    return $data;
  }

  private function removeValuePath(array $data, string $path): array {
    $segments = $this->splitValuePath($path);
    if (!$segments) {
      return $data;
    }
    $this->unsetValuePath($data, $segments);
    return $data;
  }

  private function unsetValuePath(array &$data, array $segments): void {
    $segment = array_shift($segments);
    if ($segment === NULL) {
      return;
    }
    if ($segment === '*') {
      foreach ($data as &$child) {
        if (is_array($child)) {
          if ($segments) {
            $this->unsetValuePath($child, $segments);
          }
          else {
            $child = NULL;
          }
        }
      }
      unset($child);
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
      $this->unsetValuePath($data[$segment], $segments);
    }
  }

  private function splitValuePath(string $path): array {
    $path = trim($path);
    if ($path === '') {
      return [];
    }
    $path = preg_replace('/\[([^\]]+)\]/', '.$1', $path);
    $parts = preg_split('/\.+/', (string) $path);
    return array_values(array_filter(array_map('trim', (array) $parts), static fn($part) => $part !== ''));
  }

  private function pathMatchesPattern(string $relativePath, string $pattern): bool {
    $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
    $pattern = trim(str_replace('\\', '/', $pattern), '/');
    if ($relativePath === $pattern) {
      return TRUE;
    }
    $regex = '/^' . str_replace('\\*', '.*', preg_quote($pattern, '/')) . '$/';
    return (bool) preg_match($regex, $relativePath);
  }
}
