<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Handler\CiviRulesHandler;
use Civi\ConfigManager\Handler\CustomGroupHandler;
use Civi\ConfigManager\Handler\OptionGroupHandler;
use PHPUnit\Framework\TestCase;

final class ImportSafetyRegressionTest extends TestCase {
  public function testDryRunAcceptsOptionGroupPlannedEarlierInSameImport(): void {
    $handler = new ImportSafetyCustomGroupHandler();
    $handler->setPlannedDependencyNames([
      'option-groups' => ['pronouns_20230621235627' => TRUE],
    ]);

    $result = $handler->import([
      'groups/Contact_details.yml' => [
        'schema_version' => 1,
        'type' => 'custom_group',
        'name' => 'Contact_details',
        'group' => ['name' => 'Contact_details', 'title' => 'Contact details'],
        'fields' => [[
          'name' => 'Pronouns',
          'label' => 'Pronouns',
          'data_type' => 'String',
          'html_type' => 'Select',
          'option_group_name' => 'pronouns_20230621235627',
        ]],
      ],
    ], TRUE);

    self::assertTrue($result['ok']);
    self::assertSame([], $result['errors']);
    self::assertSame(2, $result['create']);
  }

  public function testDryRunStillBlocksDependencyMissingFromDbAndImportPlan(): void {
    $handler = new ImportSafetyCustomGroupHandler();

    $result = $handler->import([
      'groups/Contact_details.yml' => [
        'schema_version' => 1,
        'type' => 'custom_group',
        'name' => 'Contact_details',
        'group' => ['name' => 'Contact_details', 'title' => 'Contact details'],
        'fields' => [[
          'name' => 'Pronouns',
          'label' => 'Pronouns',
          'option_group_name' => 'pronouns_20230621235627',
        ]],
      ],
    ], TRUE);

    self::assertFalse($result['ok']);
    self::assertStringContainsString('requires missing option group: pronouns_20230621235627', $result['errors'][0]['message']);
  }

  public function testOptionValueMachineNameCollisionBlocksAndProtectsDeleteMissing(): void {
    $handler = new ImportSafetyOptionGroupHandler();

    $result = $handler->import([
      'from_email_address.yml' => [
        'schema_version' => 1,
        'type' => 'option_group',
        'name' => 'from_email_address',
        'group' => ['name' => 'from_email_address', 'title' => 'From Email Address Options'],
        'values' => [[
          'name' => '"Mary Ellis Bowler" <maryellisbowler@anvarlington.org>',
          'label' => 'Mary Ellis Bowler',
          'value' => '3',
          'is_active' => TRUE,
        ]],
      ],
    ], TRUE);

    self::assertFalse($result['ok']);
    self::assertCount(1, $result['errors']);
    self::assertStringContainsString('Possible OptionValue identity rename detected', $result['errors'][0]['message']);
    self::assertSame(0, $result['values']['delete']);
    self::assertSame(1, $result['values']['skip']);
    self::assertSame([], array_values(array_filter($result['warnings'], static function(array $warning): bool {
      return strpos((string) ($warning['message'] ?? ''), 'IntakeMgt@anvarlington.org') !== FALSE
        && strpos((string) ($warning['message'] ?? ''), 'will be deleted') !== FALSE;
    })));
  }

  public function testOptionValueMachineNameCollisionAlsoFailsClosedOnDirectApply(): void {
    $handler = new ImportSafetyOptionGroupHandler();

    $result = $handler->import([
      'from_email_address.yml' => [
        'schema_version' => 1,
        'type' => 'option_group',
        'name' => 'from_email_address',
        'group' => ['name' => 'from_email_address', 'title' => 'From Email Address Options'],
        'values' => [[
          'name' => '"Mary Ellis Bowler" <maryellisbowler@anvarlington.org>',
          'label' => 'Mary Ellis Bowler',
          'value' => '3',
        ]],
      ],
    ], FALSE);

    self::assertFalse($result['ok']);
    self::assertSame(0, $handler->writeCalls);
    self::assertSame(0, $result['values']['delete']);
  }

  public function testCiviRulesLocalIdJunctionRowsRemainMonitorOnlyAndNeverDeleteMissing(): void {
    $handler = new CiviRulesHandler();
    $result = $handler->import([
      'rule-conditions/19.yml' => [
        'schema_version' => 1,
        'type' => 'civirules.item',
        'bucket' => 'rule-conditions',
        'entity' => 'CiviRulesRuleCondition',
        'name' => '19',
        'identity_field' => 'id',
        'item' => ['id' => 19, 'rule_id' => 4, 'condition_id' => 8],
      ],
      'rule-actions/14.yml' => [
        'schema_version' => 1,
        'type' => 'civirules.item',
        'bucket' => 'rule-actions',
        'entity' => 'CiviRulesRuleAction',
        'name' => '14',
        'identity_field' => 'id',
        'item' => ['id' => 14, 'rule_id' => 4, 'action_id' => 3],
      ],
    ], TRUE);

    self::assertTrue($result['ok']);
    self::assertSame(0, $result['create']);
    self::assertSame(0, $result['update']);
    self::assertSame(0, $result['delete']);
    self::assertSame(2, $result['skip']);
    $messages = implode("\n", array_map(static function(array $row): string {
      return (string) ($row['message'] ?? '');
    }, $result['compatibility']));
    self::assertStringContainsString('intentional monitor-only snapshot', $messages);
    self::assertStringContainsString('does not expose a portable identity', $messages);
    self::assertStringContainsString('delete-missing is disabled', $messages);
  }
}

final class ImportSafetyCustomGroupHandler extends CustomGroupHandler {
  protected function api4Get(string $entity, array $where = [], array $select = ['*'], array $orderBy = []): array {
    return [];
  }

  protected function api4GetFirst(string $entity, array $where, array $select = ['*']): ?array {
    return NULL;
  }
}

final class ImportSafetyOptionGroupHandler extends OptionGroupHandler {
  public int $writeCalls = 0;

  protected function api4Get(string $entity, array $where = [], array $select = ['*'], array $orderBy = []): array {
    if ($entity === 'OptionValue') {
      return [[
        'id' => 10,
        'option_group_id' => 1,
        'name' => '"Mary Ellis Bowler" <IntakeMgt@anvarlington.org>',
        'label' => 'Mary Ellis Bowler',
        'value' => '3',
        'is_reserved' => FALSE,
      ]];
    }
    return [];
  }

  protected function api4GetFirst(string $entity, array $where, array $select = ['*']): ?array {
    if ($entity === 'OptionGroup') {
      return [
        'id' => 1,
        'name' => 'from_email_address',
        'title' => 'From Email Address Options',
      ];
    }
    if ($entity !== 'OptionValue') {
      return NULL;
    }

    $name = $this->whereValue($where, 'name');
    $value = $this->whereValue($where, 'value');
    if ($name !== NULL && $name === '"Mary Ellis Bowler" <IntakeMgt@anvarlington.org>') {
      return [
        'id' => 10,
        'option_group_id' => 1,
        'name' => $name,
        'label' => 'Mary Ellis Bowler',
        'value' => '3',
      ];
    }
    if ($value !== NULL && $value === '3') {
      return [
        'id' => 10,
        'option_group_id' => 1,
        'name' => '"Mary Ellis Bowler" <IntakeMgt@anvarlington.org>',
        'label' => 'Mary Ellis Bowler',
        'value' => '3',
      ];
    }
    return NULL;
  }

  protected function api4Create(string $entity, array $values): array {
    $this->writeCalls++;
    return ['id' => 99] + $values;
  }

  protected function api4Update(string $entity, array $where, array $values): array {
    $this->writeCalls++;
    return [];
  }

  protected function api4Delete(string $entity, array $where): array {
    $this->writeCalls++;
    return [];
  }

  private function whereValue(array $where, string $field): ?string {
    foreach ($where as $condition) {
      if (($condition[0] ?? NULL) === $field && array_key_exists(2, $condition)) {
        return (string) $condition[2];
      }
    }
    return NULL;
  }
}
