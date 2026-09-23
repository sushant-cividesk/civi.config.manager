<?php
namespace Civi\ConfigManager\Service;

use Civi\ConfigManager\Version;

/**
 * Stable persistence/fingerprint policy for reviewed Import plans.
 */
final class ImportReviewPlanGuard {
  private ImportPlanStore $store;
  private ConfigScope $scope;

  public function __construct(ImportPlanStore $store, ConfigScope $scope) {
    $this->store = $store;
    $this->scope = $scope;
  }

  public function create(array $plan): array { return $this->store->create($plan); }
  public function load(string $planId): array { return $this->store->load($planId); }
  public function discard(string $planId): void { $this->store->delete($planId); }
  public function fingerprint($value): string { return $this->store->fingerprint($value); }

  public function scopeFingerprint(array $handlers, array $ignoreRules): string {
    $policies = [];
    foreach ($handlers as $handler) {
      $type = (string) $handler->getType();
      $policy = (array) $this->scope->getPolicy($type);
      $policies[$type] = [
        'mode' => (string) ($policy['mode'] ?? ''),
        'selectors' => $this->sortedStrings((array) ($policy['selectors'] ?? [])),
        'watch_unmanaged' => !empty($policy['watch_unmanaged']),
      ];
    }
    ksort($policies, SORT_STRING);
    return $this->fingerprint([
      'policies' => $policies,
      'policy_overridden' => $this->scope->isPolicyOverridden(),
      'ignore_rules' => $this->sortedStrings($ignoreRules),
      'settings_allowlist' => $this->sortedStrings((array) \Civi::settings()->get('civicfg_settings_allowlist')),
      'allow_cross_site_import' => (bool) \Civi::settings()->get('civicfg_allow_cross_site_import'),
    ]);
  }

  public function providerFingerprint(array $handlers): string {
    $providers = [];
    foreach ($handlers as $handler) {
      $type = (string) $handler->getType();
      $providers[$type] = [
        'class' => get_class($handler),
        'directory' => (string) $handler->getDirectory(),
        'delete_missing_allowed' => $this->scope->allowsDeleteMissing($type),
        'metadata' => method_exists($handler, 'getProviderMetadata') ? (array) $handler->getProviderMetadata() : [],
        'runtime' => method_exists($handler, 'getRuntimeAvailability') ? (array) $handler->getRuntimeAvailability() : [],
      ];
    }
    ksort($providers, SORT_STRING);
    return $this->fingerprint($providers);
  }

  public function implementationFingerprint(array $handlers): string {
    $classes = [
      ConfigManager::class,
      ConfigScope::class,
      ConfigIdentity::class,
      Canonicalizer::class,
      ImportPlanStore::class,
      self::class,
      \Civi\ConfigManager\Storage\YamlFileStorage::class,
    ];
    foreach ($handlers as $handler) {
      $classes[] = get_class($handler);
    }
    $classes = array_values(array_unique($classes));
    sort($classes, SORT_STRING);
    $hashes = [];
    foreach ($classes as $class) {
      try {
        $reflection = new \ReflectionClass($class);
        $file = (string) ($reflection->getFileName() ?: '');
        $hashes[$class] = $file !== '' && is_file($file) ? (string) hash_file('sha256', $file) : '';
      }
      catch (\Throwable $e) {
        $hashes[$class] = '';
      }
    }
    $civiVersion = class_exists('CRM_Utils_System') ? (string) \CRM_Utils_System::version() : '';
    return $this->fingerprint([
      'extension_version' => Version::get(),
      'civicrm_version' => $civiVersion,
      'php_version' => PHP_VERSION,
      'classes' => $hashes,
    ]);
  }

  public function preflightFingerprint(array $preflight): string {
    unset($preflight['_active_fingerprints']);
    return $this->fingerprint([
      'ok' => !empty($preflight['ok']),
      'validation' => (array) ($preflight['validation'] ?? []),
      'items' => (array) ($preflight['items'] ?? []),
      'errors' => (array) ($preflight['errors'] ?? []),
      'possible_renames' => (array) ($preflight['possible_renames'] ?? []),
    ]);
  }

  public function currentUserId(): int {
    if (!class_exists('CRM_Core_Session') || !method_exists('CRM_Core_Session', 'getLoggedInContactID')) {
      return 0;
    }
    return (int) \CRM_Core_Session::getLoggedInContactID();
  }

  public function stale(string $reason): \RuntimeException {
    return new \RuntimeException('Import preview is stale because ' . $reason . '. No new changes were applied from this stale preview. Build and review a fresh Import preview before trying again.');
  }

  private function sortedStrings(array $values): array {
    $values = array_values(array_unique(array_map('strval', $values)));
    sort($values, SORT_STRING);
    return $values;
  }
}
