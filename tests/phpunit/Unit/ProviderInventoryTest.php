<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Service\ConfigManager;
use PHPUnit\Framework\TestCase;

final class ProviderInventoryTest extends TestCase {
  protected function tearDown(): void {
    \CRM_Utils_Hook::resetCallbacks();
  }

  /**
   * Requirement: the public service inventory is deterministic metadata only.
   * Failure mode: it returns provider values/counts or loses custom ownership.
   */
  public function testServiceInventoryIncludesCustomDefinitionWithoutProviderValues(): void {
    \CRM_Utils_Hook::setCallback('civicfg_entityDefinitions', static function (array &$definitions): void {
      $definitions['custom_inventory_item'] = [
        'provider' => 'org.example.inventory',
        'label' => 'Inventory item',
        'entity' => 'CivicfgInventoryReadTrap',
        'path' => 'extensions/example/inventory',
        'key_fields' => ['name'],
        'export_fields' => ['name', 'label'],
        'runtime_fields' => ['modified_date'],
        'sensitive_fields' => ['secret_key'],
        'reference_fields' => [
          'group_id' => ['entity' => 'Group', 'key_fields' => ['name']],
        ],
      ];
    });

    $inventory = (new ConfigManager())->getProviderInventory();
    $matches = array_values(array_filter($inventory['providers'], static function(array $provider): bool {
      return $provider['type'] === 'custom_inventory_item';
    }));

    self::assertTrue($inventory['ok']);
    self::assertSame(1, $inventory['schema_version']);
    self::assertCount(1, $matches);
    self::assertSame('org.example.inventory', $matches[0]['owner']);
    self::assertSame('entity_definition_hook', $matches[0]['registration_source']);
    self::assertSame('managed_no_delete', $matches[0]['capability']);
    self::assertSame('handler_managed_no_delete', $matches[0]['capability_reason_code']);
    self::assertSame(['name'], $matches[0]['identity_fields']);
    self::assertSame(['group_id'], $matches[0]['reference_fields']);
    self::assertSame(['secret_key'], $matches[0]['sensitive_fields']);
    self::assertSame(['modified_date'], $matches[0]['runtime_fields']);
    self::assertFalse($matches[0]['collection_read_during_inventory']);
    self::assertArrayNotHasKey('records', $matches[0]);
    self::assertArrayNotHasKey('values', $matches[0]);
    self::assertSame(count($inventory['providers']), $inventory['summary']['provider_count']);
  }

  /**
   * Requirement: rejected provider collisions remain visible to operators as
   * unavailable metadata and never gain management authority.
   */
  public function testProviderInventorySurfacesRejectedDuplicateRegistration(): void {
    \CRM_Utils_Hook::setCallback('civicfg_entityDefinitions', static function (array &$definitions): void {
      $definitions['option-groups'] = [
        'provider' => 'example.collision',
        'entity' => 'OptionGroup',
        'key_fields' => ['name'],
      ];
    });

    $inventory = (new \Civi\ConfigManager\Service\ConfigManager())->getProviderInventory();
    $matches = array_values(array_filter($inventory['providers'], static function(array $provider): bool {
      return ($provider['capability_reason_code'] ?? '') === 'registry_duplicate_handler_type';
    }));

    self::assertCount(1, $matches);
    self::assertFalse($matches[0]['admitted']);
    self::assertSame('unavailable', $matches[0]['capability']);
    self::assertSame(1, $inventory['summary']['rejected_registration_count']);
  }
}
