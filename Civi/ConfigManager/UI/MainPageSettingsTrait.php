<?php
namespace Civi\ConfigManager\UI;

/**
 * MainPageSettings methods from MainPage.
 *
 * Methods run on the original owning object and retain the same state and
 * access modifiers. Do not instantiate this trait separately.
 */
trait MainPageSettingsTrait {

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
        throw new \RuntimeException('Sync Directory Is Required.');
      }
      if (preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $syncDir)) {
        throw new \RuntimeException('Sync Directory Must Be A Server File Path, Not A URL.');
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

}
