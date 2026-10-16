<?php
namespace Civi\ConfigManager\UI;

use Civi\ConfigManager\Service\ConfigManager;


// Include stateful concerns for direct CiviCRM/legacy bootstrap loaders too.
require_once __DIR__ . '/PresenterChangeDescriptionsTrait.php';
require_once __DIR__ . '/PresenterImportFeedbackTrait.php';

/**
 * Converts service/API data into template-friendly rows, labels, and summaries.
 */
class Presenter {
  use PresenterChangeDescriptionsTrait;
  use PresenterImportFeedbackTrait;



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

  public function buildTypeRows(ConfigManager $manager, array $result, array $status = []): array {
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
        'savedCount' => !empty($option['virtual']) ? 0 : (int) (($status['saved_config_counts'][$baseType] ?? 0)),
        'hasSavedCount' => empty($option['virtual']),
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
      'saved_config_count' => (int) ($status['saved_config_count'] ?? 0),
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
        $file['show_inline_path'] = !$this->isProfileFieldFile($file);
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
        'display_title' => $file['display_title'] ?? $this->displayTitleForFile($file),
        'show_inline_path' => $file['show_inline_path'] ?? !$this->isProfileFieldFile($file),
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
            ? ts('Saved copy only')
            : ($deleteBlocked ? ts('Export to Saved Config') : $this->importActionLabel($status))),
        'status_label' => $this->statusLabel($status),
        'note' => $possibleRename
          ? ts('Possible identity rename detected. Configuration Manager will not apply this create/delete pair automatically; review and align the accepted identity first.')
          : ($providerWriteBlocked
            ? ts('This contributed configuration is safe to back up and compare, but its provider does not expose a write-safe portable identity on this site. Automatic restore is disabled.')
            : ($deleteBlocked
              ? ts('This item remains in Current CiviCRM. Export it to Saved Config if you want Configuration Manager to manage it; automatic removal is unavailable for this type.')
              : $this->importActionNote($status, $importable))),
      ];
    }
    return $plan;
  }
}

