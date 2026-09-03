<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Service\ProviderAdmissionPolicy;
use PHPUnit\Framework\TestCase;

final class ProviderAdmissionPolicyTest extends TestCase {
  private ProviderAdmissionPolicy $policy;

  protected function setUp(): void {
    $this->policy = new ProviderAdmissionPolicy();
  }

  /**
   * Requirement: CRUD alone must never authorize a business-data provider.
   * Failure mode: a provider with a portable-looking name but transactional
   * fields is incorrectly granted generic management authority.
   */
  public function testBusinessDataFailsClassificationBeforeCapability(): void {
    $result = $this->policy->assess(['name'], [
      'name' => ['name' => 'name'],
      'contact_id' => ['name' => 'contact_id'],
    ]);

    self::assertFalse($result['admitted']);
    self::assertSame('business_data_marker', $result['reason_code']);
    self::assertFalse($result['stages']['classify']['passed']);
    self::assertFalse($result['stages']['capability']['passed']);
  }

  /** Requirement: local numeric IDs are never portable identity evidence. */
  public function testIdOnlyIdentityFailsClosed(): void {
    $result = $this->policy->assess(['id'], ['id' => ['name' => 'id']]);
    self::assertFalse($result['admitted']);
    self::assertSame('incomplete_identity_metadata', $result['reason_code']);
    self::assertFalse($result['stages']['portable_identity']['passed']);
  }

  /** Requirement: secrets cannot enter a generic writable projection. */
  public function testSensitiveWritableProjectionFailsClosed(): void {
    $result = $this->policy->assess(['name'], [
      'name' => ['name' => 'name'],
      'api_key' => ['name' => 'api_key'],
    ]);
    self::assertFalse($result['admitted']);
    self::assertSame('sensitive_writable_field', $result['reason_code']);
    self::assertTrue($result['stages']['portable_identity']['passed']);
    self::assertFalse($result['stages']['writable_projection']['passed']);
  }

  /**
   * Requirement: a writable foreign/local reference must have an explicit
   * semantic mapping before generic management is admitted.
   */
  public function testUnmappedWritableReferenceFailsClosed(): void {
    $result = $this->policy->assess(['name'], [
      'name' => ['name' => 'name'],
      'option_group_id' => ['name' => 'option_group_id', 'fk_entity' => 'OptionGroup'],
    ]);
    self::assertFalse($result['admitted']);
    self::assertSame('unmapped_reference_field', $result['reason_code']);
    self::assertSame(['option_group_id'], $result['reference_fields']);
    self::assertFalse($result['stages']['reference_mapping']['passed']);
  }

  /** Requirement: explicit semantic reference metadata can complete admission. */
  public function testPortableReferenceMappingAllowsFinalCapabilityAssignment(): void {
    $result = $this->policy->assess(['name'], [
      'name' => ['name' => 'name'],
      'option_group_id' => [
        'name' => 'option_group_id',
        'fk_entity' => 'OptionGroup',
        'civicfg_reference' => [
          'entity' => 'OptionGroup',
          'identity_fields' => ['name'],
        ],
      ],
      'label' => ['name' => 'label'],
    ]);
    self::assertTrue($result['admitted']);
    self::assertSame('portable_identity_and_field_policy', $result['reason_code']);
    self::assertTrue($result['stages']['reference_mapping']['passed']);
    self::assertTrue($result['stages']['capability']['passed']);
  }
}
