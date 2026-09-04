<?php

declare(strict_types=1);

namespace {
  if (!class_exists('CRM_Core_Session')) {
    final class CRM_Core_Session {
      private static ?self $instance = NULL;
      /** @var array<string,mixed> */
      private array $values = [];

      public static function singleton(): self {
        if (self::$instance === NULL) {
          self::$instance = new self();
        }
        return self::$instance;
      }

      public function get(string $name) {
        return $this->values[$name] ?? NULL;
      }

      public function set(string $name, $value): void {
        $this->values[$name] = $value;
      }

      public function resetForTest(): void {
        $this->values = [];
      }
    }
  }
}

namespace Civi\ConfigManager\Tests\Unit {

  use Civi\ConfigManager\Service\LifecycleStateCleaner;
  use PHPUnit\Framework\TestCase;

  final class LifecycleStateCleanerTest extends TestCase {

    protected function setUp(): void {
      \Civi::settings()->reset();
      \CRM_Core_Session::singleton()->resetForTest();
    }

    protected function tearDown(): void {
      \Civi::settings()->reset();
      \CRM_Core_Session::singleton()->resetForTest();
    }

    public function testFreshInstallResetsScopeOperationalAndSessionState(): void {
      \Civi::settings()->set('civicfg_scope_default_mode', 'all');
      \Civi::settings()->set('civicfg_scope', ['Tag' => ['mode' => 'manage']]);
      \Civi::settings()->set('civicfg_scope_resolved', ['Tag' => ['1' => 'example']]);
      \Civi::settings()->set('civicfg_last_health', ['status' => 'stale']);
      \Civi::settings()->set('civicfg_watch_summary', ['changed' => 4]);
      \Civi::settings()->set('civicfg_watch_history', [['changed' => 4]]);
      foreach (LifecycleStateCleaner::transientSessionKeys() as $key) {
        \CRM_Core_Session::singleton()->set($key, ['stale' => TRUE]);
      }

      LifecycleStateCleaner::resetLocalState();

      self::assertSame('ignore', \Civi::settings()->get('civicfg_scope_default_mode'));
      self::assertSame([], \Civi::settings()->get('civicfg_scope'));
      self::assertSame([], \Civi::settings()->get('civicfg_scope_resolved'));
      self::assertSame([], \Civi::settings()->get('civicfg_last_health'));
      self::assertSame([], \Civi::settings()->get('civicfg_watch_summary'));
      self::assertSame([], \Civi::settings()->get('civicfg_watch_history'));
      foreach (LifecycleStateCleaner::transientSessionKeys() as $key) {
        self::assertNull(\CRM_Core_Session::singleton()->get($key), $key . ' must be cleared');
      }
    }

    public function testFreshInstallDoesNotOverrideCodeOwnedScope(): void {
      $GLOBALS['civicrm_setting'] = [
        'domain' => [
          'civicfg_scope' => [
            'tags' => ['mode' => 'selected', 'selectors' => ['Important']],
          ],
        ],
      ];

      LifecycleStateCleaner::resetLocalState();
      $scope = new \Civi\ConfigManager\Service\ConfigScope();

      self::assertTrue($scope->isPolicyOverridden());
      self::assertSame('selected', $scope->getPolicy('tags')['mode']);
      self::assertSame(['Important'], $scope->getPolicy('tags')['selectors']);
      unset($GLOBALS['civicrm_setting']);
    }

    public function testLifecycleResetPreservesConfigurationThatIsNotTransientState(): void {
      $preserved = [
        'civicfg_sync_dir' => '/custom/sync',
        'civicfg_site_id' => 'family-id',
        'civicfg_allow_cross_site_import' => 1,
        'civicfg_ignore_paths' => ['settings/example.yml:key'],
        'civicfg_ignore_values' => ['legacy'],
        'civicfg_settings_allowlist' => ['theme_backend'],
      ];
      foreach ($preserved as $name => $value) {
        \Civi::settings()->set($name, $value);
      }

      LifecycleStateCleaner::resetLocalState();

      foreach ($preserved as $name => $value) {
        self::assertSame($value, \Civi::settings()->get($name), $name . ' must be preserved');
      }
    }

  }
}
