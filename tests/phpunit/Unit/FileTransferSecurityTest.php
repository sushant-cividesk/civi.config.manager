<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Handler\AbstractHandler;
use Civi\ConfigManager\Service\ConfigManager;
use Civi\ConfigManager\Tests\Support\TemporaryDirectoryTrait;
use Civi\ConfigManager\UI\FileTransfer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class FileTransferSecurityTest extends TestCase {
  use TemporaryDirectoryTrait;

  protected function setUp(): void {
    parent::setUp();
    \Civi::settings()->reset();
    $GLOBALS['civicrm_setting'] = [];
  }

  protected function tearDown(): void {
    \Civi::settings()->reset();
    $GLOBALS['civicrm_setting'] = [];
    $this->removeTemporaryDirectories();
    parent::tearDown();
  }

  /**
   * @dataProvider safeYamlPathProvider
   */
  public function testAcceptsOnlySafeRelativeYamlPaths(string $path, bool $expected): void {
    $transfer = new FileTransfer();
    $method = new ReflectionMethod($transfer, 'isSafeRelativeYamlPath');
    $method->setAccessible(TRUE);

    self::assertSame($expected, $method->invoke($transfer, $path));
  }

  /**
   * @return array<string, array{0: string, 1: bool}>
   */
  public static function safeYamlPathProvider(): array {
    return [
      'root yml' => ['manifest.yml', TRUE],
      'nested yaml' => ['searchkit/displays/example.yaml', TRUE],
      'parent traversal' => ['../outside.yml', FALSE],
      'nested traversal' => ['safe/../../outside.yml', FALSE],
      'absolute unix' => ['/tmp/outside.yml', FALSE],
      'absolute windows' => ['C:\\temp\\outside.yml', FALSE],
      'empty segment' => ['safe//outside.yml', FALSE],
      'current segment' => ['safe/./outside.yml', FALSE],
      'not yaml' => ['safe/file.php', FALSE],
      'null byte' => ["safe/file.yml\0.php", FALSE],
      'unsupported character' => ['safe/file name.yml', FALSE],
    ];
  }

  public function testSafeWriteIsAtomicAndStaysInsideRoot(): void {
    $root = $this->createTemporaryDirectory();
    $target = $root . '/nested/example.yml';
    $transfer = new FileTransfer();
    $method = new ReflectionMethod($transfer, 'safeWriteContents');
    $method->setAccessible(TRUE);

    $method->invoke($transfer, "type: example\n", $target, $root);

    self::assertSame("type: example\n", file_get_contents($target));
    self::assertSame([], glob($root . '/nested/.civicfg-upload-*') ?: []);
  }


  public function testSafeWriteAllowsSymlinkedSyncRoot(): void {
    if (!function_exists('symlink')) {
      self::markTestSkipped('Symlinks are unavailable.');
    }

    $realRoot = $this->createTemporaryDirectory();
    $parent = $this->createTemporaryDirectory('civicfg-upload-root-link-parent-');
    $linkRoot = $parent . '/sync-link';
    if (!@symlink($realRoot, $linkRoot)) {
      self::markTestSkipped('Could not create symlinked sync root in this environment.');
    }

    $transfer = new FileTransfer();
    $method = new ReflectionMethod($transfer, 'safeWriteContents');
    $method->setAccessible(TRUE);

    $method->invoke($transfer, "safe: true\n", $linkRoot . '/from-upload.yml', $linkRoot);

    self::assertSame("safe: true\n", file_get_contents($realRoot . '/from-upload.yml'));
  }

  public function testSafeWriteRejectsPrefixCollisionOutsideRoot(): void {
    $parent = $this->createTemporaryDirectory();
    $root = $parent . '/sync';
    $outside = $parent . '/sync-other';
    mkdir($root);
    mkdir($outside);

    $transfer = new FileTransfer();
    $method = new ReflectionMethod($transfer, 'safeWriteContents');
    $method->setAccessible(TRUE);

    $this->expectException(RuntimeException::class);
    $method->invoke($transfer, "outside: true\n", $outside . '/outside.yml', $root);
  }

  public function testManagedSingleExportRejectsCraftedUnselectedItem(): void {
    $root = $this->createTemporaryDirectory();
    \Civi::settings()->reset();
    \Civi::settings()->set('civicfg_scope', [
      'scheduled-jobs' => [
        'mode' => 'selected',
        'selectors' => ['10'],
        'watch_unmanaged' => TRUE,
      ],
    ]);

    $handler = new FileTransferScopeFixtureHandler([
      $this->scopeJobFile(10, 'job_one'),
      $this->scopeJobFile(20, 'job_two'),
    ]);
    $manager = new FileTransferScopeFixtureManager($root, [$handler]);

    $managed = $manager->getManagedActiveExportFile('scheduled-jobs', 'job_one.yml');
    self::assertSame('scheduled-jobs/job_one.yml', $managed['relative']);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('outside the current managed scope');
    $manager->getManagedActiveExportFile('scheduled-jobs', 'job_two.yml');
  }

  public function testManagedArchiveOmitsStaleDeselectedYaml(): void {
    $root = $this->createTemporaryDirectory();
    \Civi::settings()->reset();
    \Civi::settings()->set('civicfg_scope', [
      'scheduled-jobs' => [
        'mode' => 'selected',
        'selectors' => ['job_one'],
        'watch_unmanaged' => TRUE,
      ],
    ]);

    $handler = new FileTransferScopeFixtureHandler([]);
    $manager = new FileTransferScopeFixtureManager($root, [$handler]);
    $storage = new \Civi\ConfigManager\Storage\YamlFileStorage($root);
    $storage->write('', 'manifest.yml', [
      'schema_version' => 1,
      'managed_scope' => [
        'scheduled-jobs' => [
          'mode' => 'selected',
          'config_keys' => ['scheduled-jobs|Job|name=job_one'],
          'selector_map' => ['job_one' => 'scheduled-jobs|Job|name=job_one'],
        ],
      ],
    ]);
    $storage->write('scheduled-jobs', 'job_one.yml', $this->scopeJobFile(10, 'job_one')['data']);
    $storage->write('scheduled-jobs', 'job_two.yml', $this->scopeJobFile(20, 'job_two')['data']);

    $files = $manager->getManagedYamlArchiveFiles();

    self::assertArrayHasKey('manifest.yml', $files);
    self::assertArrayHasKey('scheduled-jobs/job_one.yml', $files);
    self::assertArrayNotHasKey('scheduled-jobs/job_two.yml', $files);
  }

  private function scopeJobFile(int $id, string $name): array {
    return [
      'filename' => $name . '.yml',
      'source_id' => $id,
      'data' => [
        'schema_version' => 1,
        'type' => 'scheduled-jobs.item',
        'entity' => 'Job',
        'name' => $name,
        'identity_field' => 'name',
        'identity_confidence' => 'DISCOVERED_UNIQUE',
        'item' => ['name' => $name, 'is_active' => TRUE],
      ],
    ];
  }

  public function testSafeWriteRejectsSymlinkedDirectory(): void {
    if (!function_exists('symlink')) {
      self::markTestSkipped('Symlinks are unavailable.');
    }

    $root = $this->createTemporaryDirectory();
    $outside = $this->createTemporaryDirectory('civicfg-upload-outside-');
    if (!@symlink($outside, $root . '/linked')) {
      self::markTestSkipped('Could not create symlink in this environment.');
    }

    $transfer = new FileTransfer();
    $method = new ReflectionMethod($transfer, 'safeWriteContents');
    $method->setAccessible(TRUE);

    $this->expectException(RuntimeException::class);
    $method->invoke($transfer, "outside: true\n", $root . '/linked/outside.yml', $root);
  }
}

final class FileTransferScopeFixtureManager extends ConfigManager {
  private string $fixtureSyncDir;
  private array $fixtureHandlers;

  public function __construct(string $syncDir, array $handlers) {
    parent::__construct();
    $this->fixtureSyncDir = $syncDir;
    $this->fixtureHandlers = $handlers;
  }

  public function getSyncDir(): string {
    return $this->fixtureSyncDir;
  }

  public function getHandlers(): array {
    return $this->fixtureHandlers;
  }
}

final class FileTransferScopeFixtureHandler extends AbstractHandler {
  private array $files;

  public function __construct(array $files) {
    $this->files = $files;
  }

  public function getType(): string {
    return 'scheduled-jobs';
  }

  public function getLabel(): string {
    return 'Scheduled Jobs';
  }

  public function getDirectory(): string {
    return 'scheduled-jobs';
  }

  public function getWeight(): int {
    return 110;
  }

  public function export(): array {
    return $this->files;
  }
}
