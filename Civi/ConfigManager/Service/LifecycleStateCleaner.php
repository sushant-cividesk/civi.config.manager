<?php

namespace Civi\ConfigManager\Service;

/**
 * Reset Configuration Manager-owned local lifecycle state.
 *
 * User configuration that defines filesystem location, portable site identity,
 * ignore rules, allowlists, or cross-site policy is intentionally preserved.
 */
final class LifecycleStateCleaner {

  /** @return array<string,mixed> */
  public static function freshInstallSettingDefaults(): array {
    return [
      'civicfg_scope_default_mode' => 'ignore',
      'civicfg_scope' => [],
      'civicfg_scope_resolved' => [],
      'civicfg_last_health' => [],
      'civicfg_watch_summary' => [],
      'civicfg_watch_history' => [],
    ];
  }

  /** @return string[] */
  public static function transientSessionKeys(): array {
    return [
      'civicfg_last_validation_result',
      'civicfg_last_export_result',
      'civicfg_last_import_summary',
      'civicfg_last_import_result',
    ];
  }

  public static function resetLocalState(): void {
    if (class_exists('Civi')) {
      foreach (self::freshInstallSettingDefaults() as $name => $value) {
        \Civi::settings()->set($name, $value);
      }
    }
    self::clearTransientSessionState();
  }

  public static function clearTransientSessionState(): void {
    if (!class_exists('CRM_Core_Session')) {
      return;
    }
    $session = \CRM_Core_Session::singleton();
    foreach (self::transientSessionKeys() as $key) {
      $session->set($key, NULL);
    }
  }

}
