<?php
namespace Civi\ConfigManager\UI;

use Civi\ConfigManager\Service\ConfigManager;

/**
 * Converts service/API data into template-friendly rows, labels, and summaries.
 */
class Presenter {

  public function buildTabs(string $op): array {
    $tabs = [
      'sync' => ts('Synchronize'),
      'import' => ts('Import'),
      'export' => ts('Export'),
      'settings' => ts('Settings'),
    ];
    $rows = [];
    foreach ($tabs as $key => $label) {
      if ($key === 'import' && !Permission::has(Permission::IMPORT)) {
        continue;
      }
      if ($key === 'export' && !Permission::has(Permission::EXPORT)) {
        continue;
      }
      if ($key === 'settings' && !Permission::has(Permission::ADMINISTER)) {
        continue;
      }
      $rows[] = [
        'key' => $key,
        'label' => $label,
        'active' => $op === $key,
        'url' => \CRM_Utils_System::url('civicrm/admin/config-manager', 'reset=1&op=' . $key, FALSE, NULL, FALSE),
      ];
    }
    return $rows;
  }

  public function buildTypeRows(ConfigManager $manager, array $result): array {
    $diffByType = [];
    if (!empty($result['items']) && is_array($result['items'])) {
      foreach ($result['items'] as $item) {
        if (!empty($item['type'])) {
          $diffByType[$item['type']] = $item;
        }
      }
    }

    $rows = [];
    foreach ($manager->getManagedTypeOptions() as $option) {
      $type = (string) $option['type'];
      $baseType = (string) ($option['base_type'] ?? $type);
      $diff = $diffByType[$baseType] ?? [];
      if (!empty($option['virtual'])) {
        $counts = $this->countsForVirtualType($type, $diff);
        $dbCount = NULL;
        $fileCount = NULL;
        $status = ($counts['changed'] || $counts['new'] || $counts['missing']) ? 'changed' : ($diff['status'] ?? NULL);
      }
      else {
        $counts = [
          'changed' => !empty($diff['changed']) ? count($diff['changed']) : 0,
          'new' => !empty($diff['new_in_db']) ? count($diff['new_in_db']) : 0,
          'missing' => !empty($diff['missing_in_db']) ? count($diff['missing_in_db']) : 0,
        ];
        $dbCount = $diff['db_count'] ?? NULL;
        $fileCount = $diff['file_count'] ?? ($diff['count'] ?? NULL);
        $status = $diff['status'] ?? NULL;
      }
      $rows[] = [
        'type' => $type,
        'base_type' => $baseType,
        'label' => (string) $option['label'],
        'directory' => (string) ($option['directory'] ?? ''),
        'weight' => (int) ($option['weight'] ?? 0),
        'virtual' => !empty($option['virtual']),
        'provider' => (string) ($option['provider'] ?? ''),
        'status' => $status,
        'dbCount' => $dbCount,
        'fileCount' => $fileCount,
        'changedCount' => $counts['changed'],
        'newCount' => $counts['new'],
        'missingCount' => $counts['missing'],
        'valid' => $diff['valid'] ?? NULL,
        'statusUrl' => \CRM_Utils_System::url('civicrm/admin/config-manager', 'reset=1&op=sync&type=' . rawurlencode($type), FALSE, NULL, FALSE),
      ];
    }
    return $rows;
  }

  private function countsForVirtualType(string $type, array $diff): array {
    $counts = ['changed' => 0, 'new' => 0, 'missing' => 0];
    foreach (($diff['files'] ?? []) as $file) {
      $path = (string) ($file['path'] ?? '');
      if (!$this->pathMatchesVirtualType($path, $type)) {
        continue;
      }
      $status = (string) ($file['status'] ?? 'changed');
      if ($status === 'new_in_db') {
        $counts['new']++;
      }
      elseif ($status === 'missing_in_db') {
        $counts['missing']++;
      }
      else {
        $counts['changed']++;
      }
    }
    return $counts;
  }

  private function pathMatchesVirtualType(string $path, string $type): bool {
    $parts = explode(':', $type);
    if (count($parts) !== 4 || $parts[0] !== 'extensions') {
      return FALSE;
    }
    $prefix = 'extensions/' . $parts[1] . '/' . $parts[2] . '/' . $parts[3] . '/';
    return strpos($path, $prefix) === 0;
  }

  public function buildSummary(array $result, array $status, string $op): array {
    $changed = 0;
    $new = 0;
    $missing = 0;
    $warnings = 0;
    if (!empty($result['items']) && is_array($result['items'])) {
      foreach ($result['items'] as $item) {
        $changed += !empty($item['changed']) ? count($item['changed']) : 0;
        $new += !empty($item['new_in_db']) ? count($item['new_in_db']) : 0;
        $missing += !empty($item['missing_in_db']) ? count($item['missing_in_db']) : 0;
        $warnings += !empty($item['warnings']) ? count($item['warnings']) : 0;
      }
    }

    return [
      'ok' => ($result['ok'] ?? FALSE) && ($status['ok'] ?? FALSE),
      'sync_dir' => $status['sync_dir'] ?? ($result['sync_dir'] ?? NULL),
      'planned_count' => !empty($result['planned']) && is_array($result['planned']) ? count($result['planned']) : 0,
      'written_count' => !empty($result['written']) && is_array($result['written']) ? count($result['written']) : 0,
      'skipped_count' => !empty($result['skipped']) && is_array($result['skipped']) ? count($result['skipped']) : 0,
      'error_count' => !empty($result['errors']) && is_array($result['errors']) ? count($result['errors']) : 0,
      'warning_count' => $warnings,
      'item_count' => !empty($result['items']) && is_array($result['items']) ? count($result['items']) : 0,
      'changed_count' => $changed,
      'new_count' => $new,
      'missing_count' => $missing,
      'total_changes' => $changed + $new + $missing,
      'exists' => $status['exists'] ?? NULL,
      'writable' => $status['writable'] ?? NULL,
      'op' => $op,
    ];
  }


  public function labelsForTypes(ConfigManager $manager, array $types): array {
    if (!$types) {
      return [];
    }
    $wanted = array_fill_keys(array_map('strval', $types), TRUE);
    $labels = [];
    foreach ($manager->getManagedTypeOptions() as $row) {
      $type = (string) $row['type'];
      if (isset($wanted[$type])) {
        $labels[$type] = (string) $row['label'];
      }
    }
    return $labels;
  }

  public function extractDiffFiles(array $result): array {
    $files = [];
    if (empty($result['items']) || !is_array($result['items'])) {
      return $files;
    }
    $i = 0;
    foreach ($result['items'] as $item) {
      $renameByConfigKey = [];
      foreach ((array) ($item['possible_renames'] ?? []) as $candidate) {
        if (!is_array($candidate)) {
          continue;
        }
        foreach (['old_config_key', 'new_config_key'] as $keyField) {
          $configKey = (string) ($candidate[$keyField] ?? '');
          if ($configKey !== '') {
            $renameByConfigKey[$configKey] = $candidate;
          }
        }
      }
      foreach (($item['files'] ?? []) as $file) {
        $file['id'] = 'civicfg-diff-' . (++$i);
        $file['type'] = $item['type'] ?? '';
        $file['type_label'] = $item['label'] ?? ($item['type'] ?? '');
        $file['status_label'] = $this->statusLabel((string) ($file['status'] ?? 'changed'));
        $file['plain_status_label'] = $this->plainStatusLabel((string) ($file['status'] ?? 'changed'));
        $file['sync_state_label'] = $this->syncStateLabel((string) ($file['sync_state'] ?? ''), (string) ($file['merge_state'] ?? ''));
        $configKey = (string) ($file['config_key'] ?? '');
        $file['possible_rename'] = $configKey !== '' ? ($renameByConfigKey[$configKey] ?? NULL) : NULL;
        $file['display_title'] = $this->displayTitleForFile($file);
        $file['rows'] = [];
        foreach (($file['changes'] ?? []) as $change) {
          $changePath = (string) ($change['path'] ?? 'value');
          $label = $this->humanizeChangePath($changePath);
          $oldText = $this->formatChangeValue($change['old'] ?? NULL, $change['new'] ?? NULL);
          $newText = $this->formatChangeValue($change['new'] ?? NULL, $change['old'] ?? NULL);
          $sentence = $this->isTechnicalChangePath($changePath)
            ? ''
            : $this->describeFieldChange($file, (array) $change, $label, (string) ($change['type'] ?? 'changed'), $oldText, $newText);
          $file['rows'][] = [
            'label' => $label,
            'path' => (string) ($change['path'] ?? 'value'),
            'old' => $oldText,
            'new' => $newText,
            'old_html' => $this->formatChangeValueHtml($change['old'] ?? NULL, $change['new'] ?? NULL),
            'new_html' => $this->formatChangeValueHtml($change['new'] ?? NULL, $change['old'] ?? NULL),
            'type' => (string) ($change['type'] ?? 'changed'),
            'sentence' => $sentence,
          ];
        }
        $file['detail_sentences'] = array_slice(array_values(array_unique(array_filter(array_map(static fn($row) => (string) ($row['sentence'] ?? ''), $file['rows'])))), 0, 3);
        $file['summary_sentence'] = $this->describeFileChange($file);
        $files[] = $file;
      }
    }
    return $files;
  }

  public function buildImportPlan(array $diffFiles): array {
    $plan = [];
    foreach ($diffFiles as $file) {
      $status = (string) ($file['status'] ?? 'changed');
      $type = (string) ($file['type'] ?? '');
      $possibleRename = !empty($file['possible_rename']) && is_array($file['possible_rename']) ? $file['possible_rename'] : NULL;
      $deleteBlocked = $status === 'new_in_db' && empty($file['delete_allowed']);
      $providerWriteBlocked = $type === 'extensions' && empty($file['write_safe']);
      $importable = in_array($type, $this->getImportableTypes(), TRUE)
        && $possibleRename === NULL
        && !$deleteBlocked
        && !$providerWriteBlocked;
      $plan[] = [
        'file' => $file['file'] ?? '',
        'path' => $file['path'] ?? '',
        'type' => $type,
        'type_label' => $file['type_label'] ?? $type,
        'status' => $status,
        'change_count' => $file['change_count'] ?? 0,
        'rows' => $file['rows'] ?? [],
        'detail_sentences' => $file['detail_sentences'] ?? [],
        'summary_sentence' => $file['summary_sentence'] ?? '',
        'possible_rename' => $possibleRename,
        'importable' => $importable,
        'action' => $possibleRename
          ? ts('Review rename')
          : ($providerWriteBlocked
            ? ts('Backup only')
            : ($deleteBlocked ? ts('Export to YAML') : $this->importActionLabel($status))),
        'status_label' => $this->statusLabel($status),
        'note' => $possibleRename
          ? ts('Possible identity rename detected. Configuration Manager will not apply this create/delete pair automatically; review and align the accepted identity first.')
          : ($providerWriteBlocked
            ? ts('This contributed configuration is safe to back up and compare, but its provider does not expose a write-safe portable identity on this site. Automatic restore is disabled.')
            : ($deleteBlocked
              ? ts('This item is in selected scope but has no managed YAML yet. Selective scope never treats missing YAML as permission to delete it; export it if you want to keep managing it.')
              : $this->importActionNote($status, $importable))),
      ];
    }
    return $plan;
  }

  public function statusLabel(string $status): string {
    if ($status === 'missing_in_db') {
      return ts('Missing from CiviCRM');
    }
    if ($status === 'new_in_db') {
      return ts('New in CiviCRM');
    }
    if ($status === 'changed') {
      return ts('Changed');
    }
    return ts('In Sync');
  }

  private function plainStatusLabel(string $status): string {
    if ($status === 'missing_in_db') {
      return ts('is managed in YAML but is missing from CiviCRM');
    }
    if ($status === 'new_in_db') {
      return ts('is new in CiviCRM and is not in managed YAML');
    }
    if ($status === 'changed') {
      return ts('has changed between YAML and CiviCRM');
    }
    return ts('is in sync');
  }

  private function syncStateLabel(string $state, string $mergeState = ''): string {
    if ($state === 'ACTIVE_DRIFT') {
      return ts('Active drift');
    }
    if ($state === 'YAML_CHANGE') {
      return ts('YAML change');
    }
    if ($state === 'BOTH_CHANGED' && $mergeState === 'CONFLICT') {
      return ts('Conflict');
    }
    if ($state === 'BOTH_CHANGED') {
      return ts('Both changed');
    }
    return '';
  }

  private function describeFileChange(array $file): string {
    $status = (string) ($file['status'] ?? 'changed');
    $title = (string) ($file['display_title'] ?? $this->displayTitleForFile($file));
    $possibleRename = !empty($file['possible_rename']) && is_array($file['possible_rename']) ? $file['possible_rename'] : NULL;
    if ($possibleRename) {
      return ts('Possible identity rename detected. Review the old and new identities before applying anything automatically.');
    }

    $syncState = (string) ($file['sync_state'] ?? '');
    $mergeState = (string) ($file['merge_state'] ?? '');
    if ($status === 'new_in_db') {
      return ts('%1 is new in CiviCRM and is not in managed YAML yet.', [1 => $title]);
    }
    if ($status === 'missing_in_db') {
      return ts('%1 is managed in YAML but is currently missing from CiviCRM.', [1 => $title]);
    }
    if ($syncState === 'ACTIVE_DRIFT') {
      return ts('%1 changed in CiviCRM since the last accepted sync.', [1 => $title]);
    }
    if ($syncState === 'YAML_CHANGE') {
      return ts('%1 changed in YAML since the last accepted sync.', [1 => $title]);
    }
    if ($syncState === 'BOTH_CHANGED' && $mergeState === 'CONFLICT') {
      return ts('%1 changed in both CiviCRM and YAML, with at least one conflicting field.', [1 => $title]);
    }
    if ($syncState === 'BOTH_CHANGED') {
      return ts('%1 changed in both CiviCRM and YAML.', [1 => $title]);
    }
    return ts('%1 has configuration changes to review.', [1 => $title]);
  }

  private function describeFieldChange(array $file, array $change, string $label, string $changeType, string $yamlValue, string $civiValue): string {
    $path = (string) ($change['path'] ?? '');
    if ($path === 'extension.status' && $changeType === 'changed') {
      return ts('Extension state: YAML %1 → CiviCRM %2.', [
        1 => $this->friendlyExtensionStatus($yamlValue),
        2 => $this->friendlyExtensionStatus($civiValue),
      ]);
    }

    $field = $this->friendlyFieldLabel($path, $label);
    if ($changeType === 'added') {
      return ts('%1 was added in CiviCRM.', [1 => $field]);
    }
    if ($changeType === 'removed') {
      return ts('%1 exists in YAML but is missing from CiviCRM.', [1 => $field]);
    }
    return ts('%1 changed.', [1 => $field]);
  }

  private function friendlyExtensionStatus(string $status): string {
    $status = strtolower(trim($status));
    $map = [
      'enabled' => ts('Enabled'),
      'installed' => ts('Installed but disabled'),
      'disabled' => ts('Installed but disabled'),
      'uninstalled' => ts('Not installed'),
      'missing' => ts('Not available'),
    ];
    return (string) ($map[$status] ?? ucfirst($status));
  }

  private function isTechnicalChangePath(string $path): bool {
    $root = preg_replace('/[.\[].*$/', '', trim($path));
    return in_array($root, [
      'api', 'capabilities', 'dependencies', 'schema_version', 'type', 'entity',
      'identity_field', 'identity_key', 'identity_confidence', 'required_by',
      'config_index',
    ], TRUE);
  }

  private function friendlyFieldLabel(string $path, string $fallback): string {
    $map = [
      'template.msg_subject' => 'Subject',
      'template.msg_html' => 'HTML message',
      'template.msg_text' => 'Plain-text message',
      'template.msg_title' => 'Template title',
      'template.is_active' => 'Enabled',
      'template.is_default' => 'Default template',
      'template.is_reserved' => 'System template',
      'item.is_active' => 'Enabled',
      'item.run_frequency' => 'Run frequency',
      'item.scheduled_run_date' => 'Next scheduled run',
      'item.parameters' => 'Parameters',
      'extension.status' => 'Extension state',
      'item.value' => 'Value',
      'item.description' => 'Description',
      'item.label' => 'Label',
      'item.title' => 'Title',
      'item.name' => 'Name',
      'extension.status' => 'Extension state',
    ];
    if (isset($map[$path])) {
      return $map[$path];
    }
    return ucfirst($this->fieldNameForChange($path, $fallback));
  }

  private function displayTitleForFile(array $file): string {
    $path = (string) ($file['path'] ?? $file['file'] ?? '');
    $typeLabel = (string) ($file['type_label'] ?? $file['type'] ?? 'Configuration');
    $subject = $this->subjectFromFilePath($path, '');
    if ($subject !== '') {
      return $subject;
    }
    $base = preg_replace('/\.ya?ml$/i', '', basename($path));
    $base = $this->humanizeMachineName((string) $base);
    return $base !== '' ? $base : $typeLabel;
  }

  private function subjectForChange(array $file, array $change, string $fallbackLabel): string {
    $path = (string) ($file['path'] ?? $file['file'] ?? '');
    $changePath = (string) ($change['path'] ?? '');
    $typeLabel = (string) ($file['type_label'] ?? $file['type'] ?? 'Configuration');

    if (preg_match('#^contact-types/#', $path) && preg_match('/items\[([^\]]+)\]/', $changePath, $m)) {
      return ts('Contact Type "%1"', [1 => $this->humanizeMachineName($m[1])]);
    }
    if (preg_match('#^relationship-types/#', $path) && preg_match('/items\[([^\]]+)\]/', $changePath, $m)) {
      return ts('Relationship Type "%1"', [1 => $this->humanizeMachineName($m[1])]);
    }
    if (preg_match('#^location-types/#', $path) && preg_match('/items\[([^\]]+)\]/', $changePath, $m)) {
      return ts('Location Type "%1"', [1 => $this->humanizeMachineName($m[1])]);
    }
    if (preg_match('#^option-groups/([^/]+)\.yml$#', $path, $fileMatch) && preg_match('/values\[([^\]]+)\]/', $changePath, $m)) {
      return ts('%1 "%2"', [1 => $this->humanizeMachineName($fileMatch[1]), 2 => $this->humanizeMachineName($m[1])]);
    }
    if (preg_match('#^custom-data/groups/([^/]+)\.yml$#', $path, $m)) {
      return ts('Custom data group "%1"', [1 => $this->humanizeMachineName($m[1])]);
    }
    if (preg_match('#^settings/#', $path) && preg_match('/items\.([^.]+)$/', $changePath, $m)) {
      return ts('CiviCRM setting "%1"', [1 => $this->humanizeMachineName($m[1])]);
    }
    if (preg_match('#^extensions/([^/]+)\.yml$#', $path, $m)) {
      if (preg_match('/^settings\.([^.]+)(?:\.(.+))?$/', $changePath, $setting)) {
        $name = $this->humanizeMachineName($setting[1]);
        if (!empty($setting[2])) {
          $name .= ' › ' . $this->humanizeMachineName(str_replace('.', ' › ', $setting[2]));
        }
        return ts('Extension setting "%1" for %2', [1 => $name, 2 => $m[1]]);
      }
      if (preg_match('/^config_index\[([^\]]+)\]/', $changePath, $idx)) {
        return ts('Extension config index "%1" for %2', [1 => $idx[1], 2 => $m[1]]);
      }
      return ts('Extension "%1"', [1 => $m[1]]);
    }
    if (preg_match('#^extensions/([^/]+)/(api[34])/([^/]+)/([^/]+)\.yml$#', $path, $m)) {
      return ts('%1 "%2" from %3', [1 => $this->humanizeMachineName($m[3]), 2 => $this->humanizeMachineName($m[4]), 3 => $m[1]]);
    }
    if (preg_match('/items\[([^\]]+)\]/', $changePath, $m)) {
      return ts('%1 "%2"', [1 => $typeLabel, 2 => $this->humanizeMachineName($m[1])]);
    }
    if (preg_match('/values\[([^\]]+)\]/', $changePath, $m)) {
      return ts('%1 "%2"', [1 => $typeLabel, 2 => $this->humanizeMachineName($m[1])]);
    }
    return $this->subjectFromFilePath($path, $typeLabel ?: $fallbackLabel);
  }

  private function subjectFromFilePath(string $path, string $fallback): string {
    if (preg_match('#^extensions/([^/]+)/(api[34])/([^/]+)/([^/]+)\.yml$#', $path, $m)) {
      return ts('%1 "%2" from %3', [1 => $this->humanizeMachineName($m[3]), 2 => $this->humanizeMachineName($m[4]), 3 => $m[1]]);
    }
    if (preg_match('#^extensions/([^/]+)\.yml$#', $path, $m)) {
      return ts('Extension "%1"', [1 => $m[1]]);
    }
    if (preg_match('#^option-groups/([^/]+)\.yml$#', $path, $m)) {
      return ts('Option group "%1"', [1 => $this->humanizeMachineName($m[1])]);
    }
    if (preg_match('#^custom-data/groups/([^/]+)\.yml$#', $path, $m)) {
      return ts('Custom data group "%1"', [1 => $this->humanizeMachineName($m[1])]);
    }
    if (preg_match('#^message-templates/(?:system|user)/([^/]+)\.yml$#', $path, $m)) {
      return $this->humanizeMachineName($m[1]);
    }
    if (preg_match('#^(?:scheduled-jobs|contact-types|relationship-types|location-types|dedupe-rules|site-tokens)/([^/]+)\.yml$#', $path, $m)) {
      return $this->humanizeMachineName($m[1]);
    }
    if (preg_match('#^financial/([^/]+)\.yml$#', $path, $m)) {
      return ts('Financial type "%1"', [1 => $this->humanizeMachineName($m[1])]);
    }
    if (preg_match('#^payment-processors/([^/]+)\.yml$#', $path, $m)) {
      return ts('Payment processor "%1"', [1 => $this->humanizeMachineName($m[1])]);
    }
    if (preg_match('#^settings/([^/]+)\.yml$#', $path, $m)) {
      return ts('CiviCRM setting "%1"', [1 => $this->humanizeMachineName($m[1])]);
    }
    if (preg_match('#^civirules/([^/]+)/([^/]+)\.yml$#', $path, $m)) {
      return ts('CiviRules %1 "%2"', [1 => $this->humanizeMachineName($m[1]), 2 => $this->humanizeMachineName($m[2])]);
    }
    if (preg_match('#^(?:searchkit/(?:saved-searches|displays)|formbuilder/afforms)/([^/]+)\.yml$#', $path, $m)) {
      return $this->humanizeMachineName($m[1]);
    }
    return $fallback;
  }

  private function fieldNameForChange(string $path, string $label): string {
    $field = $path;
    $field = preg_replace('/^.*\]\./', '', $field);
    $field = preg_replace('/^item\./', '', $field);
    $field = preg_replace('/^group\./', '', $field);
    $field = preg_replace('/^settings\.[^.]+\.?/', '', $field);
    if ($field === '' || $field === $path) {
      $field = $label;
    }
    return strtolower($this->humanizeMachineName($field));
  }

  private function humanizeMachineName(string $name): string {
    $name = trim($name);
    if ($name === '') {
      return $name;
    }
    $name = str_replace(['__', '_', '-', '.', ' › '], [' ', ' ', ' ', ' ', ' › '], $name);
    $name = preg_replace('/(?<!^)([A-Z])/', ' $1', (string) $name);
    $name = preg_replace('/\s+/', ' ', (string) $name);
    $name = trim((string) $name);
    if ($name === strtoupper($name)) {
      return $name;
    }
    return ucwords($name);
  }

  private function shortInlineValue(string $value): string {
    $value = trim(preg_replace('/\s+/', ' ', $value) ?: '');
    if ($value === '' || $value === '-') {
      return 'empty';
    }
    if (strlen($value) > 120) {
      return substr($value, 0, 117) . '...';
    }
    return $value;
  }

  private function importActionLabel(string $status): string {
    if ($status === 'missing_in_db') {
      return ts('Create in CiviCRM');
    }
    if ($status === 'new_in_db') {
      return ts('Remove from CiviCRM');
    }
    return ts('Update CiviCRM');
  }

  private function importActionNote(string $status, bool $importable): string {
    if (!$importable) {
      return ts('Import for this config type is not available yet.');
    }
    if ($status === 'new_in_db') {
      return ts('This exists in CiviCRM but not in YAML. Import treats YAML as the source of truth and will delete this record after confirmation. Export first if you want to keep it.');
    }
    if ($status === 'missing_in_db') {
      return ts('This exists in YAML but not in CiviCRM. Import will recreate it. CiviCRM may assign a new numeric database ID.');
    }
    return '';
  }

  public function getImportApplyTypes(array $importPlan, array $selectedTypes = []): array {
    $types = [];
    foreach ($importPlan as $item) {
      if (!empty($item['importable']) && !empty($item['type'])) {
        $types[] = (string) $item['type'];
      }
    }
    $types = array_values(array_unique($types));

    // Diff rows use the base `extensions` handler type, but the UI filter may
    // have selected one or more virtual contributed-provider subtypes. Keep
    // those subtype selectors through preview/apply so a SQLTasks-only import
    // does not expand back to every extension-owned provider at submit time.
    if (in_array('extensions', $types, TRUE) && !in_array('extensions', $selectedTypes, TRUE)) {
      $providerTypes = array_values(array_unique(array_filter(array_map('strval', $selectedTypes), static function($type) {
        return strpos($type, 'extensions:') === 0;
      })));
      if ($providerTypes) {
        $types = array_values(array_filter($types, static fn($type) => $type !== 'extensions'));
        $types = array_values(array_unique(array_merge($types, $providerTypes)));
      }
    }

    return $types;
  }

  public function countDiffChanges(array $result): int {
    $count = 0;
    foreach (($result['items'] ?? []) as $item) {
      $count += !empty($item['changed']) ? count($item['changed']) : 0;
      $count += !empty($item['new_in_db']) ? count($item['new_in_db']) : 0;
      $count += !empty($item['missing_in_db']) ? count($item['missing_in_db']) : 0;
    }
    return $count;
  }


  public function firstImportProblem(?array $importResult): string {
    if (!$importResult) {
      return '';
    }
    if (!empty($importResult['errors'])) {
      foreach ($importResult['errors'] as $error) {
        $type = !empty($error['type']) ? ((string) $error['type'] . ': ') : '';
        return $type . (string) ($error['message'] ?? json_encode($error));
      }
    }
    if (!empty($importResult['validation']['errors'])) {
      foreach ($importResult['validation']['errors'] as $error) {
        $type = !empty($error['type']) ? ((string) $error['type'] . ': ') : '';
        return $type . (string) ($error['message'] ?? json_encode($error));
      }
    }
    if (!empty($importResult['validation']['items'])) {
      foreach ($importResult['validation']['items'] as $item) {
        foreach (($item['errors'] ?? []) as $error) {
          $file = !empty($error['file']) ? ((string) $error['file'] . ': ') : '';
          return $this->humanizeType((string) ($item['type'] ?? 'validation')) . ': ' . $file . (string) ($error['message'] ?? json_encode($error));
        }
      }
    }
    foreach (($importResult['items'] ?? []) as $item) {
      foreach (($item['errors'] ?? []) as $error) {
        $file = !empty($error['file']) ? ((string) $error['file'] . ': ') : '';
        return $this->humanizeType((string) ($item['type'] ?? 'import')) . ': ' . $file . (string) ($error['message'] ?? json_encode($error));
      }
    }
    return '';
  }

  public function extractImportMessages(?array $importResult): array {
    $messages = [];
    if (!$importResult) {
      return $messages;
    }
    if (!empty($importResult['error'])) {
      $messages[] = [
        'type' => 'error',
        'title' => ts('Import'),
        'message' => (string) $importResult['error'],
      ];
    }
    if (!empty($importResult['errors']) && is_array($importResult['errors'])) {
      foreach ($importResult['errors'] as $error) {
        $messages[] = [
          'type' => 'error',
          'title' => !empty($error['type']) ? $this->humanizeType((string) $error['type']) : ts('Import'),
          'message' => (string) ($error['message'] ?? json_encode($error)),
        ];
      }
    }
    if (!empty($importResult['validation']['errors']) && is_array($importResult['validation']['errors'])) {
      foreach ($importResult['validation']['errors'] as $error) {
        $messages[] = [
          'type' => 'error',
          'title' => !empty($error['type']) ? $this->humanizeType((string) $error['type']) : ts('Validation'),
          'message' => (string) ($error['message'] ?? json_encode($error)),
        ];
      }
    }
    if (!empty($importResult['validation']['items']) && is_array($importResult['validation']['items'])) {
      foreach ($importResult['validation']['items'] as $item) {
        foreach (($item['warnings'] ?? []) as $warning) {
          $messages[] = [
            'type' => 'warning',
            'title' => $this->humanizeType((string) ($item['type'] ?? 'validation')),
            'message' => (string) ($warning['message'] ?? json_encode($warning)),
          ];
        }
        foreach (($item['errors'] ?? []) as $error) {
          $messages[] = [
            'type' => 'error',
            'title' => $this->humanizeType((string) ($item['type'] ?? 'validation')),
            'message' => (string) ($error['message'] ?? json_encode($error)),
          ];
        }
      }
    }
    foreach ((array) ($importResult['items'] ?? []) as $item) {
      foreach (($item['warnings'] ?? []) as $warning) {
        $messages[] = [
          'type' => 'warning',
          'title' => $this->humanizeType((string) ($item['type'] ?? 'import')),
          'message' => (string) ($warning['message'] ?? json_encode($warning)),
        ];
      }
      foreach (($item['errors'] ?? []) as $error) {
        $messages[] = [
          'type' => 'error',
          'title' => $this->humanizeType((string) ($item['type'] ?? 'import')),
          'message' => (string) ($error['message'] ?? json_encode($error)),
        ];
      }
    }

    // One provider can surface the same compatibility/import warning through
    // several YAML records. Repeating the same banner makes the actual blocker
    // difficult to find, so show each distinct message only once.
    $unique = [];
    foreach ($messages as $message) {
      $key = implode("\0", [
        (string) ($message['type'] ?? ''),
        (string) ($message['title'] ?? ''),
        (string) ($message['message'] ?? ''),
      ]);
      $unique[$key] = $message;
    }
    return array_values($unique);
  }

  public function humanizeType(string $type): string {
    return ucwords(str_replace(['-', '_'], ' ', $type));
  }

  private function getImportableTypes(): array {
    return ['extensions', 'option-groups', 'contact-types', 'relationship-types', 'location-types', 'financial-types', 'custom-data', 'settings', 'site-tokens', 'message-templates', 'dedupe-rules', 'scheduled-jobs', 'searchkit-saved-searches', 'searchkit-displays', 'formbuilder-afforms', 'civirules'];
  }

  private function humanizeChangePath(string $path): string {
    $friendly = [
      'template.msg_subject' => 'Subject',
      'template.msg_html' => 'HTML message',
      'template.msg_text' => 'Plain-text message',
      'template.msg_title' => 'Template title',
      'template.is_active' => 'Enabled',
      'item.run_frequency' => 'Run frequency',
      'item.scheduled_run_date' => 'Next scheduled run',
      'item.parameters' => 'Parameters',
    ];
    if (isset($friendly[$path])) {
      return $friendly[$path];
    }
    $label = $path;
    $label = preg_replace('/^values\[([^\]]+)\]\./', 'Values > $1 > ', $label);
    $label = preg_replace('/^values\[([^\]]+)\]$/', 'Values > $1', $label);
    $label = preg_replace('/^items\[([^\]]+)\]\./', 'Items > $1 > ', $label);
    $label = preg_replace('/^items\[([^\]]+)\]$/', 'Items > $1', $label);
    $label = preg_replace('/^group\./', 'Group > ', $label);
    $map = [
      'name' => 'Machine Name',
      'label' => 'Label',
      'title' => 'Title',
      'description' => 'Description',
      'value' => 'Value',
      'weight' => 'Order / Weight',
      'is_active' => 'Enabled',
      'is_reserved' => 'Reserved',
      'is_default' => 'Default',
      'is_optgroup' => 'Option Group Marker',
      'component_id' => 'Component',
      'domain_id' => 'Domain',
      'visibility_id' => 'Visibility',
      'data_type' => 'Data Type',
    ];
    foreach ($map as $machine => $human) {
      if (preg_match('/(^| > |\.)' . preg_quote($machine, '/') . '$/', $label)) {
        $label = preg_replace('/' . preg_quote($machine, '/') . '$/', $human, $label);
        break;
      }
    }
    return str_replace(['.', '>'], [' > ', '›'], $label);
  }

  private function formatChangeValue($value, $other = NULL): string {
    if ($value === NULL || $value === '') {
      return '-';
    }
    if (is_bool($value)) {
      return $value ? 'true' : 'false';
    }
    if (is_array($value)) {
      $parts = [];
      foreach (['name', 'label', 'title', 'value', 'weight', 'is_active'] as $key) {
        if (array_key_exists($key, $value)) {
          $parts[] = $key . ': ' . $this->formatChangeValue($value[$key]);
        }
      }
      if ($parts) {
        return implode("\n", $parts);
      }
      return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    $value = (string) $value;
    return $this->truncateLongValue($value, $other);
  }

  private function formatChangeValueHtml($value, $other = NULL): string {
    $text = $this->formatChangeValue($value, $other);
    if (!is_string($value) || !is_string($other) || $value === $other || strlen($value) < 200) {
      return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    return $this->highlightChangedText($value, $other);
  }

  private function truncateLongValue(string $value, $other = NULL): string {
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $lines = explode("\n", $value);
    $maxLines = 18;
    $maxChars = 1800;
    if (is_string($other) && $other !== '' && $value !== $other && (count($lines) > $maxLines || strlen($value) > $maxChars)) {
      return $this->focusedTextExcerpt($value, $other);
    }
    if (count($lines) > $maxLines) {
      $value = implode("\n", array_slice($lines, 0, $maxLines)) . "\n... (preview truncated; use Show Diff Text or open the YAML file for full content)";
    }
    if (strlen($value) > $maxChars) {
      $value = substr($value, 0, $maxChars) . "\n... (preview truncated)";
    }
    return $value;
  }

  private function focusedTextExcerpt(string $value, string $other, int $context = 220): string {
    [$start, $endValue] = $this->changedRange($value, $other);
    $from = max(0, $start - $context);
    $length = min(strlen($value), $endValue + $context) - $from;
    $excerpt = substr($value, $from, $length);
    $prefix = $from > 0 ? "...\n" : '';
    $suffix = ($from + $length) < strlen($value) ? "\n..." : '';
    if ($excerpt === '') {
      return '[empty at changed position]';
    }
    return $prefix . $excerpt . $suffix;
  }

  private function highlightChangedText(string $value, string $other, int $context = 220): string {
    [$start, $endValue] = $this->changedRange($value, $other);
    $from = max(0, $start - $context);
    $to = min(strlen($value), $endValue + $context);
    $before = substr($value, $from, $start - $from);
    $changed = substr($value, $start, max(0, $endValue - $start));
    $after = substr($value, $endValue, $to - $endValue);
    $html = '';
    if ($from > 0) {
      $html .= htmlspecialchars("...\n", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    $html .= htmlspecialchars($before, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    if ($changed === '') {
      $html .= '<mark class="civicfg-diff-empty">[missing here]</mark>';
    }
    else {
      $html .= '<mark>' . htmlspecialchars($changed, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</mark>';
    }
    $html .= htmlspecialchars($after, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    if ($to < strlen($value)) {
      $html .= htmlspecialchars("\n...", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    return $html;
  }

  private function changedRange(string $value, string $other): array {
    $valueLen = strlen($value);
    $otherLen = strlen($other);
    $start = 0;
    $maxStart = min($valueLen, $otherLen);
    while ($start < $maxStart && $value[$start] === $other[$start]) {
      $start++;
    }
    $valueEnd = $valueLen;
    $otherEnd = $otherLen;
    while ($valueEnd > $start && $otherEnd > $start && $value[$valueEnd - 1] === $other[$otherEnd - 1]) {
      $valueEnd--;
      $otherEnd--;
    }
    return [$start, $valueEnd];
  }
}

