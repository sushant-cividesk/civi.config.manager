<?php

declare(strict_types=1);

// This gate deliberately runs without Composer/CiviCRM. It exercises the
// extension's bounded primitives under a low PHP memory ceiling.
$root = dirname(__DIR__, 2);

spl_autoload_register(static function(string $class) use ($root): void {
  $prefix = 'Civi\\ConfigManager\\';
  if (strpos($class, $prefix) !== 0) {
    return;
  }
  $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
  $file = $root . '/Civi/ConfigManager/' . $relative . '.php';
  if (is_file($file)) {
    require_once $file;
  }
});

require_once $root . '/tests/phpunit/Support/CiviApi4Stubs.php';

if (!function_exists('yaml_parse_file')) {
  function yaml_parse_file(string $file) {
    return [
      'schema_version' => 1,
      'type' => 'stress.item',
      'name' => pathinfo($file, PATHINFO_FILENAME),
      'item' => ['name' => pathinfo($file, PATHINFO_FILENAME)],
    ];
  }
}

use Civi\ConfigManager\Handler\AbstractHandler;
use Civi\ConfigManager\Service\StagedExportWorkspace;
use Civi\ConfigManager\Storage\YamlFileStorage;

final class Alpha62StressHandler extends AbstractHandler {
  public function getType(): string { return 'alpha62-stress'; }
  public function getLabel(): string { return 'Alpha62 Stress'; }
  public function getDirectory(): string { return 'alpha62-stress'; }
  public function getWeight(): int { return 999; }
  public function export(): array { return []; }
  public function iterateRows(): iterable {
    return $this->api4Iterate('CivicfgPagingTestEntity', [], ['id', 'name', 'payload'], ['id' => 'ASC']);
  }
}

final class Alpha62FailOnceStorage extends YamlFileStorage {
  private string $failRelative;
  private bool $failed = FALSE;

  public function __construct(string $root, string $failRelative) {
    parent::__construct($root);
    $this->failRelative = $failRelative;
  }

  public function writeRaw(string $directory, string $filename, string $contents): string {
    $relative = trim($directory, '/');
    $relative = ($relative === '' ? '' : $relative . '/') . ltrim($filename, '/');
    if (!$this->failed && $relative === $this->failRelative) {
      $this->failed = TRUE;
      throw new RuntimeException('intentional alpha62 publish failure');
    }
    return parent::writeRaw($directory, $filename, $contents);
  }
}

function alpha62_assert(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

function alpha62_remove_tree(string $path): void {
  if (!is_dir($path)) {
    return;
  }
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($it as $item) {
    $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
  }
  @rmdir($path);
}

$baselineMemory = memory_get_usage(TRUE);

// 1) 5,000 API4 rows: pages are requested lazily and previous page arrays are
// released before the next one is processed.
\Civi\Api4\CivicfgPagingTestEntity::$rows = [];
for ($i = 1; $i <= 5000; $i++) {
  \Civi\Api4\CivicfgPagingTestEntity::$rows[] = [
    'id' => $i,
    'name' => 'row-' . $i,
    'payload' => str_repeat('x', 256),
  ];
}
\Civi\Api4\CivicfgPagingTestEntity::$executeCalls = 0;
$handler = new Alpha62StressHandler();
$count = 0;
foreach ($handler->iterateRows() as $row) {
  $count++;
  if ($count === 1) {
    alpha62_assert(\Civi\Api4\CivicfgPagingTestEntity::$executeCalls === 1, 'API4 iterator loaded more than its first page before first yield.');
  }
  if ($count === 201) {
    alpha62_assert(\Civi\Api4\CivicfgPagingTestEntity::$executeCalls === 2, 'API4 iterator did not advance exactly one bounded page at row 201.');
  }
}
alpha62_assert($count === 5000, 'API4 stress iterator did not yield all 5,000 rows.');
alpha62_assert(\Civi\Api4\CivicfgPagingTestEntity::$executeCalls === 26, 'Expected 25 full API4 pages plus one terminating empty page.');
\Civi\Api4\CivicfgPagingTestEntity::$rows = [];

// 2) 5,000 YAML files: only path strings and one parsed document at a time are
// retained by iterateDirectory().
$tmp = sys_get_temp_dir() . '/civicfg-alpha62-stress-' . bin2hex(random_bytes(6));
mkdir($tmp . '/yaml', 0777, TRUE);
for ($i = 1; $i <= 5000; $i++) {
  $itemName = 'item-' . str_pad((string) $i, 5, '0', STR_PAD_LEFT);
  file_put_contents($tmp . '/yaml/' . $itemName . '.yml', "schema_version: 1\nname: $itemName\n");
}
$yamlStorage = new YamlFileStorage($tmp);
$yamlCount = 0;
foreach ($yamlStorage->iterateDirectory('yaml') as $filename => $data) {
  $yamlCount++;
  $expectedName = pathinfo((string) $filename, PATHINFO_FILENAME);
  alpha62_assert(($data['name'] ?? NULL) === $expectedName, 'YAML iterator did not return the parser result for ' . $filename . '.');
}
alpha62_assert($yamlCount === 5000, 'YAML stress iterator did not yield all 5,000 files.');

// 3) Duplicate staged paths are a hard blocker before live mutation.
$liveRoot = $tmp . '/live';
$live = new YamlFileStorage($liveRoot);
$live->ensureRoot();
$workspace = new StagedExportWorkspace($live);
$workspace->stage('stress', 'stress', 'same.yml', ['name' => 'first']);
$duplicateBlocked = FALSE;
try {
  $workspace->stage('stress', 'stress', 'same.yml', ['name' => 'second']);
}
catch (RuntimeException $e) {
  $duplicateBlocked = strpos($e->getMessage(), 'Duplicate export path') !== FALSE;
}
alpha62_assert($duplicateBlocked, 'Duplicate staged export path was not blocked.');
$workspace->cleanup();

// 4) Forced mid-publish failure restores the previous coherent snapshot.
$rollbackRoot = $tmp . '/rollback-live';
$seed = new YamlFileStorage($rollbackRoot);
$seed->writeRaw('', 'a.yml', "old-a\n");
$seed->writeRaw('', 'b.yml', "old-b\n");
$failingLive = new Alpha62FailOnceStorage($rollbackRoot, 'b.yml');
$workspace = new StagedExportWorkspace($failingLive);
$workspace->stage('stress', '', 'a.yml', ['value' => 'new-a']);
$workspace->stage('stress', '', 'b.yml', ['value' => 'new-b']);
$publishFailed = FALSE;
try {
  $workspace->publish([]);
}
catch (RuntimeException $e) {
  $publishFailed = strpos($e->getMessage(), 'rolled back') !== FALSE;
}
alpha62_assert($publishFailed, 'Forced staged publish failure did not report rollback.');
alpha62_assert($seed->readRaw('a.yml') === "old-a\n", 'Rollback did not restore a.yml.');
alpha62_assert($seed->readRaw('b.yml') === "old-b\n", 'Rollback did not restore b.yml.');
$workspace->cleanup();

$peak = memory_get_peak_usage(TRUE);
$delta = max(0, $peak - $baselineMemory);
$limit = 96 * 1024 * 1024;
alpha62_assert($delta < $limit, 'Alpha62 stress peak-memory delta exceeded 96 MiB: ' . round($delta / 1048576, 1) . ' MiB.');

alpha62_remove_tree($tmp);

echo 'alpha62 stress OK: 5,000 API4 rows + 5,000 YAML files; peak delta ' . round($delta / 1048576, 1) . " MiB\n";
