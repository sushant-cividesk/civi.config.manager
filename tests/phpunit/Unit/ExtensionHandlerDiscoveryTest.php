<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Handler\ExtensionHandler;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ExtensionHandlerDiscoveryTest extends TestCase {
  public function testApi3EntityNameSupportsTopLevelAndActionDirectoryLayouts(): void {
    $handler = new ExtensionHandler();
    $method = new ReflectionMethod($handler, 'api3EntityNameFromFile');
    $method->setAccessible(TRUE);

    self::assertSame('LegacyEntity', $method->invoke($handler, '/tmp/ext/api/v3', '/tmp/ext/api/v3/LegacyEntity.php'));
    self::assertSame('SqltaskTemplate', $method->invoke($handler, '/tmp/ext/api/v3', '/tmp/ext/api/v3/SqltaskTemplate/Create.php'));
    self::assertSame('SqltaskTemplate', $method->invoke($handler, '/tmp/ext/api/v3', '/tmp/ext/api/v3/SqltaskTemplate/GetAll.php'));
  }

  public function testApi3CollectionActionCandidatesAcceptSafeGetAllVariantsOnly(): void {
    $handler = new ExtensionHandler();
    $method = new ReflectionMethod($handler, 'api3CollectionActionCandidates');
    $method->setAccessible(TRUE);

    $actions = (array) $method->invoke($handler, [
      'create',
      'delete',
      'execute',
      'get',
      'getalltasks',
      'get_all_items',
      'getwarningmessage',
    ]);

    self::assertSame(['get_all_items', 'getalltasks'], $actions);
  }

  public function testNormalizeApi3RowsSupportsCollectionAndSingleRecordResults(): void {
    $handler = new ExtensionHandler();
    $method = new ReflectionMethod($handler, 'normalizeApi3Rows');
    $method->setAccessible(TRUE);

    self::assertSame(
      [['id' => 1, 'name' => 'One'], ['id' => 2, 'name' => 'Two']],
      $method->invoke($handler, ['values' => [1 => ['id' => 1, 'name' => 'One'], 2 => ['id' => 2, 'name' => 'Two']]])
    );
    self::assertSame(
      [['id' => 7, 'name' => 'Full task', 'actions' => [['type' => 'sql']]]],
      $method->invoke($handler, ['values' => ['id' => 7, 'name' => 'Full task', 'actions' => [['type' => 'sql']]]])
    );
  }

  public function testStripRuntimeRemovesPortableRuntimeFieldsRecursively(): void {
    $handler = new ExtensionHandler();
    $method = new ReflectionMethod($handler, 'stripRuntime');
    $method->setAccessible(TRUE);

    $row = (array) $method->invoke($handler, [
      'id' => 7,
      'name' => 'Portable task',
      'last_modified' => '2026-08-13 12:34:56',
      'config' => [
        'id' => 99,
        'modified_date' => '2026-08-13 12:35:00',
        'sql' => 'SELECT 1',
      ],
    ]);

    self::assertSame([
      'name' => 'Portable task',
      'config' => ['sql' => 'SELECT 1'],
    ], $row);
  }

  public function testExtensionTokensIncludeConservativeSingularNamespace(): void {
    $handler = new ExtensionHandler();
    $method = new ReflectionMethod($handler, 'extensionTokens');
    $method->setAccessible(TRUE);

    $tokens = (array) $method->invoke($handler, 'de.systopia.sqltasks');

    self::assertContains('sqltasks', $tokens);
    self::assertContains('sqltask', $tokens);
  }
}
