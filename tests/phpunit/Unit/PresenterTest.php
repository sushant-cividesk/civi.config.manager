<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\UI\Presenter;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class PresenterTest extends TestCase {
  public function testImportMessagesAreDeduplicated(): void {
    $presenter = new Presenter();
    $result = [
      'validation' => [
        'items' => [[
          'type' => 'extensions',
          'warnings' => [
            ['message' => 'Provider is backup/monitor-only.'],
            ['message' => 'Provider is backup/monitor-only.'],
          ],
          'errors' => [],
        ]],
      ],
    ];

    $messages = $presenter->extractImportMessages($result);

    self::assertCount(1, $messages);
    self::assertSame('Provider is backup/monitor-only.', $messages[0]['message']);
  }

  public function testExtensionStatusChangeUsesPlainLanguageAndBothSides(): void {
    $presenter = new Presenter();
    $method = new ReflectionMethod($presenter, 'describeFieldChange');
    $method->setAccessible(TRUE);

    $sentence = (string) $method->invoke(
      $presenter,
      ['type' => 'extensions'],
      ['path' => 'extension.status'],
      'Extension state',
      'changed',
      'disabled',
      'enabled'
    );

    self::assertStringContainsString('YAML Installed but disabled', $sentence);
    self::assertStringContainsString('CiviCRM Enabled', $sentence);
  }

  public function testBackupOnlyExtensionProviderDoesNotOfferRestoreAction(): void {
    $presenter = new Presenter();
    $plan = $presenter->buildImportPlan([[
      'type' => 'extensions',
      'type_label' => 'Extensions',
      'status' => 'missing_in_db',
      'path' => 'extensions/org.wikimedia.geocoder/api4/Geocoder/Addok.yml',
      'write_safe' => FALSE,
    ]]);

    self::assertCount(1, $plan);
    self::assertFalse($plan[0]['importable']);
    self::assertSame('Backup only', $plan[0]['action']);
    self::assertStringContainsString('Automatic restore is disabled', $plan[0]['note']);
  }

  public function testWriteSafeExtensionProviderStillOffersCreate(): void {
    $presenter = new Presenter();
    $plan = $presenter->buildImportPlan([[
      'type' => 'extensions',
      'type_label' => 'Extensions',
      'status' => 'missing_in_db',
      'path' => 'extensions/de.systopia.sqltasks/api3/Sqltask/hii.yml',
      'write_safe' => TRUE,
    ]]);

    self::assertTrue($plan[0]['importable']);
    self::assertSame('Create in CiviCRM', $plan[0]['action']);
  }

  public function testVirtualExtensionSubtypeIsPreservedForUiImportApply(): void {
    $presenter = new Presenter();
    $plan = [[
      'type' => 'extensions',
      'importable' => TRUE,
    ]];

    $types = $presenter->getImportApplyTypes($plan, [
      'extensions:de.systopia.sqltasks:api3:Sqltask',
    ]);

    self::assertSame([
      'extensions:de.systopia.sqltasks:api3:Sqltask',
    ], $types);
  }

  public function testExplicitBaseExtensionsSelectionKeepsBroadUiImport(): void {
    $presenter = new Presenter();
    $plan = [[
      'type' => 'extensions',
      'importable' => TRUE,
    ]];

    $types = $presenter->getImportApplyTypes($plan, [
      'extensions',
      'extensions:de.systopia.sqltasks:api3:Sqltask',
    ]);

    self::assertSame(['extensions'], $types);
  }

  public function testCompatibilityInformationDoesNotInflateImportWarningCount(): void {
    $presenter = new Presenter();
    $messages = $presenter->extractImportMessages([
      'items' => [[
        'type' => 'extensions',
        'warnings' => [],
        'compatibility' => [
          ['message' => 'Provider is backup/monitor-only.'],
          ['message' => 'Provider is backup/monitor-only.'],
        ],
        'errors' => [],
      ]],
    ]);

    self::assertSame([], $messages);
  }
}
