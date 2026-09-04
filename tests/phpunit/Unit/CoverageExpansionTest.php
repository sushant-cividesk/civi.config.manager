<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Handler\ContactLayoutHandler;
use Civi\ConfigManager\Handler\ReportInstanceHandler;
use Civi\ConfigManager\Handler\ProfileFieldHandler;
use Civi\ConfigManager\Handler\TagHandler;
use Civi\ConfigManager\Service\CoreEntityDefinitions;
use Civi\ConfigManager\Service\HandlerRegistry;
use PHPUnit\Framework\TestCase;

final class CoverageExpansionTest extends TestCase {

  /**
   * Requirement: newly managed core families must use semantic identities and
   * must not gain delete-missing authority merely because CRUD exists.
   */
  public function testReviewedCoreDefinitionsArePortableAndNoDelete(): void {
    $definitions = CoreEntityDefinitions::metadataDriven();
    $tag = CoreEntityDefinitions::tag();

    self::assertSame(['name'], $tag['key_fields']);
    self::assertSame('Tag', $tag['reference_fields']['parent_id']['entity']);
    self::assertFalse($tag['can_delete']);
    self::assertFalse($tag['delete_missing']);

    self::assertSame(['name'], $definitions['profiles']['key_fields']);
    self::assertArrayNotHasKey('profile-fields', $definitions, 'Profile Fields require the dedicated reviewed identity adapter.');
  }

  /**
   * Requirement: Tag import must not depend on child/parent filename order.
   * Failure mode: a child encountered first fails because its parent has not
   * yet been created on an empty target.
   */
  public function testTagImportCreatesParentBeforeChildWhenYamlIsChildFirst(): void {
    $handler = new CoverageExpansionTagFixture();
    $documents = [
      'a-child.yml' => [
        'type' => 'tags.item',
        'entity' => 'Tag',
        'item' => [
          'name' => 'child_tag',
          'label' => 'Child Tag',
          'parent_id' => ['provider' => 'api4:Tag', 'entity' => 'Tag', 'key' => ['name' => 'parent_tag']],
          'is_active' => TRUE,
        ],
      ],
      'z-parent.yml' => [
        'type' => 'tags.item',
        'entity' => 'Tag',
        'item' => [
          'name' => 'parent_tag',
          'label' => 'Parent Tag',
          'parent_id' => NULL,
          'is_active' => TRUE,
        ],
      ],
    ];

    $preview = $handler->import($documents, TRUE);
    self::assertTrue($preview['ok']);
    self::assertSame(2, $preview['create']);
    self::assertSame([], $handler->rows);

    $applied = $handler->import($documents, FALSE);
    self::assertTrue($applied['ok']);
    self::assertSame(2, $applied['create']);
    self::assertSame('parent_tag', $handler->createOrder[0]);
    self::assertSame('child_tag', $handler->createOrder[1]);
    self::assertSame($handler->rowId('parent_tag'), $handler->rowParentId('child_tag'));
  }

  /** Requirement: cyclic Tag parent dependencies must fail closed with zero writes. */
  public function testTagImportBlocksParentCycle(): void {
    $handler = new CoverageExpansionTagFixture();
    $documents = [
      'a.yml' => ['type' => 'tags.item', 'entity' => 'Tag', 'item' => [
        'name' => 'a', 'label' => 'A',
        'parent_id' => ['provider' => 'api4:Tag', 'entity' => 'Tag', 'key' => ['name' => 'b']],
      ]],
      'b.yml' => ['type' => 'tags.item', 'entity' => 'Tag', 'item' => [
        'name' => 'b', 'label' => 'B',
        'parent_id' => ['provider' => 'api4:Tag', 'entity' => 'Tag', 'key' => ['name' => 'a']],
      ]],
    ];

    $result = $handler->import($documents, FALSE);
    self::assertFalse($result['ok']);
    self::assertSame([], $handler->rows);
    self::assertSame([], $handler->createOrder);
  }

  /** Requirement: early coverage-expansion families are visible as independent configuration types. */
  public function testRegistryAdvertisesCoverageExpansionFamilies(): void {
    $types = array_map(static fn($handler): string => $handler->getType(), (new HandlerRegistry())->getHandlers());

    foreach (['tags', 'profiles', 'profile-fields', 'contact-layouts', 'report-instances'] as $type) {
      self::assertContains($type, $types);
    }
  }

  /**
   * Requirement: two Profile Fields with the same field_name in one Profile
   * must export to distinct portable paths when their semantic qualifiers differ.
   */
  public function testProfileFieldsWithSameFieldNameExportDistinctPortablePaths(): void {
    $handler = new CoverageExpansionProfileFieldFixture();
    $files = $handler->export();

    self::assertCount(2, $files);
    self::assertNotSame($files[0]['data']['key'], $files[1]['data']['key']);
    self::assertNotSame($files[0]['filename'], $files[1]['filename']);
    self::assertStringContainsString('summary_overlay__phone', $files[0]['filename']);
    self::assertStringContainsString('summary_overlay__phone', $files[1]['filename']);
    self::assertSame('Home', $files[0]['data']['item']['location_type_id']['key']['name']);
    self::assertSame('Work', $files[1]['data']['item']['location_type_id']['key']['name']);
    self::assertArrayNotHasKey('id', $files[0]['data']['item']);
    self::assertArrayNotHasKey('id', $files[1]['data']['item']);
  }

  /** Requirement: Profile Field import must resolve semantic profile/location references at the API boundary. */
  public function testProfileFieldImportMatchesSemanticQualifierInsteadOfFirstPhoneField(): void {
    $handler = new CoverageExpansionProfileFieldFixture();
    $files = $handler->export();
    $work = $files[1]['data'];
    $work['item']['label'] = 'Work telephone';

    $result = $handler->import(['work-phone.yml' => $work], FALSE);

    self::assertTrue($result['ok']);
    self::assertSame(1, $result['update']);
    self::assertSame(202, $handler->lastUpdatedId);
    self::assertSame(9, $handler->lastUpdateValues['location_type_id']);
    self::assertSame(11, $handler->lastUpdateValues['uf_group_id']);
  }

  /**
   * Requirement: report YAML must match by stable name and never persist the
   * target site's numeric ReportInstance ID.
   */
  public function testReportInstanceUpdateUsesNameButWritesLocalIdOnlyAtApiBoundary(): void {
    $handler = new CoverageExpansionReportFixture();
    $export = $handler->export();

    self::assertCount(1, $export);
    self::assertSame(['report_id', 'name'], $export[0]['data']['key_fields']);
    self::assertSame('contact/summary', $export[0]['data']['item']['report_id']);
    self::assertSame('member_summary', $export[0]['data']['item']['name']);
    self::assertArrayNotHasKey('id', $export[0]['data']['item']);

    $document = $export[0]['data'];
    $document['item']['title'] = 'Updated Member Summary';
    $result = $handler->import(['member_summary.yml' => $document], FALSE);

    self::assertTrue($result['ok']);
    self::assertSame(1, $result['update']);
    self::assertSame(81, $handler->lastCreateParams['id']);
    self::assertSame('contact/summary', $handler->lastCreateParams['report_id']);
    self::assertSame('member_summary', $handler->lastCreateParams['name']);
  }

  /** Requirement: legacy unnamed reports with a template and title must remain exportable without local IDs. */
  public function testUnnamedReportUsesGuardedTitleFallbackIdentity(): void {
    $export = (new CoverageExpansionUnnamedReportFixture())->export();

    self::assertCount(1, $export);
    self::assertSame('legacy-title', $export[0]['data']['identity_mode']);
    self::assertSame(['report_id', 'title'], $export[0]['data']['key_fields']);
    self::assertSame('report_id=contact/summary|title=Unnamed report', $export[0]['data']['key']);
    self::assertArrayNotHasKey('id', $export[0]['data']['item']);
  }

  /** Requirement: title-fallback identity must update the matching unnamed report instead of creating another one. */
  public function testUnnamedReportFallbackUpdatesExistingUnnamedInstance(): void {
    $handler = new CoverageExpansionUnnamedReportFixture();
    $document = $handler->export()[0]['data'];
    $document['item']['description'] = 'Updated legacy report';

    $result = $handler->import(['legacy.yml' => $document], FALSE);

    self::assertTrue($result['ok']);
    self::assertSame(1, $result['update']);
    self::assertSame(82, $handler->lastCreateParams['id']);
    self::assertSame('', $handler->lastCreateParams['name']);
    self::assertSame('Unnamed report', $handler->lastCreateParams['title']);
  }

  /** Requirement: a report with no report template/provider must still fail closed with actionable context. */
  public function testReportWithoutTemplateStillFailsClosed(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('missing report_id');
    $this->expectExceptionMessage('Source ID: 83');
    (new CoverageExpansionTemplateLessReportFixture())->export();
  }

  /**
   * Requirement: Contact Layout YAML must replace nested local IDs with stable
   * references and resolve only at the target API boundary.
   */
  public function testContactLayoutNestedReferencesRoundTripWithoutLocalIds(): void {
    $handler = new CoverageExpansionContactLayoutFixture();
    $export = $handler->export();
    $item = $export[0]['data']['item'];

    self::assertSame('board_members', $item['groups'][0]['key']['name']);
    self::assertSame('member_profile', $item['blocks'][0][0][0]['profile_id']['key']['name']);
    self::assertSame('member_fields', $item['blocks'][0][0][0]['custom_group_id']['key']['name']);
    self::assertSame('Employer of', $item['blocks'][0][0][0]['related_rel']['relationship_type']['key']['name_a_b']);
    self::assertSame('ab', $item['blocks'][0][0][0]['related_rel']['direction']);

    $result = $handler->import(['member.yml' => $export[0]['data']], FALSE);
    self::assertTrue($result['ok']);
    self::assertSame(1, $result['skip'], 'An unchanged portable layout should not be rewritten.');

    $document = $export[0]['data'];
    $document['item']['weight'] = 9;
    $result = $handler->import(['member.yml' => $document], FALSE);
    self::assertTrue($result['ok']);
    self::assertSame(1, $result['update']);
    self::assertSame([17], $handler->lastUpdateValues['groups']);
    self::assertSame(23, $handler->lastUpdateValues['blocks'][0][0][0]['profile_id']);
    self::assertSame(31, $handler->lastUpdateValues['blocks'][0][0][0]['custom_group_id']);
    self::assertSame('44_ab', $handler->lastUpdateValues['blocks'][0][0][0]['related_rel']);
  }

  /** Requirement: unknown nested local-ID shapes must block portable Contact Layout export. */
  public function testContactLayoutUnknownLocalReferenceFailsClosed(): void {
    $handler = new CoverageExpansionContactLayoutFixture();
    $handler->includeUnknownLocalReference = TRUE;
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('unresolved local numeric/reference field');
    $handler->export();
  }
}

final class CoverageExpansionTagFixture extends TagHandler {
  /** @var array<string,array<string,mixed>> */
  public array $rows = [];
  /** @var string[] */
  public array $createOrder = [];
  private int $nextId = 100;

  protected function api4GetFirst(string $entity, array $where, array $select = ['*']): ?array {
    if ($entity !== 'Tag') return NULL;
    $field = (string) ($where[0][0] ?? '');
    $value = $where[0][2] ?? NULL;
    foreach ($this->rows as $row) {
      if (($row[$field] ?? NULL) === $value) return $row;
    }
    return NULL;
  }

  public function rowId(string $name): ?int {
    $row = $this->rows[$name] ?? NULL;
    return is_array($row) && isset($row['id']) ? (int) $row['id'] : NULL;
  }

  public function rowParentId(string $name): ?int {
    $row = $this->rows[$name] ?? NULL;
    return is_array($row) && isset($row['parent_id']) ? (int) $row['parent_id'] : NULL;
  }

  protected function api4Create(string $entity, array $values): array {
    $values['id'] = $this->nextId++;
    $this->rows[(string) $values['name']] = $values;
    $this->createOrder[] = (string) $values['name'];
    return $values;
  }

  protected function api4Update(string $entity, array $where, array $values): array {
    $id = $where[0][2] ?? NULL;
    foreach ($this->rows as $name => $row) {
      if (($row['id'] ?? NULL) === $id) {
        $this->rows[$name] = array_merge($row, $values);
        return $this->rows[$name];
      }
    }
    return [];
  }
}

final class CoverageExpansionProfileFieldFixture extends ProfileFieldHandler {
  public ?int $lastUpdatedId = NULL;
  public array $lastUpdateValues = [];

  protected function api4Iterate(string $entity, array $where = [], array $select = ['*'], array $orderBy = []): \Generator {
    foreach ($this->profileRows() as $row) {
      yield $row;
    }
  }

  protected function api4Get(string $entity, array $where = [], array $select = ['*'], array $orderBy = []): array {
    if ($entity !== 'UFField') return [];
    return $this->profileRows();
  }

  protected function api4GetFirst(string $entity, array $where, array $select = ['*']): ?array {
    $value = $where[0][2] ?? NULL;
    if ($entity === 'UFGroup') {
      if (($where[0][0] ?? '') === 'id' && (int) $value === 11) return ['id' => 11, 'name' => 'summary_overlay'];
      if (($where[0][0] ?? '') === 'name' && $value === 'summary_overlay') return ['id' => 11, 'name' => 'summary_overlay'];
    }
    if ($entity === 'LocationType') {
      if ((string) $value === '8' || $value === 'Home') return ['id' => 8, 'name' => 'Home'];
      if ((string) $value === '9' || $value === 'Work') return ['id' => 9, 'name' => 'Work'];
    }
    return NULL;
  }

  protected function api4Update(string $entity, array $where, array $values): array {
    $this->lastUpdatedId = (int) ($where[0][2] ?? 0);
    $this->lastUpdateValues = $values;
    return $values;
  }

  protected function api4Create(string $entity, array $values): array {
    return $values;
  }

  /** @return array<int,array<string,mixed>> */
  private function profileRows(): array {
    return [
      [
        'id' => 201, 'uf_group_id' => 11, 'field_name' => 'phone', 'label' => 'Home phone',
        'field_type' => 'Contact', 'location_type_id' => 8, 'weight' => 1, 'is_active' => 1,
      ],
      [
        'id' => 202, 'uf_group_id' => 11, 'field_name' => 'phone', 'label' => 'Work phone',
        'field_type' => 'Contact', 'location_type_id' => 9, 'weight' => 2, 'is_active' => 1,
      ],
    ];
  }
}

final class CoverageExpansionReportFixture extends ReportInstanceHandler {
  public array $lastCreateParams = [];

  protected function api3(string $entity, string $action, array $params): array {
    if ($action === 'getactions') {
      return ['values' => ['get' => [], 'create' => []]];
    }
    if ($action === 'get' && isset($params['name'])) {
      return ['values' => [[
        'id' => 81,
        'name' => 'member_summary',
        'title' => 'Member Summary',
        'report_id' => 'contact/summary',
        'description' => '',
        'permission' => 'access CiviReport',
        'grouprole' => '',
        'is_active' => 1,
        'is_reserved' => 0,
        'form_values' => ['fields' => ['display_name' => 1]],
      ]]];
    }
    if ($action === 'get') {
      return ['values' => [[
        'id' => 81,
        'navigation_id' => 199,
        'name' => 'member_summary',
        'title' => 'Member Summary',
        'report_id' => 'contact/summary',
        'description' => '',
        'permission' => 'access CiviReport',
        'grouprole' => '',
        'is_active' => 1,
        'is_reserved' => 0,
        'form_values' => ['fields' => ['display_name' => 1]],
      ]]];
    }
    if ($action === 'create') {
      $this->lastCreateParams = $params;
      return ['values' => [$params]];
    }
    return ['values' => []];
  }
}

final class CoverageExpansionUnnamedReportFixture extends ReportInstanceHandler {
  public array $lastCreateParams = [];

  protected function api3(string $entity, string $action, array $params): array {
    if ($action === 'get') {
      return ['values' => [[
        'id' => 82,
        'name' => '',
        'title' => 'Unnamed report',
        'report_id' => 'contact/summary',
        'description' => '',
        'permission' => 'access CiviReport',
        'grouprole' => '',
        'is_active' => 1,
        'is_reserved' => 0,
        'form_values' => [],
      ]]];
    }
    if ($action === 'create') {
      $this->lastCreateParams = $params;
      return ['values' => [$params]];
    }
    return ['values' => ['get' => [], 'create' => []]];
  }
}

final class CoverageExpansionTemplateLessReportFixture extends ReportInstanceHandler {
  protected function api3(string $entity, string $action, array $params): array {
    if ($action === 'get') {
      return ['values' => [[
        'id' => 83, 'name' => 'broken_report', 'title' => 'Broken report', 'report_id' => '',
        'permission' => 'access CiviReport', 'grouprole' => '', 'is_active' => 1, 'is_reserved' => 0, 'form_values' => [],
      ]]];
    }
    return ['values' => ['get' => [], 'create' => []]];
  }
}

final class CoverageExpansionContactLayoutFixture extends ContactLayoutHandler {
  public array $lastUpdateValues = [];
  public bool $includeUnknownLocalReference = FALSE;

  protected function api4Get(string $entity, array $where = [], array $select = ['*'], array $orderBy = []): array {
    if ($entity !== 'ContactLayout') {
      return [];
    }
    $row = $this->layoutRow();
    if ($where && (($where[0][0] ?? '') === 'label') && (($where[0][2] ?? '') !== $row['label'])) {
      return [];
    }
    return [$row];
  }

  protected function api4GetFirst(string $entity, array $where, array $select = ['*']): ?array {
    $id = $where[0][2] ?? NULL;
    if ($entity === 'Group') return ['id' => 17, 'name' => 'board_members'];
    if ($entity === 'UFGroup') return ['id' => 23, 'name' => 'member_profile'];
    if ($entity === 'CustomGroup') return ['id' => 31, 'name' => 'member_fields'];
    if ($entity === 'RelationshipType') return ['id' => 44, 'name_a_b' => 'Employer of'];
    return NULL;
  }

  protected function api4Update(string $entity, array $where, array $values): array {
    $this->lastUpdateValues = $values;
    return $values;
  }

  protected function api4Create(string $entity, array $values): array {
    return $values;
  }

  private function layoutRow(): array {
    $row = [
      'id' => 7,
      'label' => 'Member layout',
      'contact_type' => 'Individual',
      'contact_sub_type' => ['Member'],
      'groups' => [17],
      'weight' => 3,
      'blocks' => [[[[
        'name' => 'Profile',
        'profile_id' => 23,
        'custom_group_id' => 31,
        'related_rel' => '44_ab',
      ]]]],
      'tabs' => [['id' => 'activity', 'is_active' => TRUE]],
      'settings' => [],
    ];
    if ($this->includeUnknownLocalReference) {
      $row['blocks'][0][0][0]['mystery_id'] = 999;
    }
    return $row;
  }
}
