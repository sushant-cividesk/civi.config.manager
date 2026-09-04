<?php
namespace Civi\ConfigManager\UI;
use Civi\ConfigManager\Service\ConfigManager;
use Civi\ConfigManager\Service\LifecycleStateCleaner;
use Civi\ConfigManager\Service\QueuedOperationService;
use Exception;
use RuntimeException;
/**
 * Controller for the admin UI. Keeps the CRM page class thin and delegates
 * presentation/file-transfer details to focused helper classes.
 */
class MainPage {
  private \CRM_Core_Page $page;
  private ConfigManager $manager;
  private Request $request;
  private Presenter $presenter;
  private OperationResultPresenter $operationResultPresenter;
  private FileTransfer $files;
  private Permission $permission;
  public function __construct(\CRM_Core_Page $page, ?ConfigManager $manager = NULL) {
    $this->page = $page;
    $this->manager = $manager ?: new ConfigManager();
    $this->request = new Request();
    $this->presenter = new Presenter();
    $this->operationResultPresenter = new OperationResultPresenter();
    $this->files = new FileTransfer();
    $this->permission = new Permission();
  }
  public function run(): void {
    \CRM_Utils_System::setTitle(ts('Configuration Manager'));
    $op = $this->request->getOperation();
    $postAction = $this->request->getPostAction();
    $types = $this->request->getSelectedTypes();
    $notice = NULL;
    $validationResult = NULL;
    $importResult = NULL;
    $exportResult = NULL;
    $importSummary = NULL;
    $sessionValidationResult = \CRM_Core_Session::singleton()->get('civicfg_last_validation_result');
    if (is_array($sessionValidationResult)) {
      $validationResult = $sessionValidationResult;
      \CRM_Core_Session::singleton()->set('civicfg_last_validation_result', NULL);
    }
    $sessionExportResult = \CRM_Core_Session::singleton()->get('civicfg_last_export_result');
    if (is_array($sessionExportResult)) {
      $exportResult = $sessionExportResult;
    }
    $sessionImportSummary = \CRM_Core_Session::singleton()->get('civicfg_last_import_summary');
    if (is_array($sessionImportSummary)) {
      $importSummary = $sessionImportSummary;
    }
    $sessionImportResult = \CRM_Core_Session::singleton()->get('civicfg_last_import_result');
    if (is_array($sessionImportResult)) {
      $importResult = $sessionImportResult;
      \CRM_Core_Session::singleton()->set('civicfg_last_import_result', NULL);
    }
    $result = [];
    $this->permission->requireForPage($op, $postAction);
    try {
      if ($postAction !== '') {
        $this->requireValidCsrfToken();
      }
      if ($op === 'operation-start-json') {
        $this->jsonStartOperation($postAction, $types);
      }
      elseif ($op === 'operation-step-json') {
        $this->jsonAdvanceOperation();
      }
      elseif ($op === 'operation-status-json') {
        $this->jsonOperationStatus();
      }
      elseif ($op === 'operation-stream') {
        // Compatibility endpoint for older cached JavaScript. New alpha63 UI
        // uses the persistent Queue start/step/status endpoints below.
        $this->streamOperation($postAction, $types);
      }
      elseif ($op === 'diff-detail-json') {
        $this->jsonDiffDetail();
      }
      elseif ($op === 'single-export-json') {
        $this->files->jsonSingleExport($this->manager);
      }
      elseif ($op === 'scope-options-json') {
        $this->jsonScopeOptions();
      }
      elseif ($op === 'provider-inventory-json') {
        $this->jsonProviderInventory();
      }
      elseif ($op === 'download-archive') {
        $this->files->downloadArchive($this->manager);
      }
      elseif ($op === 'download-single') {
        $this->files->downloadSingleExport($this->manager);
      }
      elseif ($postAction === 'import_single_yaml') {
        $notice = $this->files->uploadSingleYaml($this->manager);
        $this->redirectWithNotice($notice, 'import', 'success');
      }
      elseif ($postAction === 'import_zip_archive') {
        $notice = $this->files->uploadZipArchive($this->manager);
        $this->redirectWithNotice($notice, 'import', 'success');
      }
      elseif ($postAction === 'save_settings') {
        $this->saveSettings();
        \CRM_Core_Session::setStatus(ts('Configuration Manager settings saved.'), ts('Saved'), 'success');
        $watchSummary = $this->manager->getWatchSummary();
        if ((int) ($watchSummary['baseline'] ?? 0) > 0) {
          \CRM_Core_Session::setStatus(ts('Monitoring baseline captured for %1 watched item(s). Future watch scans will report new, changed, or missing configuration.', [
            1 => (int) $watchSummary['baseline'],
          ]), ts('Configuration Manager'), 'success');
        }
        $scopeDependencyWarnings = $this->manager->getScopeDependencyWarnings();
        if ($scopeDependencyWarnings) {
          \CRM_Core_Session::setStatus(ts('%1 Configuration Scope dependency warning(s) remain. Review the highlighted related types before exporting or promoting this configuration.', [
            1 => count($scopeDependencyWarnings),
          ]), ts('Configuration Manager'), 'warning');
        }
        if (!empty($_POST['allow_cross_site_import'])) {
          \CRM_Core_Session::setStatus(ts('Experimental cross-site import is enabled. Keep it off for normal dev/stage/prod synchronization and use it only for a reviewed one-off migration between different sites.'), ts('Configuration Manager'), 'warning');
        }
        $ignoreRaw = trim((string) ($_POST['ignore_paths'] ?? ''));
        if ($ignoreRaw !== '') {
          \CRM_Core_Session::setStatus(ts('Config Ignore is active. Whole-file rules exclude matching Saved Config files; path:dot.path rules exclude only selected values while keeping the rest of the file managed. Review ignored dependencies and never ignore portable identity fields.'), ts('Configuration Manager'), 'warning');
          try {
            foreach ($this->manager->getIgnoredDependencyWarnings() as $warning) {
              \CRM_Core_Session::setStatus($warning, ts('Configuration Manager'), 'warning');
            }
          }
          catch (Exception $e) {
            \CRM_Core_Session::setStatus(ts('Config Ignore was saved, but dependency warnings could not be checked: %1', [1 => $e->getMessage()]), ts('Configuration Manager'), 'warning');
          }
        }
        \CRM_Utils_System::redirect(\CRM_Utils_System::url('civicrm/admin/config-manager', 'reset=1&op=settings'));
      }
      elseif ($postAction === 'scan_watch') {
        $watchResult = $this->manager->scanWatched($types);
        $notice = !empty($watchResult['ok'])
          ? ts('Watch scan complete. %1 watched item(s), %2 baseline, %3 new, %4 changed, %5 missing.', [
              1 => (int) ($watchResult['watched'] ?? 0),
              2 => (int) ($watchResult['baseline'] ?? 0),
              3 => (int) ($watchResult['new'] ?? 0),
              4 => (int) ($watchResult['changed'] ?? 0),
              5 => (int) ($watchResult['missing'] ?? 0),
            ])
          : ts('Watch scan completed with errors. Review the Configuration Manager watch summary.');
        $this->redirectWithNotice($notice, 'sync', !empty($watchResult['ok']) ? 'success' : 'error', 'watch=1', 'civicfg-watch-panel');
      }
      elseif ($postAction === 'clear_watch_history') {
        $this->manager->clearWatchHistory();
        $this->redirectWithNotice(ts('Watch history cleared. Current watch fingerprints and monitoring baselines were not changed.'), 'sync', 'success', 'watch=1', 'civicfg-watch-panel');
      }
      elseif ($postAction === 'revert_file') {
        $path = trim((string) ($_POST['path'] ?? ''));
        $result = $this->manager->revertCiviFromYaml($path);
        $this->redirectWithNotice((string) ($result['message'] ?? ts('Current CiviCRM was restored from a Saved Config.')), 'sync', empty($result['ok']) ? 'error' : 'success');
      }
      elseif ($postAction === 'ignore_config') {
        $path = trim((string) ($_POST['path'] ?? ''));
        $scope = (string) ($_POST['ignore_scope'] ?? 'file');
        if ($scope === 'fields') {
          $fields = $_POST['value_path'] ?? [];
          if (!is_array($fields) || !$fields) {
            throw new RuntimeException('Select at least one field to ignore, or choose whole file.');
          }
          $this->manager->addIgnoreValueRules($path, array_map('strval', $fields));
          $this->redirectWithNotice(ts('Field-level ignore rule(s) saved for %1.', [1 => $path]), 'sync', 'warning');
        }
        else {
          $this->manager->addIgnorePathRule($path);
          $this->redirectWithNotice(ts('Config ignore rule saved for %1.', [1 => $path]), 'sync', 'warning');
        }
      }
      elseif ($postAction === 'export_write') {
        $requestedTypes = $types;
        $exportResult = $this->manager->export(FALSE, $requestedTypes);
        $dependencyTypes = (array) ($exportResult['dependency_types'] ?? []);
        $exportErrors = (array) ($exportResult['errors'] ?? []);
        $exportSummary = $this->operationResultPresenter->exportSummary($exportResult, $this->manager->status());
        if ($exportErrors) {
          $firstError = (array) reset($exportErrors);
          $firstType = trim((string) ($firstError['type'] ?? ''));
          $firstMessage = trim((string) ($firstError['message'] ?? ''));
          $firstProblem = trim(($firstType !== '' ? $firstType . ': ' : '') . $firstMessage);
          $notice = ts('Export stopped with %1 error(s). The previous Saved Config snapshot was left unchanged.', [1 => count($exportErrors)]);
          if ($firstProblem !== '') {
            $notice .= ' ' . $firstProblem;
          }
        }
        else {
          $notice = $this->operationResultPresenter->exportMessage($exportSummary);
        }
        if ($dependencyTypes) {
          $notice .= ' ' . ts('Related dependency types were included automatically: %1.', [1 => implode(', ', $dependencyTypes)]);
        }
        if ($requestedTypes) {
          $notice .= ' ' . ts('The temporary filter was cleared so the Synchronize tab now shows the full managed status.');
        }
        \CRM_Core_Session::singleton()->set('civicfg_last_import_summary', NULL);
        \CRM_Core_Session::singleton()->set('civicfg_last_export_result', $exportSummary);
        $this->redirectWithNotice($notice, 'sync', empty($exportResult['errors']) ? 'success' : 'error');
      }
      elseif ($postAction === 'import_apply') {
        $importTypes = $this->request->getSelectedTypes();
        $importResult = $this->manager->import(FALSE, TRUE, $importTypes ?: []);
        \CRM_Core_Session::singleton()->set('civicfg_last_import_result', $importResult);
        \CRM_Core_Session::singleton()->set('civicfg_last_export_result', NULL);
        \CRM_Core_Session::singleton()->set('civicfg_last_import_summary', $this->operationResultPresenter->importSummary($importResult));
        $summaryMessage = (string) ($importResult['summary_message'] ?? '');
        if (!empty($importResult['ok'])) {
          // Do not run a second complete active/YAML diff in the same request.
          // The redirect opens Synchronize, whose fresh request performs the
          // authoritative post-import diff with a clean memory budget.
          $notice = trim(ts('Import complete. Synchronize will verify the resulting configuration state.') . ' ' . $summaryMessage);
          $type = 'success';
        }
        else {
          $firstProblem = $this->presenter->firstImportProblem($importResult);
          $notice = trim(ts('Import found problems.') . ' ' . ($firstProblem ?: ts('Review the warnings or errors below.')) . ' ' . $summaryMessage);
          $type = 'error';
        }
        $this->redirectWithNotice($notice, 'sync', $type);
      }
      elseif ($postAction === 'validate_files') {
        $validationResult = $this->manager->validate($types);
        \CRM_Core_Session::singleton()->set('civicfg_last_validation_result', $validationResult);
        $this->redirectWithNotice(
          !empty($validationResult['ok'])
            ? ts('Validation passed. No YAML format problems were found for the selected files.')
            : ts('Validation found problems. Review the validation details below.'),
          'sync',
          !empty($validationResult['ok']) ? 'success' : 'error'
        );
      }
      elseif ($op === 'import') {
        $result = $this->manager->diff($types);
      }
      elseif ($op === 'export') {
        $result = $this->manager->export(TRUE, $types);
      }
      elseif ($op === 'settings') {
        $result = $this->manager->status();
      }
      else {
        $op = 'sync';
        $result = $this->manager->diff($types);
      }
    }
    catch (Exception $e) {
      $result = [
        'ok' => FALSE,
        'error' => $e->getMessage(),
      ];
    }
    $this->assignTemplate($op, $types, $result, $notice, $validationResult, $importResult, $exportResult, $importSummary);
  }
  private function redirectWithNotice(string $message, string $op = 'sync', string $type = 'success', string $extraQuery = '', string $fragment = ''): void {
    \CRM_Core_Session::setStatus($message, ts('Configuration Manager'), $type);
    $query = 'reset=1&op=' . $op;
    if ($extraQuery !== '') {
      $query .= '&' . ltrim($extraQuery, '&');
    }
    $url = \CRM_Utils_System::url('civicrm/admin/config-manager', $query);
    if ($fragment !== '') {
      $url .= '#' . rawurlencode($fragment);
    }
    \CRM_Utils_System::redirect($url);
  }
  private function requireValidCsrfToken(): void {
    $token = isset($_POST['civicfg_csrf']) ? (string) $_POST['civicfg_csrf'] : '';
    if ($token === '' || !\CRM_Core_Key::validate($token, 'civicfg_config_manager')) {
      throw new RuntimeException('Invalid or expired Configuration Manager form token. Reload the page and try again.');
    }
  }
  /**
   * Stream truthful server-side progress as newline-delimited JSON.
   * Export/import remain normal synchronous PHP operations, but the browser can
   * render each completed server step instead of showing a timer animation.
   */
  private function streamOperation(string $postAction, array $types): void {
    if (!in_array($postAction, ['export_write', 'import_apply'], TRUE)) {
      throw new RuntimeException('Unsupported Configuration Manager streamed operation.');
    }
    while (ob_get_level() > 0) {
      @ob_end_flush();
    }
    @ini_set('output_buffering', '0');
    @ini_set('zlib.output_compression', '0');
    header('Content-Type: application/x-ndjson; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Accel-Buffering: no');
    $emit = static function(array $event): void {
      echo json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
      @flush();
    };
    $progress = static function(array $event) use ($emit): void {
      $event['event'] = 'progress';
      $emit($event);
    };
    try {
      if ($postAction === 'export_write') {
        $result = $this->manager->export(FALSE, $types, $progress);
        $errors = (array) ($result['errors'] ?? []);
        $exportSummary = $this->operationResultPresenter->exportSummary($result, $this->manager->status());
        if ($errors) {
          $first = (array) reset($errors);
          $problem = trim((string) ($first['message'] ?? ''));
          $message = ts('Export stopped with %1 error(s). The previous Saved Config snapshot was left unchanged.', [1 => count($errors)]);
          if ($problem !== '') {
            $message .= ' ' . $problem;
          }
          $statusType = 'error';
        }
        else {
          $message = $this->operationResultPresenter->exportMessage($exportSummary);
          $statusType = 'success';
        }
        \CRM_Core_Session::singleton()->set('civicfg_last_import_summary', NULL);
        \CRM_Core_Session::singleton()->set('civicfg_last_export_result', $exportSummary);
        \CRM_Core_Session::setStatus($message, ts('Configuration Manager'), $statusType);
        $emit([
          'event' => 'complete',
          'ok' => !$errors,
          'percent' => 100,
          'message' => $message,
          'redirect_url' => \CRM_Utils_System::url('civicrm/admin/config-manager', 'reset=1&op=sync', FALSE, NULL, FALSE),
        ]);
      }
      else {
        $result = $this->manager->import(FALSE, TRUE, $types, $progress);
        \CRM_Core_Session::singleton()->set('civicfg_last_import_result', $result);
        $summaryMessage = (string) ($result['summary_message'] ?? '');
        if (!empty($result['ok'])) {
          $message = trim(ts('Import complete. Synchronize will verify the resulting configuration state.') . ' ' . $summaryMessage);
          $statusType = 'success';
        }
        else {
          $firstProblem = $this->presenter->firstImportProblem($result);
          $message = trim(ts('Import found problems.') . ' ' . ($firstProblem ?: ts('Review the warnings or errors below.')) . ' ' . $summaryMessage);
          $statusType = 'error';
        }
        \CRM_Core_Session::setStatus($message, ts('Configuration Manager'), $statusType);
        $emit([
          'event' => 'complete',
          'ok' => !empty($result['ok']),
          'percent' => 100,
          'message' => $message,
          'redirect_url' => \CRM_Utils_System::url('civicrm/admin/config-manager', 'reset=1&op=sync', FALSE, NULL, FALSE),
        ]);
      }
    }
    catch (\Throwable $e) {
      $emit([
        'event' => 'error',
        'ok' => FALSE,
        'message' => $e->getMessage(),
      ]);
    }
    exit;
  }
  /**
   * Start a persistent CiviCRM Queue job. The form action itself is used for
   * permission/CSRF checks, but the queue stores only operation metadata.
   */
  private function jsonStartOperation(string $postAction, array $types): void {
    try {
      if ($postAction === 'export_write') {
        Permission::require(Permission::EXPORT);
        $operation = 'export';
      }
      elseif ($postAction === 'import_apply') {
        Permission::require(Permission::IMPORT);
        $operation = 'import';
      }
      else {
        throw new RuntimeException('Unsupported Configuration Manager queued operation.');
      }
      $job = (new QueuedOperationService())->start($operation, $types);
      $payload = ['ok' => TRUE, 'job' => $job] + $this->operationLinks((int) $job['id']);
    }
    catch (\Throwable $e) {
      $payload = ['ok' => FALSE, 'error' => $e->getMessage()];
    }
    $this->emitJsonAndExit($payload);
  }
  /** Advance at most one persistent queue item. */
  private function jsonAdvanceOperation(): void {
    try {
      $jobId = isset($_REQUEST['job_id']) ? (int) $_REQUEST['job_id'] : 0;
      $service = new QueuedOperationService();
      $job = $service->status($jobId);
      $this->requireOperationPermission((string) ($job['operation'] ?? ''));
      $job = $service->advance($jobId);
      $payload = ['ok' => TRUE, 'job' => $job] + $this->operationLinks($jobId);
    }
    catch (\Throwable $e) {
      $payload = ['ok' => FALSE, 'error' => $e->getMessage()];
    }
    $this->emitJsonAndExit($payload);
  }
  /** Read-only reconnect/status endpoint for a running queue job. */
  private function jsonOperationStatus(): void {
    try {
      $jobId = isset($_REQUEST['job_id']) ? (int) $_REQUEST['job_id'] : 0;
      $service = new QueuedOperationService();
      $job = $service->status($jobId);
      $this->requireOperationPermission((string) ($job['operation'] ?? ''));
      $payload = ['ok' => TRUE, 'job' => $job] + $this->operationLinks($jobId);
      if (in_array((string) ($job['status'] ?? ''), ['complete', 'failed', 'blocked'], TRUE)) {
        [$message, $type] = $this->operationTerminalMessage($job);
        $payload['terminal_message'] = $message;
        $payload['terminal_type'] = $type;
        if ((string) ($job['operation'] ?? '') === 'export') {
          \CRM_Core_Session::singleton()->set('civicfg_last_import_summary', NULL);
          \CRM_Core_Session::singleton()->set('civicfg_last_export_result', $this->operationResultPresenter->exportSummary((array) ($job['result'] ?? []), $this->manager->status(), $job));
        }
        elseif ((string) ($job['operation'] ?? '') === 'import' && is_array($job['result'] ?? NULL)) {
          \CRM_Core_Session::singleton()->set('civicfg_last_import_result', (array) $job['result']);
          \CRM_Core_Session::singleton()->set('civicfg_last_export_result', NULL);
          \CRM_Core_Session::singleton()->set('civicfg_last_import_summary', $this->operationResultPresenter->importSummary((array) $job['result'], $job));
        }
        \CRM_Core_Session::setStatus($message, ts('Configuration Manager'), $type);
      }
    }
    catch (\Throwable $e) {
      $payload = ['ok' => FALSE, 'error' => $e->getMessage()];
    }
    $this->emitJsonAndExit($payload);
  }
  private function requireOperationPermission(string $operation): void {
    if ($operation === 'export') {
      Permission::require(Permission::EXPORT);
      return;
    }
    if ($operation === 'import') {
      Permission::require(Permission::IMPORT);
      return;
    }
    throw new RuntimeException('Unknown Configuration Manager operation type.');
  }

  /** @return array{status_url:string,step_url:string,redirect_url:string} */
  private function operationLinks(int $jobId): array {
    return [
      'status_url' => \CRM_Utils_System::url('civicrm/admin/config-manager', 'reset=1&op=operation-status-json&job_id=' . $jobId, FALSE, NULL, FALSE),
      'step_url' => \CRM_Utils_System::url('civicrm/admin/config-manager', 'reset=1&op=operation-step-json&job_id=' . $jobId, FALSE, NULL, FALSE),
      'redirect_url' => \CRM_Utils_System::url('civicrm/admin/config-manager', 'reset=1&op=sync', FALSE, NULL, FALSE),
    ];
  }

  /** @return array{0:string,1:string} */
  private function operationTerminalMessage(array $job): array {
    $operation = (string) ($job['operation'] ?? 'operation');
    $result = (array) ($job['result'] ?? []);
    if ((string) ($job['status'] ?? '') !== 'complete') {
      $error = trim((string) ($job['error'] ?? ''));
      return [
        ucfirst($operation) . ' stopped safely.' . ($error !== '' ? ' ' . $error : ' Review the recorded operation diagnostics.'),
        'error',
      ];
    }
    if ($operation === 'export') {
      $summary = $this->operationResultPresenter->exportSummary($result, [], $job);
      return [$this->operationResultPresenter->exportMessage($summary), 'success'];
    }
    $summary = trim((string) ($result['summary_message'] ?? ''));
    return [trim(ts('Import complete. Synchronize will verify the resulting configuration state.') . ' ' . $summary), 'success'];
  }

  /** @param array<string,mixed> $payload */
  private function emitJsonAndExit(array $payload): void {
    // WordPress/contributed extensions can emit notices or buffered markup
    // during bootstrap. A JSON endpoint must never append its payload to that
    // HTML because response.json() would then fail with "Unexpected token <".
    // Discard only buffered output immediately before this terminal JSON
    // response; normal HTML page rendering never enters this method.
    while (ob_get_level() > 0) {
      if (!@ob_end_clean()) {
        break;
      }
    }

    \CRM_Utils_System::setHttpHeader('Content-Type', 'application/json; charset=utf-8');
    \CRM_Utils_System::setHttpHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === FALSE) {
      $json = '{"ok":false,"error":"Configuration Manager could not encode the JSON response."}';
    }
    echo $json;
    \CRM_Utils_System::civiExit();
  }

  private function getCodeDefinedSyncDir(): ?string {
    global $civicrm_setting;

    $fromGlobal = $this->readSyncDirFromSettingsArray($civicrm_setting ?? []);
    if ($fromGlobal !== NULL) {
      return $fromGlobal;
    }

    foreach ($this->getSettingsFileCandidates() as $file) {
      $fromFile = $this->readSyncDirFromSettingsFile($file);
      if ($fromFile !== NULL) {
        return $fromFile;
      }
    }

    return NULL;
  }

  private function readSyncDirFromSettingsArray($settings): ?string {
    if (!is_array($settings)) {
      return NULL;
    }
    foreach (['domain', 'Domain', 'CiviCRM Preferences'] as $group) {
      if (isset($settings[$group]['civicfg_sync_dir']) && trim((string) $settings[$group]['civicfg_sync_dir']) !== '') {
        return (string) $settings[$group]['civicfg_sync_dir'];
      }
    }
    return NULL;
  }

  private function getSettingsFileCandidates(): array {
    $candidates = [];
    if (defined('CIVICRM_SETTINGS_PATH')) {
      $candidates[] = CIVICRM_SETTINGS_PATH;
    }
    if (!empty($_SERVER['CIVICRM_SETTINGS'])) {
      $candidates[] = $_SERVER['CIVICRM_SETTINGS'];
    }
    if (!empty($_ENV['CIVICRM_SETTINGS'])) {
      $candidates[] = $_ENV['CIVICRM_SETTINGS'];
    }

    try {
      $config = \CRM_Core_Config::singleton();
      if (!empty($config->configAndLogDir)) {
        $dir = rtrim((string) $config->configAndLogDir, DIRECTORY_SEPARATOR);
        $candidates[] = dirname($dir, 3) . DIRECTORY_SEPARATOR . 'civicrm.settings.php';
        $candidates[] = dirname($dir, 2) . DIRECTORY_SEPARATOR . 'civicrm.settings.php';
      }
    }
    catch (\Throwable $e) {
      // Ignore config discovery errors; other candidates may still work.
    }

    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
      $candidates[] = rtrim((string) $_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR) . '/sites/default/civicrm.settings.php';
    }

    return array_values(array_unique(array_filter($candidates, 'is_string')));
  }

  private function readSyncDirFromSettingsFile(string $file): ?string {
    if ($file === '' || !is_file($file) || !is_readable($file)) {
      return NULL;
    }
    $contents = (string) file_get_contents($file);
    if (strpos($contents, 'civicfg_sync_dir') === FALSE) {
      return NULL;
    }
    $pattern = '/\$civicrm_setting\s*\[\s*[\"\']domain[\"\']\s*\]\s*\[\s*[\"\']civicfg_sync_dir[\"\']\s*\]\s*=\s*([\"\'])(.*?)\1\s*;/s';
    if (preg_match($pattern, $contents, $matches) && trim($matches[2]) !== '') {
      return stripcslashes($matches[2]);
    }
    return NULL;
  }

  private function saveSettings(): void {
    if ($this->getCodeDefinedSyncDir() === NULL) {
      $syncDir = trim((string) ($_POST['sync_dir'] ?? ''));
      if ($syncDir === '') {
        throw new RuntimeException('Sync Directory Is Required.');
      }
      if (preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $syncDir)) {
        throw new RuntimeException('Sync Directory Must Be A Server File Path, Not A URL.');
      }
      \Civi::settings()->set('civicfg_sync_dir', $syncDir);
    }

    $this->manager->getSiteIdentifier();
    $this->manager->setCrossSiteImportAllowed(!empty($_POST['allow_cross_site_import']));

    if (!$this->manager->isScopePolicyOverridden()) {
      $modes = is_array($_POST['scope_mode'] ?? NULL) ? $_POST['scope_mode'] : [];
      $selectors = is_array($_POST['scope_selectors'] ?? NULL) ? $_POST['scope_selectors'] : [];
      $watchUnmanaged = is_array($_POST['scope_watch_unmanaged'] ?? NULL) ? $_POST['scope_watch_unmanaged'] : [];
      $policies = [];
      foreach ($this->manager->getScopeTypeOptions() as $row) {
        $type = (string) $row['type'];
        $mode = strtolower(trim((string) ($modes[$type] ?? $this->manager->getScopeDefaultMode())));
        if (!in_array($mode, ['all', 'selected', 'watch', 'ignore'], TRUE)) {
          $mode = $this->manager->getScopeDefaultMode();
        }

        // An unavailable provider cannot safely enter a new managed/watch
        // state. Preserve its existing policy while administrators edit other
        // settings, but always allow an explicit switch to Ignore so a broken
        // or removed optional provider can be taken out of scope safely.
        if (($row['capability'] ?? '') === 'unavailable' && $mode !== 'ignore') {
          $existingPolicy = $this->manager->getScopePolicy($type);
          $policies[$type] = [
            'mode' => (string) ($existingPolicy['mode'] ?? $this->manager->getScopeDefaultMode()),
            'selectors' => array_values((array) ($existingPolicy['selectors'] ?? [])),
            'watch_unmanaged' => !empty($existingPolicy['watch_unmanaged']),
          ];
          continue;
        }

        $rawSelectors = (string) ($selectors[$type] ?? '');
        $selectorList = preg_split('/[\r\n,]+/', $rawSelectors) ?: [];
        $selectorList = array_values(array_unique(array_filter(array_map('trim', $selectorList), 'strlen')));
        $policies[$type] = [
          'mode' => $mode,
          'selectors' => $mode === 'selected' ? $selectorList : [],
          'watch_unmanaged' => $mode === 'selected' && !empty($watchUnmanaged[$type]),
        ];
      }
      $this->manager->saveScopePolicies($policies);
    }

    $allowlistRaw = (string) ($_POST['settings_allowlist'] ?? '');
    $allowlist = preg_split('/[\r\n,]+/', $allowlistRaw);
    $allowlist = array_values(array_unique(array_filter(array_map('trim', $allowlist))));
    \Civi::settings()->set('civicfg_settings_allowlist', $allowlist);

    $ignoreRaw = (string) ($_POST['ignore_paths'] ?? '');
    $ignoreRules = preg_split('/[\r\n,]+/', $ignoreRaw) ?: [];
    $this->manager->setConfiguredIgnoreRules($ignoreRules);

    // Settings/scope/ignore changes alter what a future managed comparison
    // means. Scope saving initializes any newly enabled watch baseline and
    // stores that summary, so do not erase the new watch fingerprints here.
    \Civi::settings()->set('civicfg_last_health', []);
  }

  private function getSyncDirLockMessage(): string {
    return ts('This value is set in civicrm.settings.php and cannot be edited from the UI.');
  }

  private function jsonDiffDetail(): void {
    try {
      $path = isset($_REQUEST['path']) ? trim((string) $_REQUEST['path']) : '';
      if ($path === '') {
        throw new RuntimeException('Choose a managed YAML path.');
      }
      $payload = $this->manager->getDiffDetail($path);
    }
    catch (\Throwable $e) {
      $payload = ['ok' => FALSE, 'error' => $e->getMessage()];
    }

    $this->emitJsonAndExit($payload);
  }

  /**
   * Lazily expose metadata-only provider inventory for the Settings browser.
   *
   * This endpoint deliberately runs only after the page is interactive. The
   * underlying inventory contract forbids provider collection reads, YAML
   * inspection, and configuration writes.
   */
  private function jsonProviderInventory(): void {
    try {
      $payload = $this->manager->getProviderInventory();
    }
    catch (\Throwable $e) {
      $payload = [
        'ok' => FALSE,
        'error' => $e->getMessage(),
      ];
    }

    $this->emitJsonAndExit($payload);
  }

  private function jsonScopeOptions(): void {
    try {
      $type = isset($_REQUEST['scope_type']) ? trim((string) $_REQUEST['scope_type']) : '';
      if ($type === '') {
        throw new RuntimeException('Choose a configuration type.');
      }
      $payload = ['ok' => TRUE] + $this->manager->getScopePickerItems($type);
    }
    catch (\Throwable $e) {
      $payload = [
        'ok' => FALSE,
        'error' => $e->getMessage(),
      ];
    }

    $this->emitJsonAndExit($payload);
  }

  private function assignTemplate(string $op, array $types, array $result, $notice, $validationResult, $importResult, $exportResult, $importSummary): void {
    // Settings already fetched status in run(); reuse it instead of doing the
    // same filesystem/handler status work twice in one page request.
    $status = ($op === 'settings' && isset($result['types']))
      ? $result
      : $this->manager->status();
    $diffResult = in_array($op, ['sync', 'import'], TRUE)
      ? $result
      : ['ok' => TRUE, 'items' => []];
    if (!isset($diffResult['items']) || !is_array($diffResult['items'])) {
      $diffResult['items'] = [];
    }
    // Settings needs only base scope types; do not discover extension-owned
    // virtual providers just to render the settings form.
    $allTypes = $op === 'settings'
      ? []
      : $this->presenter->buildTypeRows($this->manager, $diffResult, $status);
    $settingsAllowlist = (array) \Civi::settings()->get('civicfg_settings_allowlist');
    foreach (['menubar_color', 'menubar_position'] as $recommendedSetting) {
      if (!in_array($recommendedSetting, $settingsAllowlist, TRUE)) {
        $settingsAllowlist[] = $recommendedSetting;
      }
    }
    sort($settingsAllowlist, SORT_NATURAL | SORT_FLAG_CASE);
    $ignoreRules = $this->manager->getIgnoreRules();
    $siteId = $this->manager->getSiteIdentifier();
    $allowCrossSiteImport = (bool) \Civi::settings()->get('civicfg_allow_cross_site_import');
    $allDiffFiles = $this->presenter->extractDiffFiles($diffResult);
    foreach ($allDiffFiles as &$diffFile) {
      $diffType = (string) ($diffFile['type'] ?? '');
      $diffFile['delete_allowed'] = $diffType !== '' && $this->manager->allowsDeleteMissingForType($diffType);
    }
    unset($diffFile);
    $allImportPlan = $this->presenter->buildImportPlan($allDiffFiles);
    $importApplyTypes = $this->presenter->getImportApplyTypes($allImportPlan, $types);

    // Large sites can have thousands of changed objects. Keep the page/DOM
    // bounded while preserving the full compact diff result for counts and
    // import type planning.
    $diffPerPage = 100;
    $diffTotal = count($allDiffFiles);
    $diffPageCount = max(1, (int) ceil($diffTotal / $diffPerPage));
    $diffPage = isset($_REQUEST['diff_page']) ? max(1, (int) $_REQUEST['diff_page']) : 1;
    $diffPage = min($diffPage, $diffPageCount);
    $diffOffset = ($diffPage - 1) * $diffPerPage;
    $diffFiles = array_slice($allDiffFiles, $diffOffset, $diffPerPage);
    $importPlan = array_slice($allImportPlan, $diffOffset, $diffPerPage);
    $diffPageBaseQuery = 'reset=1&op=' . rawurlencode($op);
    foreach ($types as $selectedType) {
      $diffPageBaseQuery .= '&type[]=' . rawurlencode((string) $selectedType);
    }
    $diffPrevUrl = $diffPage > 1
      ? \CRM_Utils_System::url('civicrm/admin/config-manager', $diffPageBaseQuery . '&diff_page=' . ($diffPage - 1), FALSE, NULL, FALSE)
      : '';
    $diffNextUrl = $diffPage < $diffPageCount
      ? \CRM_Utils_System::url('civicrm/admin/config-manager', $diffPageBaseQuery . '&diff_page=' . ($diffPage + 1), FALSE, NULL, FALSE)
      : '';

    if ($op === 'import' && $importResult === NULL && $importApplyTypes) {
      try {
        $importResult = $this->manager->import(TRUE, FALSE, $importApplyTypes);
      }
      catch (Exception $e) {
        $importResult = [
          'ok' => FALSE,
          'error' => $e->getMessage(),
        ];
      }
    }

    $effectiveExportTypes = $this->manager->getEffectiveExportTypeFilter($types);
    $exportDependencyTypes = $types ? array_values(array_diff($effectiveExportTypes, $types)) : [];
    $exportDeletePlanned = [];
    if ($op === 'sync') {
      foreach ((array) ($diffResult['items'] ?? []) as $diffItem) {
        $diffType = (string) ($diffItem['type'] ?? '');
        if ($diffType === '' || !$this->manager->allowsDeleteMissingForType($diffType)) {
          continue;
        }
        foreach ((array) ($diffItem['files'] ?? []) as $diffFile) {
          if (($diffFile['status'] ?? '') === 'missing_in_db' && !empty($diffFile['path'])) {
            $exportDeletePlanned[] = (string) $diffFile['path'];
          }
        }
      }
      $exportDeletePlanned = array_values(array_unique($exportDeletePlanned));
    }
    $exportNeedsConfirmation = !empty($exportDependencyTypes) || !empty($exportDeletePlanned);
    $exportConfirmMessage = !empty($exportDeletePlanned)
      ? ts('Export will update Saved Configs from Current CiviCRM and delete stale managed Saved Config file(s) that no longer exist in CiviCRM. Review the changed files before continuing.')
      : ts('The selected filter has related dependency types. Export will include those related Saved Config files too so the configuration can deploy safely.');
    $exportConfirmWarning = !empty($exportDeletePlanned)
      ? ts('Stale Saved Config files to delete: %1', [1 => implode(', ', array_slice($exportDeletePlanned, 0, 10)) . (count($exportDeletePlanned) > 10 ? ' ...' : '')])
      : ts('Export writes Current CiviCRM configuration to YAML. Related dependency files will also be exported so the exported set stays deployable.');
    $exportItems = $op === 'export' ? $this->files->buildExportItemsFromPreview($result) : [];
    $selectedExportItem = $op === 'export' ? $this->request->getSingleExportKey() : '';
    $singleExport = NULL;
    if ($op === 'export' && $selectedExportItem !== '') {
      try {
        $singleExport = $this->files->loadSingleExport($this->manager, $selectedExportItem);
        $singleExport['has_value'] = TRUE;
      }
      catch (Exception $e) {
        $singleExport = ['error' => $e->getMessage(), 'has_value' => FALSE];
      }
    }

    $allTypeKeys = array_map(fn($row) => (string) $row['type'], $allTypes);
    $extensionManagedTypeCount = count(array_filter($allTypes, static fn(array $row): bool => !empty($row['virtual'])));
    $selectedTypesMap = array_fill_keys($allTypeKeys, FALSE);
    foreach ($types as $type) {
      $selectedTypesMap[(string) $type] = TRUE;
    }
    $importApplyTypesMap = array_fill_keys($allTypeKeys, FALSE);
    foreach ($importApplyTypes as $type) {
      $importApplyTypesMap[(string) $type] = TRUE;
    }
    $scopeRows = [];
    $scopeConfiguredCount = 0;
    foreach ($this->manager->getScopeTypeOptions() as $row) {
      $type = (string) $row['type'];
      $policy = $this->manager->getScopePolicy($type);
      $mode = (string) ($policy['mode'] ?? $this->manager->getScopeDefaultMode());
      if ($mode !== 'ignore') {
        $scopeConfiguredCount++;
      }
      $scopeRows[] = $row + [
        'mode' => $mode,
        'mode_all' => $mode === 'all',
        'mode_selected' => $mode === 'selected',
        'mode_watch' => $mode === 'watch',
        'mode_ignore' => $mode === 'ignore',
        'selectors_text' => implode("\n", array_map('strval', (array) ($policy['selectors'] ?? []))),
        'selector_count' => count((array) ($policy['selectors'] ?? [])),
        'watch_unmanaged' => !empty($policy['watch_unmanaged']),
      ];
    }
    $scopeDependencyWarnings = $this->manager->getScopeDependencyWarnings();
    $scopeSetupState = $this->manager->getScopeSetupState();
    $managedScopeConfigured = !empty($scopeSetupState['managed']);
    $watchedScopeConfigured = !empty($scopeSetupState['watched']);
    $watchOnlyScope = !empty($scopeSetupState['watch_only']);
    // Synchronize/import already performed an explicit diff, which owns the
    // legacy-tree check. Other tabs use only the cheap current manifest marker
    // and never recursively walk the YAML tree just to render the header. A
    // baseline is meaningful only after at least one managed scope exists.
    $hasCurrentManifest = $this->manager->hasCurrentManifest();
    $initialExportRequired = $managedScopeConfigured && (in_array($op, ['sync', 'import'], TRUE)
      ? !empty($diffResult['initial_export_required'])
      : !$hasCurrentManifest);
    if (!$hasCurrentManifest) {
      // A browser session can outlive an uninstall/reinstall performed through
      // CLI. Never render operation history from a configuration baseline that
      // no longer exists, even before a new managed scope has been selected.
      LifecycleStateCleaner::clearTransientSessionState();
      $exportResult = NULL;
      $importSummary = NULL;
    }
    $watchSummary = $this->manager->getWatchSummary();
    $watchHistory = $this->manager->getWatchHistory();
    $watchDetectedCount = (int) ($watchSummary['new'] ?? 0) + (int) ($watchSummary['changed'] ?? 0) + (int) ($watchSummary['missing'] ?? 0);
    $watchPanelOpen = $this->request->shouldOpenWatchPanel()
      || (int) ($watchSummary['baseline'] ?? 0) > 0
      || $watchDetectedCount > 0;
    $result += [
      'error' => NULL,
      'errors' => [],
      'items' => [],
      'planned' => [],
      'delete_planned' => [],
      'available' => [],
      'written' => [],
      'deleted' => [],
      'skipped' => [],
      'requested_types' => [],
      'effective_types' => [],
      'dependency_types' => [],
    ];
    $singleExportDefaults = [
      'error' => NULL,
      'has_value' => FALSE,
      'key' => '',
      'type' => '',
      'label' => '',
      'directory' => '',
      'file' => '',
      'path' => '',
      'yaml' => '',
      'download_url' => '',
    ];
    $singleExport = is_array($singleExport) ? ($singleExport + $singleExportDefaults) : $singleExportDefaults;

    $assetLoader = new AssetLoader();
    $assetLoader->addResources();

    $this->page->assign('criticalCss', $assetLoader->getCriticalCss());
    $this->page->assign('civicfgCsrfToken', \CRM_Core_Key::get('civicfg_config_manager'));
    $this->page->assign('op', $op);
    $this->page->assign('notice', $notice);
    $this->page->assign('result', $result);
    $this->page->assign('importResult', $importResult);
    $this->page->assign('lastExportResult', is_array($exportResult) ? $exportResult : []);
    $this->page->assign('lastImportSummary', is_array($importSummary) ? $importSummary : []);
    $importMessages = $this->presenter->extractImportMessages($importResult);
    $importErrorMessages = [];
    $importWarningMessages = [];
    foreach ($importMessages as $importMessage) {
      if (($importMessage['type'] ?? '') === 'error') {
        $importErrorMessages[] = $importMessage;
      }
      else {
        $importWarningMessages[] = $importMessage;
      }
    }
    $this->page->assign('importMessages', $importMessages);
    $this->page->assign('importErrorMessages', $importErrorMessages);
    $this->page->assign('importWarningMessages', $importWarningMessages);
    $this->page->assign('importErrorCount', count($importErrorMessages));
    $syncErrors = [];
    if ($op === 'sync') {
      foreach ((array) ($diffResult['errors'] ?? []) as $error) {
        $error = is_array($error) ? $error : ['message' => (string) $error];
        $message = trim((string) ($error['message'] ?? ''));
        if ($message === '') {
          continue;
        }
        $syncErrors[] = [
          'type' => trim((string) ($error['type'] ?? '')),
          'message' => $message,
        ];
      }
    }
    $this->page->assign('syncErrors', $syncErrors);
    $this->page->assign('validationResult', $validationResult);
    $this->page->assign('status', $status);
    $this->page->assign('allTypes', $allTypes);
    $this->page->assign('extensionManagedTypeCount', $extensionManagedTypeCount);
    $this->page->assign('selectedTypes', $types);
    $this->page->assign('selectedTypesMap', $selectedTypesMap);
    $this->page->assign('scopeRows', $scopeRows);
    $this->page->assign('scopeDependencyWarnings', $scopeDependencyWarnings);
    $this->page->assign('scopeDefaultMode', $this->manager->getScopeDefaultMode());
    $this->page->assign('scopeNeedsSetup', $scopeConfiguredCount === 0 && !$this->manager->isScopePolicyOverridden());
    $this->page->assign('scopeOverridden', $this->manager->isScopePolicyOverridden());
    $this->page->assign('scopeSelectorHelp', $this->manager->getScopeSelectorHelp());
    $this->page->assign('scopeSettingsExample', $this->manager->getScopeSettingsExample());
    $this->page->assign('scopeOptionsUrl', \CRM_Utils_System::url('civicrm/admin/config-manager', 'reset=1&op=scope-options-json', FALSE, NULL, FALSE));
    $this->page->assign('providerInventoryUrl', \CRM_Utils_System::url('civicrm/admin/config-manager', 'reset=1&op=provider-inventory-json', FALSE, NULL, FALSE));
    $this->page->assign('managedScopeConfigured', $managedScopeConfigured);
    $this->page->assign('watchedScopeConfigured', $watchedScopeConfigured);
    $this->page->assign('watchOnlyScope', $watchOnlyScope);
    $this->page->assign('initialExportRequired', $initialExportRequired);
    $this->page->assign('watchSummary', $watchSummary);
    $this->page->assign('watchHistory', $watchHistory);
    $this->page->assign('watchDetectedCount', $watchDetectedCount);
    $this->page->assign('watchPanelOpen', $watchPanelOpen);
    $this->page->assign('settingsAllowlist', implode("\n", $settingsAllowlist));
    $this->page->assign('ignorePaths', implode("\n", $ignoreRules));
    $this->page->assign('siteId', $siteId);
    $this->page->assign('allowCrossSiteImport', $allowCrossSiteImport);
    $codeDefinedSyncDir = $this->getCodeDefinedSyncDir();
    $savedSyncDir = trim((string) \Civi::settings()->get('civicfg_sync_dir'));
    if ($savedSyncDir === '' || $savedSyncDir === '../civicrm-config') {
      $savedSyncDir = $this->manager->getDefaultSyncDirSetting();
    }
    $this->page->assign('syncDir', $codeDefinedSyncDir ?: $savedSyncDir);
    $this->page->assign('syncDirLocked', $codeDefinedSyncDir !== NULL);
    $this->page->assign('syncDirLockValue', $codeDefinedSyncDir ?: '');
    $this->page->assign('syncDirLockMessage', $this->getSyncDirLockMessage());
    $this->page->assign('tabs', $this->presenter->buildTabs($op));
    $this->page->assign('summary', $this->presenter->buildSummary($diffResult, $status, $op));
    $this->page->assign('diffResult', $diffResult);
    $this->page->assign('diffFiles', $diffFiles);
    $this->page->assign('diffTotal', $diffTotal);
    $this->page->assign('diffPage', $diffPage);
    $this->page->assign('diffPageCount', $diffPageCount);
    $this->page->assign('diffPerPage', $diffPerPage);
    $this->page->assign('diffPrevUrl', $diffPrevUrl);
    $this->page->assign('diffNextUrl', $diffNextUrl);
    $this->page->assign('diffDetailUrl', \CRM_Utils_System::url('civicrm/admin/config-manager', 'reset=1&op=diff-detail-json', FALSE, NULL, FALSE));
    $this->page->assign('importPlan', $importPlan);
    $this->page->assign('importApplyTypes', $importApplyTypes);
    $this->page->assign('importApplyTypesMap', $importApplyTypesMap);
    $this->page->assign('effectiveExportTypes', $effectiveExportTypes);
    $this->page->assign('exportDependencyTypes', $exportDependencyTypes);
    $this->page->assign('exportDependencyTypeLabels', $this->presenter->labelsForTypes($this->manager, $exportDependencyTypes));
    $this->page->assign('exportDeletePlanned', $exportDeletePlanned);
    $this->page->assign('exportNeedsConfirmation', $exportNeedsConfirmation);
    $this->page->assign('exportConfirmMessage', $exportConfirmMessage);
    $this->page->assign('exportConfirmWarning', $exportConfirmWarning);
    $this->page->assign('exportItems', $exportItems);
    $this->page->assign('selectedExportItem', $selectedExportItem);
    $this->page->assign('singleExport', $singleExport);
    $this->page->assign('zipAvailable', class_exists('ZipArchive'));
    $this->page->assign('canExport', Permission::has(Permission::EXPORT));
    $this->page->assign('canImport', Permission::has(Permission::IMPORT));
    $this->page->assign('canAdminister', Permission::has(Permission::ADMINISTER));
  }
}
