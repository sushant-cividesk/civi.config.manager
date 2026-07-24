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

namespace {
  if (!class_exists('CRM_Utils_Hook')) {
    class CRM_Utils_Hook {
      public static function singleton(): self {
        return new self();
      }

      public function invoke(array $names, &$arg1, &$arg2, &$arg3, &$arg4, &$arg5, &$arg6, string $hook): void {
      }
    }
  }
}
