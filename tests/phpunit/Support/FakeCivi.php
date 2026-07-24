<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Support {
  final class FakeSettings {
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
