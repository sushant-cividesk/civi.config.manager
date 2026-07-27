<?php

declare(strict_types=1);

namespace Civi\Api4 {
  final class CivicfgHookFixture {
    public static array $rows = [];
    public static array $created = [];
    public static array $updated = [];
    public static array $deleted = [];

    public static function get(bool $checkPermissions = FALSE): CivicfgHookFixtureAction {
      return new CivicfgHookFixtureAction('get');
    }

    public static function create(bool $checkPermissions = FALSE): CivicfgHookFixtureAction {
      return new CivicfgHookFixtureAction('create');
    }

    public static function update(bool $checkPermissions = FALSE): CivicfgHookFixtureAction {
      return new CivicfgHookFixtureAction('update');
    }

    public static function delete(bool $checkPermissions = FALSE): CivicfgHookFixtureAction {
      return new CivicfgHookFixtureAction('delete');
    }
  }

  final class CivicfgHookFixtureAction {
    private string $op;
    private array $select = [];
    private array $where = [];
    private array $values = [];

    public function __construct(string $op) {
      $this->op = $op;
    }

    public function addSelect(string ...$fields): self {
      $this->select = array_merge($this->select, $fields);
      return $this;
    }

    public function addWhere(...$condition): self {
      $this->where[] = $condition;
      return $this;
    }

    public function addOrderBy(string $field, string $direction): self {
      return $this;
    }

    public function addValue(string $field, $value): self {
      $this->values[$field] = $value;
      return $this;
    }

    public function execute(): array {
      if ($this->op === 'create') {
        CivicfgHookFixture::$created[] = $this->values;
        return [$this->values + ['id' => 99]];
      }
      if ($this->op === 'update') {
        CivicfgHookFixture::$updated[] = ['where' => $this->where, 'values' => $this->values];
        return [$this->values + ['id' => $this->where[0][2] ?? 1]];
      }
      if ($this->op === 'delete') {
        CivicfgHookFixture::$deleted[] = $this->where;
        return [];
      }

      $rows = CivicfgHookFixture::$rows;
      foreach ($this->where as $condition) {
        [$field, $op, $value] = $condition;
        $rows = array_values(array_filter($rows, static function (array $row) use ($field, $op, $value): bool {
          return $op === '=' && array_key_exists($field, $row) && $row[$field] === $value;
        }));
      }
      return $rows;
    }
  }
}

namespace Civi\ConfigManager\Tests\Unit {
  use Civi\Api4\CivicfgHookFixture;
  use Civi\ConfigManager\Handler\EntityDefinitionHandler;
  use PHPUnit\Framework\TestCase;

  final class EntityDefinitionHandlerTest extends TestCase {
    protected function setUp(): void {
      CivicfgHookFixture::$rows = [[
        'id' => 1,
        'name' => 'alpha_template',
        'label' => 'Alpha Template',
        'is_active' => TRUE,
        'api_key' => 'secret-value',
        'modified_date' => '2026-01-01',
      ]];
      CivicfgHookFixture::$created = [];
      CivicfgHookFixture::$updated = [];
      CivicfgHookFixture::$deleted = [];
    }

    public function testExportUsesStableKeyAndRemovesRuntimeAndSensitiveFields(): void {
      $handler = $this->buildHandler();
      $files = $handler->export();

      self::assertCount(1, $files);
      self::assertSame('alpha_template.yml', $files[0]['filename']);
      self::assertSame('myext_templates.item', $files[0]['data']['type']);
      self::assertSame(['name'], $files[0]['data']['key_fields']);
      self::assertSame('name=alpha_template', $files[0]['data']['key']);
      self::assertArrayNotHasKey('id', $files[0]['data']['item']);
      self::assertArrayNotHasKey('api_key', $files[0]['data']['item']);
      self::assertArrayNotHasKey('modified_date', $files[0]['data']['item']);
    }

    public function testImportUpdatesExistingRecordByStableKey(): void {
      $handler = $this->buildHandler();
      $summary = $handler->import([
        'alpha_template.yml' => [
          'type' => 'myext_templates.item',
          'entity' => 'CivicfgHookFixture',
          'item' => [
            'name' => 'alpha_template',
            'label' => 'Alpha Template Updated',
            'is_active' => TRUE,
          ],
        ],
      ], FALSE);

      self::assertTrue($summary['ok']);
      self::assertSame(1, $summary['update']);
      self::assertSame('Alpha Template Updated', CivicfgHookFixture::$updated[0]['values']['label']);
      self::assertArrayNotHasKey('id', CivicfgHookFixture::$updated[0]['values']);
    }

    public function testValidateRejectsSensitiveFieldsInYaml(): void {
      $handler = $this->buildHandler();
      $result = $handler->validate([
        'alpha_template.yml' => [
          'type' => 'myext_templates.item',
          'entity' => 'CivicfgHookFixture',
          'item' => [
            'name' => 'alpha_template',
            'api_key' => 'must-not-be-here',
          ],
        ],
      ]);

      self::assertFalse($result['valid']);
      self::assertStringContainsString('Sensitive field', $result['errors'][0]['message']);
    }

    private function buildHandler(): EntityDefinitionHandler {
      return new EntityDefinitionHandler('myext_templates', [
        'label' => 'My Extension Templates',
        'api_version' => 4,
        'entity' => 'CivicfgHookFixture',
        'path' => 'extensions/myext/templates',
        'key_fields' => ['name'],
        'export_fields' => ['name', 'label', 'is_active', 'api_key', 'modified_date'],
        'ignore_fields' => ['id', 'modified_date'],
        'sensitive_fields' => ['api_key'],
        'dependencies' => ['extension' => ['myext']],
      ]);
    }
  }
}
