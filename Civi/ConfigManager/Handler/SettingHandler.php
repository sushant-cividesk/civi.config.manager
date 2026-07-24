<?php
namespace Civi\ConfigManager\Handler;

class SettingHandler extends AbstractHandler {
  public function getType(): string { return 'settings'; }
  public function getLabel(): string { return 'CiviCRM Settings Allowlist'; }
  public function getDirectory(): string { return 'settings'; }
  public function getWeight(): int { return 80; }

  public function export(): array {
    $configuredAllowlist = (array) \Civi::settings()->get('civicfg_settings_allowlist');
    $allowlist = [];
    $items = [];
    foreach ($configuredAllowlist as $name) {
      if (!is_string($name) || $name === '' || !$this->isSafeSettingName($name) || $this->isSensitiveSettingName($name)) {
        continue;
      }
      $allowlist[] = $name;
      $items[$name] = \Civi::settings()->get($name);
    }
    $allowlist = array_values(array_unique($allowlist));
    sort($allowlist);
    ksort($items);
    return [[
      'filename' => 'civicrm.settings.yml',
      'data' => [
        'schema_version' => 1,
        'type' => 'settings.allowlist',
        'dependencies' => [],
        'allowlist' => $allowlist,
        'items' => $items,
      ],
    ]];
  }

  public function validate(array $items): array {
    $errors = [];
    $warnings = [];
    foreach ($items as $filename => $file) {
      if (($file['type'] ?? '') !== 'settings.allowlist') {
        $errors[] = ['file' => $filename, 'message' => 'Invalid type. Expected settings.allowlist.'];
        continue;
      }
      foreach (($file['items'] ?? []) as $name => $value) {
        $name = (string) $name;
        if (!$this->isSafeSettingName($name)) {
          $errors[] = ['file' => $filename, 'message' => 'Unsafe setting name: ' . $name];
        }
        elseif ($this->isSensitiveSettingName($name)) {
          $errors[] = ['file' => $filename, 'message' => 'Sensitive settings cannot be managed in YAML: ' . $name];
        }
      }
      foreach (($file['allowlist'] ?? []) as $name) {
        $name = (string) $name;
        if ($this->isSensitiveSettingName($name)) {
          $warnings[] = ['file' => $filename, 'message' => 'Sensitive setting was removed from the managed allowlist: ' . $name];
        }
      }
    }
    return ['type' => $this->getType(), 'valid' => empty($errors), 'warnings' => $warnings, 'errors' => $errors, 'count' => count($items)];
  }

  public function import(array $items, bool $dryRun = TRUE): array {
    $summary = $this->baseImportSummary($dryRun);
    foreach ($items as $filename => $file) {
      if (($file['type'] ?? '') !== 'settings.allowlist') {
        $summary['errors'][] = ['file' => $filename, 'message' => 'Invalid type. Expected settings.allowlist.'];
        continue;
      }
      $settings = (array) ($file['items'] ?? []);
      foreach ($settings as $name => $value) {
        $name = (string) $name;
        if (!$this->isSafeSettingName($name)) {
          $summary['errors'][] = ['file' => $filename, 'message' => 'Unsafe setting name: ' . $name];
          continue;
        }
        if ($this->isSensitiveSettingName($name)) {
          $summary['errors'][] = ['file' => $filename, 'message' => 'Sensitive settings cannot be imported from YAML: ' . $name];
          continue;
        }
        $current = \Civi::settings()->get($name);
        if ($this->normaliseComparableValue($current) !== $this->normaliseComparableValue($value)) {
          $summary['update']++;
          if (!$dryRun) {
            \Civi::settings()->set($name, $value);
          }
        }
        else {
          $summary['skip']++;
        }
      }
      if (array_key_exists('allowlist', $file) && is_array($file['allowlist'])) {
        $allowlist = array_values(array_filter(array_map('strval', $file['allowlist']), function(string $name): bool {
          return $this->isSafeSettingName($name) && !$this->isSensitiveSettingName($name);
        }));
        $allowlist = array_values(array_unique($allowlist));
        $currentAllowlist = (array) \Civi::settings()->get('civicfg_settings_allowlist');
        $currentAllowlist = array_values(array_filter(array_map('strval', $currentAllowlist), function(string $name): bool {
          return $this->isSafeSettingName($name) && !$this->isSensitiveSettingName($name);
        }));
        sort($allowlist);
        sort($currentAllowlist);
        if ($allowlist !== $currentAllowlist) {
          $summary['update']++;
          if (!$dryRun) {
            \Civi::settings()->set('civicfg_settings_allowlist', $allowlist);
          }
        }
      }
    }
    $summary['ok'] = empty($summary['errors']);
    return $summary;
  }

  private function isSafeSettingName(string $name): bool {
    return (bool) preg_match('/^[A-Za-z0-9_.:-]+$/', $name);
  }

  private function isSensitiveSettingName(string $name): bool {
    $normalised = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name);
    return (bool) preg_match('/(?:password|passwd|secret|token|credential|(?:^|[_.:-])key(?:$|[_.:-])|(?:api|private|access|auth|signing|encryption|consumer)[_-]?key)/i', (string) $normalised);
  }
}
