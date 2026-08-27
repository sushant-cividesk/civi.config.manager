<?php
namespace Civi\ConfigManager\UI;

/**
 * Small request helper. Keeps filtering/normalisation in one place.
 */
class Request {
  public function getOperation(): string {
    $op = isset($_REQUEST['op']) ? (string) $_REQUEST['op'] : 'sync';
    if (in_array($op, ['status', 'diff', 'validate'], TRUE)) {
      return 'sync';
    }
    $allowed = ['sync', 'import', 'export', 'settings', 'single-export-json', 'scope-options-json', 'download-archive', 'download-single', 'operation-stream', 'operation-start-json', 'operation-step-json', 'operation-status-json', 'diff-detail-json'];
    return in_array($op, $allowed, TRUE) ? $op : 'sync';
  }

  public function getPostAction(): string {
    return isset($_POST['_action']) ? (string) $_POST['_action'] : '';
  }

  public function getSelectedTypes(): array {
    $raw = $_REQUEST['type'] ?? [];
    if (is_string($raw)) {
      $raw = ($raw === '' || $raw === 'all') ? [] : [$raw];
    }
    if (!is_array($raw)) {
      return [];
    }
    $types = [];
    foreach ($raw as $type) {
      $type = trim((string) $type);
      if ($type !== '' && $type !== 'all' && preg_match('/^[A-Za-z0-9_.-]+$/', $type)) {
        $types[] = $type;
      }
    }
    return array_values(array_unique($types));
  }

  public function getSingleExportKey(): string {
    return isset($_REQUEST['export_item']) ? trim((string) $_REQUEST['export_item']) : '';
  }

  public function shouldOpenWatchPanel(): bool {
    return isset($_REQUEST['watch']) && (string) $_REQUEST['watch'] === '1';
  }
}
