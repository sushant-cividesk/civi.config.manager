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

    self::assertEqualsCanonicalizing(['get_all_items', 'getalltasks'], $actions);
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

  public function testStripRuntimeRemovesOnlyKnownTopLevelRuntimeFields(): void {
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
      'config' => [
        'id' => 99,
        'modified_date' => '2026-08-13 12:35:00',
        'sql' => 'SELECT 1',
      ],
    ], $row);
  }

  public function testStrongIdentityIsPreferredAndWeakIdentityIsAmbiguous(): void {
    $handler = new ExtensionHandler();
    $identityField = new ReflectionMethod($handler, 'identityField');
    $identityField->setAccessible(TRUE);
    $confidence = new ReflectionMethod($handler, 'identityConfidence');
    $confidence->setAccessible(TRUE);

    self::assertSame('name', $identityField->invoke($handler, [
      'label' => 'Readable label',
      'name' => 'machine_name',
    ]));
    self::assertSame('DISCOVERED_UNIQUE', $confidence->invoke($handler, 'name'));
    self::assertSame('label', $identityField->invoke($handler, ['label' => 'Only weak identity']));
    self::assertSame('AMBIGUOUS', $confidence->invoke($handler, 'label'));
  }

  public function testApiMatchFieldGetsVerifiedConfidenceAndDuplicateValueIsNotUnique(): void {
    $handler = new ExtensionHandler();
    $identityField = new ReflectionMethod($handler, 'identityField');
    $identityField->setAccessible(TRUE);
    $confidence = new ReflectionMethod($handler, 'identityConfidence');
    $confidence->setAccessible(TRUE);
    $unique = new ReflectionMethod($handler, 'identityValueIsUnique');
    $unique->setAccessible(TRUE);

    $definition = ['match_fields' => ['code']];
    self::assertSame('code', $identityField->invoke($handler, ['name' => 'Fallback', 'code' => 'alpha'], $definition));
    self::assertSame('API_VERIFIED', $confidence->invoke($handler, 'code', $definition));
    self::assertTrue($unique->invoke($handler, [['code' => 'alpha'], ['code' => 'beta']], 'code', 'alpha'));
    self::assertFalse($unique->invoke($handler, [['code' => 'alpha'], ['code' => 'alpha']], 'code', 'alpha'));
  }

  public function testIdentitySafetyRequiresRowsWithUniqueStrongKeys(): void {
    $handler = new ExtensionHandler();
    $method = new ReflectionMethod($handler, 'identitySafetyForRows');
    $method->setAccessible(TRUE);

    self::assertSame('UNVERIFIED', $method->invoke($handler, [], ['match_fields' => ['code']]));
    self::assertSame('SAFE', $method->invoke($handler, [['code' => 'a'], ['code' => 'b']], ['match_fields' => ['code']]));
    self::assertSame('UNSAFE', $method->invoke($handler, [['code' => 'a'], ['code' => 'a']], ['match_fields' => ['code']]));
    self::assertSame('UNSAFE', $method->invoke($handler, [['label' => 'Weak']], []));
  }

  public function testAmbiguousProviderIdentityIsCompatibilityInformationNotValidationWarning(): void {
    $handler = new ExtensionHandler();
    $method = new ReflectionMethod($handler, 'validateExtensionConfigItem');
    $method->setAccessible(TRUE);
    $errors = [];
    $warnings = [];
    $compatibility = [];
    $definition = [
      'extension' => 'example.ext',
      'api' => 'api4',
      'entity' => 'ExampleConfig',
      'match_fields' => [],
    ];
    $definitions = [
      'example.ext|api4|exampleconfig' => $definition,
    ];
    $item = [
      'type' => 'extension_config.item',
      'extension' => 'example.ext',
      'api' => 'api4',
      'entity' => 'ExampleConfig',
      'identity_field' => 'label',
      'item' => ['label' => 'Readable title'],
    ];

    $method->invokeArgs($handler, ['example.yml', $item, $definitions, &$errors, &$warnings, &$compatibility]);

    self::assertSame([], $errors);
    self::assertSame([], $warnings);
    self::assertCount(1, $compatibility);
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
