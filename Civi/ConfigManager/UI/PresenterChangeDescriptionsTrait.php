<?php
namespace Civi\ConfigManager\UI;


/**
 * PresenterChangeDescriptions methods from Presenter.
 *
 * Methods run on the original owning object and retain the same state and
 * access modifiers. Do not instantiate this trait separately.
 */
trait PresenterChangeDescriptionsTrait {

  public function statusLabel(string $status): string {
    if ($status === 'missing_in_db') {
      return ts('Not in Current CiviCRM');
    }
    if ($status === 'new_in_db') {
      return ts('Not Yet Saved');
    }
    if ($status === 'changed') {
      return ts('Changed');
    }
    return ts('In Sync');
  }

  private function plainStatusLabel(string $status): string {
    if ($status === 'missing_in_db') {
      return ts('is saved but is not currently in CiviCRM');
    }
    if ($status === 'new_in_db') {
      return ts('is ready to be exported to Saved Configs');
    }
    if ($status === 'changed') {
      return ts('has changed between the Saved Config and Current CiviCRM');
    }
    return ts('is in sync');
  }

  private function syncStateLabel(string $state, string $mergeState = ''): string {
    if ($state === 'ACTIVE_DRIFT') {
      return ts('Current CiviCRM changed');
    }
    if ($state === 'YAML_CHANGE') {
      return ts('Saved Config changed');
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
      return ts('Export this item to add it to Saved Configs.');
    }
    if ($status === 'missing_in_db') {
      return ts('%1 is saved but is not currently in CiviCRM.', [1 => $title]);
    }
    if ($syncState === 'ACTIVE_DRIFT') {
      return ts('%1 changed in CiviCRM since the last accepted sync.', [1 => $title]);
    }
    if ($syncState === 'YAML_CHANGE') {
      return ts('%1 changed in the Saved Config since the last accepted sync.', [1 => $title]);
    }
    if ($syncState === 'BOTH_CHANGED' && $mergeState === 'CONFLICT') {
      return ts('%1 changed in both Current CiviCRM and the Saved Config, with at least one conflicting field.', [1 => $title]);
    }
    if ($syncState === 'BOTH_CHANGED') {
      return ts('%1 changed in both Current CiviCRM and the Saved Config.', [1 => $title]);
    }
    return ts('%1 has configuration changes to review.', [1 => $title]);
  }

  private function describeFieldChange(array $file, array $change, string $label, string $changeType, string $yamlValue, string $civiValue): string {
    $path = (string) ($change['path'] ?? '');
    if ($path === 'extension.status' && $changeType === 'changed') {
      return ts('Extension state: Saved Config %1 → Current CiviCRM %2.', [
        1 => $this->friendlyExtensionStatus($yamlValue),
        2 => $this->friendlyExtensionStatus($civiValue),
      ]);
    }

    $field = $this->friendlyFieldLabel($path, $label);
    if ($changeType === 'added') {
      return ts('%1 was added in CiviCRM.', [1 => $field]);
    }
    if ($changeType === 'removed') {
      return ts('%1 exists in the Saved Config but is missing from Current CiviCRM.', [1 => $field]);
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
    if ($this->isProfileFieldFile($file)) {
      $profileFieldTitle = $this->profileFieldDisplayTitle($file);
      if ($profileFieldTitle !== '') {
        return $profileFieldTitle;
      }
    }
    $subject = $this->subjectFromFilePath($path, '');
    if ($subject !== '') {
      return $subject;
    }
    $base = preg_replace('/\.ya?ml$/i', '', basename($path));
    $base = $this->humanizeMachineName((string) $base);
    return $base !== '' ? $base : $typeLabel;
  }

  private function isProfileFieldFile(array $file): bool {
    $type = (string) ($file['type'] ?? '');
    $path = (string) ($file['path'] ?? $file['file'] ?? '');
    return $type === 'profile-fields' || (bool) preg_match('#^profiles/fields/#', $path);
  }

  private function profileFieldDisplayTitle(array $file): string {
    $configKey = (string) ($file['config_key'] ?? '');
    if (preg_match('/\|key=(.+)$/', $configKey, $match)) {
      // ConfigIdentity escapes percent before pipe, so decode in reverse order.
      $identity = str_replace(['%7C', '%25'], ['|', '%'], (string) $match[1]);
      $parts = [];
      foreach (explode('|', $identity) as $part) {
        $separator = strpos($part, '=');
        if ($separator === FALSE) {
          continue;
        }
        $parts[substr($part, 0, $separator)] = substr($part, $separator + 1);
      }
      $profile = trim((string) ($parts['profile'] ?? ''));
      $label = trim((string) ($parts['label'] ?? ''));
      $field = trim((string) ($parts['field'] ?? ''));
      if ($profile !== '' && ($label !== '' || $field !== '')) {
        return ts('Profile Field "%1" in "%2"', [
          1 => $label !== '' ? $label : $this->humanizeMachineName($field),
          2 => $this->humanizeMachineName($profile),
        ]);
      }
    }

    // Transitional fallback for pre-adapter filenames and incomplete state.
    // The hash is never part of a normal user-facing title.
    $path = (string) ($file['path'] ?? $file['file'] ?? '');
    $base = (string) preg_replace('/\.ya?ml$/i', '', basename($path));
    $base = (string) preg_replace('/--[a-f0-9]{10}$/i', '', $base);
    $parts = explode('__', $base);
    if (count($parts) >= 2) {
      $profile = array_shift($parts);
      $field = array_shift($parts);
      $label = $parts ? implode('__', $parts) : $field;
      return ts('Profile Field "%1" in "%2"', [
        1 => $this->humanizeMachineName($label),
        2 => $this->humanizeMachineName((string) $profile),
      ]);
    }
    return '';
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
      return ts('Restore to Current CiviCRM');
    }
    if ($status === 'new_in_db') {
      return ts('Remove from Current CiviCRM');
    }
    return ts('Update Current CiviCRM');
  }

  private function importActionNote(string $status, bool $importable): string {
    if (!$importable) {
      return ts('Import for this config type is not available yet.');
    }
    if ($status === 'new_in_db') {
      return ts('This item remains in Current CiviCRM. Export it to Saved Config if you want Configuration Manager to manage it; automatic removal is unavailable for this type.');
    }
    if ($status === 'missing_in_db') {
      return ts('This Saved Config is not in Current CiviCRM. Import will restore it. CiviCRM may assign a new numeric database ID.');
    }
    return '';
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
