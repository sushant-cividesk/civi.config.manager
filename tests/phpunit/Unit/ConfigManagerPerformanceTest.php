<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

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
}
