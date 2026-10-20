<?php
/**
 * Disposable API4 CRUD certification for Bundle 2 provider handlers.
 *
 * Run from /var/www/html/build/dcivi-stage/web ONLY:
 * CIVICFG_BUNDLE2_DISPOSABLE_CRUD=YES_STAGE_ONLY drush php:script \
 *   /var/www/html/ext/civi.config.manager/tests/manual/bundle2-provider-disposable-crud.php
 *
 * This creates short-lived Tag, UFGroup and UFField records on STAGE.
 * Each operation uses the Configuration Manager handlers, then independent
 * API4 reads verify the result. API4 deletion is used ONLY for fixture
 * cleanup. Never run this on a client site, DEV, or production.
 *
 * A SIGKILL or machine crash cannot invoke PHP finally. If that happens,
 * use the printed test token to locate the exact fixture records manually.
 */

declare(strict_types=1);

use Civi\ConfigManager\Handler\EntityDefinitionHandler;
use Civi\ConfigManager\Handler\ProfileFieldHandler;
use Civi\ConfigManager\Handler\TagHandler;
use Civi\ConfigManager\Service\CoreEntityDefinitions;

/**
 * Require an actual DDEV STAGE working directory and CiviCRM database.
 */
function bundle2AssertStage(): void {
  $expectedRoot = realpath('/var/www/html/build/dcivi-stage/web');
  if (!$expectedRoot || realpath(getcwd()) !== $expectedRoot) {
    throw new \RuntimeException('STOP: This destructive fixture is allowed only from the dcivi-stage Drupal webroot.');
  }
  if (getenv('CIVICFG_BUNDLE2_DISPOSABLE_CRUD') !== 'YES_STAGE_ONLY') {
    throw new \RuntimeException('STOP: Set CIVICFG_BUNDLE2_DISPOSABLE_CRUD=YES_STAGE_ONLY explicitly.');
  }
  if (!class_exists('Drupal') || !\Drupal::getContainer()->has('civicrm')) {
    throw new \RuntimeException('STOP: Drupal/CiviCRM bootstrap is unavailable.');
  }
  \Drupal::service('civicrm')->initialize();

  $dbName = (string) \CRM_Core_DAO::singleValueQuery('SELECT DATABASE()');
  if (!preg_match('/^dcivistage[a-z0-9_]*$/i', $dbName)) {
    throw new \RuntimeException('STOP: CiviCRM DB is not a dcivistage database; no test data was created.');
  }
  echo 'STAGE guard: webroot and CiviCRM database confirmed (' . $dbName . ").\n";
}

/**
 * Read at most a few rows using CiviCRM API4, with no permission bypass beyond
 * the same FALSE permission argument used by this extension's handler calls.
 *
 * @return array<int,array<string,mixed>>
 */
function bundle2Read(string $entity, array $where, array $select = ['*']): array {
  $class = 'Civi\\Api4\\' . $entity;
  if (!class_exists($class)) {
    throw new \RuntimeException('Required API4 entity is missing: ' . $entity);
  }
  $action = $class::get(FALSE)->addSelect(...$select)->setLimit(3);
  foreach ($where as $condition) {
    $action->addWhere(...$condition);
  }
  return array_values((array) $action->execute());
}

/**
 * @return array<string,mixed>
 */
function bundle2ReadOne(string $entity, array $where, array $select = ['*']): array {
  $rows = bundle2Read($entity, $where, $select);
  if (count($rows) !== 1) {
    throw new \RuntimeException($entity . ' exact fixture lookup returned ' . count($rows) . ' rows, expected 1.');
  }
  return $rows[0];
}

/**
 * Check one Import result, including protection against implicit deletion.
 */
function bundle2Counts(array $result, int $create, int $update, int $skip, string $stage): void {
  if (empty($result['ok'])
    || (int) ($result['create'] ?? -1) !== $create
    || (int) ($result['update'] ?? -1) !== $update
    || (int) ($result['skip'] ?? -1) !== $skip
    || (int) ($result['delete'] ?? -1) !== 0) {
    throw new \RuntimeException($stage . ' unexpected handler summary: ' . json_encode($result));
  }
  echo $stage . ': PASS (' . $create . ' create, ' . $update . ' update, ' . $skip . " skip).\n";
}

/**
 * Validate an exported or handwritten portable document before applying it.
 */
function bundle2Validate($handler, array $documents, string $stage): void {
  $result = $handler->validate($documents);
  if (empty($result['valid'])) {
    throw new \RuntimeException($stage . ' portable validation failed: ' . json_encode($result['errors'] ?? []));
  }
}

/**
 * Assert a narrowly defined condition with a useful failure diagnostic.
 */
function bundle2Require(bool $condition, string $message): void {
  if (!$condition) {
    throw new \RuntimeException($message);
  }
}

/**
 * Locate a particular portable export without importing any other record.
 *
 * @return array<string,mixed>
 */
function bundle2ExportOne($handler, string $field, string $value): array {
  foreach ($handler->iterateExport() as $row) {
    if (($row['data']['item'][$field] ?? NULL) === $value) {
      return (array) $row['data'];
    }
  }
  throw new \RuntimeException('Could not find exported ' . $handler->getType() . ' fixture ' . $value . '.');
}

/**
 * Delete an exact fixture by its immutable local ID, with no wildcard deletes.
 */
function bundle2DeleteById(string $entity, int $id): void {
  if ($id <= 0) {
    throw new \RuntimeException('Refusing to delete ' . $entity . ' without a valid fixture ID.');
  }
  $class = 'Civi\\Api4\\' . $entity;
  $class::delete(FALSE)->addWhere('id', '=', $id)->execute();
}

/**
 * Preflight the API4 cleanup actions, without executing any of them.
 */
function bundle2RequireCleanupActions(): void {
  foreach (['Tag', 'UFGroup', 'UFField'] as $entity) {
    $class = 'Civi\\Api4\\' . $entity;
    if (!class_exists($class)) {
      throw new \RuntimeException('Missing API4 class: ' . $entity . '.');
    }
    $class::delete(FALSE);
  }
}

/**
 * Clean only records belonging to the randomly named fixture, reverse order.
 *
 * @return array<int,string> Cleanup issues that need manual attention.
 */
function bundle2Cleanup(string $parentName, string $childName, string $groupName, string $fieldLabel): array {
  $errors = [];
  try {
    $groups = bundle2Read('UFGroup', [['name', '=', $groupName]], ['id', 'name']);
    if (count($groups) > 1) {
      throw new \RuntimeException('Multiple fixture UFGroup identities found; refusing ambiguous cleanup.');
    }
    if ($groups) {
      $groupId = (int) $groups[0]['id'];
      $fields = bundle2Read('UFField', [
        ['uf_group_id', '=', $groupId],
        ['field_name', '=', 'first_name'],
        ['label', '=', $fieldLabel],
      ], ['id', 'uf_group_id', 'field_name', 'label']);
      if (count($fields) > 1) {
        throw new \RuntimeException('Multiple fixture UFField identities found; refusing ambiguous cleanup.');
      }
      foreach ($fields as $field) {
        bundle2DeleteById('UFField', (int) $field['id']);
      }
      bundle2Require(!bundle2Read('UFField', [['uf_group_id', '=', $groupId], ['label', '=', $fieldLabel]], ['id']), 'Fixture UFField survived cleanup.');
      bundle2DeleteById('UFGroup', $groupId);
    }
    bundle2Require(!bundle2Read('UFGroup', [['name', '=', $groupName]], ['id']), 'Fixture UFGroup survived cleanup.');
  }
  catch (\Throwable $e) {
    $errors[] = 'Profile/Field cleanup: ' . $e->getMessage();
  }

  foreach ([$childName, $parentName] as $name) {
    try {
      $tags = bundle2Read('Tag', [['name', '=', $name]], ['id', 'name']);
      if (count($tags) > 1) {
        throw new \RuntimeException('Multiple fixture Tag identities found; refusing ambiguous cleanup.');
      }
      if ($tags) {
        bundle2DeleteById('Tag', (int) $tags[0]['id']);
      }
      bundle2Require(!bundle2Read('Tag', [['name', '=', $name]], ['id']), 'Fixture Tag survived cleanup: ' . $name);
    }
    catch (\Throwable $e) {
      $errors[] = 'Tag cleanup (' . $name . '): ' . $e->getMessage();
    }
  }
  return $errors;
}

bundle2AssertStage();

$extensionRoot = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($extensionRoot): void {
  $prefix = 'Civi\\ConfigManager\\';
  if (strpos($class, $prefix) !== 0) {
    return;
  }
  $file = $extensionRoot . '/Civi/ConfigManager/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
  if (is_file($file)) {
    require_once $file;
  }
}, TRUE, TRUE);

$activeFile = realpath((new \ReflectionClass(TagHandler::class))->getFileName());
$expectedRoot = realpath($extensionRoot);
if (!$activeFile || !$expectedRoot || strpos($activeFile, $expectedRoot . DIRECTORY_SEPARATOR) !== 0) {
  throw new \RuntimeException('STOP: Tag handler is not loaded from the supplied Configuration Manager checkout.');
}

bundle2RequireCleanupActions();

$token = 'cfgb2_' . bin2hex(random_bytes(6));
$parentName = $token . '_parent';
$childName = $token . '_child';
$groupName = $token . '_profile';
$fieldLabel = $token . '_first_name';

echo "Bundle 2 disposable CRUD token: {$token}\n";
echo "Only uniquely named STAGE fixture records will be created and deleted.\n";

$tag = new TagHandler();
$profile = new EntityDefinitionHandler('profiles', CoreEntityDefinitions::metadataDriven()['profiles']);
$field = new ProfileFieldHandler();
$completed = FALSE;
$cleanupErrors = [];

try {
  foreach ([[$parentName, 'Tag'], [$childName, 'Tag'], [$groupName, 'UFGroup']] as $identity) {
    bundle2Require(!bundle2Read($identity[1], [['name', '=', $identity[0]]], ['id']), 'Fixture name already exists: ' . $identity[0]);
  }

  $parent = [
    'name' => $parentName,
    'label' => $token . ' parent',
    'description' => 'Temporary Bundle 2 certification parent',
    'parent_id' => NULL,
  ];
  $child = [
    'name' => $childName,
    'label' => $token . ' child',
    'description' => 'Temporary Bundle 2 certification child',
    'parent_id' => [
      'provider' => 'api4:Tag',
      'entity' => 'Tag',
      'key' => ['name' => $parentName],
    ],
  ];
  // Deliberately put the child first to certify dependency retries.
  $tagDocs = [
    'child.yml' => ['schema_version' => 1, 'type' => 'tags.item', 'entity' => 'Tag', 'dependencies' => [], 'item' => $child],
    'parent.yml' => ['schema_version' => 1, 'type' => 'tags.item', 'entity' => 'Tag', 'dependencies' => [], 'item' => $parent],
  ];

  $group = [
    'name' => $groupName,
    'title' => $token . ' certification profile',
    'frontend_title' => $token . ' certification profile',
    'is_active' => FALSE,
    'group_type' => 'Individual',
  ];
  $groupDocs = [
    'profile.yml' => ['schema_version' => 1, 'type' => 'profiles.item', 'entity' => 'UFGroup', 'dependencies' => [], 'item' => $group],
  ];

  $fieldRow = [
    'uf_group_id' => [
      'provider' => 'api4:UFGroup',
      'entity' => 'UFGroup',
      'key' => ['name' => $groupName],
    ],
    'field_name' => 'first_name',
    'field_type' => 'Individual',
    'label' => $fieldLabel,
    'weight' => 1,
    'is_active' => TRUE,
    'is_required' => FALSE,
    'help_pre' => 'Temporary Bundle 2 certification field',
  ];
  $fieldDocs = [
    'field.yml' => ['schema_version' => 1, 'type' => 'profile-fields.item', 'entity' => 'UFField', 'dependencies' => [], 'item' => $fieldRow],
  ];

  bundle2Validate($tag, $tagDocs, 'Tags');
  bundle2Validate($profile, $groupDocs, 'Profiles');
  bundle2Validate($field, $fieldDocs, 'Profile Fields');
  bundle2Counts($tag->import($tagDocs, TRUE), 2, 0, 0, 'Tags initial preview');
  bundle2Counts($profile->import($groupDocs, TRUE), 1, 0, 0, 'Profiles initial preview');

  $missingParent = $child;
  $missingParent['name'] = $token . '_orphan';
  $missingParent['parent_id']['key']['name'] = $token . '_missing';
  $orphanPreview = $tag->import(['orphan.yml' => ['type' => 'tags.item', 'entity' => 'Tag', 'item' => $missingParent]], TRUE);
  bundle2Require(empty($orphanPreview['ok']) && (int) ($orphanPreview['create'] ?? 0) === 0 && !empty($orphanPreview['errors']), 'Missing Tag parent was not rejected safely.');
  echo "Missing Tag parent: correctly rejected.\n";

  bundle2Counts($tag->import($tagDocs, FALSE), 2, 0, 0, 'Tags create, child-first');
  $parentRecord = bundle2ReadOne('Tag', [['name', '=', $parentName]], ['id', 'name', 'label']);
  $childRecord = bundle2ReadOne('Tag', [['name', '=', $childName]], ['id', 'name', 'parent_id', 'label']);
  bundle2Require((int) $childRecord['parent_id'] === (int) $parentRecord['id'], 'Tag child parent_id was not resolved to the new parent.');
  bundle2Counts($tag->import($tagDocs, FALSE), 0, 0, 2, 'Tags repeat import');
  $exportedChild = bundle2ExportOne($tag, 'name', $childName);
  bundle2Require(!isset($exportedChild['item']['id']), 'Tag export leaked a local record ID.');
  bundle2Require(($exportedChild['item']['parent_id']['key']['name'] ?? '') === $parentName, 'Tag export lost portable parent reference.');
  bundle2Validate($tag, ['child-export.yml' => $exportedChild], 'Exported Tag');
  bundle2Counts($tag->import(['child-export.yml' => $exportedChild], TRUE), 0, 0, 1, 'Tag export round-trip');
  $tagDocs['child.yml']['item']['description'] = 'Bundle 2 updated child';
  bundle2Counts($tag->import($tagDocs, TRUE), 0, 1, 1, 'Tags update preview');
  bundle2Counts($tag->import($tagDocs, FALSE), 0, 1, 1, 'Tags update');
  bundle2Require((string) bundle2ReadOne('Tag', [['name', '=', $childName]], ['description'])['description'] === 'Bundle 2 updated child', 'Tag update was not persisted.');
  bundle2Counts($tag->import($tagDocs, FALSE), 0, 0, 2, 'Tags idempotent update');

  bundle2Counts($profile->import($groupDocs, FALSE), 1, 0, 0, 'Profile create');
  $groupRecord = bundle2ReadOne('UFGroup', [['name', '=', $groupName]], ['id', 'name', 'title']);
  $groupId = (int) $groupRecord['id'];
  // Inspect a repeat import without writing first. If CiviCRM persisted a
  // different field shape, show only this random fixture's source/readback.
  $profileRepeatPreview = $profile->import($groupDocs, TRUE);
  if ((int) ($profileRepeatPreview['update'] ?? 0) !== 0 || empty($profileRepeatPreview['ok'])) {
    $readback = bundle2ReadOne('UFGroup', [['name', '=', $groupName]], array_merge(['id'], array_keys($group)));
    fwrite(STDERR, 'Profile repeat preview diagnostic (disposable fixture only): ' . json_encode([
      'desired' => $group,
      'api4_readback' => $readback,
    ]) . "\n");
  }
  bundle2Counts($profileRepeatPreview, 0, 0, 1, 'Profile repeat preview');
  bundle2Counts($profile->import($groupDocs, FALSE), 0, 0, 1, 'Profile repeat import');
  $exportedGroup = bundle2ExportOne($profile, 'name', $groupName);
  bundle2Require(!isset($exportedGroup['item']['id']), 'Profile export leaked a local record ID.');
  bundle2Validate($profile, ['group-export.yml' => $exportedGroup], 'Exported Profile');
  bundle2Counts($profile->import(['group-export.yml' => $exportedGroup], TRUE), 0, 0, 1, 'Profile export round-trip');
  $groupDocs['profile.yml']['item']['title'] = $token . ' updated profile';
  bundle2Counts($profile->import($groupDocs, TRUE), 0, 1, 0, 'Profile update preview');
  bundle2Counts($profile->import($groupDocs, FALSE), 0, 1, 0, 'Profile update');
  bundle2Require((string) bundle2ReadOne('UFGroup', [['name', '=', $groupName]], ['title'])['title'] === $token . ' updated profile', 'Profile update was not persisted.');
  bundle2Counts($profile->import($groupDocs, FALSE), 0, 0, 1, 'Profile idempotent update');

  $missingGroup = $fieldRow;
  $missingGroup['uf_group_id']['key']['name'] = $token . '_missing_profile';
  $missingGroupResult = $field->import(['bad-field.yml' => ['type' => 'profile-fields.item', 'entity' => 'UFField', 'item' => $missingGroup]], TRUE);
  bundle2Require(empty($missingGroupResult['ok']) && (int) ($missingGroupResult['create'] ?? 0) === 0 && !empty($missingGroupResult['errors']), 'Missing UFGroup reference was not rejected safely.');
  echo "Missing UFGroup reference: correctly rejected.\n";

  bundle2Counts($field->import($fieldDocs, TRUE), 1, 0, 0, 'Profile Field initial preview');
  bundle2Counts($field->import($fieldDocs, FALSE), 1, 0, 0, 'Profile Field create');
  $fieldRecord = bundle2ReadOne('UFField', [['uf_group_id', '=', $groupId], ['label', '=', $fieldLabel]], ['id', 'uf_group_id', 'label', 'field_name']);
  bundle2Require((string) $fieldRecord['field_name'] === 'first_name', 'Profile Field was created with unexpected field_name.');
  bundle2Counts($field->import($fieldDocs, FALSE), 0, 0, 1, 'Profile Field repeat import');
  $exportedField = NULL;
  foreach ($field->iterateExport() as $exportRow) {
    if (($exportRow['data']['item']['label'] ?? '') === $fieldLabel) {
      $exportedField = $exportRow['data'];
      break;
    }
  }
  bundle2Require(is_array($exportedField) && !isset($exportedField['item']['id']), 'Profile Field export not found or leaked a local ID.');
  bundle2Require(($exportedField['item']['uf_group_id']['key']['name'] ?? '') === $groupName, 'Profile Field export lost portable UFGroup reference.');
  bundle2Validate($field, ['field-export.yml' => $exportedField], 'Exported Profile Field');
  bundle2Counts($field->import(['field-export.yml' => $exportedField], TRUE), 0, 0, 1, 'Profile Field export round-trip');
  $fieldDocs['field.yml']['item']['help_pre'] = 'Bundle 2 updated field';
  bundle2Counts($field->import($fieldDocs, TRUE), 0, 1, 0, 'Profile Field update preview');
  bundle2Counts($field->import($fieldDocs, FALSE), 0, 1, 0, 'Profile Field update');
  bundle2Require((string) bundle2ReadOne('UFField', [['uf_group_id', '=', $groupId], ['label', '=', $fieldLabel]], ['help_pre'])['help_pre'] === 'Bundle 2 updated field', 'Profile Field update was not persisted.');
  bundle2Counts($field->import($fieldDocs, FALSE), 0, 0, 1, 'Profile Field idempotent update');

  // A missing YAML item is never permission to delete live configuration.
  bundle2Counts($tag->import(['parent-only.yml' => $tagDocs['parent.yml']], FALSE), 0, 0, 1, 'Tag omission must not delete child');
  bundle2Require(count(bundle2Read('Tag', [['name', '=', $childName]], ['id'])) === 1, 'Missing child YAML caused implicit Tag deletion.');
  bundle2Counts($profile->import($groupDocs, FALSE), 0, 0, 1, 'Profile omission must not delete field');
  bundle2Require(count(bundle2Read('UFField', [['uf_group_id', '=', $groupId], ['label', '=', $fieldLabel]], ['id'])) === 1, 'Missing field YAML caused implicit UFField deletion.');
  echo "All Bundle 2 CRUD/round-trip/idempotency/preservation checks passed.\n";
  $completed = TRUE;
}
catch (\Throwable $e) {
  fwrite(STDERR, 'BUNDLE 2 CERTIFICATION FAILED: ' . $e->getMessage() . "\n");
}
finally {
  $cleanupErrors = bundle2Cleanup($parentName, $childName, $groupName, $fieldLabel);
  if ($cleanupErrors) {
    foreach ($cleanupErrors as $error) {
      fwrite(STDERR, 'CLEANUP FAILED: ' . $error . "\n");
    }
    fwrite(STDERR, 'Manual cleanup required for token ' . $token . ". Do not delete records belonging to other tests.\n");
  }
  else {
    echo 'Fixture cleanup PASS: no test Tag, UFGroup or UFField records remain for ' . $token . ".\n";
  }
}

if (!$completed || $cleanupErrors) {
  exit(1);
}
echo "BUNDLE 2 STAGE DISPOSABLE CRUD: PASS.\n";
