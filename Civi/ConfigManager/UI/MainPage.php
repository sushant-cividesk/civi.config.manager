<?php
namespace Civi\ConfigManager\UI;

use Civi\ConfigManager\Service\ConfigManager;
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
  private FileTransfer $files;
  private Permission $permission;

  public function __construct(\CRM_Core_Page $page, ?ConfigManager $manager = NULL) {
    $this->page = $page;
    $this->manager = $manager ?: new ConfigManager();
    $this->request = new Request();
    $this->presenter = new Presenter();
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
    $sessionImportResult = \CRM_Core_Session::singleton()->get('civicfg_last_import_result');
    if (is_array($sessionImportResult)) {
      $importResult = $sessionImportResult;
      \CRM_Core_Session::singleton()->set('civicfg_last_import_result', NULL);
    }
    $result = [];

    $this->permission->requireForPage($op, $postAction);

    try {
      if ($op === 'single-export-json') {
        $this->files->jsonSingleExport($this->manager);
      }
      elseif ($op === 'scope-options-json') {
        $this->jsonScopeOptions();
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
        if (!empty($_POST['allow_cross_site_import'])) {
          \CRM_Core_Session::setStatus(ts('Experimental cross-site import is enabled. Keep it off for normal dev/stage/prod synchronization and use it only for a reviewed one-off migration between different sites.'), ts('Configuration Manager'), 'warning');
        }
        $ignoreValueRaw = trim((string) ($_POST['ignore_values'] ?? ''));
        if ($ignoreValueRaw !== '') {
          \CRM_Core_Session::setStatus(ts('Config Ignore Values is active. Ignored YAML fields are excluded during diff, export, import, and preview so environments can keep local values. Do not ignore identity fields, dependency fields, or required configuration relationships.'), ts('Configuration Manager'), 'warning');
        }
        $ignoreRaw = trim((string) ($_POST['ignore_paths'] ?? ''));
        if ($ignoreRaw !== '') {
          \CRM_Core_Session::setStatus(ts('Config Ignore is active. Ignored YAML files are skipped during diff, validate, export, import, single-file preview, and ZIP download. Make sure ignored files are not required dependencies for non-ignored configuration.'), ts('Configuration Manager'), 'warning');
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
        $this->redirectWithNotice($notice, 'sync', !empty($watchResult['ok']) ? 'success' : 'warning', 'watch=1', 'civicfg-watch-panel');
      }
      elseif ($postAction === 'clear_watch_history') {
        $this->manager->clearWatchHistory();
        $this->redirectWithNotice(ts('Watch history cleared. Current watch fingerprints and monitoring baselines were not changed.'), 'sync', 'success', 'watch=1', 'civicfg-watch-panel');
      }
      elseif ($postAction === 'revert_file') {
        $path = trim((string) ($_POST['path'] ?? ''));
        $result = $this->manager->revertCiviFromYaml($path);
        $this->redirectWithNotice((string) ($result['message'] ?? ts('Active CiviCRM was reverted from YAML.')), 'sync', empty($result['ok']) ? 'error' : 'success');
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
        $written = !empty($exportResult['written']) ? count($exportResult['written']) : 0;
        $deleted = !empty($exportResult['deleted']) ? count($exportResult['deleted']) : 0;
        $skipped = !empty($exportResult['skipped']) ? count($exportResult['skipped']) : 0;
        $dependencyTypes = (array) ($exportResult['dependency_types'] ?? []);
        $notice = ($written || $deleted)
          ? ts('Export complete. %1 YAML file(s) updated, %2 stale YAML file(s) deleted, %3 unchanged file(s) skipped.', [1 => $written, 2 => $deleted, 3 => $skipped])
          : ts('Nothing to export. YAML files already match active CiviCRM configuration.');
        if ($dependencyTypes) {
          $notice .= ' ' . ts('Related dependency types were included automatically: %1.', [1 => implode(', ', $dependencyTypes)]);
        }
        if ($requestedTypes) {
          $notice .= ' ' . ts('The temporary filter was cleared so the Synchronize tab now shows the full managed status.');
        }
        $this->redirectWithNotice($notice, 'sync', empty($exportResult['errors']) ? 'success' : 'error');
      }
      elseif ($postAction === 'import_apply') {
        $importTypes = $this->request->getSelectedTypes();
        $importResult = $this->manager->import(FALSE, TRUE, $importTypes ?: []);
        \CRM_Core_Session::singleton()->set('civicfg_last_import_result', $importResult);
        $afterDiff = $this->manager->diff([]);
        $remaining = $this->presenter->countDiffChanges($afterDiff);
        $summaryMessage = (string) ($importResult['summary_message'] ?? '');
        if (!empty($importResult['ok']) && $remaining === 0) {
          $notice = trim(ts('Import complete. YAML files and active CiviCRM configuration now match.') . ' ' . $summaryMessage);
          $type = 'success';
        }
        elseif (!empty($importResult['ok'])) {
          $notice = trim(ts('Import ran, but %1 pending change(s) remain. Review the remaining changes below.', [1 => $remaining]) . ' ' . $summaryMessage);
          $type = 'warning';
        }
        else {
          $firstProblem = $this->presenter->firstImportProblem($importResult);
          if ($remaining === 0) {
            $notice = trim(ts('Import completed with non-blocking issue(s), and no pending configuration changes remain.') . ' ' . ($firstProblem ?: '') . ' ' . $summaryMessage);
            $type = 'warning';
          }
          else {
            $notice = trim(ts('Import found problems.') . ' ' . ($firstProblem ?: ts('Review the warnings or errors below.')) . ' ' . $summaryMessage);
            $type = 'error';
          }
        }
        $this->redirectWithNotice($notice, 'sync', $type);
      }
      elseif ($postAction === 'validate_files') {
        $validationResult = $this->manager->validate($types);
        $op = 'sync';
        $result = $this->manager->diff($types);

        \CRM_Core_Session::setStatus(
          !empty($validationResult['ok'])
            ? ts('Validation passed. No YAML format problems were found for the selected files.')
            : ts('Validation found problems. Review the validation details below.'),
          ts('Configuration Manager'),
          !empty($validationResult['ok']) ? 'success' : 'warning'
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

    $this->assignTemplate($op, $types, $result, $notice, $validationResult, $importResult);
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
    $ignorePaths = preg_split('/[\r\n,]+/', $ignoreRaw);
    $ignorePaths = array_values(array_unique(array_filter(array_map(function($value) {
      return trim(str_replace('\\', '/', (string) $value), '/');
    }, $ignorePaths))));
    \Civi::settings()->set('civicfg_ignore_paths', $ignorePaths);

    $ignoreValuesRaw = (string) ($_POST['ignore_values'] ?? '');
    $ignoreValues = preg_split('/[\r\n,]+/', $ignoreValuesRaw);
    $ignoreValues = array_values(array_unique(array_filter(array_map(function($value) {
      return trim(str_replace('\\', '/', (string) $value));
    }, $ignoreValues))));
    \Civi::settings()->set('civicfg_ignore_values', $ignoreValues);

    // Settings/scope/ignore changes alter what a future managed comparison
    // means. Scope saving initializes any newly enabled watch baseline and
    // stores that summary, so do not erase the new watch fingerprints here.
    \Civi::settings()->set('civicfg_last_health', []);
  }


  private function getSyncDirLockMessage(): string {
    return ts('This value is set in civicrm.settings.php and cannot be edited from the UI.');
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

    \CRM_Utils_System::setHttpHeader('Content-Type', 'application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    \CRM_Utils_System::civiExit();
  }

  private function assignTemplate(string $op, array $types, array $result, $notice, $validationResult, $importResult): void {
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
      : $this->presenter->buildTypeRows($this->manager, $diffResult);
    $settingsAllowlist = (array) \Civi::settings()->get('civicfg_settings_allowlist');
    foreach (['menubar_color', 'menubar_position'] as $recommendedSetting) {
      if (!in_array($recommendedSetting, $settingsAllowlist, TRUE)) {
        $settingsAllowlist[] = $recommendedSetting;
      }
    }
    sort($settingsAllowlist, SORT_NATURAL | SORT_FLAG_CASE);
    $ignorePaths = $this->manager->getIgnorePatterns();
    $ignoreValues = $this->manager->getIgnoreValuePatterns();
    $siteId = $this->manager->getSiteIdentifier();
    $allowCrossSiteImport = (bool) \Civi::settings()->get('civicfg_allow_cross_site_import');
    $diffFiles = $this->presenter->extractDiffFiles($diffResult);
    foreach ($diffFiles as &$diffFile) {
      $diffType = (string) ($diffFile['type'] ?? '');
      $diffFile['delete_allowed'] = $diffType !== '' && $this->manager->allowsDeleteMissingForType($diffType);
    }
    unset($diffFile);
    $importPlan = $this->presenter->buildImportPlan($diffFiles);
    $importApplyTypes = $this->presenter->getImportApplyTypes($importPlan, $types);

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
      ? ts('Export will update YAML from active CiviCRM and delete stale managed YAML file(s) that no longer exist in CiviCRM. Review the changed files before continuing.')
      : ts('The selected filter has related dependency types. Export will include those related YAML files too so the configuration can deploy safely.');
    $exportConfirmWarning = !empty($exportDeletePlanned)
      ? ts('Stale YAML files to delete: %1', [1 => implode(', ', array_slice($exportDeletePlanned, 0, 10)) . (count($exportDeletePlanned) > 10 ? ' ...' : '')])
      : ts('Export writes active CiviCRM configuration to YAML. Related dependency files will also be exported so the exported set stays deployable.');
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
    // Synchronize/import already performed an explicit diff, which owns the
    // legacy-tree check. Other tabs use only the cheap current manifest marker
    // and never recursively walk the YAML tree just to render the header.
    $initialExportRequired = in_array($op, ['sync', 'import'], TRUE)
      ? !empty($diffResult['initial_export_required'])
      : !$this->manager->hasCurrentManifest();
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
    $this->page->assign('op', $op);
    $this->page->assign('notice', $notice);
    $this->page->assign('result', $result);
    $this->page->assign('importResult', $importResult);
    $importMessages = $this->presenter->extractImportMessages($importResult);
    $importErrorCount = 0;
    foreach ($importMessages as $importMessage) {
      if (($importMessage['type'] ?? '') === 'error') {
        $importErrorCount++;
      }
    }
    $this->page->assign('importMessages', $importMessages);
    $this->page->assign('importErrorCount', $importErrorCount);
    $this->page->assign('validationResult', $validationResult);
    $this->page->assign('status', $status);
    $this->page->assign('allTypes', $allTypes);
    $this->page->assign('selectedTypes', $types);
    $this->page->assign('selectedTypesMap', $selectedTypesMap);
    $this->page->assign('scopeRows', $scopeRows);
    $this->page->assign('scopeDefaultMode', $this->manager->getScopeDefaultMode());
    $this->page->assign('scopeNeedsSetup', $scopeConfiguredCount === 0 && !$this->manager->isScopePolicyOverridden());
    $this->page->assign('scopeOverridden', $this->manager->isScopePolicyOverridden());
    $this->page->assign('scopeSelectorHelp', $this->manager->getScopeSelectorHelp());
    $this->page->assign('scopeSettingsExample', $this->manager->getScopeSettingsExample());
    $this->page->assign('scopeOptionsUrl', \CRM_Utils_System::url('civicrm/admin/config-manager', 'reset=1&op=scope-options-json'));
    $this->page->assign('initialExportRequired', $initialExportRequired);
    $this->page->assign('watchSummary', $watchSummary);
    $this->page->assign('watchHistory', $watchHistory);
    $this->page->assign('watchDetectedCount', $watchDetectedCount);
    $this->page->assign('watchPanelOpen', $watchPanelOpen);
    $this->page->assign('settingsAllowlist', implode("\n", $settingsAllowlist));
    $this->page->assign('ignorePaths', implode("\n", $ignorePaths));
    $this->page->assign('ignoreValues', implode("\n", array_map(fn($rule) => (string) ($rule['raw'] ?? ''), $ignoreValues)));
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
