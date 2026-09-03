<?php
namespace Civi\ConfigManager\Handler;

class SettingHandler extends AbstractHandler {
  public function getType(): string { return 'settings'; }
  public function getLabel(): string { return 'CiviCRM Settings'; }
  public function getDirectory(): string { return 'settings'; }
  public function getWeight(): int { return 80; }

  public function getProviderMetadata(): array {
    return [
      'owner' => 'civi.config.manager', 'api_version' => 'settings_service', 'entity' => 'Setting',
      'actions' => ['read' => TRUE, 'create' => TRUE, 'update' => TRUE, 'delete' => FALSE],
      'field_names' => ['name','value'], 'identity_fields' => ['name'], 'reference_fields' => [],
      'sensitive_fields' => ['dynamic_name_policy'], 'runtime_fields' => [], 'management_capability' => 'managed_no_delete',
      'identity_evidence' => 'explicit_allowlist', 'metadata_completeness' => 'declared_policy',
    ];
  }

  public function export(): array {
    $files = [];
    foreach ($this->configuredAllowlist() as $name) {
      $files[] = [
        'filename' => $this->safeName($name) . '.yml',
        'data' => [
          'schema_version' => 1,
          'type' => 'setting.item',
          'name' => $name,
          'identity_field' => 'name',
          'identity_confidence' => 'EXPLICIT',
          'dependencies' => [],
          'item' => [
            'name' => $name,
            'value' => \Civi::settings()->get($name),
          ],
        ],
      ];
    }
    usort($files, fn($a, $b) => strcmp((string) $a['filename'], (string) $b['filename']));
    return $files;
  }

  public function validate(array $items): array {
    $errors = [];
    $warnings = [];
    $allowlist = array_fill_keys($this->configuredAllowlist(), TRUE);
    foreach ($items as $filename => $file) {
      $type = (string) ($file['type'] ?? '');
      if ($type === 'settings.allowlist') {
        $this->validateLegacyCollection((string) $filename, (array) $file, $errors, $warnings);
        continue;
      }
      if ($type !== 'setting.item') {
        $errors[] = ['file' => $filename, 'message' => 'Invalid type. Expected setting.item.'];
        continue;
      }

      $item = (array) ($file['item'] ?? []);
      $name = (string) ($item['name'] ?? ($file['name'] ?? ''));
      if ($name === '' || !$this->isSafeSettingName($name)) {
        $errors[] = ['file' => $filename, 'message' => 'Setting item is missing a safe setting name.'];
        continue;
      }
      if ($this->isSensitiveSettingName($name)) {
        $errors[] = ['file' => $filename, 'message' => 'Sensitive settings cannot be managed in YAML: ' . $name];
        continue;
      }
      if (!isset($allowlist[$name])) {
        $errors[] = ['file' => $filename, 'message' => 'Setting is not in the local Configuration Manager Settings Allowlist: ' . $name];
      }
      if (!array_key_exists('value', $item)) {
        $errors[] = ['file' => $filename, 'message' => 'Setting item is missing value: ' . $name];
      }
      if (!empty($file['name']) && (string) $file['name'] !== $name) {
        $errors[] = ['file' => $filename, 'message' => 'Top-level setting name and item.name do not match.'];
      }
    }
    return ['type' => $this->getType(), 'valid' => empty($errors), 'warnings' => $warnings, 'errors' => $errors, 'count' => count($items)];
  }

  public function import(array $items, bool $dryRun = TRUE): array {
    $summary = $this->baseImportSummary($dryRun);
    $allowlist = array_fill_keys($this->configuredAllowlist(), TRUE);
    foreach ($items as $filename => $file) {
      $type = (string) ($file['type'] ?? '');
      if ($type === 'settings.allowlist') {
        $this->importLegacyCollection((string) $filename, (array) $file, $dryRun, $summary);
        continue;
      }
      if ($type !== 'setting.item') {
        $summary['errors'][] = ['file' => $filename, 'message' => 'Invalid type. Expected setting.item.'];
        continue;
      }

      $item = (array) ($file['item'] ?? []);
      $name = (string) ($item['name'] ?? ($file['name'] ?? ''));
      if ($name === '' || !$this->isSafeSettingName($name)) {
        $summary['errors'][] = ['file' => $filename, 'message' => 'Setting item is missing a safe setting name.'];
        continue;
      }
      if ($this->isSensitiveSettingName($name)) {
        $summary['errors'][] = ['file' => $filename, 'message' => 'Sensitive settings cannot be imported from YAML: ' . $name];
        continue;
      }
      if (!isset($allowlist[$name])) {
        $summary['errors'][] = ['file' => $filename, 'message' => 'Setting is not in the local Configuration Manager Settings Allowlist: ' . $name];
        continue;
      }
      if (!array_key_exists('value', $item)) {
        $summary['errors'][] = ['file' => $filename, 'message' => 'Setting item is missing value: ' . $name];
        continue;
      }

      $value = $item['value'];
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
    $summary['ok'] = empty($summary['errors']);
    return $summary;
  }

  private function validateLegacyCollection(string $filename, array $file, array &$errors, array &$warnings): void {
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
    $warnings[] = ['file' => $filename, 'message' => 'Legacy settings collection format is accepted for import. Re-export to create one YAML file per setting for selective scope management.'];
  }

  private function importLegacyCollection(string $filename, array $file, bool $dryRun, array &$summary): void {
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

    // Transitional compatibility only: older alpha YAML carried the allowlist
    // inside the portable file. Current exports keep this local policy out of
    // YAML and write one setting per file instead.
    if (array_key_exists('allowlist', $file) && is_array($file['allowlist'])) {
      $allowlist = array_values(array_filter(array_map('strval', $file['allowlist']), function(string $name): bool {
        return $this->isSafeSettingName($name) && !$this->isSensitiveSettingName($name);
      }));
      $allowlist = array_values(array_unique($allowlist));
      $currentAllowlist = $this->configuredAllowlist();
      sort($allowlist, SORT_NATURAL | SORT_FLAG_CASE);
      if ($allowlist !== $currentAllowlist) {
        $summary['update']++;
        if (!$dryRun) {
          \Civi::settings()->set('civicfg_settings_allowlist', $allowlist);
        }
      }
    }
  }

  private function configuredAllowlist(): array {
    $configured = (array) \Civi::settings()->get('civicfg_settings_allowlist');
    $allowlist = [];
    foreach ($configured as $name) {
      if (!is_string($name) || $name === '' || !$this->isSafeSettingName($name) || $this->isSensitiveSettingName($name)) {
        continue;
      }
      $allowlist[] = $name;
    }
    $allowlist = array_values(array_unique($allowlist));
    sort($allowlist, SORT_NATURAL | SORT_FLAG_CASE);
    return $allowlist;
  }

  private function safeName(string $name): string {
    $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name);
    return trim((string) $safe, '-') ?: sha1($name);
  }

  private function isSafeSettingName(string $name): bool {
    return (bool) preg_match('/^[A-Za-z0-9_.:-]+$/', $name);
  }

  private function isSensitiveSettingName(string $name): bool {
    $normalised = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name);
    return (bool) preg_match('/(?:password|passwd|secret|token|credential|(?:^|[_.:-])key(?:$|[_.:-])|(?:api|private|access|auth|signing|encryption|consumer)[_-]?key)/i', (string) $normalised);
  }
}
