<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Tests\Support\TemporaryDirectoryTrait;
use Civi\ConfigManager\Util\SimpleYaml;
use PHPUnit\Framework\TestCase;

final class SimpleYamlTest extends TestCase {
  use TemporaryDirectoryTrait;

  protected function tearDown(): void {
    $this->removeTemporaryDirectories();
    parent::tearDown();
  }

  public function testDumpUsesReadableNullAndStableTrailingNewline(): void {
    $yaml = SimpleYaml::dump([
      'empty' => NULL,
      'enabled' => TRUE,
      'count' => 4,
    ]);

    self::assertStringContainsString('empty: null', $yaml);
    self::assertStringContainsString('enabled: true', $yaml);
    self::assertSame("\n", substr($yaml, -1));
    self::assertStringNotContainsString('~', $yaml);
  }

  public function testRoundTripPreservesUnicodeAndMultilineText(): void {
    $directory = $this->createTemporaryDirectory();
    $file = $directory . '/sample.yml';
    $data = [
      'title' => 'Résumé – नमस्ते',
      'body' => "First line\nSecond: line # marker\n",
      'nested' => [
        'false_value' => FALSE,
        'empty_string' => '',
      ],
    ];

    file_put_contents($file, SimpleYaml::dump($data));

    self::assertSame($data, SimpleYaml::parseFile($file));
  }

  public function testSymfonyYamlIsRuntimeDependency(): void {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/composer.json'), TRUE);

    self::assertIsArray($composer);
    self::assertArrayHasKey('symfony/yaml', (array) ($composer['require'] ?? []));
    self::assertArrayNotHasKey('symfony/yaml', (array) ($composer['require-dev'] ?? []));
  }

  public function testParsingScalarYamlReturnsEmptyArray(): void {
    $directory = $this->createTemporaryDirectory();
    $file = $directory . '/scalar.yml';
    file_put_contents($file, "plain scalar\n");

    self::assertSame([], SimpleYaml::parseFile($file));
  }
}
