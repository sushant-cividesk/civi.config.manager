<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Handler\AbstractHandler;
use PHPUnit\Framework\TestCase;

final class AbstractHandlerDiffTest extends TestCase {
  public function testDiffIgnoresReverseDependencyMetadata(): void {
    $handler = new TestHandler();
    $yaml = [
      'alpha.yml' => [
        'type' => 'example.item',
        'item' => ['name' => 'alpha', 'label' => 'Alpha'],
        'required_by' => [['type' => 'dependent', 'name' => 'one']],
      ],
    ];
    $database = [[
      'filename' => 'alpha.yml',
      'data' => [
        'type' => 'example.item',
        'item' => ['name' => 'alpha', 'label' => 'Alpha'],
      ],
    ]];

    $diff = $handler->diffFromExports($database, $yaml);

    self::assertSame('in_sync', $diff['status']);
    self::assertSame([], $diff['files']);
  }

  public function testNamedListsAreComparedByStableIdentityInsteadOfPosition(): void {
    $handler = new TestHandler();
    $yaml = [
      'alpha.yml' => [
        'items' => [
          ['name' => 'two', 'label' => 'Two'],
          ['name' => 'one', 'label' => 'One'],
        ],
      ],
    ];
    $database = [[
      'filename' => 'alpha.yml',
      'data' => [
        'items' => [
          ['name' => 'one', 'label' => 'One'],
          ['name' => 'two', 'label' => 'Two changed'],
        ],
      ],
    ]];

    $diff = $handler->diffFromExports($database, $yaml);

    self::assertSame('changed', $diff['status']);
    self::assertSame(1, $diff['files'][0]['change_count']);
    self::assertSame('items[two].label', $diff['files'][0]['changes'][0]['path']);
  }

  public function testLargeTextDiffUsesFocusedExcerpt(): void {
    $handler = new TestHandler();
    $old = str_repeat('A', 700) . 'OLD-MARKER' . str_repeat('B', 700);
    $new = str_repeat('A', 700) . 'NEW-MARKER' . str_repeat('B', 700);

    $diff = $handler->diffFromExports([
      ['filename' => 'template.yml', 'data' => ['msg_html' => $new]],
    ], [
      'template.yml' => ['msg_html' => $old],
    ]);

    $rendered = $diff['files'][0]['diff'];
    self::assertStringContainsString('OLD-MARKER', $rendered);
    self::assertStringContainsString('NEW-MARKER', $rendered);
    self::assertLessThan(strlen($old) + strlen($new), strlen($rendered));
  }
}

final class TestHandler extends AbstractHandler {
  public function getType(): string {
    return 'example';
  }

  public function getLabel(): string {
    return 'Example';
  }

  public function getDirectory(): string {
    return 'examples';
  }

  public function export(): array {
    return [];
  }

  public function getWeight(): int {
    return 1;
  }
}
