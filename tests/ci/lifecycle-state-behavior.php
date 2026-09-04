<?php

declare(strict_types=1);

final class CivicfgLifecycleFakeSettings {
  /** @var array<string,mixed> */
  private array $values = [];

  public function get(string $name) {
    return $this->values[$name] ?? NULL;
  }

  public function set(string $name, $value): void {
    $this->values[$name] = $value;
  }
}

final class Civi {
  private static ?CivicfgLifecycleFakeSettings $settings = NULL;

  public static function settings(): CivicfgLifecycleFakeSettings {
    if (self::$settings === NULL) {
      self::$settings = new CivicfgLifecycleFakeSettings();
    }
    return self::$settings;
  }
}

final class CRM_Core_Session {
  private static ?self $instance = NULL;
  /** @var array<string,mixed> */
  private array $values = [];

  public static function singleton(): self {
    if (self::$instance === NULL) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  public function get(string $name) {
    return $this->values[$name] ?? NULL;
  }

  public function set(string $name, $value): void {
    $this->values[$name] = $value;
  }
}

require_once dirname(__DIR__, 2) . '/Civi/ConfigManager/Service/LifecycleStateCleaner.php';

use Civi\ConfigManager\Service\LifecycleStateCleaner;

$checks = 0;
$assert = static function(bool $condition, string $message) use (&$checks): void {
  $checks++;
  if (!$condition) {
    fwrite(STDERR, "lifecycle state behavior failed: {$message}\n");
    exit(1);
  }
};

$staleSettings = [
  'civicfg_scope_default_mode' => 'all',
  'civicfg_scope' => ['tags' => ['mode' => 'selected', 'selectors' => ['Old']]],
  'civicfg_scope_resolved' => ['tags' => ['1' => 'Old']],
  'civicfg_last_health' => ['status' => 'stale'],
  'civicfg_watch_summary' => ['changed' => 2],
  'civicfg_watch_history' => [['changed' => 2]],
];
foreach ($staleSettings as $name => $value) {
  Civi::settings()->set($name, $value);
}
$preserved = [
  'civicfg_sync_dir' => '/custom/sync',
  'civicfg_site_id' => 'portable-family',
  'civicfg_allow_cross_site_import' => 1,
  'civicfg_ignore_paths' => ['settings/example.yml:key'],
  'civicfg_settings_allowlist' => ['theme_backend'],
];
foreach ($preserved as $name => $value) {
  Civi::settings()->set($name, $value);
}
foreach (['civicfg_last_validation_result', 'civicfg_last_export_result', 'civicfg_last_import_summary', 'civicfg_last_import_result'] as $key) {
  CRM_Core_Session::singleton()->set($key, ['stale' => TRUE]);
}
$GLOBALS['civicrm_setting'] = [
  'domain' => [
    'civicfg_scope' => ['tags' => ['mode' => 'selected', 'selectors' => ['CodeOwned']]],
  ],
];
$codeOwnedBefore = $GLOBALS['civicrm_setting'];

LifecycleStateCleaner::resetLocalState();

$assert(Civi::settings()->get('civicfg_scope_default_mode') === 'ignore', 'fresh install must use Ignore by default');
$assert(Civi::settings()->get('civicfg_scope') === [], 'stale persisted scope must be cleared');
$assert(Civi::settings()->get('civicfg_scope_resolved') === [], 'stale resolved dependency selectors must be cleared');
$assert(Civi::settings()->get('civicfg_last_health') === [], 'stale health state must be cleared');
$assert(Civi::settings()->get('civicfg_watch_summary') === [], 'stale watch summary must be cleared');
$assert(Civi::settings()->get('civicfg_watch_history') === [], 'stale watch history must be cleared');
foreach (['civicfg_last_validation_result', 'civicfg_last_export_result', 'civicfg_last_import_summary', 'civicfg_last_import_result'] as $key) {
  $assert(CRM_Core_Session::singleton()->get($key) === NULL, $key . ' must be cleared');
}
foreach ($preserved as $name => $value) {
  $assert(Civi::settings()->get($name) === $value, $name . ' must be preserved');
}
$assert($GLOBALS['civicrm_setting'] === $codeOwnedBefore, 'civicrm.settings.php overrides must remain untouched');

echo "lifecycle state behavior OK ({$checks} checks)\n";
