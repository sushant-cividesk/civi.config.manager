<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Service\DiskRowSpool;
use Civi\ConfigManager\Service\StagedExportWorkspace;
use Civi\ConfigManager\Storage\YamlFileStorage;
use PHPUnit\Framework\TestCase;

final class Alpha63WorkspaceTest extends TestCase {
  private string $root;

  protected function setUp(): void {
    $this->root = sys_get_temp_dir() . '/civicfg-alpha63-unit-' . bin2hex(random_bytes(6));
    mkdir($this->root, 0700, TRUE);
  }

  protected function tearDown(): void {
    $this->removeTree($this->root);
  }

  public function testDiskRowSpoolReplaysRowsWithoutKeepingProviderCollection(): void {
    $spool = new DiskRowSpool();
    for ($i = 1; $i <= 1000; $i++) {
      $spool->append(['id' => $i, 'name' => 'row-' . $i]);
    }
    $count = 0;
    foreach ($spool->iterate() as $row) {
      $count++;
      self::assertSame($count, $row['id']);
    }
    self::assertSame(1000, $count);
    $spool->close();
  }

  public function testPersistentStageIndexSurvivesASecondRequestObject(): void {
    $live = new YamlFileStorage($this->root . '/live');
    $workspaceRoot = $this->root . '/job/export';
    $first = new StagedExportWorkspace($live, $workspaceRoot, FALSE);
    $first->stage('civirules', 'civirules/actions', 'one.yml', ['name' => 'one'], [
      'unit_key' => 'actions',
      'monitor_only' => TRUE,
    ]);
    $first->persistIndex();
    unset($first);

    $second = new StagedExportWorkspace($live, $workspaceRoot, FALSE);
    self::assertArrayHasKey('civirules/actions/one.yml', $second->files());
    self::assertTrue($second->files()['civirules/actions/one.yml']['monitor_only']);
  }

  /** Requirement: duplicate-path failures must identify both colliding objects without changing live YAML. */
  public function testDuplicatePathDiagnosticIncludesIdentitiesAndSourceIds(): void {
    $live = new YamlFileStorage($this->root . '/live');
    $workspace = new StagedExportWorkspace($live, $this->root . '/job/export', FALSE);
    $workspace->stage('profile-fields', 'profiles/fields', 'same.yml', ['item' => ['label' => 'Home']], [
      'config_key' => 'profile=summary|field=phone|location=Home',
      'source_id' => '201',
    ]);

    try {
      $workspace->stage('profile-fields', 'profiles/fields', 'same.yml', ['item' => ['label' => 'Work']], [
        'config_key' => 'profile=summary|field=phone|location=Work',
        'source_id' => '202',
      ]);
      self::fail('Expected duplicate export path to fail closed.');
    }
    catch (\RuntimeException $e) {
      self::assertStringContainsString('profiles/fields/same.yml', $e->getMessage());
      self::assertStringContainsString('existing identity=profile=summary|field=phone|location=Home', $e->getMessage());
      self::assertStringContainsString('incoming identity=profile=summary|field=phone|location=Work', $e->getMessage());
      self::assertStringContainsString('existing source ID=201', $e->getMessage());
      self::assertStringContainsString('incoming source ID=202', $e->getMessage());
      self::assertStringContainsString('No live YAML was changed', $e->getMessage());
    }

    self::assertSame([], iterator_to_array($live->iterateYamlPaths(), false));
  }

  public function testDurablePublishJournalRestoresHardInterruptedSnapshot(): void {
    $live = new YamlFileStorage($this->root . '/live');
    $live->writeRaw('', 'existing.yml', "original\n");
    $workspaceRoot = $this->root . '/job/export';
    $workspace = new StagedExportWorkspace($live, $workspaceRoot, FALSE);

    // Simulate a PHP hard-stop after one overwrite and one new file were
    // applied. The rollback copy and durable journal are what survive.
    $rollback = new YamlFileStorage($workspaceRoot . '/rollback');
    $rollback->writeRaw('', 'existing.yml', "original\n");
    $live->writeRaw('', 'existing.yml', "partial-new\n");
    $live->writeRaw('', 'new.yml', "partial-new-file\n");
    file_put_contents($workspaceRoot . '/publish-state.json', json_encode([
      'status' => 'publishing',
      'journal' => ['existing.yml'],
      'new_paths' => ['new.yml'],
    ], JSON_UNESCAPED_SLASHES));

    $recovery = $workspace->recoverIncompletePublish();

    self::assertTrue($recovery['recovered']);
    self::assertSame([], $recovery['errors']);
    self::assertSame("original\n", $live->readRaw('existing.yml'));
    self::assertNull($live->readRaw('new.yml'));
    self::assertFileDoesNotExist($workspaceRoot . '/publish-state.json');
  }

  private function removeTree(string $path): void {
    if (!is_dir($path)) {
      return;
    }
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
      $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
  }
}
