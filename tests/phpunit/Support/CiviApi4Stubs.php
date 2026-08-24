<?php

declare(strict_types=1);

namespace Civi\Api4\Generic {
  if (!class_exists(AbstractEntity::class)) {
    abstract class AbstractEntity {
    }
  }

  if (!class_exists(BasicGetFieldsAction::class)) {
    class BasicGetFieldsAction {
      private bool $checkPermissions = TRUE;

      public function __construct(string $entity, string $action, callable $callback) {
      }

      public function setCheckPermissions(bool $checkPermissions): self {
        $this->checkPermissions = $checkPermissions;
        return $this;
      }

      public function getCheckPermissions(): bool {
        return $this->checkPermissions;
      }
    }
  }
}

namespace Civi\Api4 {
  if (!class_exists(CiviRulesRuleCondition::class)) {
    class CiviRulesRuleCondition {
    }
  }

  if (!class_exists(CiviRulesRuleAction::class)) {
    class CiviRulesRuleAction {
    }
  }
}

namespace {
  if (!class_exists('CRM_Utils_Hook')) {
    class CRM_Utils_Hook {
      /** @var array<string, callable> */
      private static array $callbacks = [];

      public static function singleton(): self {
        return new self();
      }

      public static function setCallback(string $hook, callable $callback): void {
        self::$callbacks[$hook] = $callback;
      }

      public static function resetCallbacks(): void {
        self::$callbacks = [];
      }

      public function invoke(array $names, &$arg1, &$arg2, &$arg3, &$arg4, &$arg5, &$arg6, string $hook): void {
        if (isset(self::$callbacks[$hook])) {
          self::$callbacks[$hook]($arg1, $arg2, $arg3, $arg4, $arg5, $arg6);
        }
      }
    }
  }
}
