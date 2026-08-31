<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit {

use Civi\ConfigManager\Handler\CiviRulesHandler;
use PHPUnit\Framework\TestCase;

final class Alpha63AmbiguityTest extends TestCase {

  public function testIdenticalDuplicateCiviRulesActionsExportAsDistinctMonitorOnlySnapshots(): void {
    $handler = new Alpha63CiviRulesFixtureHandler([
      'CiviRulesAction' => [
        ['id' => 10, 'name' => 'emailapi_send', 'label' => 'Send email', 'class_name' => 'EmailApi'],
        ['id' => 11, 'name' => 'emailapi_send', 'label' => 'Send email', 'class_name' => 'EmailApi'],
      ],
    ]);

    $files = iterator_to_array($handler->iterateExportUnit('actions'), FALSE);

    self::assertCount(2, $files);
    self::assertMatchesRegularExpression('#^actions/emailapi_send--ambiguous-[a-f0-9]{12}-01\\.yml$#', $files[0]['filename']);
    self::assertMatchesRegularExpression('#^actions/emailapi_send--ambiguous-[a-f0-9]{12}-02\\.yml$#', $files[1]['filename']);
    self::assertNotSame($files[0]['filename'], $files[1]['filename']);
    foreach ($files as $index => $file) {
      self::assertTrue($file['data']['monitor_only']);
      self::assertFalse($file['data']['identity_portable']);
      self::assertSame('AMBIGUOUS', $file['data']['identity_confidence']);
      self::assertSame(2, $file['data']['ambiguity']['group_count']);
      self::assertSame(2, $file['data']['ambiguity']['content_count']);
      self::assertSame($index + 1, $file['data']['ambiguity']['occurrence']);
      self::assertArrayNotHasKey('id', $file['data']['item']);
    }
    self::assertSame(
      $files[0]['data']['ambiguity']['content_fingerprint'],
      $files[1]['data']['ambiguity']['content_fingerprint']
    );
  }

  public function testLocalIdJunctionRowIsExportedAsMonitorOnlyInsteadOfDisappearing(): void {
    $handler = new Alpha63CiviRulesFixtureHandler([
      'CiviRulesRuleAction' => [[
        'id' => 14,
        'rule_id' => 4,
        'rule_id.name' => 'renewal_rule',
        'action_id' => 3,
        'action_id.name' => 'emailapi_send',
        'weight' => 1,
      ]],
    ]);

    $files = iterator_to_array($handler->iterateExportUnit('rule-actions'), FALSE);

    self::assertCount(1, $files);
    self::assertStringStartsWith('rule-actions/renewal_rule--emailapi_send--ambiguous-', $files[0]['filename']);
    self::assertTrue($files[0]['data']['monitor_only']);
    self::assertSame('local_id_only', $files[0]['data']['ambiguity']['reason']);
    self::assertArrayNotHasKey('id', $files[0]['data']['item']);
  }

  public function testPortableSourceIdentityThatIsAmbiguousOnTargetBlocksImport(): void {
    $handler = (new Alpha63CiviRulesFixtureHandler([
      'CiviRulesAction' => [
        ['id' => 20, 'name' => 'portable_action', 'label' => 'A'],
        ['id' => 21, 'name' => 'portable_action', 'label' => 'B'],
      ],
    ]))->setDeleteMissingEnabled(FALSE);

    $result = $handler->import([
      'actions/portable_action.yml' => [
        'schema_version' => 1,
        'type' => 'civirules.item',
        'entity' => 'CiviRulesAction',
        'bucket' => 'actions',
        'name' => 'portable_action',
        'identity_field' => 'name',
        'identity_portable' => TRUE,
        'identity_confidence' => 'DISCOVERED_UNIQUE',
        'monitor_only' => FALSE,
        'item' => ['name' => 'portable_action', 'label' => 'Source'],
      ],
    ], TRUE);

    self::assertFalse($result['ok']);
    self::assertSame(0, $result['create']);
    self::assertSame(0, $result['update']);
    self::assertStringContainsString('target conflict', (string) $result['errors'][0]['message']);
  }

  public function testLegacyPortableYamlWithoutIdentityPortableMetadataRemainsImportable(): void {
    $handler = (new Alpha63CiviRulesFixtureHandler([
      'CiviRulesAction' => [],
    ]))->setDeleteMissingEnabled(FALSE);

    $result = $handler->import([
      'actions/legacy_action.yml' => [
        'schema_version' => 1,
        'type' => 'civirules.item',
        'entity' => 'CiviRulesAction',
        'bucket' => 'actions',
        'name' => 'legacy_action',
        'identity_field' => 'name',
        // alpha61/62 files may not contain identity_portable/monitor_only.
        'item' => ['name' => 'legacy_action', 'label' => 'Legacy portable action'],
      ],
    ], TRUE);

    self::assertTrue($result['ok']);
    self::assertSame(1, $result['create']);
    self::assertSame(0, $result['monitor_only']);
  }

  public function testIntentionalMonitorOnlySourceDoesNotBlockUnrelatedPortablePreview(): void {
    $handler = (new Alpha63CiviRulesFixtureHandler([
      'CiviRulesAction' => [],
    ]))->setDeleteMissingEnabled(FALSE);

    $result = $handler->import([
      'actions/duplicate--ambiguous-deadbeef0000-01.yml' => [
        'schema_version' => 1,
        'type' => 'civirules.item',
        'entity' => 'CiviRulesAction',
        'bucket' => 'actions',
        'name' => 'duplicate',
        'identity_field' => 'name',
        'identity_portable' => FALSE,
        'identity_confidence' => 'AMBIGUOUS',
        'monitor_only' => TRUE,
        'item' => ['name' => 'duplicate'],
      ],
      'actions/unique_action.yml' => [
        'schema_version' => 1,
        'type' => 'civirules.item',
        'entity' => 'CiviRulesAction',
        'bucket' => 'actions',
        'name' => 'unique_action',
        'identity_field' => 'name',
        'identity_portable' => TRUE,
        'identity_confidence' => 'DISCOVERED_UNIQUE',
        'monitor_only' => FALSE,
        'item' => ['name' => 'unique_action', 'label' => 'Unique'],
      ],
    ], TRUE);

    self::assertTrue($result['ok']);
    self::assertSame(1, $result['monitor_only']);
    self::assertSame(1, $result['skip']);
    self::assertSame(1, $result['create']);
    self::assertSame(0, $result['update']);
    self::assertSame(0, $result['delete']);
  }
}

final class Alpha63CiviRulesFixtureHandler extends CiviRulesHandler {
  /** @var array<string,array<int,array<string,mixed>>> */
  private array $rows;

  /** @param array<string,array<int,array<string,mixed>>> $rows */
  public function __construct(array $rows) {
    $this->rows = $rows;
  }

  public function getRuntimeAvailability(): array {
    return ['available' => TRUE, 'management_capability' => 'full', 'reason' => ''];
  }

  protected function api4Get(string $entity, array $where = [], array $select = ['*'], array $orderBy = []): array {
    $rows = array_values($this->rows[$entity] ?? []);
    foreach ($where as $condition) {
      if (count($condition) < 3 || ($condition[1] ?? '') !== '=') {
        continue;
      }
      [$field, $_operator, $expected] = $condition;
      $rows = array_values(array_filter($rows, static function(array $row) use ($field, $expected): bool {
        return array_key_exists((string) $field, $row) && (string) $row[(string) $field] === (string) $expected;
      }));
    }
    if ($orderBy) {
      usort($rows, static function(array $a, array $b) use ($orderBy): int {
        foreach ($orderBy as $field => $direction) {
          $cmp = ($a[$field] ?? NULL) <=> ($b[$field] ?? NULL);
          if ($cmp !== 0) {
            return strtoupper((string) $direction) === 'DESC' ? -$cmp : $cmp;
          }
        }
        return 0;
      });
    }
    return $rows;
  }
}

}
