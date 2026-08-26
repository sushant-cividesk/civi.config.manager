<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Handler\AbstractHandler;
use Civi\ConfigManager\Service\ConfigManager;
use Civi\ConfigManager\Service\HandlerRegistry;
use PHPUnit\Framework\TestCase;

final class ConfigManagerPerformanceTest extends TestCase {
  private string $dir;

  protected function setUp(): void {
    \Civi::settings()->reset();
    $this->dir = sys_get_temp_dir() . '/civicfg-health-' . bin2hex(random_bytes(6));
    mkdir($this->dir . '/settings', 0777, TRUE);
    file_put_contents($this->dir . '/manifest.yml', "schema_version: 1\n");
    file_put_contents($this->dir . '/settings/example.yml', "type: example\n");
    \Civi::settings()->set('civicfg_sync_dir', $this->dir);
  }

  protected function tearDown(): void {
    \Civi::settings()->reset();
    if (is_dir($this->dir)) {
      $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
      );
      foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
      }
      rmdir($this->dir);
    }
  }

  public function testMissingManifestWinsOverStaleCachedHealthWithoutDiscoveringHandlers(): void {
    unlink($this->dir . '/manifest.yml');
    \Civi::settings()->set('civicfg_last_health', [
      'level' => 'info',
      'title' => 'Configuration Manager: In sync',
      'message' => 'stale cached result',
    ]);

    $registry = new class extends HandlerRegistry {
      public function getHandlers(): array {
        throw new \RuntimeException('Health must not discover configuration handlers.');
      }
    };

    $health = (new ConfigManager($registry))->getHealth();

    self::assertSame('Configuration Manager: Initial export required', $health['title']);
  }

  public function testHealthUsesCachedStatusWithoutDiscoveringHandlers(): void {
    \Civi::settings()->set('civicfg_last_health', [
      'level' => 'info',
      'title' => 'Configuration Manager: In sync',
      'message' => 'cached',
      'changed' => 0,
      'in_civicrm' => 0,
      'in_yaml' => 0,
    ]);

    $registry = new class extends HandlerRegistry {
      public function getHandlers(): array {
        throw new \RuntimeException('Health must not discover configuration handlers.');
      }
    };

    $health = (new ConfigManager($registry))->getHealth();

    self::assertSame('Configuration Manager: In sync', $health['title']);
    self::assertSame('cached', $health['message']);
  }

  public function testLargeSiteHotPathsStayBoundedByContract(): void {
    $managerSource = (string) file_get_contents(dirname(__DIR__, 3) . '/Civi/ConfigManager/Service/ConfigManager.php');
    $extensionSource = (string) file_get_contents(dirname(__DIR__, 3) . '/Civi/ConfigManager/Handler/ExtensionHandler.php');
    $transferSource = (string) file_get_contents(dirname(__DIR__, 3) . '/Civi/ConfigManager/UI/FileTransfer.php');

    self::assertStringContainsString('pruneExtensionIndexesForIgnoredOrFilteredConfig(array &$queue): void', $managerSource);
    self::assertStringContainsString('addReverseDependencyMetadataToExportQueue(array &$queue): void', $managerSource);
    self::assertStringContainsString('iterateManagedYamlArchiveFiles(): \Generator', $managerSource);
    self::assertStringContainsString('renameStructuralSignature', (string) file_get_contents(dirname(__DIR__, 3) . '/Civi/ConfigManager/Handler/AbstractHandler.php'));
    self::assertStringNotContainsString("'limit' => 0", $extensionSource);
    self::assertStringContainsString('iterateManagedYamlArchiveFiles()', $transferSource);
  }

  public function testApi4CollectionReadsArePagedAndFirstLookupIsBounded(): void {
    \Civi\Api4\CivicfgPagingTestEntity::$rows = [];
    for ($i = 1; $i <= 450; $i++) {
      \Civi\Api4\CivicfgPagingTestEntity::$rows[] = ['id' => $i, 'name' => 'Row ' . $i];
    }
    \Civi\Api4\CivicfgPagingTestEntity::$executeCalls = 0;

    $handler = new PerformancePagingFixtureHandler();
    $rows = $handler->readAll();

    self::assertCount(450, $rows);
    self::assertSame(1, $rows[0]['id']);
    self::assertSame(450, $rows[449]['id']);
    self::assertSame(3, \Civi\Api4\CivicfgPagingTestEntity::$executeCalls);

    \Civi\Api4\CivicfgPagingTestEntity::$executeCalls = 0;
    $first = $handler->readFirst();
    self::assertSame(1, $first['id']);
    self::assertSame(1, \Civi\Api4\CivicfgPagingTestEntity::$executeCalls);
  }

}


final class PerformancePagingFixtureHandler extends AbstractHandler {
  public function getType(): string { return 'performance-paging'; }
  public function getLabel(): string { return 'Performance Paging'; }
  public function getDirectory(): string { return 'performance-paging'; }
  public function getWeight(): int { return 999; }
  public function export(): array { return []; }

  public function readAll(): array {
    return $this->api4Get('CivicfgPagingTestEntity', [], ['id', 'name'], ['id' => 'ASC']);
  }

  public function readFirst(): array {
    return (array) $this->api4GetFirst('CivicfgPagingTestEntity', [], ['id', 'name']);
  }
}
