<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Handler\ExtensionHandler;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

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

  public function testApi3WriteResultKeepsAssociativeProviderRow(): void {
    $handler = new ExtensionHandler();
    $method = new ReflectionMethod($handler, 'firstApi3ResultRow');
    $method->setAccessible(TRUE);

    self::assertSame(
      ['id' => 7, 'name' => 'Portable task'],
      $method->invoke($handler, ['values' => ['id' => 7, 'name' => 'Portable task']])
    );
    self::assertSame(
      ['id' => 8, 'name' => 'Normal row'],
      $method->invoke($handler, ['values' => [['id' => 8, 'name' => 'Normal row']]])
    );
  }

  public function testProviderSubtypeSeedsEmptyDesiredSetForLastRecordDelete(): void {
    $handler = new ExtensionHandler();
    $handler->setRuntimeTypeFilters(['extensions:de.systopia.sqltasks:api3:Sqltask']);
    $method = new ReflectionMethod($handler, 'desiredConfigKeysForRuntimeFilter');
    $method->setAccessible(TRUE);

    $desired = (array) $method->invoke($handler, [
      'de.systopia.sqltasks|api3|sqltask' => [
        'extension' => 'de.systopia.sqltasks',
        'api' => 'api3',
        'entity' => 'Sqltask',
      ],
      'example.ext|api4|exampleconfig' => [
        'extension' => 'example.ext',
        'api' => 'api4',
        'entity' => 'ExampleConfig',
      ],
    ]);

    self::assertSame([
      'de.systopia.sqltasks|api3|sqltask' => [],
    ], $desired);
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

  public function testRuntimeStrongIdentityAllowsCreateWhenTargetHasNoMatch(): void {
    $handler = new ExtensionHandler();
    $definition = [
      'extension' => 'de.systopia.sqltasks',
      'api' => 'api3',
      'entity' => 'Sqltask',
      'match_fields' => [],
    ];
    $this->setIdentityRows($handler, 'de.systopia.sqltasks|api3|sqltask', []);

    $method = new ReflectionMethod($handler, 'runtimeIdentityConfidence');
    $method->setAccessible(TRUE);

    self::assertSame('DISCOVERED_UNIQUE', $method->invoke($handler, $definition, 'name', 'hii'));
  }

  public function testRuntimeStrongIdentityAllowsOneTargetMatchButBlocksDuplicates(): void {
    $handler = new ExtensionHandler();
    $definition = [
      'extension' => 'de.systopia.sqltasks',
      'api' => 'api3',
      'entity' => 'Sqltask',
      'match_fields' => [],
    ];
    $method = new ReflectionMethod($handler, 'runtimeIdentityConfidence');
    $method->setAccessible(TRUE);

    $this->setIdentityRows($handler, 'de.systopia.sqltasks|api3|sqltask', [['name' => 'hii']]);
    self::assertSame('DISCOVERED_UNIQUE', $method->invoke($handler, $definition, 'name', 'hii'));

    $this->setIdentityRows($handler, 'de.systopia.sqltasks|api3|sqltask', [['name' => 'hii'], ['name' => 'hii']]);
    self::assertSame('AMBIGUOUS', $method->invoke($handler, $definition, 'name', 'hii'));
  }

  public function testRuntimeWeakIdentityStaysAmbiguousEvenWithNoTargetMatch(): void {
    $handler = new ExtensionHandler();
    $definition = [
      'extension' => 'example.ext',
      'api' => 'api4',
      'entity' => 'ExampleConfig',
      'match_fields' => [],
    ];
    $this->setIdentityRows($handler, 'example.ext|api4|exampleconfig', []);
    $method = new ReflectionMethod($handler, 'runtimeIdentityConfidence');
    $method->setAccessible(TRUE);

    self::assertSame('AMBIGUOUS', $method->invoke($handler, $definition, 'title', 'Readable title'));
  }

  public function testStrongNewProviderItemValidatesAsWriteSafeCreateCandidate(): void {
    $handler = new ExtensionHandler();
    $definition = [
      'extension' => 'de.systopia.sqltasks',
      'api' => 'api3',
      'entity' => 'Sqltask',
      'match_fields' => [],
      'can_create' => TRUE,
      'can_update' => TRUE,
    ];
    $definitions = ['de.systopia.sqltasks|api3|sqltask' => $definition];
    $this->setIdentityRows($handler, 'de.systopia.sqltasks|api3|sqltask', []);
    $method = new ReflectionMethod($handler, 'validateExtensionConfigItem');
    $method->setAccessible(TRUE);
    $errors = [];
    $warnings = [];
    $compatibility = [];
    $item = [
      'type' => 'extension_config.item',
      'extension' => 'de.systopia.sqltasks',
      'api' => 'api3',
      'entity' => 'Sqltask',
      'identity_field' => 'name',
      'item' => [
        'name' => 'hii',
        'description' => 'Portable SQL task',
        'actions' => [['type' => 'sql', 'configuration' => ['query' => 'SELECT 1']]],
      ],
    ];

    $method->invokeArgs($handler, ['hii.yml', $item, $definitions, &$errors, &$warnings, &$compatibility]);

    self::assertSame([], $errors);
    self::assertSame([], $warnings);
    self::assertSame([], $compatibility);
  }

  public function testNormalExtensionStateChangesArePlannedWithoutWarningNoise(): void {
    $handler = new ExtensionHandler();
    $method = new ReflectionMethod($handler, 'applyExtensionStatus');
    $method->setAccessible(TRUE);

    foreach ([
      ['current' => ['example.ext' => 'installed'], 'desired' => 'enabled', 'counter' => 'enable'],
      ['current' => ['example.ext' => 'enabled'], 'desired' => 'disabled', 'counter' => 'disable'],
      ['current' => ['example.ext' => 'uninstalled'], 'desired' => 'installed', 'counter' => 'install'],
    ] as $case) {
      $summary = [
        'install' => 0,
        'enable' => 0,
        'disable' => 0,
        'skip' => 0,
        'warnings' => [],
        'errors' => [],
      ];
      $method->invokeArgs($handler, [new \stdClass(), $case['current'], 'example.yml', 'example.ext', $case['desired'], TRUE, &$summary]);
      self::assertSame(1, $summary[$case['counter']]);
      self::assertSame([], $summary['warnings']);
      self::assertSame([], $summary['errors']);
    }
  }

  public function testContributedProviderCleaningPreservesNestedConfigurationValues(): void {
    $handler = new ExtensionHandler();
    $definition = [
      'api' => 'api3',
      'entity' => 'Sqltask',
      'write_fields' => ['name', 'description', 'config'],
    ];
    $row = [
      'id' => 42,
      'name' => 'hii',
      'description' => 'Portable SQL task',
      'last_runtime' => '0.002s',
      'config' => [
        'actions' => [[
          'id' => 7,
          'type' => 'CRM_Sqltasks_Action_RunSQL',
          'script' => "SELECT 'all values'",
          'configuration' => [
            'id' => 99,
            'options' => ['abort_on_error' => TRUE, 'tags' => ['one', 'two']],
          ],
        ]],
      ],
    ];

    $export = new ReflectionMethod($handler, 'cleanEntityRowForExport');
    $export->setAccessible(TRUE);
    $import = new ReflectionMethod($handler, 'cleanEntityRowForImport');
    $import->setAccessible(TRUE);

    $exported = (array) $export->invoke($handler, $row, $definition);
    $imported = (array) $import->invoke($handler, $exported, $definition);

    self::assertArrayNotHasKey('id', $exported);
    self::assertArrayNotHasKey('last_runtime', $exported);
    self::assertSame(7, $exported['config']['actions'][0]['id']);
    self::assertSame(99, $exported['config']['actions'][0]['configuration']['id']);
    self::assertSame("SELECT 'all values'", $exported['config']['actions'][0]['script']);
    self::assertSame(['one', 'two'], $imported['config']['actions'][0]['configuration']['options']['tags']);
  }

  public function testApi3ProviderCleaningDropsSqltaskReadOnlyRuntimeFields(): void {
    $handler = new ExtensionHandler();
    $definition = [
      'api' => 'api3',
      'entity' => 'Sqltask',
      'write_fields' => [
        'id' => ['name' => 'id'],
        'name' => ['name' => 'name'],
        'description' => ['name' => 'description'],
        'run_permissions' => ['name' => 'run_permissions'],
        'category' => ['name' => 'category'],
        'weight' => ['name' => 'weight'],
        'scheduled' => ['name' => 'scheduled'],
        'parallel_exec' => ['name' => 'parallel_exec'],
        'input_required' => ['name' => 'input_required'],
        'enabled' => ['name' => 'enabled'],
        'config' => ['name' => 'config'],
        'abort_on_error' => ['name' => 'abort_on_error'],
        'last_modified' => ['name' => 'last_modified'],
      ],
    ];
    $row = [
      'id' => 55,
      'name' => 'hii',
      'description' => 'Default template for new tasks',
      'run_permissions' => '',
      'category' => '',
      'weight' => 1,
      'scheduled' => 'always',
      'parallel_exec' => '0',
      'input_required' => '0',
      'enabled' => '0',
      'config' => [
        'actions' => [[
          'type' => 'CRM_Sqltasks_Action_RunSQL',
          'enabled' => '1',
          'script' => 'show tables;',
        ]],
        'version' => '2',
      ],
      'abort_on_error' => '1',
      'last_modified' => '2026-08-17 17:18:35',
      'archive_date' => '',
      'is_archived' => '0',
      'last_executed' => '2026-08-17 17:18:35',
      'last_runtime' => '0.002s',
      'next_execution' => 'TODO',
      'schedule' => 'always',
      'schedule_label' => 'always (warning: dispatcher currently disabled)',
      'short_desc' => 'Default template for new tasks',
    ];

    $method = new ReflectionMethod($handler, 'cleanEntityRowForImport');
    $method->setAccessible(TRUE);
    $clean = (array) $method->invoke($handler, $row, $definition);

    self::assertSame('hii', $clean['name']);
    self::assertSame('always', $clean['scheduled']);
    self::assertSame('show tables;', $clean['config']['actions'][0]['script']);
    self::assertArrayNotHasKey('id', $clean);
    self::assertArrayNotHasKey('last_modified', $clean);
    self::assertArrayNotHasKey('archive_date', $clean);
    self::assertArrayNotHasKey('is_archived', $clean);
    self::assertArrayNotHasKey('last_executed', $clean);
    self::assertArrayNotHasKey('last_runtime', $clean);
    self::assertArrayNotHasKey('next_execution', $clean);
    self::assertArrayNotHasKey('schedule', $clean);
    self::assertArrayNotHasKey('schedule_label', $clean);
    self::assertArrayNotHasKey('short_desc', $clean);
  }

  private function setIdentityRows(ExtensionHandler $handler, string $key, array $rows): void {
    $property = new ReflectionProperty($handler, 'identityRowsByDefinition');
    $property->setAccessible(TRUE);
    $property->setValue($handler, [$key => $rows]);
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
