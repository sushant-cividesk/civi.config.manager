<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Tests\Support\TemporaryDirectoryTrait;
use Civi\ConfigManager\UI\FileTransfer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class FileTransferSecurityTest extends TestCase {
  use TemporaryDirectoryTrait;

  protected function tearDown(): void {
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
