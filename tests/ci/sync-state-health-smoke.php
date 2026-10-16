<?php
/**
 * Dependency-free A67-07 regression: do not report an unverified scan as In Sync.
 * Uses in-memory FakeCivi settings; never boots or changes a CiviCRM database.
 */
declare(strict_types=1);
$root = dirname(__DIR__, 2);
require_once $root . '/tests/phpunit/Support/FakeCivi.php';
spl_autoload_register(static function(string $class) use ($root): void {
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
use Civi\ConfigManager\Service\DiffStateClassifier;

$checks = 0;
$assert = static function(bool $valid, string $label) use (&$checks): void {
  $checks++;
  if (!$valid) {
    fwrite(STDERR, "Sync status regression FAILED: {$label}\n");
    exit(1);
  }
};
$manager = new ConfigManager();
$cache = new ReflectionMethod($manager, 'cacheHealthFromDiff');
$cache->setAccessible(TRUE);
$health = static function(array $diff) use ($cache, $manager): array {
  Civi::settings()->reset();
  $cache->invoke($manager, ['sync_dir' => '/tmp/sync-fixture'] + $diff);
  return (array) Civi::settings()->get('civicfg_last_health');
};

$classifier = new DiffStateClassifier();
$assert($classifier->classify(NULL, 'same', 'same') === 'NO_BASELINE', 'matching contents without accepted baseline are unverified');
$assert($classifier->classify('same', 'same', 'same') === 'IN_SYNC', 'accepted baseline and matching contents are in sync');
$assert($classifier->classify(NULL, 'left', 'right') === 'DIFFERENT', 'real difference without baseline remains different');
$assert($classifier->classify(NULL, 'left', NULL) === 'ONLY_IN_YAML', 'missing active record remains visible');
$assert($classifier->classify(NULL, NULL, 'right') === 'ONLY_IN_CIVICRM', 'missing YAML record remains visible');

$clean = $health(['ok' => TRUE, 'items' => [], 'errors' => []]);
$assert($clean['title'] === 'Configuration Manager: In sync', 'verified clean scan retains successful status');

$failed = $health(['ok' => FALSE, 'items' => [], 'errors' => [['type' => 'settings', 'message' => 'provider error']]]);
$assert($failed['title'] !== 'Configuration Manager: In sync', 'provider failure never reports In Sync');
$assert($failed['level'] === 'warning', 'provider failure exposes warning health');
$assert(strpos($failed['message'], 'provider') !== FALSE || strpos($failed['message'], 'incomplete') !== FALSE, 'provider error receives actionable message');

$stateFailed = $health(['ok' => TRUE, 'items' => [], 'errors' => [], 'state_warning' => 'database unavailable']);
$assert($stateFailed['title'] !== 'Configuration Manager: In sync', 'state tracking failure never reports In Sync');

$scope = $health(['ok' => TRUE, 'items' => [['status' => 'in_sync', 'changed' => [], 'new_in_db' => [], 'missing_in_db' => [], 'scope_warnings' => ['unresolved selector']]], 'errors' => []]);
$assert($scope['title'] !== 'Configuration Manager: In sync', 'unresolved selected scope never reports In Sync');

$unbaselined = $health(['ok' => TRUE, 'items' => [], 'errors' => [], 'baseline_unverified' => 3]);
$assert($unbaselined['title'] !== 'Configuration Manager: In sync', 'clean comparison without baseline does not imply accepted sync');
$assert(strpos(strtolower($unbaselined['message']), 'baseline') !== FALSE, 'missing accepted baseline is explained');

$changed = $health(['ok' => TRUE, 'items' => [['changed' => ['one.yml'], 'new_in_db' => [], 'missing_in_db' => []]], 'errors' => []]);
$assert($changed['title'] === 'Configuration Manager: Pending configuration changes', 'real changes preserve pending status');
$watch = $health(['no_managed_scope' => TRUE, 'watch_only' => TRUE]);
$assert($watch['title'] === 'Configuration Manager: Monitoring only', 'watch-only remains distinct');
$setup = $health(['no_managed_scope' => TRUE, 'setup_required' => TRUE]);
$assert($setup['title'] === 'Configuration Manager: Setup required', 'unconfigured scope remains setup required');
$initial = $health(['initial_export_required' => TRUE]);
$assert($initial['title'] === 'Configuration Manager: Initial export required', 'initial export still requires setup');


// Exercise the real state-enrichment services without touching a database.
class SyncStateFixtureStore extends \Civi\ConfigManager\Service\StateStore {
  public array $baselines = [];
  public array $states = [];
  public function isAvailable(): bool { return TRUE; }
  public function getBaseline(string $providerKey, string $identityHash): ?array {
    return $this->baselines[$providerKey . '|' . $identityHash] ?? NULL;
  }
  public function previousIdentityHash(string $providerKey, string $newIdentityHash): ?string { return NULL; }
  public function upsertObjectState(array $identity, ?string $yamlHash, ?string $activeHash, string $state): void {
    $this->states[] = $state;
  }
  public function acceptBaseline(array $identity, array $canonicalData, string $hash, string $source): void {
    $this->baselines[$identity['provider_key'] . '|' . $identity['identity_hash']] = [
      'baseline_hash' => $hash,
      'baseline_data' => $canonicalData,
      'canonical_version' => \Civi\ConfigManager\Service\Canonicalizer::VERSION,
    ];
  }
}
class SyncStateFixtureHandler extends \Civi\ConfigManager\Handler\AbstractHandler {
  public function getType(): string { return 'example'; }
  public function getLabel(): string { return 'Example'; }
  public function getDirectory(): string { return 'example'; }
  public function getWeight(): int { return 10; }
  public function export(): array { return []; }
}
$store = new SyncStateFixtureStore();
$stateManager = new \Civi\ConfigManager\Service\ConfigStateManager($store);
$handler = new SyncStateFixtureHandler();
$doc = ['schema_version' => 1, 'type' => 'example.item', 'item' => ['name' => 'sample', 'label' => 'Sample']];
$active = [['filename' => 'sample.yml', 'data' => $doc]];
$yaml = ['sample.yml' => $doc];
$diff = $handler->diffFromExports($active, $yaml);
$enriched = $stateManager->enrichDiff($handler, $active, $yaml, $diff);
$assert($enriched['state_summary'] === ['NO_BASELINE' => 1], 'detailed diff marks missing accepted baseline');
$assert($store->states === ['NO_BASELINE'], 'object state stores unverified baseline status');

$identity = (new \Civi\ConfigManager\Service\ConfigIdentity())->identify('example', $doc, 'sample.yml');
$canonicalizer = new \Civi\ConfigManager\Service\Canonicalizer();
$store->acceptBaseline($identity, $canonicalizer->canonicalize($doc), $canonicalizer->hash($doc), 'export');
$enriched = $stateManager->enrichDiff($handler, $active, $yaml, $diff);
$assert($enriched['state_summary'] === ['IN_SYNC' => 1], 'accepted baseline restores actual in-sync classification');

$store->baselines = [];
$compact = [
  (string) $identity['config_key'] => [
    'identity' => $identity,
    'hash' => $canonicalizer->hash($doc),
    'filename' => 'sample.yml',
  ],
];
$compactDiff = $stateManager->enrichCompactDiff($handler, $compact, $compact, ['files' => [], 'status' => 'in_sync']);
$assert($compactDiff['state_summary'] === ['NO_BASELINE' => 1], 'streaming summary preserves missing-baseline distinction');

print "Sync state and health smoke OK ({$checks} checks).\n";
