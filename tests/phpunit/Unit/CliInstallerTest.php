<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Service\CliInstaller;
use Civi\ConfigManager\Service\ConfigManager;
use PHPUnit\Framework\TestCase;

final class CliInstallerTest extends TestCase {
  private string $sandbox;
  private string $projectRoot;
  private string $globalBin;
  private string $registryDir;

  /** @var array<string, string|false> */
  private array $environment = [];

  protected function setUp(): void {
    $this->sandbox = sys_get_temp_dir() . '/civicfg-cli-test-' . bin2hex(random_bytes(6));
    $this->projectRoot = $this->sandbox . '/project';
    $this->globalBin = $this->sandbox . '/global-bin';
    $this->registryDir = $this->sandbox . '/registry';

    foreach (['PATH', 'HOME', 'XDG_CONFIG_HOME', 'CIVICFG_GLOBAL_BIN_DIR', 'CIVICFG_REGISTRY_DIR', 'CIVICFG_CV'] as $name) {
      $this->environment[$name] = getenv($name);
    }

    mkdir($this->projectRoot . '/vendor/bin', 0775, TRUE);
    file_put_contents($this->projectRoot . '/vendor/autoload.php', "<?php\n");
    mkdir($this->globalBin, 0775, TRUE);

    putenv('HOME=' . $this->sandbox . '/home');
    putenv('PATH=' . $this->globalBin . ':/usr/bin:/bin');
    putenv('XDG_CONFIG_HOME');
    putenv('CIVICFG_GLOBAL_BIN_DIR=' . $this->globalBin);
    putenv('CIVICFG_REGISTRY_DIR=' . $this->registryDir);
  }

  protected function tearDown(): void {
    foreach ($this->environment as $name => $value) {
      $value === FALSE ? putenv($name) : putenv($name . '=' . $value);
    }
    $this->removeTree($this->sandbox);
  }

  public function testInstallCreatesOnlyVendorAndOneGlobalLauncher(): void {
    $installer = new CliInstaller(new CliInstallerTestConfigManager($this->projectRoot, 'shared-site'));

    $result = $installer->install();

    self::assertTrue($result['ok']);
    self::assertSame($this->projectRoot . '/vendor/bin/civicfg', $result['vendor_launcher']);
    self::assertSame($this->globalBin . '/civicfg', $result['global_launcher']);
    self::assertFileExists($this->projectRoot . '/vendor/bin/civicfg');
    self::assertFileExists($this->globalBin . '/civicfg');
    self::assertFileDoesNotExist($this->projectRoot . '/bin/civicfg');
    self::assertFileDoesNotExist($this->projectRoot . '/bin/ce');
    self::assertFileDoesNotExist($this->projectRoot . '/bin/civicfg-path');

    $launcher = (string) file_get_contents($this->globalBin . '/civicfg');
    self::assertStringContainsString('Managed by Configuration Manager extension', $launcher);
    self::assertStringContainsString('keyToBasePath', $launcher);
    self::assertStringContainsString('command -v cv', $launcher);
    self::assertStringContainsString('$(dirname "$0")/cv', $launcher);
    self::assertStringContainsString('export CIVICFG_CV="${cv_cmd}"', $launcher);
    self::assertStringNotContainsString($this->projectRoot, $launcher);
    self::assertStringNotContainsString(dirname(__DIR__, 3), $launcher);
  }

  public function testStatusReportsExtensionVendorAndGlobalAvailabilityWithoutWriting(): void {
    $installer = new CliInstaller(new CliInstallerTestConfigManager($this->projectRoot, 'shared-site'));
    $before = $installer->status();

    self::assertTrue($before['extension_cli_available']);
    self::assertFalse($before['vendor_launcher_available']);
    self::assertFalse($before['global_launcher_available']);
    self::assertFalse($before['registered']);

    self::assertTrue($installer->install()['ok']);
    $after = $installer->status();

    self::assertTrue($after['vendor_launcher_available']);
    self::assertTrue($after['global_launcher_available']);
    self::assertTrue($after['registered']);
  }

  public function testInstallIsIdempotentWhenManagedLauncherIsCurrent(): void {
    $installer = new CliInstaller(new CliInstallerTestConfigManager($this->projectRoot, 'shared-site'));
    $first = $installer->install();
    self::assertTrue($first['ok']);

    $second = $installer->install();

    self::assertTrue($second['ok']);
    self::assertSame([], $second['installed']);
    self::assertSame($this->projectRoot . '/vendor/bin/civicfg', $second['vendor_launcher']);
    self::assertSame($this->globalBin . '/civicfg', $second['global_launcher']);
  }

  public function testInstallNeverOverwritesExistingNonManagedGlobalCommand(): void {
    $custom = "#!/usr/bin/env bash\necho custom\n";
    file_put_contents($this->globalBin . '/civicfg', $custom);

    $installer = new CliInstaller(new CliInstallerTestConfigManager($this->projectRoot, 'shared-site'));
    $result = $installer->install();

    self::assertTrue($result['ok']);
    self::assertSame($custom, file_get_contents($this->globalBin . '/civicfg'));
    self::assertStringContainsString('existing non-managed file', implode("\n", $result['skipped']));
    self::assertFileExists($this->projectRoot . '/vendor/bin/civicfg');
    self::assertFileDoesNotExist($this->registryDir . '/installations.json');
  }

  public function testNonComposerProjectStillGetsOneGlobalLauncher(): void {
    $this->removeTree($this->projectRoot . '/vendor');

    $installer = new CliInstaller(new CliInstallerTestConfigManager($this->projectRoot, 'legacy-drupal-site'));
    $result = $installer->install();

    self::assertTrue($result['ok']);
    self::assertNull($result['vendor_launcher']);
    self::assertSame($this->globalBin . '/civicfg', $result['global_launcher']);
    self::assertFileExists($this->globalBin . '/civicfg');
  }

  public function testDrupalSiteLocalComposerVendorIsDiscovered(): void {
    $this->removeTree($this->projectRoot . '/vendor');
    $drupalRoot = $this->projectRoot . '/web';
    $siteVendor = $drupalRoot . '/sites/default/vendor';
    mkdir($siteVendor . '/bin', 0775, TRUE);
    file_put_contents($siteVendor . '/autoload.php', "<?php\n");

    $installer = new CliInstaller(new CliInstallerTestConfigManager($drupalRoot, 'legacy-drupal-site'));
    $result = $installer->install();

    self::assertTrue($result['ok']);
    self::assertSame($siteVendor . '/bin/civicfg', $result['vendor_launcher']);
    self::assertFileExists($siteVendor . '/bin/civicfg');
  }

  public function testWritableCvDirectoryIsPreferredForGlobalLauncher(): void {
    putenv('CIVICFG_GLOBAL_BIN_DIR');
    $cvBin = $this->sandbox . '/cv-bin';
    mkdir($cvBin, 0775, TRUE);
    file_put_contents($cvBin . '/cv', "#!/bin/sh\nexit 0\n");
    chmod($cvBin . '/cv', 0755);
    putenv('PATH=' . $cvBin . ':/usr/bin:/bin');
    putenv('CIVICFG_CV');

    $installer = new CliInstaller(new CliInstallerTestConfigManager($this->projectRoot, 'shared-site'));
    $result = $installer->install();

    self::assertTrue($result['ok']);
    self::assertSame($cvBin . '/civicfg', $result['global_launcher']);
    self::assertFileExists($cvBin . '/civicfg');
  }

  public function testGlobalPathDirectoryIsCreatedWhenPathAlreadyAdvertisesIt(): void {
    putenv('CIVICFG_GLOBAL_BIN_DIR');
    $home = $this->sandbox . '/home';
    mkdir($home, 0775, TRUE);
    $pathBin = $home . '/.local/bin';
    putenv('HOME=' . $home);
    putenv('PATH=' . $pathBin . ':/usr/bin:/bin');

    $installer = new CliInstaller(new CliInstallerTestConfigManager($this->projectRoot, 'shared-site'));
    $result = $installer->install();

    self::assertTrue($result['ok']);
    self::assertSame($pathBin . '/civicfg', $result['global_launcher']);
    self::assertFileExists($pathBin . '/civicfg');
    self::assertTrue($installer->status()['registered']);
  }

  public function testSharedGlobalLauncherSurvivesUntilLastProjectUninstalls(): void {
    $secondRoot = $this->sandbox . '/project-stage';
    mkdir($secondRoot . '/vendor/bin', 0775, TRUE);
    file_put_contents($secondRoot . '/vendor/autoload.php', "<?php\n");

    // Same CiviCRM site-family ID deliberately models a PROD DB copied to
    // DEV/STAGE. Local project roots must still register independently.
    $dev = new CliInstaller(new CliInstallerTestConfigManager($this->projectRoot, 'shared-site'));
    $stage = new CliInstaller(new CliInstallerTestConfigManager($secondRoot, 'shared-site'));

    self::assertTrue($dev->install()['ok']);
    self::assertTrue($stage->install()['ok']);

    $registry = json_decode((string) file_get_contents($this->registryDir . '/installations.json'), TRUE);
    self::assertCount(2, $registry['sites']);
    self::assertFileExists($this->globalBin . '/civicfg');

    $devResult = $dev->uninstall();
    self::assertTrue($devResult['ok']);
    self::assertFileDoesNotExist($this->projectRoot . '/vendor/bin/civicfg');
    self::assertFileExists($secondRoot . '/vendor/bin/civicfg');
    self::assertFileExists($this->globalBin . '/civicfg');

    $stageResult = $stage->uninstall();
    self::assertTrue($stageResult['ok']);
    self::assertFileDoesNotExist($secondRoot . '/vendor/bin/civicfg');
    self::assertFileDoesNotExist($this->globalBin . '/civicfg');
    self::assertFileDoesNotExist($this->registryDir . '/installations.json');
  }

  public function testLegacyManagedAliasesAreRemovedButUnrelatedFilesRemain(): void {
    mkdir($this->projectRoot . '/bin', 0775, TRUE);
    file_put_contents($this->projectRoot . '/bin/ce', "#!/usr/bin/env bash\n# Managed by Configuration Manager extension\n");
    file_put_contents($this->projectRoot . '/bin/custom-tool', 'do not remove');

    $installer = new CliInstaller(new CliInstallerTestConfigManager($this->projectRoot, 'shared-site'));
    $result = $installer->install();

    self::assertTrue($result['ok']);
    self::assertFileDoesNotExist($this->projectRoot . '/bin/ce');
    self::assertFileExists($this->projectRoot . '/bin/custom-tool');
    self::assertContains($this->projectRoot . '/bin/ce', $result['removed_legacy']);
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
  private string $siteId;

  public function __construct(string $projectRoot, string $siteId) {
    $this->projectRoot = $projectRoot;
    $this->siteId = $siteId;
  }

  public function getProjectRoot(): string {
    return $this->projectRoot;
  }

  public function getSiteIdentifier(): string {
    return $this->siteId;
  }
}
