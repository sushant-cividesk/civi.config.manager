<?php
/**
 * Dependency-free regression smoke for extracted Config Ignore and portable
 * provider-identity rules. Uses only disposable in-memory FakeCivi settings.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/tests/phpunit/Support/FakeCivi.php';
spl_autoload_register(static function (string $class) use ($root): void {
  $prefix = 'Civi\\ConfigManager\\';
  if (strpos($class, $prefix) !== 0) {
    return;
  }
  $path = $root . '/Civi/ConfigManager/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
  if (is_file($path)) {
    require_once $path;
  }
});
use Civi\ConfigManager\Service\ConfigManager;
use Civi\ConfigManager\Handler\ExtensionHandler;

$checked = 0;
$check = static function (bool $pass, string $description) use (&$checked): void {
  $checked++;
  if (!$pass) {
    fwrite(STDERR, 'Refactor boundary smoke FAILED: ' . $description . "\n");
    exit(1);
  }
};
$callPrivate = static function (object $object, string $method, array $args = []) {
  $reflection = new \ReflectionMethod($object, $method);
  $reflection->setAccessible(TRUE);
  return $reflection->invokeArgs($object, $args);
};
\Civi::settings()->reset();
$manager = new ConfigManager();
$check($manager->getBuiltInIgnoreRules() === [
  'extensions/civi.config.manager.yml',
  'extensions/*/api3/Job/*.yml:item.last_run',
  'extensions/*/api3/Job/*.yml:item.last_run_end',
  'scheduled-jobs/*.yml:item.scheduled_run_date',
  'site-tokens/*.yml:item.modified_date',
], 'unchanged built-in rule order');
$check($manager->shouldIgnorePath('extensions/civi.config.manager.yml'), 'built-in whole-file ignore');
$check(!$manager->shouldIgnorePath('scheduled-jobs/Example.yml'), 'value rules do not hide files');
$check($callPrivate($manager, 'isIgnoredPath', ['extensions/civi.config.manager.yml']), 'legacy internal ignore guard still excludes built-in paths');
$check(!$callPrivate($manager, 'isIgnoredPath', ['settings/unignored.yml']), 'internal ignore guard never excludes unrelated settings');
$check($manager->setConfiguredIgnoreRules([
  ' custom/docs/*.yml ',
  'settings/theme.yml:item.value',
  'settings/theme.yml:item.value',
  'extensions/civi.config.manager.yml',
  '../unsafe.yml',
]) === ['custom/docs/*.yml', 'settings/theme.yml:item.value'], 'canonical ignore normalization and deduplication');
$check($manager->shouldIgnorePath('custom/docs/alpha.yml'), 'wildcard file match');
$check($callPrivate($manager, 'isIgnoredPath', ['custom/docs/alpha.yml']), 'internal staged-export and import path checks respect configured wildcard ignores');
$check(!$manager->shouldIgnorePath('custom/other.yml'), 'unrelated file remains managed');
$check($manager->applyIgnoredValueRules('settings/theme.yml', [
  'item' => ['name' => 'theme', 'value' => 'dev'],
  'metadata' => TRUE,
]) === ['item' => ['name' => 'theme'], 'metadata' => TRUE], 'field-level ignores preserve sibling fields');
$check($manager->applyIgnoredValueRules('settings/unrelated.yml', [
  'item' => ['value' => 'keep'],
]) === ['item' => ['value' => 'keep']], 'unrelated values remain managed');
$check($manager->applyIgnoredValueRules('extensions/a/api3/Job/cron.yml', [
  'item' => ['name' => 'cron', 'last_run' => 'yesterday', 'last_run_end' => 'today'],
]) === ['item' => ['name' => 'cron']], 'runtime-only job metadata removed');
\Civi::settings()->set('civicfg_ignore_values', ['settings/legacy.yml:item.value']);
$check(in_array('settings/legacy.yml:item.value', $manager->getConfiguredIgnoreRules(), TRUE), 'legacy ignore values are readable');
$manager->setConfiguredIgnoreRules($manager->getConfiguredIgnoreRules());
$check(\Civi::settings()->get('civicfg_ignore_values') === [], 'legacy values migrated only on explicit write');
$check($callPrivate($manager, 'filterIgnoredFiles', ['custom/docs', [
  'alpha.yml' => ['item' => 1],
  'folder.yml' => ['item' => 2],
  'README.txt' => ['item' => 3],
],
]) === ['README.txt' => ['item' => 3]], 'only matching files filtered, unrelated file preserved');
$check($callPrivate($manager, 'filterIgnoredDiffItem', [[
  'files' => [['path' => 'custom/docs/alpha.yml', 'file' => 'alpha.yml']],
  'changed' => ['alpha.yml'],
  'new_in_db' => [],
  'missing_in_db' => [],
  'status' => 'different',
], 'custom/docs',
]) === [
  'files' => [],
  'changed' => [],
  'new_in_db' => [],
  'missing_in_db' => [],
  'status' => 'in_sync',
], 'existing ignored-only diff normalization unchanged');
\Civi::settings()->reset();

$handler = new ExtensionHandler();
$check($callPrivate($handler, 'identityField', [['name' => 'portable', 'label' => 'User label']]) === 'name', 'strong identity precedes label');
$check($callPrivate($handler, 'identityField', [['name' => 'fallback', 'code' => 'alpha'], ['match_fields' => ['code']]]) === 'code', 'provider match field wins');
$check($callPrivate($handler, 'identityConfidence', ['name']) === 'DISCOVERED_UNIQUE', 'discovered semantic identity');
$check($callPrivate($handler, 'identityConfidence', ['code', ['match_fields' => ['code']]]) === 'API_VERIFIED', 'provider-verified identity');
$check($callPrivate($handler, 'identityConfidence', ['label']) === 'AMBIGUOUS', 'weak labels remain unsafe');
$check(!$callPrivate($handler, 'identityValueIsUnique', [[['name' => 'same'], ['name' => 'same']], 'name', 'same']), 'duplicate identity rejected');
$check($callPrivate($handler, 'identitySafetyForRows', [[], ['match_fields' => ['name']]]) === 'UNVERIFIED', 'empty provider remains unverified');
$check($callPrivate($handler, 'identitySafetyForRows', [[['name' => 'a'], ['name' => 'b']], ['match_fields' => ['name']]]) === 'SAFE', 'unique semantic names are safe');
$check($callPrivate($handler, 'identitySafetyForRows', [[['name' => 'a'], ['name' => 'a']], ['match_fields' => ['name']]]) === 'UNSAFE', 'duplicate semantic identity unsafe');
$check($callPrivate($handler, 'identitySafetyForRows', [[['label' => 'Weak']], []]) === 'UNSAFE', 'label-only identity unsafe');
$counts = $callPrivate($handler, 'identityMultiplicityForRows', [[['name' => 'a'], ['name' => 'b'], ['name' => 'b']], ['match_fields' => ['name']]]);
$check(($counts['name']['b'] ?? NULL) === 2, 'portable identity counts retain duplicates');
$check($callPrivate($handler, 'identityValueHasDuplicateConflict', [[['code' => 'a'], ['code' => 'a']], 'code', 'a']), 'duplicate conflict detected');
$check(!$callPrivate($handler, 'identityValueHasDuplicateConflict', [[['code' => 'a'], ['code' => 'b']], 'code', 'a']), 'no false identity conflicts');

// The reviewed plan store remains opaque, persistent and fail-closed; do not
// weaken plan-ID validation to work around upstream import-preview failures.
$planDir = sys_get_temp_dir() . '/civicfg-review-smoke-' . bin2hex(random_bytes(6));
$store = new \Civi\ConfigManager\Service\ImportPlanStore($planDir);
try {
  $plan = $store->create(['requested_types' => ['example']]);
  $check(preg_match('/^[a-f0-9]{48}$/', (string) $plan['plan_id']) === 1, 'reviewed import ID remains 48 lowercase hex bytes');
  $check($store->load((string) $plan['plan_id']) === $plan, 'reviewed plan can be loaded without changing immutable content');
  try {
    $store->load('../unsafe-id');
    $check(FALSE, 'unsafe plan IDs must be rejected');
  }
  catch (\RuntimeException $e) {
    $check(strpos($e->getMessage(), 'Invalid reviewed Import plan identifier') !== FALSE, 'plan path traversal remains rejected');
  }
  $store->delete((string) $plan['plan_id']);
  try {
    $store->load((string) $plan['plan_id']);
    $check(FALSE, 'deleted reviewed plan must not be reusable');
  }
  catch (\RuntimeException $e) {
    $check(strpos($e->getMessage(), 'no longer available') !== FALSE, 'single-use plan lifecycle remains enforced');
  }
}
finally {
  $store->cleanupAll();
  @rmdir($planDir);
}

echo 'Refactor boundary smoke OK (' . $checked . " checks; no CiviCRM runtime needed).\n";
