<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Handler\ContactLayoutHandler;
use Civi\ConfigManager\Handler\ReportInstanceHandler;
use Civi\ConfigManager\Handler\TagHandler;
use Civi\ConfigManager\Service\CoreEntityDefinitions;
use Civi\ConfigManager\Service\HandlerRegistry;
use PHPUnit\Framework\TestCase;

final class Alpha69CoverageTest extends TestCase {

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
    self::assertSame(['uf_group_id.name', 'field_name'], $definitions['profile-fields']['key_fields']);
    self::assertSame('UFGroup', $definitions['profile-fields']['reference_fields']['uf_group_id']['entity']);
    self::assertSame('LocationType', $definitions['profile-fields']['reference_fields']['location_type_id']['entity']);
    self::assertNotContains('phone_type_id', $definitions['profile-fields']['export_fields']);
    self::assertNotContains('website_type_id', $definitions['profile-fields']['export_fields']);
  }

  /**
   * Requirement: Tag import must not depend on child/parent filename order.
   * Failure mode: a child encountered first fails because its parent has not
   * yet been created on an empty target.
   */
  public function testTagImportCreatesParentBeforeChildWhenYamlIsChildFirst(): void {
    $handler = new Alpha69TagFixture();
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
    self::assertSame($handler->rows['parent_tag']['id'], $handler->rows['child_tag']['parent_id']);
  }

  /** Requirement: cyclic Tag parent dependencies must fail closed with zero writes. */
  public function testTagImportBlocksParentCycle(): void {
    $handler = new Alpha69TagFixture();
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

  /** Requirement: Alpha69 families are visible as independent configuration types. */
  public function testRegistryAdvertisesAlpha69ConfigurationFamilies(): void {
    $types = array_map(static fn($handler): string => $handler->getType(), (new HandlerRegistry())->getHandlers());

    foreach (['tags', 'profiles', 'profile-fields', 'contact-layouts', 'report-instances'] as $type) {
      self::assertContains($type, $types);
    }
  }

  /**
   * Requirement: report YAML must match by stable name and never persist the
   * target site's numeric ReportInstance ID.
   */
  public function testReportInstanceUpdateUsesNameButWritesLocalIdOnlyAtApiBoundary(): void {
    $handler = new Alpha69ReportFixture();
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

  /** Requirement: a report without complete semantic identity must fail export, not disappear silently. */
  public function testReportExportFailsClosedWithoutCompositeIdentity(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('report_id + name identity');
    (new Alpha69UnnamedReportFixture())->export();
  }

  /**
   * Requirement: Contact Layout YAML must replace nested local IDs with stable
   * references and resolve only at the target API boundary.
   */
  public function testContactLayoutNestedReferencesRoundTripWithoutLocalIds(): void {
    $handler = new Alpha69ContactLayoutFixture();
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
    $handler = new Alpha69ContactLayoutFixture();
    $handler->includeUnknownLocalReference = TRUE;
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('unresolved local numeric/reference field');
    $handler->export();
  }
}

final class Alpha69TagFixture extends TagHandler {
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

final class Alpha69ReportFixture extends ReportInstanceHandler {
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

final class Alpha69UnnamedReportFixture extends ReportInstanceHandler {
  protected function api3(string $entity, string $action, array $params): array {
    if ($action === 'get') {
      return ['values' => [[
        'id' => 82,
        'name' => '',
        'title' => 'Unnamed report',
        'report_id' => 'contact/summary',
        'permission' => 'access CiviReport',
        'grouprole' => '',
        'is_active' => 1,
        'is_reserved' => 0,
        'form_values' => [],
      ]]];
    }
    return ['values' => ['get' => [], 'create' => []]];
  }
}

final class Alpha69ContactLayoutFixture extends ContactLayoutHandler {
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
