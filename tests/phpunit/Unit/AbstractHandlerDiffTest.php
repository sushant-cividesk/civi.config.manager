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

  public function testSameSemanticIdentitySurvivesFilenameRename(): void {
    $handler = new TestHandler();
    $yaml = [
      'old-name.yml' => [
        'type' => 'example.item',
        'item' => ['name' => 'alpha', 'label' => 'Alpha'],
      ],
    ];
    $database = [[
      'filename' => 'new-name.yml',
      'data' => [
        'type' => 'example.item',
        'item' => ['name' => 'alpha', 'label' => 'Alpha'],
      ],
    ]];

    $diff = $handler->diffFromExports($database, $yaml);

    self::assertSame('in_sync', $diff['status']);
    self::assertSame([], $diff['files']);
    self::assertCount(1, $diff['renamed']);
    self::assertSame('old-name.yml', $diff['renamed'][0]['from']);
    self::assertSame('new-name.yml', $diff['renamed'][0]['to']);
  }

  public function testMachineIdentityChangeIsSuggestedButNotAutomaticallyMatched(): void {
    $handler = new TestHandler();
    $diff = $handler->diffFromExports([
      [
        'filename' => 'new-name.yml',
        'data' => [
          'type' => 'example.item',
          'key_fields' => ['name'],
          'key' => 'name=new_name',
          'item' => ['name' => 'new_name', 'label' => 'Same label'],
        ],
      ],
    ], [
      'old-name.yml' => [
        'type' => 'example.item',
        'key_fields' => ['name'],
        'key' => 'name=old_name',
        'item' => ['name' => 'old_name', 'label' => 'Same label'],
      ],
    ]);

    self::assertSame('changed', $diff['status']);
    self::assertSame(['new-name.yml'], $diff['new_in_db']);
    self::assertSame(['old-name.yml'], $diff['missing_in_db']);
    self::assertCount(1, $diff['possible_renames']);
    self::assertTrue($diff['possible_renames'][0]['requires_confirmation']);
    self::assertSame('old-name.yml', $diff['possible_renames'][0]['from']);
    self::assertSame('new-name.yml', $diff['possible_renames'][0]['to']);
  }

  public function testDiffPreservesScalarTypes(): void {
    $handler = new TestHandler();
    $diff = $handler->diffFromExports([
      [
        'filename' => 'alpha.yml',
        'data' => [
          'type' => 'example.item',
          'item' => ['name' => 'alpha', 'weight' => 1],
        ],
      ],
    ], [
      'alpha.yml' => [
        'type' => 'example.item',
        'item' => ['name' => 'alpha', 'weight' => '1'],
      ],
    ]);

    self::assertSame('changed', $diff['status']);
    self::assertSame('item.weight', $diff['files'][0]['changes'][0]['path']);
    self::assertSame(64, strlen($diff['files'][0]['yaml_hash']));
    self::assertSame(64, strlen($diff['files'][0]['active_hash']));
  }

  public function testDiffPreservesNullVersusEmptyString(): void {
    $handler = new TestHandler();
    $diff = $handler->diffFromExports([
      [
        'filename' => 'alpha.yml',
        'data' => [
          'type' => 'example.item',
          'item' => ['name' => 'alpha', 'value' => ''],
        ],
      ],
    ], [
      'alpha.yml' => [
        'type' => 'example.item',
        'item' => ['name' => 'alpha', 'value' => NULL],
      ],
    ]);

    self::assertSame('changed', $diff['status']);
    self::assertSame('item.value', $diff['files'][0]['changes'][0]['path']);
    self::assertNull($diff['files'][0]['changes'][0]['old']);
    self::assertSame('', $diff['files'][0]['changes'][0]['new']);
  }

  public function testDuplicateSemanticIdentitiesAreAllMarkedAmbiguous(): void {
    $handler = new TestHandler();
    $diff = $handler->diffFromExports([
      [
        'filename' => 'active-a.yml',
        'data' => ['type' => 'example.item', 'item' => ['name' => 'duplicate', 'label' => 'One']],
      ],
      [
        'filename' => 'active-b.yml',
        'data' => ['type' => 'example.item', 'item' => ['name' => 'duplicate', 'label' => 'Two']],
      ],
    ], []);

    self::assertCount(2, $diff['files']);
    foreach ($diff['files'] as $file) {
      self::assertSame('AMBIGUOUS', $file['identity_confidence']);
      self::assertFalse($file['write_safe']);
      self::assertStringContainsString('|duplicate=', $file['config_key']);
    }
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

  /**
   * @return array<int, array{filename: string, data: array<string, mixed>}>
   */
  public function export(): array {
    return [];
  }

  public function getWeight(): int {
    return 1;
  }
}
