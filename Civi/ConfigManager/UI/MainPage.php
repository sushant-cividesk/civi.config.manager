<?php
namespace Civi\ConfigManager\UI;

use Civi\ConfigManager\Service\ConfigManager;

// Include stateful concerns for direct CiviCRM/legacy bootstrap loaders too.
require_once __DIR__ . '/MainPageOperationEndpointsTrait.php';
require_once __DIR__ . '/MainPageResponseTrait.php';
require_once __DIR__ . '/MainPageSettingsTrait.php';

/**
 * Controller for the admin UI. Keeps the CRM page class thin and delegates
 * presentation/file-transfer details to focused helper classes.
 */
class MainPage {
  use MainPageOperationEndpointsTrait;
  use MainPageResponseTrait;
  use MainPageSettingsTrait;


  /**
   * @var \CRM_Core_Page
   */
  private \CRM_Core_Page $page;
  /**
   * @var \Civi\ConfigManager\Service\ConfigManager
   */
  private ConfigManager $manager;
  /**
   * @var Request
   */
  private Request $request;
  /**
   * @var Presenter
   */
  private Presenter $presenter;
  /**
   * @var OperationResultPresenter
   */
  private OperationResultPresenter $operationResultPresenter;
  /**
   * @var ImportActionCoordinator
   */
  private ImportActionCoordinator $importActions;
  /**
   * @var FileTransfer
   */
  private FileTransfer $files;
  /**
   * @var Permission
   */
  private Permission $permission;

  public function __construct(\CRM_Core_Page $page, ?ConfigManager $manager = NULL) {
    $this->page = $page;
    $this->manager = $manager ?: new ConfigManager();
    $this->request = new Request();
    $this->presenter = new Presenter();
    $this->operationResultPresenter = new OperationResultPresenter();
    $this->importActions = new ImportActionCoordinator($this->manager, $this->operationResultPresenter);
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
          catch (\Exception $e) {
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
            throw new \RuntimeException('Select at least one field to ignore, or choose whole file.');
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
        $outcome = $this->importActions->apply($this->request->requireImportPlanId());
        $this->redirectWithNotice($outcome['notice'], 'sync', $outcome['type']);
      }
      elseif ($postAction === 'import_exclude_component') {
        $outcome = $this->importActions->excludeDependencyComponent($this->request->requireDependencyComponentId(), $types);
        $query = implode('&', array_map(static fn(string $type): string => 'type[]=' . rawurlencode($type), $outcome['remaining_types']));
        $this->redirectWithNotice($outcome['notice'], 'import', $outcome['type'], $query);
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
    catch (\Exception $e) {
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
      throw new \RuntimeException('Invalid or expired Configuration Manager form token. Reload the page and try again.');
    }
  }

}
