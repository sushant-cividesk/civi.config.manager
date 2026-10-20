<?php
/**
 * Dependency-free Bundle 3 portability and fail-closed reference regression.
 * Mocks API4 boundaries; never connects to a CiviCRM database.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
  $prefix = 'Civi\\ConfigManager\\';
  if (strpos($class, $prefix) !== 0) {
    return;
  }
  $file = $root . '/Civi/ConfigManager/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
  if (is_file($file)) {
    require_once $file;
  }
});

use Civi\ConfigManager\Handler\ContactLayoutHandler;

class Bundle3ReferenceFixture extends ContactLayoutHandler {
  public array $references = [];
  public array $layouts = [];
  public array $writes = [];

  protected function api4Get(string $entity, array $where = [], array $select = ['*'], array $orderBy = []): array {
    if ($entity !== 'ContactLayout') {
      throw new \RuntimeException('Unexpected API4 entity in fixture.');
    }
    $rows = $this->layouts;
    foreach ($where as $condition) {
      $rows = array_values(array_filter($rows, static function (array $row) use ($condition): bool {
        return ($row[$condition[0]] ?? NULL) === $condition[2];
      }));
    }
    return $rows;
  }

  protected function api4GetFirst(string $entity, array $where, array $select = ['*']): ?array {
    foreach ($this->references[$entity] ?? [] as $row) {
      foreach ($where as $condition) {
        if (($row[$condition[0]] ?? NULL) != $condition[2]) {
          continue 2;
        }
      }
      return $row;
    }
    return NULL;
  }

  protected function api4Create(string $entity, array $values): array {
    if ($entity !== 'ContactLayout') {
      throw new \RuntimeException('Unexpected create.');
    }
    $this->writes[] = ['create', $values];
    $this->layouts[] = ['id' => 901] + $values;
    return ['id' => 901] + $values;
  }

  protected function api4Update(string $entity, array $where, array $values): array {
    if ($entity !== 'ContactLayout') {
      throw new \RuntimeException('Unexpected update.');
    }
    $this->writes[] = ['update', $values];
    $this->layouts[0] = ['id' => 901] + $values;
    return $values;
  }
}

$checked = 0;
$check = static function (bool $condition, string $description) use (&$checked): void {
  $checked++;
  if (!$condition) {
    fwrite(STDERR, 'Bundle 3 Contact Layout portability FAILED: ' . $description . "\n");
    exit(1);
  }
};

$source = new Bundle3ReferenceFixture();
$source->references = [
  'Group' => [['id' => 17, 'name' => 'board_members']],
  'UFGroup' => [['id' => 23, 'name' => 'member_profile']],
  'CustomGroup' => [['id' => 31, 'name' => 'member_fields']],
  'RelationshipType' => [['id' => 44, 'name_a_b' => 'Employer of']],
];
$source->layouts = [[
  'id' => 100,
  'label' => 'fixture_members',
  'weight' => 1,
  'groups' => [17],
  'blocks' => [[[[
    'profile_id' => 23,
    'custom_group_id' => 31,
    'related_rel' => '44_ab',
  ]]]],
]];
$export = $source->export();
$check(count($export) === 1, 'export one source layout');
$document = $export[0]['data'];
$item = $document['item'];
$check(!isset($item['id']), 'source numeric layout ID never reaches YAML');
$check(($item['groups'][0]['key']['name'] ?? '') === 'board_members', 'Group identity is semantic');
$check(($item['blocks'][0][0][0]['profile_id']['key']['name'] ?? '') === 'member_profile', 'Profile identity is semantic');
$check(($item['blocks'][0][0][0]['custom_group_id']['key']['name'] ?? '') === 'member_fields', 'CustomGroup identity is semantic');
$check(($item['blocks'][0][0][0]['related_rel']['relationship_type']['key']['name_a_b'] ?? '') === 'Employer of', 'Relationship identity is semantic');
$check(($item['blocks'][0][0][0]['related_rel']['direction'] ?? '') === 'ab', 'Relationship direction is preserved');
$check(count($document['dependencies'] ?? []) === 4, 'export declares all four dependencies');
$check(!empty($source->validate(['source.yml' => $document])['valid']), 'exported document validates');

$target = new Bundle3ReferenceFixture();
$target->references = [
  'Group' => [['id' => 117, 'name' => 'board_members']],
  'UFGroup' => [['id' => 123, 'name' => 'member_profile']],
  'CustomGroup' => [['id' => 131, 'name' => 'member_fields']],
  'RelationshipType' => [['id' => 144, 'name_a_b' => 'Employer of']],
];
$preview = $target->import(['portable.yml' => $document], TRUE);
$check(!empty($preview['ok']) && $preview['create'] === 1 && !$target->writes, 'target preview cannot write');
$applied = $target->import(['portable.yml' => $document], FALSE);
$check(!empty($applied['ok']) && $applied['create'] === 1 && $applied['delete'] === 0, 'target creates a new layout without deletion');
$written = $target->writes[0][1] ?? [];
$check(($written['groups'][0] ?? NULL) === 117, 'target Group ID is resolved independently');
$check(($written['blocks'][0][0][0]['profile_id'] ?? NULL) === 123, 'target Profile ID is resolved independently');
$check(($written['blocks'][0][0][0]['custom_group_id'] ?? NULL) === 131, 'target CustomGroup ID is resolved independently');
$check(($written['blocks'][0][0][0]['related_rel'] ?? NULL) === '144_ab', 'target Relationship ID and direction are resolved independently');
$repeat = $target->import(['portable.yml' => $document], FALSE);
$check(!empty($repeat['ok']) && $repeat['skip'] === 1 && count($target->writes) === 1, 'repeat import skips with no second write');

$rawLocal = $document;
$rawLocal['item']['blocks'][0][0][0]['related_rel'] = '44_ab';
$check(empty($target->validate(['unsafe.yml' => $rawLocal])['valid']), 'raw local relationship ID rejected during validation');
$rawResult = $target->import(['unsafe.yml' => $rawLocal], FALSE);
$check(empty($rawResult['ok']) && count($target->writes) === 1, 'raw local relationship ID cannot write');

$unknown = $document;
$unknown['item']['blocks'][0][0][0]['profile_id']['key']['name'] = 'unknown_profile';
$unknownResult = $target->import(['unknown.yml' => $unknown], FALSE);
$check(empty($unknownResult['ok']) && count($target->writes) === 1, 'unresolved semantic reference cannot write');

$invalidDirection = $document;
$invalidDirection['item']['blocks'][0][0][0]['related_rel']['direction'] = 'invalid';
$invalidResult = $target->import(['invalid.yml' => $invalidDirection], FALSE);
$check(empty($invalidResult['ok']) && count($target->writes) === 1, 'invalid relationship direction cannot write');

echo 'Bundle 3 Contact Layout portability OK (' . $checked . " checks; no database writes).\n";
