<?php
namespace Civi\ConfigManager\Service;

/**
 * Resolves managed/watch/ignored scope for configuration handlers.
 *
 * The administrator policy is stored in civicfg_scope and may be overridden by
 * the normal CiviCRM $civicrm_setting['domain'] mechanism in
 * civicrm.settings.php. Numeric IDs are accepted only as local source
 * selectors; portable matching uses semantic ConfigIdentity keys.
 */
class ConfigScope {
  public const MODE_ALL = 'all';
  public const MODE_SELECTED = 'selected';
  public const MODE_WATCH = 'watch';
  public const MODE_IGNORE = 'ignore';

  private ConfigIdentity $identity;

  public function __construct(?ConfigIdentity $identity = NULL) {
    $this->identity = $identity ?: new ConfigIdentity();
  }

  public function getPolicy(string $type): array {
    $all = $this->getPolicies();
    if (isset($all[$type])) {
      return $this->normalisePolicy((array) $all[$type]);
    }
    return $this->normalisePolicy(['mode' => $this->getDefaultMode()]);
  }

  public function getDefaultMode(): string {
    $mode = strtolower(trim((string) \Civi::settings()->get('civicfg_scope_default_mode')));
    if (in_array($mode, [self::MODE_ALL, self::MODE_SELECTED, self::MODE_WATCH, self::MODE_IGNORE], TRUE)) {
      return $mode;
    }
    // Backward compatibility: installations upgraded from alpha60 or earlier
    // have no default-mode marker and must continue managing everything.
    return self::MODE_ALL;
  }

  public function getPolicies(): array {
    // Read civicrm.settings.php explicitly instead of relying on a particular
    // SettingsBag implementation to have already folded the override in. This
    // keeps CLI, web, tests, and older supported CiviCRM runtimes consistent.
    $override = $this->getPolicyOverride();
    $raw = $override !== NULL ? $override : \Civi::settings()->get('civicfg_scope');
    $raw = is_array($raw) ? $raw : [];
    $policies = [];
    foreach ($raw as $type => $policy) {
      if (!is_string($type) || $type === '') {
        continue;
      }
      $policies[$type] = $this->normalisePolicy(is_array($policy) ? $policy : []);
    }
    ksort($policies, SORT_NATURAL | SORT_FLAG_CASE);
    return $policies;
  }

  public function savePolicies(array $policies): void {
    $clean = [];
    foreach ($policies as $type => $policy) {
      if (!is_string($type) || $type === '') {
        continue;
      }
      $clean[$type] = $this->normalisePolicy(is_array($policy) ? $policy : []);
    }
    ksort($clean, SORT_NATURAL | SORT_FLAG_CASE);
    \Civi::settings()->set('civicfg_scope', $clean);

    // Resolved selector aliases are local operational state. Keep only aliases
    // for selectors that are still explicitly configured in selected mode.
    $resolved = $this->getAllResolvedSelectorMaps();
    foreach (array_keys($resolved) as $type) {
      $policy = (array) ($clean[$type] ?? []);
      if (($policy['mode'] ?? self::MODE_ALL) !== self::MODE_SELECTED) {
        unset($resolved[$type]);
        continue;
      }
      $wanted = array_fill_keys((array) ($policy['selectors'] ?? []), TRUE);
      $resolved[$type] = array_filter((array) $resolved[$type], static function($configKey, $selector) use ($wanted) {
        return isset($wanted[(string) $selector]) && is_scalar($configKey) && trim((string) $configKey) !== '';
      }, ARRAY_FILTER_USE_BOTH);
      if (!$resolved[$type]) {
        unset($resolved[$type]);
      }
    }
    ksort($resolved, SORT_NATURAL | SORT_FLAG_CASE);
    \Civi::settings()->set('civicfg_scope_resolved', $resolved);
  }

  public function isPolicyOverridden(): bool {
    return $this->getPolicyOverride() !== NULL;
  }

  private function getPolicyOverride(): ?array {
    global $civicrm_setting;
    if (!is_array($civicrm_setting ?? NULL)) {
      return NULL;
    }
    foreach (['domain', 'Domain', 'CiviCRM Preferences'] as $group) {
      if (!isset($civicrm_setting[$group]) || !is_array($civicrm_setting[$group]) || !array_key_exists('civicfg_scope', $civicrm_setting[$group])) {
        continue;
      }
      return is_array($civicrm_setting[$group]['civicfg_scope'])
        ? $civicrm_setting[$group]['civicfg_scope']
        : [];
    }
    return NULL;
  }

  public function isManagedType(string $type): bool {
    return in_array($this->getPolicy($type)['mode'], [self::MODE_ALL, self::MODE_SELECTED], TRUE);
  }

  public function isWatchedType(string $type): bool {
    $policy = $this->getPolicy($type);
    return $policy['mode'] === self::MODE_WATCH || ($policy['mode'] === self::MODE_SELECTED && $policy['watch_unmanaged']);
  }

  public function allowsDeleteMissing(string $type): bool {
    // In selected mode, absence from YAML must never imply deleting an
    // unselected record. Explicit single-file reverts remain separately
    // reviewable, but bulk import is deliberately non-destructive here.
    return $this->getPolicy($type)['mode'] === self::MODE_ALL;
  }

  /**
   * Partition active export files into managed, watched and ignored sets.
   *
   * @param array<int,array<string,mixed>> $files
   * @param array<string,string> $portableSelectorMap Current selectors mapped
   *   to semantic keys by the last real export.
   */
  public function partition(string $type, array $files, bool $forExport = FALSE, array $portableSelectorMap = []): array {
    $policy = $this->getPolicy($type);
    $mode = $policy['mode'];
    $selectors = $policy['selectors'];
    $resolved = $this->getResolvedSelectorMap($type, $selectors);
    $resolvedKeys = array_fill_keys(array_values(array_unique(array_filter(array_values($resolved)))), TRUE);
    $manifestResolved = [];
    foreach ($selectors as $selector) {
      $candidate = $portableSelectorMap[$selector] ?? NULL;
      if (is_scalar($candidate) && trim((string) $candidate) !== '') {
        $manifestResolved[$selector] = trim((string) $candidate);
      }
    }
    $manifestResolvedKeys = array_fill_keys(array_values(array_unique(array_values($manifestResolved))), TRUE);

    $managed = [];
    $watched = [];
    $ignored = [];
    $matchedSelectors = [];
    $managedKeys = [];
    $activeConfigKeys = [];

    foreach ($files as $file) {
      $file = (array) $file;
      $filename = (string) ($file['filename'] ?? '');
      $data = (array) ($file['data'] ?? []);
      if ($filename === '') {
        continue;
      }
      $identity = $this->identity->identify($type, $data, $filename);
      $configKey = (string) $identity['config_key'];
      $activeConfigKeys[$configKey] = TRUE;
      $matches = $this->matchingSelectors($selectors, $file, $identity);
      foreach ($matches as $selector) {
        $matchedSelectors[$selector] = $configKey;
      }

      if ($mode === self::MODE_ALL) {
        $managed[] = $file;
        $managedKeys[$configKey] = TRUE;
        continue;
      }
      if ($mode === self::MODE_IGNORE) {
        $ignored[] = $file;
        continue;
      }
      if ($mode === self::MODE_WATCH) {
        $watched[] = $file;
        continue;
      }

      // Selected scope is always driven by the *current* configured selectors.
      // Local resolved mappings and manifest selector aliases make numeric
      // source selectors portable across environments. Do not automatically
      // include every historical manifest config_key here: removing a selector
      // must immediately stop managing that object without requiring a cleanup
      // export first.
      $selected = !empty($matches) || isset($resolvedKeys[$configKey]) || isset($manifestResolvedKeys[$configKey]);
      if ($selected) {
        $managed[] = $file;
        $managedKeys[$configKey] = TRUE;
      }
      elseif ($policy['watch_unmanaged']) {
        $watched[] = $file;
      }
      else {
        $ignored[] = $file;
      }
    }

    $unresolved = [];
    $missing = [];
    $selectorConfigKeys = [];
    foreach ($selectors as $selector) {
      if (isset($matchedSelectors[$selector])) {
        $selectorConfigKeys[$selector] = (string) $matchedSelectors[$selector];
        continue;
      }

      $resolvedKey = NULL;
      if (isset($resolved[$selector])) {
        $resolvedKey = (string) $resolved[$selector];
      }
      elseif (isset($manifestResolved[$selector])) {
        $resolvedKey = (string) $manifestResolved[$selector];
      }

      if ($resolvedKey !== NULL && $resolvedKey !== '') {
        $selectorConfigKeys[$selector] = $resolvedKey;
        // Keep the previously resolved portable key for a configured selector
        // whose active object is currently missing. This protects YAML backup
        // continuity. If the same semantic key exists on this environment under
        // a different numeric ID, it is already present in activeConfigKeys and
        // is therefore not reported as missing.
        $managedKeys[$resolvedKey] = TRUE;
        if (!isset($activeConfigKeys[$resolvedKey])) {
          $missing[] = $selector;
        }
        continue;
      }
      $unresolved[] = $selector;
    }

    return [
      'policy' => $policy,
      'managed' => $managed,
      'watched' => $watched,
      'ignored' => $ignored,
      'managed_config_keys' => array_keys($managedKeys),
      'matched_selectors' => $matchedSelectors,
      'selector_config_keys' => $selectorConfigKeys,
      'unresolved_selectors' => $unresolved,
      'missing_selectors' => $missing,
    ];
  }

  /**
   * Keep only YAML documents that belong to the effective selected scope.
   *
   * @param array<string,array<string,mixed>> $files
   * @param array<int,string> $managedKeys
   * @return array<string,array<string,mixed>>
   */
  public function filterYamlFiles(string $type, array $files, array $managedKeys): array {
    $mode = $this->getPolicy($type)['mode'];
    if ($mode === self::MODE_ALL) {
      return $files;
    }
    if ($mode !== self::MODE_SELECTED) {
      return [];
    }
    $wanted = array_fill_keys(array_values(array_unique(array_filter(array_map('strval', $managedKeys)))), TRUE);
    if (!$wanted) {
      return [];
    }
    $filtered = [];
    foreach ($files as $filename => $data) {
      $identity = $this->identity->identify($type, (array) $data, (string) $filename);
      if (isset($wanted[(string) $identity['config_key']])) {
        $filtered[$filename] = $data;
      }
    }
    return $filtered;
  }

  public function persistResolvedMatches(string $type, array $partition): void {
    $policy = (array) ($partition['policy'] ?? $this->getPolicy($type));
    $selectors = array_values(array_unique(array_filter(array_map('strval', (array) ($policy['selectors'] ?? [])))));
    $existing = $this->getAllResolvedSelectorMaps();
    if (($policy['mode'] ?? self::MODE_ALL) !== self::MODE_SELECTED) {
      unset($existing[$type]);
      \Civi::settings()->set('civicfg_scope_resolved', $existing);
      return;
    }

    $previous = (array) ($existing[$type] ?? []);
    $next = [];
    foreach ($selectors as $selector) {
      if (!empty($partition['matched_selectors'][$selector])) {
        $next[$selector] = (string) $partition['matched_selectors'][$selector];
      }
      elseif (!empty($previous[$selector])) {
        $next[$selector] = (string) $previous[$selector];
      }
    }
    if ($next) {
      ksort($next, SORT_NATURAL | SORT_FLAG_CASE);
      $existing[$type] = $next;
    }
    else {
      unset($existing[$type]);
    }
    ksort($existing, SORT_NATURAL | SORT_FLAG_CASE);
    \Civi::settings()->set('civicfg_scope_resolved', $existing);
  }

  public function portableSelectorMapFromManifest(array $manifest, string $type): array {
    $row = (array) (($manifest['managed_scope'] ?? [])[$type] ?? []);
    $raw = (array) ($row['selector_map'] ?? []);
    $clean = [];
    foreach ($raw as $selector => $configKey) {
      if (!is_scalar($configKey)) {
        continue;
      }
      $selector = trim((string) $selector);
      $configKey = trim((string) $configKey);
      if ($selector !== '' && $configKey !== '') {
        $clean[$selector] = $configKey;
      }
    }
    ksort($clean, SORT_NATURAL | SORT_FLAG_CASE);
    return $clean;
  }

  public function manifestEntry(string $type, array $partition): array {
    $policy = (array) ($partition['policy'] ?? $this->getPolicy($type));
    $mode = (string) ($policy['mode'] ?? self::MODE_ALL);
    if ($mode === self::MODE_ALL) {
      return ['mode' => self::MODE_ALL];
    }
    if ($mode !== self::MODE_SELECTED) {
      return ['mode' => $mode];
    }
    $keys = array_values(array_unique(array_filter(array_map('strval', (array) ($partition['managed_config_keys'] ?? [])))));
    sort($keys, SORT_STRING);
    $selectorMap = [];
    foreach ((array) ($partition['selector_config_keys'] ?? []) as $selector => $configKey) {
      $selector = trim((string) $selector);
      $configKey = is_scalar($configKey) ? trim((string) $configKey) : '';
      if ($selector !== '' && $configKey !== '') {
        $selectorMap[$selector] = $configKey;
      }
    }
    ksort($selectorMap, SORT_NATURAL | SORT_FLAG_CASE);
    return [
      'mode' => self::MODE_SELECTED,
      'config_keys' => $keys,
      'selector_map' => $selectorMap,
    ];
  }

  public function selectorHelp(): string {
    return 'Choose items by name in the UI. Advanced selectors accept a local numeric ID (source-site bootstrap only), id:123, a stable name/key, key:<portable config key>, or path:<full relative YAML path>.';
  }

  private function normalisePolicy(array $policy): array {
    $mode = strtolower(trim((string) ($policy['mode'] ?? self::MODE_ALL)));
    if (!in_array($mode, [self::MODE_ALL, self::MODE_SELECTED, self::MODE_WATCH, self::MODE_IGNORE], TRUE)) {
      $mode = self::MODE_ALL;
    }
    $selectors = $policy['selectors'] ?? ($policy['managed_ids'] ?? []);
    if (is_string($selectors)) {
      $selectors = preg_split('/[\r\n,]+/', $selectors) ?: [];
    }
    $selectors = array_values(array_unique(array_filter(array_map(function($value) {
      return trim((string) $value);
    }, is_array($selectors) ? $selectors : []), 'strlen')));

    return [
      'mode' => $mode,
      'selectors' => $selectors,
      'watch_unmanaged' => !empty($policy['watch_unmanaged']),
    ];
  }

  private function getAllResolvedSelectorMaps(): array {
    $raw = \Civi::settings()->get('civicfg_scope_resolved');
    return is_array($raw) ? $raw : [];
  }

  private function getResolvedSelectorMap(string $type, array $selectors): array {
    $all = $this->getAllResolvedSelectorMaps();
    $current = (array) ($all[$type] ?? []);
    $allowed = array_fill_keys($selectors, TRUE);
    $resolved = [];
    foreach ($current as $selector => $configKey) {
      $selector = (string) $selector;
      $configKey = is_scalar($configKey) ? trim((string) $configKey) : '';
      if ($configKey !== '' && isset($allowed[$selector])) {
        $resolved[$selector] = $configKey;
      }
    }
    return $resolved;
  }

  private function matchingSelectors(array $selectors, array $file, array $identity): array {
    if (!$selectors) {
      return [];
    }
    $aliases = $this->selectorAliases($file, $identity);
    $matches = [];
    foreach ($selectors as $selector) {
      $selector = trim((string) $selector);
      if ($selector === '') {
        continue;
      }
      if (isset($aliases[$selector])) {
        $matches[] = $selector;
        continue;
      }
      $lower = strtolower($selector);
      foreach ($aliases as $alias => $unused) {
        if (strtolower((string) $alias) === $lower) {
          $matches[] = $selector;
          break;
        }
      }
    }
    return array_values(array_unique($matches));
  }

  private function selectorAliases(array $file, array $identity): array {
    $aliases = [];
    $add = static function($value, string $prefix = '') use (&$aliases): void {
      if (!is_scalar($value)) {
        return;
      }
      $value = trim((string) $value);
      if ($value === '') {
        return;
      }
      $aliases[$value] = TRUE;
      if ($prefix !== '') {
        $aliases[$prefix . ':' . $value] = TRUE;
      }
    };

    $add((string) ($identity['config_key'] ?? ''), 'key');
    $relativePath = (string) ($file['relative_path'] ?? ($file['filename'] ?? ''));
    $add($relativePath, 'path');
    if (array_key_exists('source_id', $file)) {
      $add($file['source_id'], 'id');
    }

    $data = (array) ($file['data'] ?? []);
    foreach (['key', 'name', 'title', 'label'] as $field) {
      if (array_key_exists($field, $data)) {
        $add($data[$field], $field);
      }
    }
    foreach (['item', 'template', 'group', 'extension', 'processor', 'financial_type', 'rule', 'token'] as $container) {
      $row = (array) ($data[$container] ?? []);
      foreach (['key', 'machine_name', 'name', 'name_a_b', 'workflow_name', 'msg_title', 'title', 'label'] as $field) {
        if (array_key_exists($field, $row)) {
          $add($row[$field], $field);
        }
      }
    }
    return $aliases;
  }
}
