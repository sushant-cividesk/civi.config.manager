<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Handler\SettingHandler;
use PHPUnit\Framework\TestCase;

final class SettingHandlerSafetyTest extends TestCase {
  protected function setUp(): void {
    parent::setUp();
    \Civi::settings()->reset();
  }

  public function testExportExcludesSensitiveAllowlistedSettingsAndSplitsPortableFiles(): void {
    \Civi::settings()->set('civicfg_settings_allowlist', [
      'theme_backend',
      'qa_api_token',
      'smtpPassword',
      'service_api_key',
      'serviceAccessKey',
      'signing_key',
    ]);
    \Civi::settings()->set('theme_backend', 'riverlea');
    \Civi::settings()->set('qa_api_token', 'must-not-leak');
    \Civi::settings()->set('smtpPassword', 'must-not-leak');
    \Civi::settings()->set('service_api_key', 'must-not-leak');
    \Civi::settings()->set('serviceAccessKey', 'must-not-leak');
    \Civi::settings()->set('signing_key', 'must-not-leak');

    $export = (new SettingHandler())->export();
    $encoded = json_encode($export);

    self::assertCount(1, $export);
    self::assertSame('theme_backend.yml', $export[0]['filename']);
    self::assertSame('setting.item', $export[0]['data']['type']);
    self::assertSame('theme_backend', $export[0]['data']['item']['name']);
    self::assertSame('riverlea', $export[0]['data']['item']['value']);
    self::assertStringNotContainsString('must-not-leak', (string) $encoded);
  }

  public function testValidationRejectsSensitiveValuesFromYaml(): void {
    \Civi::settings()->set('civicfg_settings_allowlist', ['theme_backend']);
    $result = (new SettingHandler())->validate([
      'smtp_password.yml' => [
        'type' => 'setting.item',
        'name' => 'smtp_password',
        'item' => [
          'name' => 'smtp_password',
          'value' => 'secret',
        ],
      ],
    ]);

    self::assertFalse($result['valid']);
    self::assertStringContainsString('Sensitive settings cannot be managed', $result['errors'][0]['message']);
  }

  public function testImportCannotWriteSensitiveOrNonAllowlistedSettings(): void {
    \Civi::settings()->set('civicfg_settings_allowlist', ['theme_backend']);
    $handler = new SettingHandler();

    $sensitive = $handler->import([
      'service_token.yml' => [
        'type' => 'setting.item',
        'name' => 'service_token',
        'item' => ['name' => 'service_token', 'value' => 'secret'],
      ],
    ], FALSE);
    self::assertFalse($sensitive['ok']);
    self::assertNull(\Civi::settings()->get('service_token'));

    $notAllowlisted = $handler->import([
      'unapproved_setting.yml' => [
        'type' => 'setting.item',
        'name' => 'unapproved_setting',
        'item' => ['name' => 'unapproved_setting', 'value' => 'blocked'],
      ],
    ], FALSE);
    self::assertFalse($notAllowlisted['ok']);
    self::assertNull(\Civi::settings()->get('unapproved_setting'));
  }

  public function testLegacyCollectionRemainsImportableForAlphaMigration(): void {
    \Civi::settings()->set('theme_backend', 'default');
    $result = (new SettingHandler())->import([
      'civicrm.settings.yml' => [
        'type' => 'settings.allowlist',
        'allowlist' => ['theme_backend'],
        'items' => ['theme_backend' => 'riverlea'],
      ],
    ], FALSE);

    self::assertTrue($result['ok']);
    self::assertSame('riverlea', \Civi::settings()->get('theme_backend'));
    self::assertSame(['theme_backend'], \Civi::settings()->get('civicfg_settings_allowlist'));
  }
}
