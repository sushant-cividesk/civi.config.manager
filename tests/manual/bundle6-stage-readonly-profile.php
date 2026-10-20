<?php
/**
 * Read-only, bounded provider discovery/scope profiling on disposable DDEV STAGE.
 *
 * From /var/www/html/build/dcivi-stage/web, with no source/data changes:
 * CIVICFG_BUNDLE6_PROFILE=YES_STAGE_ONLY drush php:script \
 *   /var/www/html/ext/civi.config.manager/tests/manual/bundle6-stage-readonly-profile.php
 *
 * Measures one manager per sample, including same-request cached scope
 * metadata. No export(), import(), collection reads, save, or cache flush.
 * Drupal/CiviCRM bootstrap may refresh normal operational caches.
 */

declare(strict_types=1);

if (getenv('CIVICFG_BUNDLE6_PROFILE') !== 'YES_STAGE_ONLY') {
  throw new \RuntimeException('STOP: Explicit CIVICFG_BUNDLE6_PROFILE=YES_STAGE_ONLY opt-in is required.');
}
$expected = realpath('/var/www/html/build/dcivi-stage/web');
if (!$expected || realpath(getcwd()) !== $expected) {
  throw new \RuntimeException('STOP: Bundle 6 profiler is restricted to the DDEV STAGE Drupal webroot.');
}
if (!class_exists('Drupal') || !\Drupal::getContainer()->has('civicrm')) {
  throw new \RuntimeException('STOP: Drupal/CiviCRM STAGE bootstrap is required.');
}
\Drupal::service('civicrm')->initialize();
$db = (string) \CRM_Core_DAO::singleValueQuery('SELECT DATABASE()');
if (!preg_match('/^dcivistage[a-z0-9_]*$/i', $db)) {
  throw new \RuntimeException('STOP: Not the expected disposable DDEV STAGE database.');
}

$root = realpath(dirname(__DIR__, 2));
spl_autoload_register(static function (string $class) use ($root): void {
  $prefix = 'Civi\\ConfigManager\\';
  if (strpos($class, $prefix) !== 0) {
    return;
  }
  $path = $root . '/Civi/ConfigManager/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
  if (is_file($path)) {
    require_once $path;
  }
}, TRUE, TRUE);

$managerClass = \Civi\ConfigManager\Service\ConfigManager::class;
$actual = realpath((new \ReflectionClass($managerClass))->getFileName());
if (!$root || !$actual || strpos($actual, $root . DIRECTORY_SEPARATOR) !== 0) {
  throw new \RuntimeException('STOP: The active Configuration Manager checkout does not match the benchmark script.');
}
$rawSamples = getenv('CIVICFG_BUNDLE6_SAMPLES');
$count = $rawSamples === FALSE || $rawSamples === '' ? 3 : (int) $rawSamples;
if ($count < 1 || $count > 5 || ($rawSamples !== FALSE && $rawSamples !== '' && !ctype_digit($rawSamples))) {
  throw new \RuntimeException('STOP: CIVICFG_BUNDLE6_SAMPLES must be an integer from 1 through 5.');
}

$measurements = ['handlers_cold' => [], 'scope_cold' => [], 'scope_warm' => [], 'inventory' => []];
$expectedCounts = NULL;
for ($i = 0; $i < $count; $i++) {
  $manager = new $managerClass();
  $capture = static function (callable $action) use (&$measurements): array {
    $start = hrtime(TRUE);
    $result = $action();
    $elapsed = (hrtime(TRUE) - $start) / 1000000;
    return [$result, round($elapsed, 3)];
  };
  [$handlers, $time] = $capture(static function () use ($manager): array {
    return $manager->getAllHandlers();
  });
  $measurements['handlers_cold'][] = $time;
  [$scope, $time] = $capture(static function () use ($manager): array {
    return $manager->getScopeTypeOptions();
  });
  $measurements['scope_cold'][] = $time;
  [$scopeAgain, $time] = $capture(static function () use ($manager): array {
    return $manager->getScopeTypeOptions();
  });
  $measurements['scope_warm'][] = $time;
  if (count($scopeAgain) !== count($scope)) {
    throw new \RuntimeException('STOP: Cached scope metadata count changed within a single manager instance.');
  }
  [$inventory, $time] = $capture(static function () use ($manager): array {
    return $manager->getProviderInventory();
  });
  $measurements['inventory'][] = $time;
  if (empty($inventory['ok']) || !is_array($inventory['providers'] ?? NULL)) {
    throw new \RuntimeException('STOP: Provider inventory did not return a valid read-only result.');
  }
  $counts = [
    'handlers' => count($handlers),
    'scope_types' => count($scope),
    'inventory_providers' => count($inventory['providers']),
  ];
  if ($expectedCounts !== NULL && $counts !== $expectedCounts) {
    throw new \RuntimeException('STOP: Inventory cardinality changed during profiling; measurements are not comparable.');
  }
  $expectedCounts = $counts;
}

$summary = [];
foreach ($measurements as $operation => $times) {
  sort($times, SORT_NUMERIC);
  $middle = (int) floor(count($times) / 2);
  $median = count($times) % 2 === 1
    ? $times[$middle]
    : round(($times[$middle - 1] + $times[$middle]) / 2, 3);
  $summary[$operation] = [
    'min_ms' => $times[0],
    'median_ms' => $median,
    'max_ms' => $times[count($times) - 1],
  ];
}
echo 'BUNDLE 6 STAGE READ-ONLY PERFORMANCE BASELINE: PASS' . "\n";
echo json_encode([
  'samples' => $count,
  'counts' => $expectedCounts,
  'times' => $summary,
  'peak_memory_mb' => round(memory_get_peak_usage(TRUE) / 1048576, 2),
  'profile_notes' => 'Within one PHP process; no persistent discovery cache or collection reads requested. Compare same CiviCRM/extension versions and representative metadata volume.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
