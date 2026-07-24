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

  public function testExportExcludesSensitiveAllowlistedSettings(): void {
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

    $export = (new SettingHandler())->export()[0]['data'];
    $encoded = json_encode($export);

    self::assertSame(['theme_backend'], $export['allowlist']);
    self::assertSame(['theme_backend' => 'riverlea'], $export['items']);
    self::assertStringNotContainsString('must-not-leak', (string) $encoded);
  }

  public function testValidationRejectsSensitiveValuesFromYaml(): void {
    $result = (new SettingHandler())->validate([
      'civicrm.settings.yml' => [
        'type' => 'settings.allowlist',
        'allowlist' => ['theme_backend', 'smtp_password'],
        'items' => [
          'theme_backend' => 'riverlea',
          'smtp_password' => 'secret',
        ],
      ],
    ]);

    self::assertFalse($result['valid']);
    self::assertStringContainsString('Sensitive settings cannot be managed', $result['errors'][0]['message']);
  }

  public function testImportCannotWriteSensitiveSettings(): void {
    $result = (new SettingHandler())->import([
      'civicrm.settings.yml' => [
        'type' => 'settings.allowlist',
        'items' => ['service_token' => 'secret'],
      ],
    ], FALSE);

    self::assertFalse($result['ok']);
    self::assertNull(\Civi::settings()->get('service_token'));
  }
}
