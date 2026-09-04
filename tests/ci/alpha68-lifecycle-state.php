<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$main = file_get_contents($root . '/Civi/ConfigManager/UI/MainPage.php');
$entry = file_get_contents($root . '/configmanager.php');
$cleaner = file_get_contents($root . '/Civi/ConfigManager/Service/LifecycleStateCleaner.php');

$checks = 0;
$assert = static function(bool $condition, string $message) use (&$checks): void {
  $checks++;
  if (!$condition) {
    fwrite(STDERR, "alpha68 lifecycle state contract failed: {$message}\n");
    exit(1);
  }
};

$assert(substr_count($entry, 'LifecycleStateCleaner::resetLocalState()') >= 2, 'fresh install and uninstall must both reset extension-local lifecycle state');
$assert(strpos($main, 'if (!$hasCurrentManifest)') !== FALSE, 'stale operation history must be invalidated whenever the manifest is missing, including all-Ignore fresh installs');
$assert(strpos($main, 'LifecycleStateCleaner::clearTransientSessionState()') !== FALSE, 'missing baseline must invalidate stale browser operation history');
$assert(strpos($main, '$exportResult = NULL;') !== FALSE, 'stale Last Export must be suppressed when the baseline is missing');
$assert(strpos($main, '$importSummary = NULL;') !== FALSE, 'stale Last Import must be suppressed when the baseline is missing');
foreach (['civicfg_scope_default_mode', 'civicfg_scope', 'civicfg_scope_resolved', 'civicfg_last_health', 'civicfg_watch_summary', 'civicfg_watch_history'] as $setting) {
  $assert(strpos($cleaner, "'{$setting}'") !== FALSE, $setting . ' must be reset as lifecycle-local state');
}
foreach (['civicfg_sync_dir', 'civicfg_site_id', 'civicfg_allow_cross_site_import', 'civicfg_ignore_paths', 'civicfg_settings_allowlist'] as $preserved) {
  $assert(strpos($cleaner, "'{$preserved}' =>") === FALSE, $preserved . ' must not be reset by lifecycle cleanup');
}

// Disable/enable must remain non-destructive: its hook delegates only to the
// normal schema/CLI lifecycle and must not invoke a reset directly.
if (preg_match('/function configmanager_civicrm_enable\(\) \{(.*?)\n\}/s', $entry, $match)) {
  $assert(strpos($match[1], 'resetLocalState') === FALSE, 'enable must preserve configuration state');
}
else {
  $assert(FALSE, 'enable hook must remain present');
}

echo "alpha68 lifecycle state contract OK ({$checks} checks)\n";
