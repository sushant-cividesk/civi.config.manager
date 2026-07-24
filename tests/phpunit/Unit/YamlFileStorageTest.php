<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Storage\YamlFileStorage;
use Civi\ConfigManager\Tests\Support\TemporaryDirectoryTrait;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class YamlFileStorageTest extends TestCase {
  use TemporaryDirectoryTrait;

  protected function tearDown(): void {
    $this->removeTemporaryDirectories();
    parent::tearDown();
  }

  public function testWriteReadCompareAndDeleteAreIsolatedToRoot(): void {
    $root = $this->createTemporaryDirectory();
    $storage = new YamlFileStorage($root);
    $data = ['type' => 'example', 'item' => ['name' => 'alpha']];

    $path = $storage->write('nested/config', 'alpha.yml', $data);

    self::assertSame($root . '/nested/config/alpha.yml', $path);
    self::assertTrue($storage->exists('nested/config', 'alpha.yml'));
    self::assertTrue($storage->isSame('nested/config', 'alpha.yml', $data));
    self::assertSame($data, $storage->readFile('nested/config/alpha.yml'));
    self::assertSame(['alpha.yml' => $data], $storage->readDirectory('nested/config'));

    $storage->delete('nested/config', 'alpha.yml');

    self::assertFileDoesNotExist($path);
    self::assertDirectoryDoesNotExist($root . '/nested');
  }

  /**
   * @dataProvider unsafePathProvider
   */
  public function testRejectsTraversalAndAbsolutePaths(string $directory, string $filename): void {
    $root = $this->createTemporaryDirectory();
    $storage = new YamlFileStorage($root);

    $this->expectException(InvalidArgumentException::class);
    $storage->write($directory, $filename, ['safe' => TRUE]);
  }

  /**
   * @return array<string, array{0: string, 1: string}>
   */
  public static function unsafePathProvider(): array {
    return [
      'parent directory' => ['../outside', 'file.yml'],
      'parent filename' => ['', '../outside.yml'],
      'absolute filename' => ['', '/tmp/outside.yml'],
      'windows absolute filename' => ['', 'C:\\temp\\outside.yml'],
      'empty segment' => ['nested//folder', 'file.yml'],
      'null byte' => ['', "file.yml\0.php"],
    ];
  }


  public function testAllowsSymlinkedSyncRootButStillContainsAccess(): void {
    if (!function_exists('symlink')) {
      self::markTestSkipped('Symlinks are unavailable.');
    }

    $realRoot = $this->createTemporaryDirectory();
    $parent = $this->createTemporaryDirectory('civicfg-root-link-parent-');
    $linkRoot = $parent . '/sync-link';
    if (!@symlink($realRoot, $linkRoot)) {
      self::markTestSkipped('Could not create symlinked sync root in this environment.');
    }

    $storage = new YamlFileStorage($linkRoot);
    $storage->write('', 'linked-root.yml', ['safe' => TRUE]);

    self::assertSame(['safe' => TRUE], $storage->readFile('linked-root.yml'));
    self::assertFileExists($realRoot . '/linked-root.yml');
  }

  public function testDoesNotFollowSymlinkedSubdirectory(): void {
    if (!function_exists('symlink')) {
      self::markTestSkipped('Symlinks are unavailable.');
    }

    $root = $this->createTemporaryDirectory();
    $outside = $this->createTemporaryDirectory('civicfg-outside-');
    if (!@symlink($outside, $root . '/linked')) {
      self::markTestSkipped('Could not create symlink in this environment.');
    }

    $storage = new YamlFileStorage($root);
    $this->expectException(RuntimeException::class);
    $storage->write('linked', 'escape.yml', ['escaped' => TRUE]);
  }

  public function testRefusesToReadThroughSymlinkedSubdirectory(): void {
    if (!function_exists('symlink')) {
      self::markTestSkipped('Symlinks are unavailable.');
    }

    $root = $this->createTemporaryDirectory();
    $outside = $this->createTemporaryDirectory('civicfg-outside-');
    file_put_contents($outside . '/outside.yml', "outside: true\n");
    if (!@symlink($outside, $root . '/linked')) {
      self::markTestSkipped('Could not create symlink in this environment.');
    }

    $storage = new YamlFileStorage($root);
    $this->expectException(RuntimeException::class);
    $storage->readFile('linked/outside.yml');
  }

  public function testRefusesToDeleteThroughSymlinkedSubdirectory(): void {
    if (!function_exists('symlink')) {
      self::markTestSkipped('Symlinks are unavailable.');
    }

    $root = $this->createTemporaryDirectory();
    $outside = $this->createTemporaryDirectory('civicfg-outside-');
    $outsideFile = $outside . '/outside.yml';
    file_put_contents($outsideFile, "outside: true\n");
    if (!@symlink($outside, $root . '/linked')) {
      self::markTestSkipped('Could not create symlink in this environment.');
    }

    $storage = new YamlFileStorage($root);
    try {
      $storage->delete('linked', 'outside.yml');
      self::fail('Deleting through a symlinked directory must be rejected.');
    }
    catch (RuntimeException $e) {
      self::assertFileExists($outsideFile);
    }
  }

  public function testRefusesToOverwriteSymlinkedFile(): void {
    if (!function_exists('symlink')) {
      self::markTestSkipped('Symlinks are unavailable.');
    }

    $root = $this->createTemporaryDirectory();
    $outside = $this->createTemporaryDirectory('civicfg-outside-') . '/target.yml';
    file_put_contents($outside, "outside: true\n");
    if (!@symlink($outside, $root . '/linked.yml')) {
      self::markTestSkipped('Could not create symlink in this environment.');
    }

    $storage = new YamlFileStorage($root);
    $this->expectException(RuntimeException::class);
    $storage->write('', 'linked.yml', ['escaped' => TRUE]);
  }
}
