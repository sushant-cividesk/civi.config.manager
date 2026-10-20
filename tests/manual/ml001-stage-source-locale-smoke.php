<?php
/**
 * ML-001 disposable STAGE: verify locale-stable real Afform export/diff.
 * In-memory hook fixture only; never saves forms, YAML or translations.
 *
 * From /var/www/html/build/dcivi-stage/web:
 * ML001_SOURCE_LOCALE_TEST=YES_STAGE_ONLY drush php:script \
 *   /var/www/html/ext/civi.config.manager/tests/manual/ml001-stage-source-locale-smoke.php
 */

declare(strict_types=1);

if (getenv('ML001_SOURCE_LOCALE_TEST') !== 'YES_STAGE_ONLY') {
  throw new \RuntimeException('STOP: Explicit ML001_SOURCE_LOCALE_TEST=YES_STAGE_ONLY opt-in is required.');
}
$stageRoot = realpath('/var/www/html/build/dcivi-stage/web');
if (!$stageRoot || realpath(getcwd()) !== $stageRoot) {
  throw new \RuntimeException('STOP: ML-001 source-locale test is restricted to the DDEV STAGE Drupal webroot.');
}
if (!class_exists('Drupal') || !\Drupal::getContainer()->has('civicrm')) {
  throw new \RuntimeException('STOP: DDEV STAGE Drupal/CiviCRM bootstrap is required.');
}
\Drupal::service('civicrm')->initialize();
$database = (string) \CRM_Core_DAO::singleValueQuery('SELECT DATABASE()');
if (!preg_match('/^dcivistage[a-z0-9_]*$/i', $database)) {
  throw new \RuntimeException('STOP: CiviCRM database is not the expected disposable STAGE database.');
}

$root = realpath(dirname(__DIR__, 2));
spl_autoload_register(static function (string $class) use ($root): void {
  $prefix = 'Civi\\ConfigManager\\';
  if (strpos($class, $prefix) !== 0) {
    return;
  }
  $path = $root . '/Civi/ConfigManager/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
  if (is_file($path)) {
    require_once $path;
  }
}, TRUE, TRUE);

$class = \Civi\ConfigManager\Handler\GenericApi4CollectionHandler::class;
$actual = realpath((new \ReflectionClass($class))->getFileName());
if (!$root || !$actual || strpos($actual, $root . DIRECTORY_SEPARATOR) !== 0) {
  throw new \RuntimeException('STOP: The active handler does not come from this Configuration Manager checkout.');
}
if (!class_exists(\Civi\Api4\Afform::class)) {
  throw new \RuntimeException('STOP: Afform API4 is not available.');
}

$before = \Civi\Core\Locale::detect();
$fixtureName = 'afMl001Stable' . bin2hex(random_bytes(6));
$sourceTitle = 'Save';
$listener = static function (\Civi\Core\Event\GenericHookEvent $event) use ($fixtureName, &$sourceTitle): void {
  $event->afforms[] = [
    'name' => $fixtureName,
    'title' => ts($sourceTitle),
    'type' => 'system',
    'layout' => '<div><strong>ML-001 test only</strong></div>',
    'is_active' => TRUE,
  ];
};
$dispatcher = \Civi::dispatcher();
$dispatcher->addListener('civi.afform.get', $listener);

$handler = new $class(
  'formbuilder-afforms', 'FormBuilder Afforms', 'formbuilder/afforms', 'Afform',
  ['name', 'title', 'type', 'server_route', 'permission', 'permission_operator', 'is_public', 'is_token', 'is_dashlet', 'is_active', 'layout'],
  ['name' => 'ASC'], 140, 'afforms.yml', TRUE
);
$handler->setDeleteMissingEnabled(FALSE);

// Collect only hashes for existing site Afform data; never include contents.
$collect = static function () use ($handler, $fixtureName): array {
  $rows = [];
  $fixture = NULL;
  foreach ($handler->iterateExport() as $item) {
    $data = (array) ($item['data'] ?? []);
    $name = (string) ($data['name'] ?? '');
    if ($name === '') {
      continue;
    }
    if ($name === $fixtureName) {
      $fixture = $item;
    }
    $payload = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload === FALSE) {
      throw new \RuntimeException('STOP: Unable to hash Afform ' . $name . '.');
    }
    $rows[$name] = hash('sha256', $payload);
  }
  ksort($rows, SORT_STRING);
  return [$rows, $fixture];
};

try {
  \Civi\Core\Locale::negotiate('en_US')->apply();
  [$enHashes, $original] = $collect();
  \Civi\Core\Locale::negotiate('fr_FR')->apply();
  [$frHashes, $frenchView] = $collect();

  if (!$original || !$frenchView) {
    throw new \RuntimeException('INCOMPLETE: The in-memory Afform was not exported under both locales.');
  }
  $originalData = (array) ($original['data'] ?? []);
  $frenchData = (array) ($frenchView['data'] ?? []);
  if ($originalData !== $frenchData || (string) ($originalData['item']['title'] ?? '') !== 'Save') {
    throw new \RuntimeException('FAIL: Source-locale Afform export still depends on caller locale.');
  }
  $changes = [];
  foreach (array_unique(array_merge(array_keys($enHashes), array_keys($frHashes))) as $name) {
    if (($enHashes[$name] ?? NULL) !== ($frHashes[$name] ?? NULL)) {
      $changes[] = $name;
    }
  }
  if ($changes) {
    throw new \RuntimeException('FAIL: Some Afform exports still vary by ambient locale: ' . implode(', ', array_slice($changes, 0, 5)));
  }
  $sourceTitle = 'Save - ML001 genuine source edit';
  $edited = $handler->diff([(string) $original['filename'] => $originalData]);
  if (!in_array((string) $original['filename'], (array) ($edited['changed'] ?? []), TRUE)) {
    throw new \RuntimeException('FAIL: A real change to the hook-provided Afform source title was not detected.');
  }
  echo 'ML-001 STAGE SOURCE-LOCALE EXPORT/DIFF: PASS' . "\n";
  echo json_encode([
    'database' => $database,
    'civicrm_version' => defined('CIVICRM_VERSION') ? CIVICRM_VERSION : \CRM_Utils_System::version(),
    'forms_compared' => count($enHashes),
    'locale_only_export_differences' => count($changes),
    'synthetic_fixture_matches' => TRUE,
    'genuine_source_edit_detected' => TRUE,
    'no_stored_test_data' => TRUE,
    'translation_record_edit_detection' => 'NOT_TESTED',
  ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}
finally {
  $dispatcher->removeListener('civi.afform.get', $listener);
  $before->apply();
}
