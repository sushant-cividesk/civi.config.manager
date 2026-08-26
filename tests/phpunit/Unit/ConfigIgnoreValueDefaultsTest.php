<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Service\ConfigManager;
use PHPUnit\Framework\TestCase;

final class ConfigIgnoreValueDefaultsTest extends TestCase {
  protected function setUp(): void {
    parent::setUp();
    \Civi::settings()->reset();
  }

  protected function tearDown(): void {
    \Civi::settings()->reset();
    parent::tearDown();
  }

  public function testBuiltInRuntimeValueRulesAreAlwaysEffective(): void {
    $manager = new ConfigManager();

    self::assertSame([
      'extensions/*/api3/Job/*.yml:item.last_run',
      'extensions/*/api3/Job/*.yml:item.last_run_end',
      'scheduled-jobs/*.yml:item.scheduled_run_date',
      'site-tokens/*.yml:item.modified_date',
    ], $manager->getBuiltInIgnoreValueRules());

    self::assertSame([], $manager->getConfiguredIgnoreValueRules());
    self::assertCount(4, $manager->getIgnoreValuePatterns());

    $extensionJob = $manager->applyIgnoredValueRules(
      'extensions/firewall/api3/Job/Civirules-cron.yml',
      [
        'item' => [
          'name' => 'Civirules cron',
          'run_frequency' => 'Always',
          'last_run' => '2026-08-26 10:00:00',
          'last_run_end' => '2026-08-26 10:00:04',
        ],
      ]
    );
    self::assertArrayNotHasKey('last_run', $extensionJob['item']);
    self::assertArrayNotHasKey('last_run_end', $extensionJob['item']);
    self::assertSame('Always', $extensionJob['item']['run_frequency']);

    $scheduledJob = $manager->applyIgnoredValueRules(
      'scheduled-jobs/Send-Scheduled-Mailings.yml',
      [
        'item' => [
          'name' => 'Send Scheduled Mailings',
          'scheduled_run_date' => '2026-08-26 11:00:00',
          'is_active' => TRUE,
        ],
      ]
    );
    self::assertArrayNotHasKey('scheduled_run_date', $scheduledJob['item']);
    self::assertTrue($scheduledJob['item']['is_active']);

    $siteToken = $manager->applyIgnoredValueRules(
      'site-tokens/message_header.yml',
      [
        'item' => [
          'name' => 'message_header',
          'label' => 'Message header',
          'modified_date' => '2026-08-26 12:00:00',
        ],
      ]
    );
    self::assertArrayNotHasKey('modified_date', $siteToken['item']);
    self::assertSame('Message header', $siteToken['item']['label']);
  }

  public function testConfiguredRulesAreAdditionalAndDoNotReplaceBuiltIns(): void {
    \Civi::settings()->set('civicfg_ignore_values', [
      'settings/theme_frontend.yml:item.value',
      'settings/theme_frontend.yml:item.value',
      'scheduled-jobs/*.yml:item.scheduled_run_date',
    ]);

    $manager = new ConfigManager();

    self::assertSame([
      'settings/theme_frontend.yml:item.value',
    ], $manager->getConfiguredIgnoreValueRules());
    self::assertCount(5, $manager->getIgnoreValuePatterns());

    $setting = $manager->applyIgnoredValueRules(
      'settings/theme_frontend.yml',
      ['item' => ['name' => 'theme_frontend', 'value' => 'environment-theme']]
    );
    self::assertArrayNotHasKey('value', $setting['item']);

    $job = $manager->applyIgnoredValueRules(
      'scheduled-jobs/Test.yml',
      ['item' => ['name' => 'Test', 'scheduled_run_date' => '2026-08-26 13:00:00']]
    );
    self::assertArrayNotHasKey('scheduled_run_date', $job['item']);
  }

  public function testSettingsUiSeparatesBuiltInAndAdditionalRules(): void {
    $template = (string) file_get_contents(dirname(__DIR__, 3) . '/templates/CRM/Configmanager/Page/Partials/Settings.tpl');

    self::assertStringContainsString('Built-in runtime value ignores', $template);
    self::assertStringContainsString('{$builtInIgnoreValues|escape}', $template);
    self::assertStringContainsString('Additional Config Ignore Values', $template);
  }
}
