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
}
