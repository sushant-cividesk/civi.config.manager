<?php

declare(strict_types=1);

namespace Civi\ConfigManager\BrowserQa\Tests;

use PHPUnit\Framework\TestCase;
use Playwright\Playwright;

final class ConfigManagerBrowserTest extends TestCase {
  public function testSettingsExposeProviderSafetyWithoutUnsafeBypass(): void {
    $baseUrl = rtrim((string) getenv('CIVICFG_BASE_URL'), '/');
    if ($baseUrl === '') {
      self::markTestSkipped('CIVICFG_BASE_URL is required for black-box browser QA.');
    }

    $context = Playwright::chromium(['headless' => true]);
    try {
      $page = $context->newPage();
      $page->goto($baseUrl . '/civicrm/login');
      $password = $page->locator('input[type="password"]')->first();
      if ($password->count() > 0) {
        $username = $page->locator('input[name="name"], input[name="username"], input[type="email"], #edit-name')->first();
        $username->fill((string) (getenv('CIVICRM_ADMIN_USER') ?: 'admin'));
        $password->fill((string) (getenv('CIVICRM_ADMIN_PASS') ?: 'qa-admin-password'));
        $page->locator('button[type="submit"], input[type="submit"]')->first()->click();
      }

      $page->goto($baseUrl . '/civicrm/admin/config-manager?reset=1&op=settings');
      $html = $page->content();
      self::assertStringContainsString('What should Configuration Manager manage?', $html);
      self::assertStringContainsString('Provider safety', $html);
      self::assertStringNotContainsString('Continue anyway', $html);
      self::assertStringNotContainsString('continue anyway', strtolower($html));

      $artifactDir = (string) (getenv('QA_ARTIFACT_DIR') ?: '');
      if ($artifactDir !== '' && is_dir($artifactDir)) {
        $page->screenshot(rtrim($artifactDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'settings-provider-safety-php.png', ['fullPage' => true]);
      }
    }
    finally {
      $context->close();
    }
  }
}
