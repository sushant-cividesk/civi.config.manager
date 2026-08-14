<?php

declare(strict_types=1);

namespace Civi\Api4 {
  final class CivicfgHookFixture {
    /** @var array<int, array<string, mixed>> */
    public static array $rows = [];

    /** @var array<int, array<string, mixed>> */
    public static array $created = [];

    /** @var array<int, array{where: array<int, array<int, mixed>>, values: array<string, mixed>}> */
    public static array $updated = [];

    /** @var array<int, array<int, array<int, mixed>>> */
    public static array $deleted = [];

    /** @var array<int, CivicfgHookFixtureAction> */
    public static array $actions = [];

    public static function get(bool $checkPermissions = FALSE): CivicfgHookFixtureAction {
      return self::recordAction(new CivicfgHookFixtureAction('get'));
    }

    public static function create(bool $checkPermissions = FALSE): CivicfgHookFixtureAction {
      return self::recordAction(new CivicfgHookFixtureAction('create'));
    }

    public static function update(bool $checkPermissions = FALSE): CivicfgHookFixtureAction {
      return self::recordAction(new CivicfgHookFixtureAction('update'));
    }

    public static function delete(bool $checkPermissions = FALSE): CivicfgHookFixtureAction {
      return self::recordAction(new CivicfgHookFixtureAction('delete'));
    }

    private static function recordAction(CivicfgHookFixtureAction $action): CivicfgHookFixtureAction {
      self::$actions[] = $action;
      return $action;
    }
  }

  final class CivicfgReferenceFixture {
    /** @var array<int, array<string, mixed>> */
    public static array $rows = [];

    public static function get(bool $checkPermissions = FALSE): CivicfgReferenceFixtureAction {
      return new CivicfgReferenceFixtureAction();
    }
  }

  final class CivicfgReferenceFixtureAction {
    /** @var array<int, string> */
    private array $select = [];

    /** @var array<int, array<int, mixed>> */
    private array $where = [];

    public function addSelect(string ...$fields): self {
      $this->select = array_values(array_merge($this->select, $fields));
      return $this;
    }

    /** @param mixed $value */
    public function addWhere(string $field, string $operator, $value): self {
      $this->where[] = [$field, $operator, $value];
      return $this;
    }

    public function addOrderBy(string $field, string $direction): self {
      return $this;
    }

    /** @return array<int, array<string, mixed>> */
    public function execute(): array {
      $rows = array_values(array_filter(self::rows(), function (array $row): bool {
        foreach ($this->where as $condition) {
          [$field, $operator, $value] = $condition;
          if ($operator !== '=' || !array_key_exists((string) $field, $row) || $row[(string) $field] !== $value) {
            return FALSE;
          }
        }
        return TRUE;
      }));

      if ($this->select !== [] && $this->select !== ['*']) {
        $allowed = array_flip($this->select);
        $rows = array_map(static function (array $row) use ($allowed): array {
          return array_intersect_key($row, $allowed);
        }, $rows);
      }
      return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private static function rows(): array {
      return CivicfgReferenceFixture::$rows;
    }
  }

  final class CivicfgHookFixtureAction {
    private string $op;

    /** @var array<int, string> */
    public array $select = [];

    /** @var array<int, array<int, mixed>> */
    public array $where = [];

    /** @var array<string, string> */
    public array $orderBy = [];

    /** @var array<string, mixed> */
    public array $values = [];

    public function __construct(string $op) {
      $this->op = $op;
    }

    public function addSelect(string ...$fields): self {
      $this->select = array_values(array_merge($this->select, $fields));
      return $this;
    }

    /** @param mixed $value */
    public function addWhere(string $field, string $operator, $value): self {
      $this->where[] = [$field, $operator, $value];
      return $this;
    }

    public function addOrderBy(string $field, string $direction): self {
      $this->orderBy[$field] = strtoupper($direction);
      return $this;
    }

    /** @param mixed $value */
    public function addValue(string $field, $value): self {
      $this->values[$field] = $value;
      return $this;
    }

    /** @return array<int, array<string, mixed>> */
    public function execute(): array {
      if ($this->op === 'create') {
        $nextId = 1;
        foreach (CivicfgHookFixture::$rows as $row) {
          $nextId = max($nextId, ((int) ($row['id'] ?? 0)) + 1);
        }
        $row = ['id' => $nextId] + $this->values;
        CivicfgHookFixture::$created[] = $row;
        CivicfgHookFixture::$rows[] = $row;
        return [$row];
      }

      if ($this->op === 'update') {
        CivicfgHookFixture::$updated[] = [
          'where' => $this->where,
          'values' => $this->values,
        ];
        foreach (CivicfgHookFixture::$rows as $index => $row) {
          if ($this->matchesWhere($row)) {
            CivicfgHookFixture::$rows[$index] = $row + $this->values;
            CivicfgHookFixture::$rows[$index] = array_merge($row, $this->values);
          }
        }
        return [$this->values + ['id' => $this->where[0][2] ?? 1]];
      }

      if ($this->op === 'delete') {
        CivicfgHookFixture::$deleted[] = $this->where;
        CivicfgHookFixture::$rows = array_values(array_filter(CivicfgHookFixture::$rows, function (array $row): bool {
          return !$this->matchesWhere($row);
        }));
        return [];
      }

      $rows = array_values(array_filter(CivicfgHookFixture::$rows, function (array $row): bool {
        return $this->matchesWhere($row);
      }));

      foreach (array_reverse($this->orderBy, TRUE) as $field => $direction) {
        usort($rows, static function (array $left, array $right) use ($field, $direction): int {
          $comparison = strcmp((string) ($left[$field] ?? ''), (string) ($right[$field] ?? ''));
          return $direction === 'DESC' ? -$comparison : $comparison;
        });
      }

      if ($this->select !== [] && $this->select !== ['*']) {
        $allowed = array_flip($this->select);
        $rows = array_map(static function (array $row) use ($allowed): array {
          return array_intersect_key($row, $allowed);
        }, $rows);
      }

      return $rows;
    }

    /** @param array<string, mixed> $row */
    private function matchesWhere(array $row): bool {
      foreach ($this->where as $condition) {
        [$field, $operator, $value] = $condition;
        if ($operator !== '=') {
          return FALSE;
        }
        if (!array_key_exists((string) $field, $row) || $row[(string) $field] !== $value) {
          return FALSE;
        }
      }
      return TRUE;
    }
  }
}

namespace Civi\ConfigManager\Tests\Unit {
  use Civi\Api4\CivicfgHookFixture;
  use Civi\Api4\CivicfgReferenceFixture;
  use Civi\ConfigManager\Handler\EntityDefinitionHandler;
  use PHPUnit\Framework\TestCase;

  final class EntityDefinitionHandlerTest extends TestCase {
    protected function setUp(): void {
      CivicfgHookFixture::$rows = [[
        'id' => 1,
        'name' => 'alpha_template',
        'category' => 'member',
        'label' => 'Alpha Template',
        'is_active' => TRUE,
        'api_key' => 'secret-value',
        'modified_date' => '2026-01-01',
        'settings' => [
          'id' => 'portable-setting-id',
          'format' => 'pdf',
          'secret' => 'nested-secret',
        ],
      ]];
      CivicfgReferenceFixture::$rows = [[
        'id' => 91,
        'name' => 'parent_alpha',
        'label' => 'Parent Alpha',
      ]];
      CivicfgHookFixture::$created = [];
      CivicfgHookFixture::$updated = [];
      CivicfgHookFixture::$deleted = [];
      CivicfgHookFixture::$actions = [];
    }

    public function testExportUsesStableKeyAndRemovesRuntimeNestedAndSensitiveFields(): void {
      $files = $this->buildHandler()->export();

      self::assertCount(1, $files);
      self::assertSame('alpha_template.yml', $files[0]['filename']);
      self::assertSame('myext_templates.item', $files[0]['data']['type']);
      self::assertSame(['name'], $files[0]['data']['key_fields']);
      self::assertSame('name=alpha_template', $files[0]['data']['key']);
      self::assertSame([['type' => 'extension', 'name' => 'myext']], $files[0]['data']['dependencies']);
      self::assertSame(['create' => TRUE, 'update' => TRUE, 'delete' => FALSE], $files[0]['data']['capabilities']);
      self::assertArrayNotHasKey('id', $files[0]['data']['item']);
      self::assertArrayNotHasKey('api_key', $files[0]['data']['item']);
      self::assertArrayNotHasKey('modified_date', $files[0]['data']['item']);
      self::assertArrayNotHasKey('secret', $files[0]['data']['item']['settings']);
      self::assertSame('portable-setting-id', $files[0]['data']['item']['settings']['id']);
      self::assertSame('pdf', $files[0]['data']['item']['settings']['format']);
    }

    public function testExportCollectionModeProducesOneCollectionFile(): void {
      CivicfgHookFixture::$rows[] = [
        'id' => 2,
        'name' => 'beta_template',
        'category' => 'member',
        'label' => 'Beta Template',
        'is_active' => FALSE,
      ];

      $files = $this->buildHandler(['split_files' => FALSE])->export();

      self::assertCount(1, $files);
      self::assertSame('myext_templates.yml', $files[0]['filename']);
      self::assertSame('myext_templates.collection', $files[0]['data']['type']);
      self::assertCount(2, $files[0]['data']['items']);
      self::assertSame('alpha_template', $files[0]['data']['items'][0]['name']);
    }

    public function testWhereAndOrderByAreAppliedToApi4Export(): void {
      CivicfgHookFixture::$rows[] = [
        'id' => 2,
        'name' => 'beta_template',
        'category' => 'member',
        'label' => 'Beta Template',
        'is_active' => TRUE,
      ];
      CivicfgHookFixture::$rows[] = [
        'id' => 3,
        'name' => 'staff_template',
        'category' => 'staff',
        'label' => 'Staff Template',
        'is_active' => TRUE,
      ];

      $files = $this->buildHandler([
        'where' => [['category', '=', 'member']],
        'order_by' => ['name' => 'DESC'],
      ])->export();

      self::assertSame(['alpha_template.yml', 'beta_template.yml'], array_column($files, 'filename'));
      self::assertCount(2, $files);
      self::assertSame([['category', '=', 'member']], CivicfgHookFixture::$actions[0]->where);
      self::assertSame(['name' => 'DESC'], CivicfgHookFixture::$actions[0]->orderBy);
    }

    public function testMultiKeyDefinitionsUseAllKeysForFilenameAndLookup(): void {
      $files = $this->buildHandler([
        'key_fields' => ['category', 'name'],
        'export_fields' => ['category', 'name', 'label'],
      ])->export();

      self::assertSame('member__alpha_template.yml', $files[0]['filename']);
      self::assertSame('category=member|name=alpha_template', $files[0]['data']['key']);
    }

    public function testImportUpdatesExistingRecordByStableKeyAndStripsUnsafeFields(): void {
      $summary = $this->buildHandler()->import([
        'alpha_template.yml' => [
          'type' => 'myext_templates.item',
          'entity' => 'CivicfgHookFixture',
          'item' => [
            'id' => 999,
            'name' => 'alpha_template',
            'label' => 'Alpha Template Updated',
            'is_active' => TRUE,
            'api_key' => 'must-not-be-written',
            'modified_date' => '2030-01-01',
            'joined.label' => 'API relation field must not be written',
            'settings' => [
              'format' => 'docx',
              'secret' => 'must-not-be-written',
            ],
          ],
        ],
      ], FALSE);

      self::assertTrue($summary['ok']);
      self::assertSame(1, $summary['update']);
      self::assertSame('Alpha Template Updated', CivicfgHookFixture::$updated[0]['values']['label']);
      self::assertSame('docx', CivicfgHookFixture::$updated[0]['values']['settings']['format']);
      self::assertArrayNotHasKey('id', CivicfgHookFixture::$updated[0]['values']);
      self::assertArrayNotHasKey('api_key', CivicfgHookFixture::$updated[0]['values']);
      self::assertArrayNotHasKey('modified_date', CivicfgHookFixture::$updated[0]['values']);
      self::assertArrayNotHasKey('joined.label', CivicfgHookFixture::$updated[0]['values']);
      self::assertArrayNotHasKey('secret', CivicfgHookFixture::$updated[0]['values']['settings']);
    }

    public function testImportCreatesMissingRecord(): void {
      $summary = $this->buildHandler()->import([
        'new_template.yml' => [
          'type' => 'myext_templates.item',
          'entity' => 'CivicfgHookFixture',
          'item' => [
            'name' => 'new_template',
            'label' => 'New Template',
            'is_active' => TRUE,
          ],
        ],
      ], FALSE);

      self::assertTrue($summary['ok']);
      self::assertSame(1, $summary['create']);
      self::assertSame('new_template', CivicfgHookFixture::$created[0]['name']);
    }

    public function testDryRunReportsChangeButDoesNotWrite(): void {
      $summary = $this->buildHandler()->import([
        'alpha_template.yml' => [
          'type' => 'myext_templates.item',
          'entity' => 'CivicfgHookFixture',
          'item' => [
            'name' => 'alpha_template',
            'label' => 'Dry Run Label',
          ],
        ],
      ], TRUE);

      self::assertTrue($summary['ok']);
      self::assertSame(1, $summary['update']);
      self::assertSame([], CivicfgHookFixture::$updated);
      self::assertSame('Alpha Template', CivicfgHookFixture::$rows[0]['label']);
    }

    public function testDeleteMissingRemovesRowsOnlyWhenEnabledAndApplied(): void {
      CivicfgHookFixture::$rows[] = [
        'id' => 2,
        'name' => 'orphan_template',
        'category' => 'member',
        'label' => 'Orphan Template',
      ];

      $summary = $this->buildHandler(['delete_missing' => TRUE])->import([
        'alpha_template.yml' => [
          'type' => 'myext_templates.item',
          'entity' => 'CivicfgHookFixture',
          'item' => [
            'name' => 'alpha_template',
            'label' => 'Alpha Template',
          ],
        ],
      ], FALSE);

      self::assertTrue($summary['ok']);
      self::assertSame(1, $summary['delete']);
      self::assertSame([[['id', '=', 2]]], CivicfgHookFixture::$deleted);
      self::assertCount(1, CivicfgHookFixture::$rows);
    }

    public function testImportDisabledDefinitionSkipsSafely(): void {
      $summary = $this->buildHandler(['import' => FALSE])->import([
        'alpha_template.yml' => [
          'type' => 'myext_templates.item',
          'entity' => 'CivicfgHookFixture',
          'item' => ['name' => 'alpha_template'],
        ],
      ], FALSE);

      self::assertTrue($summary['ok']);
      self::assertSame(1, $summary['skip']);
      self::assertSame([], CivicfgHookFixture::$updated);
      self::assertStringContainsString('Import is disabled', $summary['warnings'][0]['message']);
    }

    public function testValidateRejectsBadYamlAndSensitiveFields(): void {
      $result = $this->buildHandler()->validate([
        'bad-type.yml' => [
          'type' => 'wrong.item',
          'entity' => 'CivicfgHookFixture',
          'item' => ['name' => 'alpha_template'],
        ],
        'bad-entity.yml' => [
          'type' => 'myext_templates.item',
          'entity' => 'OtherEntity',
          'item' => ['name' => 'alpha_template'],
        ],
        'missing-key.yml' => [
          'type' => 'myext_templates.item',
          'entity' => 'CivicfgHookFixture',
          'item' => ['label' => 'Missing Key'],
        ],
        'secret-collection.yml' => [
          'type' => 'myext_templates.collection',
          'items' => [[
            'name' => 'alpha_template',
            'api_key' => 'must-not-be-here',
          ]],
        ],
      ]);

      self::assertFalse($result['valid']);
      self::assertCount(4, $result['errors']);
    }

    public function testDiffIgnoresRuntimeAndSensitiveFieldsButDetectsRealChanges(): void {
      $handler = $this->buildHandler();
      $inSync = $handler->diff([
        'alpha_template.yml' => [
          'schema_version' => 1,
          'type' => 'myext_templates.item',
          'entity' => 'CivicfgHookFixture',
          'key_fields' => ['name'],
          'key' => 'name=alpha_template',
          'dependencies' => [['type' => 'extension', 'name' => 'myext']],
          'item' => [
            'id' => 999,
            'name' => 'alpha_template',
            'category' => 'member',
            'label' => 'Alpha Template',
            'is_active' => TRUE,
            'api_key' => 'different-secret',
            'modified_date' => '2030-01-01',
            'settings' => [
              'id' => 'portable-setting-id',
              'format' => 'pdf',
              'secret' => 'different-nested-secret',
            ],
          ],
        ],
      ]);

      self::assertSame('in_sync', $inSync['status']);

      $changed = $handler->diff([
        'alpha_template.yml' => [
          'schema_version' => 1,
          'type' => 'myext_templates.item',
          'entity' => 'CivicfgHookFixture',
          'key_fields' => ['name'],
          'key' => 'name=alpha_template',
          'dependencies' => [['type' => 'extension', 'name' => 'myext']],
          'item' => [
            'name' => 'alpha_template',
            'category' => 'member',
            'label' => 'Changed Label',
            'is_active' => TRUE,
            'settings' => ['format' => 'pdf'],
          ],
        ],
      ]);

      self::assertSame('changed', $changed['status']);
      self::assertSame(['alpha_template.yml'], $changed['changed']);
    }

    public function testProviderCapabilitiesBlockUnsafeCreateUpdateAndDelete(): void {
      $update = $this->buildHandler(['can_update' => FALSE])->import([
        'alpha_template.yml' => [
          'type' => 'myext_templates.item',
          'entity' => 'CivicfgHookFixture',
          'item' => ['name' => 'alpha_template', 'label' => 'Changed'],
        ],
      ], FALSE);
      self::assertFalse($update['ok']);
      self::assertStringContainsString('Update is not allowed', $update['errors'][0]['message']);

      $create = $this->buildHandler(['can_create' => FALSE])->import([
        'new.yml' => [
          'type' => 'myext_templates.item',
          'entity' => 'CivicfgHookFixture',
          'item' => ['name' => 'new_template', 'label' => 'New'],
        ],
      ], FALSE);
      self::assertFalse($create['ok']);
      self::assertStringContainsString('Create is not allowed', $create['errors'][0]['message']);

      CivicfgHookFixture::$rows[] = ['id' => 2, 'name' => 'orphan_template', 'label' => 'Orphan'];
      $delete = $this->buildHandler(['delete_missing' => TRUE, 'can_delete' => FALSE])->import([
        'alpha_template.yml' => [
          'type' => 'myext_templates.item',
          'entity' => 'CivicfgHookFixture',
          'item' => ['name' => 'alpha_template', 'label' => 'Alpha Template'],
        ],
      ], FALSE);
      self::assertTrue($delete['ok']);
      self::assertSame(0, $delete['delete']);
      self::assertStringContainsString('delete is not allowed', strtolower($delete['warnings'][0]['message']));
      self::assertSame([], CivicfgHookFixture::$deleted);
    }

    public function testReferenceFieldsExportStableKeysAndResolveLocalIdsOnImport(): void {
      CivicfgHookFixture::$rows[0]['parent_id'] = 91;
      $handler = $this->buildHandler([
        'export_fields' => ['name', 'label', 'parent_id'],
        'reference_fields' => [
          'parent_id' => [
            'entity' => 'CivicfgReferenceFixture',
            'key_fields' => ['name'],
            'dependency_type' => 'parent-fixtures',
          ],
        ],
      ]);

      $files = $handler->export();
      self::assertSame([
        'provider' => 'api4:CivicfgReferenceFixture',
        'entity' => 'CivicfgReferenceFixture',
        'key' => ['name' => 'parent_alpha'],
      ], $files[0]['data']['item']['parent_id']);
      self::assertContains([
        'type' => 'parent-fixtures',
        'name' => 'parent_alpha',
        'reason' => 'Referenced by myext_templates field parent_id.',
      ], $files[0]['data']['dependencies']);

      $files[0]['data']['item']['label'] = 'Updated through stable reference';
      $summary = $handler->import([
        'alpha_template.yml' => $files[0]['data'],
      ], FALSE);

      self::assertTrue($summary['ok']);
      self::assertSame(1, $summary['update']);
      self::assertSame(91, CivicfgHookFixture::$updated[0]['values']['parent_id']);
    }

    public function testReferenceFieldsRejectLocalIdsAndUnexpectedKeys(): void {
      $handler = $this->buildHandler([
        'export_fields' => ['name', 'label', 'parent_id'],
        'reference_fields' => [
          'parent_id' => [
            'entity' => 'CivicfgReferenceFixture',
            'key_fields' => ['name'],
          ],
        ],
      ]);

      $validation = $handler->validate([
        'alpha_template.yml' => [
          'type' => 'myext_templates.item',
          'entity' => 'CivicfgHookFixture',
          'item' => ['name' => 'alpha_template', 'parent_id' => 91],
        ],
      ]);
      self::assertFalse($validation['valid']);
      self::assertStringContainsString('semantic configuration reference', $validation['errors'][0]['message']);

      $summary = $handler->import([
        'alpha_template.yml' => [
          'type' => 'myext_templates.item',
          'entity' => 'CivicfgHookFixture',
          'item' => [
            'name' => 'alpha_template',
            'parent_id' => [
              'provider' => 'api4:CivicfgReferenceFixture',
              'entity' => 'CivicfgReferenceFixture',
              'key' => ['label' => 'Parent Alpha'],
            ],
          ],
        ],
      ], FALSE);
      self::assertFalse($summary['ok']);
      self::assertStringContainsString('exactly these stable key fields', $summary['errors'][0]['message']);
    }

    public function testReferenceExportFailsInsteadOfLeakingUnresolvedLocalId(): void {
      CivicfgHookFixture::$rows[0]['parent_id'] = 999;
      $handler = $this->buildHandler([
        'export_fields' => ['name', 'label', 'parent_id'],
        'reference_fields' => [
          'parent_id' => [
            'entity' => 'CivicfgReferenceFixture',
            'key_fields' => ['name'],
          ],
        ],
      ]);

      $this->expectException(\RuntimeException::class);
      $this->expectExceptionMessage('Could not resolve portable configuration reference parent_id');
      $handler->export();
    }

    public function testInvalidDefinitionReturnsValidationError(): void {
      $result = (new EntityDefinitionHandler('broken_type', [
        'api_version' => 3,
        'entity' => 'CivicfgHookFixture',
        'key_fields' => ['name'],
        'export_fields' => ['name'],
      ]))->validate([]);

      self::assertFalse($result['valid']);
      self::assertStringContainsString('Only APIv4', $result['errors'][0]['message']);
    }

    /** @param array<string, mixed> $overrides */
    private function buildHandler(array $overrides = []): EntityDefinitionHandler {
      return new EntityDefinitionHandler('myext_templates', array_merge([
        'label' => 'My Extension Templates',
        'api_version' => 4,
        'entity' => 'CivicfgHookFixture',
        'path' => 'extensions/myext/templates',
        'key_fields' => ['name'],
        'export_fields' => ['name', 'category', 'label', 'is_active', 'api_key', 'modified_date', 'settings'],
        'ignore_fields' => ['id', 'modified_date', 'settings.secret'],
        'sensitive_fields' => ['api_key', 'settings.secret'],
        'dependencies' => ['extension' => ['myext']],
      ], $overrides));
    }
  }
}
