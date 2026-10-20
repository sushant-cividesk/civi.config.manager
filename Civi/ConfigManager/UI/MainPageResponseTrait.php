<?php
namespace Civi\ConfigManager\UI;

use Civi\ConfigManager\Service\LifecycleStateCleaner;

/**
 * MainPageResponse methods from MainPage.
 *
 * Methods run on the original owning object and retain the same state and
 * access modifiers. Do not instantiate this trait separately.
 */
trait MainPageResponseTrait {

  private function jsonDiffDetail(): void {
    try {
      $path = isset($_REQUEST['path']) ? trim((string) $_REQUEST['path']) : '';
      if ($path === '') {
        throw new \RuntimeException('Choose a managed Saved Config path.');
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
        throw new \RuntimeException('Choose a configuration type.');
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

    $importPlanId = '';
    $importReviewCoordinator = new ImportReviewPlanCoordinator($this->manager);
    if ($op === 'import' && $importResult === NULL && $importApplyTypes) {
      try {
        $review = $importReviewCoordinator->loadOrCreate($importApplyTypes);
        $importResult = $review['result'];
        $importPlanId = $review['plan_id'];
      }
      catch (\Exception $e) {
        $importResult = [
          'ok' => FALSE,
          'error' => $e->getMessage(),
        ];
      }
    }
    $importExcludedComponents = $op === 'import' ? $importReviewCoordinator->getExcludedComponents($importApplyTypes) : [];

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
      catch (\Exception $e) {
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
    $this->page->assign('importReadiness', $this->presenter->getImportReadiness(
      Permission::has(Permission::IMPORT),
      (array) $importApplyTypes,
      count($importErrorMessages),
      is_array($importResult) ? $importResult : NULL,
      (string) $importPlanId,
      (array) ($importResult['dependency_components'] ?? [])
    ));
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
    $this->page->assign('diffPageBaseQuery', $diffPageBaseQuery);
    $this->page->assign('diffDetailUrl', \CRM_Utils_System::url('civicrm/admin/config-manager', 'reset=1&op=diff-detail-json', FALSE, NULL, FALSE));
    $this->page->assign('importPlan', $importPlan);
    $this->page->assign('importPlanId', $importPlanId);
    $this->page->assign('importDependencyComponents', (array) ($importResult['dependency_components'] ?? []));
    $this->page->assign('importExcludedComponents', $importExcludedComponents);
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
