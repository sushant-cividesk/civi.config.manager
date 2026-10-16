<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Support {
  // phpcs:ignore -- Fixture/bootstrap class must retain its runtime test identity.
  final class FakeSettings {
    /**
     * @var array
     */
    private array $values = [];

    public function get(string $name) {
      return $this->values[$name] ?? NULL;
    }

    public function set(string $name, $value): void {
      $this->values[$name] = $value;
    }

    public function reset(): void {
      $this->values = [];
    }

  }
}

namespace {
  if (!class_exists('Civi')) {
    final class Civi {
      /**
       * @var \Civi\ConfigManager\Tests\Support\FakeSettings|null
       */
      private static ?\Civi\ConfigManager\Tests\Support\FakeSettings $settings = NULL;

      public static function settings(): \Civi\ConfigManager\Tests\Support\FakeSettings {
        if (self::$settings === NULL) {
          self::$settings = new \Civi\ConfigManager\Tests\Support\FakeSettings();
        }
        return self::$settings;
      }

    }
  }
}
