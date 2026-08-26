<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Service\ConfigManager;
use PHPUnit\Framework\TestCase;

final class ConfigIgnoreDefaultsTest extends TestCase {
  protected function setUp(): void {
    parent::setUp();
    \Civi::settings()->reset();
  }

  protected function tearDown(): void {
    \Civi::settings()->reset();
    parent::tearDown();
  }

  public function testBuiltInWholeFileAndValueRulesShareOneRuleList(): void {
    $manager = new ConfigManager();

    self::assertSame([
      'extensions/civi.config.manager.yml',
      'extensions/*/api3/Job/*.yml:item.last_run',
      'extensions/*/api3/Job/*.yml:item.last_run_end',
      'scheduled-jobs/*.yml:item.scheduled_run_date',
      'site-tokens/*.yml:item.modified_date',
    ], $manager->getBuiltInIgnoreRules());

    self::assertSame([], $manager->getConfiguredIgnoreRules());
    self::assertSame(['extensions/civi.config.manager.yml'], $manager->getIgnorePatterns());
    self::assertCount(4, $manager->getIgnoreValuePatterns());
    self::assertTrue($manager->shouldIgnorePath('extensions/civi.config.manager.yml'));
    self::assertFalse($manager->shouldIgnorePath('scheduled-jobs/Test.yml'));
  }

  public function testRuntimeRulesIgnoreOnlyValuesNotWholeFiles(): void {
    $manager = new ConfigManager();

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

  public function testUnifiedSettingAcceptsWholeFileAndValueRules(): void {
    $manager = new ConfigManager();
    $saved = $manager->setConfiguredIgnoreRules([
      'custom/path/*.yml',
      'settings/theme_frontend.yml:item.value',
      'settings/theme_frontend.yml:item.value',
      'extensions/civi.config.manager.yml',
      'scheduled-jobs/*.yml:item.scheduled_run_date',
    ]);

    self::assertSame([
      'custom/path/*.yml',
      'settings/theme_frontend.yml:item.value',
    ], $saved);
    self::assertSame($saved, (array) \Civi::settings()->get('civicfg_ignore_paths'));
    self::assertSame([], (array) \Civi::settings()->get('civicfg_ignore_values'));

    self::assertTrue($manager->shouldIgnorePath('custom/path/example.yml'));
    self::assertFalse($manager->shouldIgnorePath('settings/theme_frontend.yml'));

    $setting = $manager->applyIgnoredValueRules(
      'settings/theme_frontend.yml',
      ['item' => ['name' => 'theme_frontend', 'value' => 'environment-theme']]
    );
    self::assertArrayNotHasKey('value', $setting['item']);
  }

  public function testLegacyValueSettingRemainsReadableAndMigratesOnSave(): void {
    \Civi::settings()->set('civicfg_ignore_paths', ['custom/whole.yml']);
    \Civi::settings()->set('civicfg_ignore_values', ['settings/legacy.yml:item.value']);

    $manager = new ConfigManager();
    self::assertSame([
      'custom/whole.yml',
      'settings/legacy.yml:item.value',
    ], $manager->getConfiguredIgnoreRules());

    $manager->setConfiguredIgnoreRules($manager->getConfiguredIgnoreRules());
    self::assertSame([
      'custom/whole.yml',
      'settings/legacy.yml:item.value',
    ], (array) \Civi::settings()->get('civicfg_ignore_paths'));
    self::assertSame([], (array) \Civi::settings()->get('civicfg_ignore_values'));
  }

  public function testIgnoreActionsWriteIntoUnifiedSetting(): void {
    $manager = new ConfigManager();
    $manager->addIgnorePathRule('custom/whole.yml');
    $manager->addIgnoreValueRules('settings/theme_frontend.yml', ['item.value']);

    self::assertSame([
      'custom/whole.yml',
      'settings/theme_frontend.yml:item.value',
    ], (array) \Civi::settings()->get('civicfg_ignore_paths'));
    self::assertSame([], (array) \Civi::settings()->get('civicfg_ignore_values'));
  }

  public function testSettingsUiUsesOneConfigIgnoreControl(): void {
    $template = (string) file_get_contents(dirname(__DIR__, 3) . '/templates/CRM/Configmanager/Page/Partials/Settings.tpl');

    self::assertStringContainsString('{ts}Config Ignore{/ts}', $template);
    self::assertStringContainsString('name="ignore_paths"', $template);
    self::assertStringNotContainsString('name="ignore_values"', $template);
    self::assertStringNotContainsString('Built-in runtime value ignores', $template);
    self::assertStringNotContainsString('Additional Config Ignore Values', $template);
  }
}
