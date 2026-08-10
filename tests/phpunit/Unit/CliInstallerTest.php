<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Service\CliInstaller;
use Civi\ConfigManager\Service\ConfigManager;
use PHPUnit\Framework\TestCase;

final class CliInstallerTest extends TestCase {
  private string $projectRoot;
  private string $originalPath;
  private ?string $originalGlobalBinDir;
  private ?string $originalBinDir;
  private ?string $originalDisableSharedBin;

  protected function setUp(): void {
    $this->projectRoot = sys_get_temp_dir() . '/civicfg-cli-test-' . bin2hex(random_bytes(6));
    $this->originalPath = (string) getenv('PATH');
    $globalBinDir = getenv('CIVICFG_GLOBAL_BIN_DIR');
    $binDir = getenv('CIVICFG_BIN_DIR');
    $disableSharedBin = getenv('CIVICFG_DISABLE_SHARED_BIN');
    $this->originalGlobalBinDir = $globalBinDir === FALSE ? NULL : (string) $globalBinDir;
    $this->originalBinDir = $binDir === FALSE ? NULL : (string) $binDir;
    $this->originalDisableSharedBin = $disableSharedBin === FALSE ? NULL : (string) $disableSharedBin;
    putenv('PATH=/usr/bin:/bin');
    putenv('CIVICFG_GLOBAL_BIN_DIR');
    putenv('CIVICFG_BIN_DIR');
    putenv('CIVICFG_DISABLE_SHARED_BIN=1');
    mkdir($this->projectRoot, 0775, TRUE);
  }

  protected function tearDown(): void {
    putenv('PATH=' . $this->originalPath);
    $this->originalGlobalBinDir === NULL ? putenv('CIVICFG_GLOBAL_BIN_DIR') : putenv('CIVICFG_GLOBAL_BIN_DIR=' . $this->originalGlobalBinDir);
    $this->originalBinDir === NULL ? putenv('CIVICFG_BIN_DIR') : putenv('CIVICFG_BIN_DIR=' . $this->originalBinDir);
    $this->originalDisableSharedBin === NULL ? putenv('CIVICFG_DISABLE_SHARED_BIN') : putenv('CIVICFG_DISABLE_SHARED_BIN=' . $this->originalDisableSharedBin);
    $this->removeTree($this->projectRoot);
  }

  public function testInstallCreatesProjectWrappersAndPathHelpers(): void {
    $installer = new CliInstaller(new CliInstallerTestConfigManager($this->projectRoot));

    $result = $installer->install();

    self::assertTrue($result['ok']);
    self::assertFileExists($this->projectRoot . '/bin/civicfg');
    self::assertFileExists($this->projectRoot . '/bin/ce');
    self::assertFileExists($this->projectRoot . '/bin/ci');
    self::assertFileExists($this->projectRoot . '/bin/civicfg-env');
    self::assertFileExists($this->projectRoot . '/bin/civicfg-path');
    self::assertStringContainsString('Managed by Configuration Manager extension', file_get_contents($this->projectRoot . '/bin/civicfg'));
    self::assertStringContainsString('export PATH=', file_get_contents($this->projectRoot . '/bin/civicfg-env'));
  }

  public function testInstallNeverOverwritesExistingNonManagedTerminalCommand(): void {
    mkdir($this->projectRoot . '/bin', 0775, TRUE);
    file_put_contents($this->projectRoot . '/bin/civicfg', '#!/usr/bin/env bash\necho local-custom\n');

    $installer = new CliInstaller(new CliInstallerTestConfigManager($this->projectRoot));
    $result = $installer->install();

    self::assertTrue($result['ok']);
    self::assertStringContainsString('existing non-managed file', implode("\n", $result['skipped']));
    self::assertSame('#!/usr/bin/env bash\necho local-custom\n', file_get_contents($this->projectRoot . '/bin/civicfg'));
  }

  public function testUninstallRemovesOnlyManagedWrappersAndHelpers(): void {
    mkdir($this->projectRoot . '/bin', 0775, TRUE);
    file_put_contents($this->projectRoot . '/bin/custom-tool', 'do not remove');

    $installer = new CliInstaller(new CliInstallerTestConfigManager($this->projectRoot));
    $installer->install();
    $result = $installer->uninstall();

    self::assertTrue($result['ok']);
    self::assertFileDoesNotExist($this->projectRoot . '/bin/civicfg');
    self::assertFileDoesNotExist($this->projectRoot . '/bin/civicfg-env');
    self::assertFileExists($this->projectRoot . '/bin/custom-tool');
  }

  private function removeTree(string $path): void {
    if (!is_dir($path)) {
      return;
    }
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $file) {
      if ($file->isDir()) {
        @rmdir($file->getPathname());
      }
      else {
        @unlink($file->getPathname());
      }
    }
    @rmdir($path);
  }
}

final class CliInstallerTestConfigManager extends ConfigManager {
  private string $projectRoot;

  public function __construct(string $projectRoot) {
    $this->projectRoot = $projectRoot;
  }

  public function getProjectRoot(): string {
    return $this->projectRoot;
  }

  public function getSiteIdentifier(): string {
    return 'civicfg-test-site';
  }
}
