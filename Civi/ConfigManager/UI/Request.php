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
    $allowed = ['sync', 'import', 'export', 'settings', 'single-export-json', 'scope-options-json', 'provider-inventory-json', 'download-archive', 'download-single', 'operation-stream', 'operation-start-json', 'operation-step-json', 'operation-status-json', 'diff-detail-json'];
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
      // Extension provider subtypes use colon-delimited semantic keys, e.g.
      // extensions:org.example:api4:Entity. Keep the request allowlist strict
      // while accepting the same portable type syntax used by ConfigManager.
      if ($type !== '' && $type !== 'all' && preg_match('/^[A-Za-z0-9_.:-]+$/', $type)) {
        $types[] = $type;
      }
    }
    return array_values(array_unique($types));
  }

  public function getImportPlanId(): string {
    $value = isset($_REQUEST['import_plan_id']) ? trim((string) $_REQUEST['import_plan_id']) : '';
    return preg_match('/^[a-f0-9]{48}$/', $value) ? $value : '';
  }

  public function requireImportPlanId(): string {
    $value = $this->getImportPlanId();
    if ($value === '') {
      throw new \RuntimeException('Import apply requires the reviewed Import plan. Build and review a fresh preview first.');
    }
    return $value;
  }

  public function requireDependencyComponentId(): string {
    $value = isset($_POST['dependency_component_id']) ? trim((string) $_POST['dependency_component_id']) : '';
    if (!preg_match('/^[a-f0-9]{24}$/', $value)) {
      throw new \RuntimeException('Select a valid dependency component from the current Import preview.');
    }
    return $value;
  }

  public function getSingleExportKey(): string {
    return isset($_REQUEST['export_item']) ? trim((string) $_REQUEST['export_item']) : '';
  }

  public function shouldOpenWatchPanel(): bool {
    return isset($_REQUEST['watch']) && (string) $_REQUEST['watch'] === '1';
  }
}
