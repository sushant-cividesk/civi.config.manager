<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FreshInstallDefaultsTest extends TestCase {
  protected function setUp(): void {
    \Civi::settings()->reset();
    if (!defined('CIVICRM_UF')) {
      define('CIVICRM_UF', 'UnitTest');
    }
    require_once dirname(__DIR__, 3) . '/configmanager.php';
  }

  protected function tearDown(): void {
    \Civi::settings()->reset();
  }

  public function testFreshInstallInitializesIgnoreDefault(): void {
    _configmanager_initialize_fresh_install_defaults();

    self::assertSame('ignore', \Civi::settings()->get('civicfg_scope_default_mode'));
  }

  public function testFreshInstallInitializerDoesNotOverwriteExplicitDefault(): void {
    \Civi::settings()->set('civicfg_scope_default_mode', 'all');

    _configmanager_initialize_fresh_install_defaults();

    self::assertSame('all', \Civi::settings()->get('civicfg_scope_default_mode'));
  }
}
