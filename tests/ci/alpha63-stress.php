<?php

declare(strict_types=1);

// Dependency-free alpha63 stress/safety gate. This complements alpha62's API4
// + YAML iterator stress by exercising the new disk spool and persistent job
// workspace/recovery primitives under the same 256 MiB ceiling.
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

use Civi\ConfigManager\Service\DiskRowSpool;
use Civi\ConfigManager\Service\StagedExportWorkspace;
use Civi\ConfigManager\Storage\YamlFileStorage;

function alpha63_assert(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}
function alpha63_remove_tree(string $path): void {
  if (!is_dir($path)) {
    return;
  }
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($it as $item) {
    $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
  }
  @rmdir($path);
}

$baseline = memory_get_usage(TRUE);
$tmp = sys_get_temp_dir() . '/civicfg-alpha63-stress-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, TRUE);

// 1) One provider scan can spool 10,000 moderately-sized rows to disk and
// replay them without retaining the complete provider collection in memory.
$spool = new DiskRowSpool();
for ($i = 1; $i <= 10000; $i++) {
  $spool->append([
    'id' => $i,
    'name' => 'provider-row-' . $i,
    'payload' => str_repeat('p', 512),
  ]);
}
$count = 0;
foreach ($spool->iterate() as $row) {
  $count++;
  if ($count === 1 || $count === 10000) {
    alpha63_assert((int) $row['id'] === $count, 'Disk provider spool replay order changed.');
  }
}
alpha63_assert($count === 10000, 'Disk provider spool did not replay all 10,000 rows.');
$spool->close();

// 2) Persistent staged metadata survives a new PHP request/object without
// reparsing the full stage merely to rebuild the compact index.
$live = new YamlFileStorage($tmp . '/live');
$workspaceRoot = $tmp . '/job/export';
$workspace = new StagedExportWorkspace($live, $workspaceRoot, FALSE);
for ($i = 1; $i <= 5000; $i++) {
  $name = 'item-' . str_pad((string) $i, 5, '0', STR_PAD_LEFT);
  $workspace->stage('stress', 'stress', $name . '.yml', ['name' => $name], [
    'unit_key' => 'provider-a',
    'identity_hash' => hash('sha256', $name),
    'canonical_hash' => hash('sha256', 'canonical:' . $name),
    'names' => [$name],
    'dependencies' => [],
    'monitor_only' => FALSE,
  ]);
}
$workspace->persistIndex();
unset($workspace);
$reopened = new StagedExportWorkspace($live, $workspaceRoot, FALSE);
alpha63_assert(count($reopened->files()) === 5000, 'Persistent staged compact index did not survive workspace reopen.');
alpha63_assert(isset($reopened->files()['stress/item-05000.yml']), 'Persistent stage lost its final indexed path.');

// 3) Simulate a hard-killed publish: live YAML has been partially mutated, but
// rollback bytes + publish-state.json survived. Recovery must restore old bytes
// and remove files which did not exist before publication.
$live->writeRaw('', 'existing.yml', "original\n");
$rollback = new YamlFileStorage($workspaceRoot . '/rollback');
$rollback->writeRaw('', 'existing.yml', "original\n");
$live->writeRaw('', 'existing.yml', "partial-overwrite\n");
$live->writeRaw('', 'new-after-crash.yml', "partial-new\n");
file_put_contents($workspaceRoot . '/publish-state.json', json_encode([
  'status' => 'publishing',
  'journal' => ['existing.yml'],
  'new_paths' => ['new-after-crash.yml'],
], JSON_UNESCAPED_SLASHES));
$recovery = $reopened->recoverIncompletePublish();
alpha63_assert(!empty($recovery['recovered']) && empty($recovery['errors']), 'Durable publish recovery failed.');
alpha63_assert($live->readRaw('existing.yml') === "original\n", 'Durable recovery did not restore overwritten YAML.');
alpha63_assert($live->readRaw('new-after-crash.yml') === NULL, 'Durable recovery did not remove newly-created partial YAML.');
alpha63_assert(!is_file($workspaceRoot . '/publish-state.json'), 'Durable publication journal was not cleared after successful recovery.');

$peak = memory_get_peak_usage(TRUE);
$delta = max(0, $peak - $baseline);
alpha63_assert($delta < 96 * 1024 * 1024, 'Alpha63 stress peak-memory delta exceeded 96 MiB: ' . round($delta / 1048576, 1) . ' MiB.');

alpha63_remove_tree($tmp);
echo 'alpha63 stress OK: 10,000 disk-spooled provider rows + 5,000 persistent staged metadata rows + durable publish recovery; peak delta ' . round($delta / 1048576, 1) . " MiB\n";
