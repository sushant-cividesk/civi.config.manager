<?php

declare(strict_types=1);

namespace Civi\Api4 {
  final class CivicfgCrudComplete {
    public static function get($checkPermissions = TRUE) {}
    public static function create($checkPermissions = TRUE) {}
    public static function update($checkPermissions = TRUE) {}
    public static function delete($checkPermissions = TRUE) {}
  }

  final class CivicfgCrudReadOnly {
    public static function get($checkPermissions = TRUE) {}
  }
}

namespace Civi\ConfigManager\Tests\Unit {

  use Civi\ConfigManager\Handler\AbstractHandler;
  use Civi\ConfigManager\Handler\GenericApi4CollectionHandler;
  use PHPUnit\Framework\TestCase;

  final class Api4RuntimeCapabilityTest extends TestCase {
    public function testCompleteCrudSurfaceIsFullManagement(): void {
      $availability = (new Api4RuntimeCapabilityFixture())->check('CivicfgCrudComplete');

      self::assertTrue($availability['available']);
      self::assertSame('full', $availability['management_capability']);
      self::assertSame([], $availability['missing_actions']);
    }

    public function testMissingWriteActionsDowngradeToExportOnly(): void {
      $availability = (new Api4RuntimeCapabilityFixture())->check('CivicfgCrudReadOnly');

      self::assertTrue($availability['available']);
      self::assertSame('export_only', $availability['management_capability']);
      self::assertSame(['create', 'update', 'delete'], $availability['missing_actions']);
    }

    public function testMissingEntityIsUnavailable(): void {
      $availability = (new Api4RuntimeCapabilityFixture())->check('CivicfgDefinitelyMissingEntity');

      self::assertFalse($availability['available']);
      self::assertSame('unavailable', $availability['management_capability']);
    }

    public function testGenericHandlerCapabilityDoesNotDependOnMutableImportFlags(): void {
      $handler = new GenericApi4CollectionHandler(
        'qa-read-only',
        'QA Read Only',
        'qa-read-only',
        'CivicfgCrudReadOnly',
        ['name'],
        ['name' => 'ASC'],
        1,
        'items.yml',
        TRUE
      );
      $handler->setImportWriteEnabled(FALSE)->setDeleteMissingEnabled(FALSE);

      $availability = $handler->getRuntimeAvailability();

      self::assertTrue($availability['available']);
      self::assertSame('export_only', $availability['management_capability']);
      self::assertSame(['create', 'update', 'delete'], $availability['missing_actions']);
    }
  }

  final class Api4RuntimeCapabilityFixture extends AbstractHandler {
    public function getType(): string { return 'qa-api4-capability'; }
    public function getLabel(): string { return 'QA API4 Capability'; }
    public function getDirectory(): string { return 'qa-api4-capability'; }
    public function getWeight(): int { return 1; }
    public function export(): array { return []; }

    public function check(string $entity): array {
      return $this->api4ManagementAvailability($entity, ['get', 'create', 'update', 'delete']);
    }
  }
}
