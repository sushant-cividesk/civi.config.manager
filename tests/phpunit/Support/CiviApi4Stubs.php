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


  if (!class_exists(CivicfgPagingTestEntity::class)) {
    class CivicfgPagingTestEntity {
      public static array $rows = [];
      public static int $executeCalls = 0;

      public static function get(bool $checkPermissions = TRUE): CivicfgPagingGetAction {
        return new CivicfgPagingGetAction();
      }
    }
  }

  if (!class_exists(CivicfgPagingGetAction::class)) {
    class CivicfgPagingGetAction {
      private int $limit = 0;
      private int $offset = 0;

      public function addSelect(...$fields): self {
        return $this;
      }

      public function addWhere(...$condition): self {
        return $this;
      }

      public function addOrderBy(string $field, string $direction): self {
        return $this;
      }

      public function setLimit(int $limit): self {
        $this->limit = $limit;
        return $this;
      }

      public function setOffset(int $offset): self {
        $this->offset = $offset;
        return $this;
      }

      public function execute(): array {
        CivicfgPagingTestEntity::$executeCalls++;
        if ($this->limit <= 0) {
          return array_slice(CivicfgPagingTestEntity::$rows, $this->offset);
        }
        return array_slice(CivicfgPagingTestEntity::$rows, $this->offset, $this->limit);
      }
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
