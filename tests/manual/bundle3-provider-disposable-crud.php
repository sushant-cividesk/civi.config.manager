<?php
/**
 * Guarded, disposable Bundle 3 ContactLayout and ReportInstance certification.
 *
 * Run ONLY from /var/www/html/build/dcivi-stage/web:
 * CIVICFG_BUNDLE3_DISPOSABLE_CRUD=YES_STAGE_ONLY drush php:script \
 *   /var/www/html/ext/civi.config.manager/tests/manual/bundle3-provider-disposable-crud.php
 *
 * Only randomly named inactive ReportInstance and ContactLayout fixtures are
 * created. The handlers never delete; independent API deletes are used only
 * after confirming the exact fixture identity. If the PHP process is killed,
 * a finally block cannot run: inspect the printed token before any cleanup.
 */

declare(strict_types=1);

use Civi\ConfigManager\Handler\ContactLayoutHandler;
use Civi\ConfigManager\Handler\ReportInstanceHandler;

function bundle3StageGuard(): void {
  if (getenv('CIVICFG_BUNDLE3_DISPOSABLE_CRUD') !== 'YES_STAGE_ONLY') {
    throw new \RuntimeException('STOP: Explicit CIVICFG_BUNDLE3_DISPOSABLE_CRUD=YES_STAGE_ONLY opt-in is required.');
  }
  $webroot = realpath('/var/www/html/build/dcivi-stage/web');
  if (!$webroot || realpath(getcwd()) !== $webroot) {
    throw new \RuntimeException('STOP: This disposable script is restricted to the DDEV STAGE Drupal webroot.');
  }
  if (!class_exists('Drupal') || !\Drupal::getContainer()->has('civicrm')) {
    throw new \RuntimeException('STOP: Drupal/CiviCRM STAGE bootstrap is not available.');
  }
  \Drupal::service('civicrm')->initialize();
  $database = (string) \CRM_Core_DAO::singleValueQuery('SELECT DATABASE()');
  if (!preg_match('/^dcivistage[a-z0-9_]*$/i', $database)) {
    throw new \RuntimeException('STOP: CiviCRM database is not a guarded disposable STAGE database.');
  }
  $active = realpath((new \ReflectionClass(ContactLayoutHandler::class))->getFileName());
  $extensionRoot = realpath(dirname(__DIR__, 2));
  if (!$active || !$extensionRoot || strpos($active, $extensionRoot . DIRECTORY_SEPARATOR) !== 0) {
    throw new \RuntimeException('STOP: Active ContactLayout handler is not from the expected extension checkout.');
  }
  echo 'Bundle 3 STAGE guard PASS (' . $database . ").\n";
}

function bundle3Require(bool $condition, string $message): void {
  if (!$condition) {
    throw new \RuntimeException($message);
  }
}

/** @return array<int,array<string,mixed>> */
function bundle3LayoutRows(string $label): array {
  $result = \Civi\Api4\ContactLayout::get(FALSE)
    ->addSelect('id', 'label', 'weight', 'contact_type', 'groups', 'blocks')
    ->addWhere('label', '=', $label)
    ->setLimit(25)
    ->execute();
  return array_values((array) $result);
}

/** @return array<int,array<string,mixed>> */
function bundle3ReportRows(string $name): array {
  $result = (array) civicrm_api3('ReportInstance', 'get', [
    'sequential' => 1,
    'name' => $name,
    'return' => ['id', 'name', 'title', 'report_id', 'description', 'is_active'],
    'options' => ['limit' => 25],
  ]);
  if (!empty($result['is_error'])) {
    throw new \RuntimeException('ReportInstance fixture read failed: ' . (string) ($result['error_message'] ?? 'Unknown APIv3 error.'));
  }
  return array_values((array) ($result['values'] ?? []));
}

function bundle3AssertCounts(array $result, int $create, int $update, int $skip, string $step): void {
  bundle3Require(!empty($result['ok']) && (int) ($result['create'] ?? -1) === $create
    && (int) ($result['update'] ?? -1) === $update
    && (int) ($result['skip'] ?? -1) === $skip && (int) ($result['delete'] ?? -1) === 0,
    $step . ' wrong handler counts: ' . json_encode($result));
  echo $step . ': PASS (' . $create . ' create, ' . $update . ' update, ' . $skip . " skip).\n";
}

function bundle3Validate($handler, array $docs, string $step): void {
  $result = $handler->validate($docs);
  bundle3Require(!empty($result['valid']), $step . ' validation rejected: ' . json_encode($result['errors'] ?? []));
}

/** @return array<string,mixed> */
function bundle3ExportOne($handler, string $field, string $value): array {
  foreach ($handler->iterateExport() as $row) {
    if (($row['data']['item'][$field] ?? NULL) === $value) {
      return (array) $row['data'];
    }
  }
  throw new \RuntimeException('Bundle 3 export could not find exact disposable fixture ' . $value . '.');
}

/**
 * Check cleanup support before writing any fixture. This does not turn on
 * provider delete-missing or change Configuration Manager's Import policy.
 */
function bundle3CleanupPreflight(): string {
  bundle3Require(class_exists('Civi\\Api4\\ContactLayout'), 'ContactLayout API4 is not installed.');
  \Civi\Api4\ContactLayout::get(FALSE);
  \Civi\Api4\ContactLayout::create(FALSE);
  \Civi\Api4\ContactLayout::update(FALSE);
  \Civi\Api4\ContactLayout::delete(FALSE);
  bundle3Require(function_exists('civicrm_api3'), 'CiviCRM APIv3 is missing.');
  $actions = (array) civicrm_api3('ReportInstance', 'getactions', ['sequential' => 1]);
  bundle3Require(empty($actions['is_error']), 'ReportInstance APIv3 actions are unavailable.');
  $names = [];
  foreach ((array) ($actions['values'] ?? []) as $key => $value) {
    if (is_string($key)) { $names[] = strtolower($key); }
    if (is_scalar($value)) { $names[] = strtolower((string) $value); }
    elseif (is_array($value) && !empty($value['name'])) { $names[] = strtolower((string) $value['name']); }
  }
  foreach (['get', 'create', 'delete'] as $required) {
    bundle3Require(in_array($required, $names, TRUE), 'ReportInstance cleanup/CRUD action unavailable: ' . $required);
  }
  $layout = new ContactLayoutHandler();
  $report = new ReportInstanceHandler();
  foreach ([$layout, $report] as $handler) {
    $availability = $handler->getRuntimeAvailability();
    bundle3Require(!empty($availability['available']) && ($availability['management_capability'] ?? '') === 'managed_no_delete',
      $handler->getType() . ' does not support reviewed create/update on this CiviCRM version.');
  }
  // Pick an installed report template from a real, already readable report.
  // A nonexistent hard-coded report_id would make the fixture meaningless.
  $template = '';
  foreach ($report->iterateExport() as $export) {
    $value = (string) ($export['data']['item']['report_id'] ?? '');
    if ($value !== '') { $template = $value; break; }
  }
  bundle3Require($template !== '', 'No usable existing ReportInstance/template; fixture writes were not started.');
  return $template;
}

/**
 * Clean only exact, randomly named fixture records; independently verify ID
 * and identity again immediately before every delete.
 *
 * @return array<int,string> Error messages if any fixture survived.
 */
function bundle3Cleanup(string $layoutLabel, string $reportName): array {
  $errors = [];
  try {
    foreach (bundle3LayoutRows($layoutLabel) as $row) {
      $id = (int) ($row['id'] ?? 0);
      bundle3Require($id > 0 && ($row['label'] ?? '') === $layoutLabel, 'Unsafe ContactLayout cleanup candidate.');
      \Civi\Api4\ContactLayout::delete(FALSE)->addWhere('id', '=', $id)->execute();
    }
    bundle3Require(!bundle3LayoutRows($layoutLabel), 'ContactLayout fixture remains after cleanup.');
  }
  catch (\Throwable $e) {
    $errors[] = 'ContactLayout cleanup: ' . $e->getMessage();
  }
  try {
    foreach (bundle3ReportRows($reportName) as $row) {
      $id = (int) ($row['id'] ?? 0);
      bundle3Require($id > 0 && ($row['name'] ?? '') === $reportName, 'Unsafe ReportInstance cleanup candidate.');
      $result = (array) civicrm_api3('ReportInstance', 'delete', ['id' => $id]);
      bundle3Require(empty($result['is_error']), 'ReportInstance fixture delete failed: ' . (string) ($result['error_message'] ?? 'Unknown error.'));
    }
    bundle3Require(!bundle3ReportRows($reportName), 'ReportInstance fixture remains after cleanup.');
  }
  catch (\Throwable $e) {
    $errors[] = 'ReportInstance cleanup: ' . $e->getMessage();
  }
  return $errors;
}

bundle3StageGuard();
$template = bundle3CleanupPreflight();
$token = 'cfgb3_' . bin2hex(random_bytes(6));
$layoutLabel = $token . '_layout';
$reportName = $token . '_report';
echo 'Bundle 3 disposable CRUD token: ' . $token . "\n";
echo "Only exact token-owned STAGE records may be created and cleaned.\n";
bundle3Require(!bundle3LayoutRows($layoutLabel) && !bundle3ReportRows($reportName), 'Collision on random fixture identity.');

$failure = NULL;
try {
  $layout = new ContactLayoutHandler();
  $report = new ReportInstanceHandler();
  $layoutRow = [
    'label' => $layoutLabel,
    'contact_type' => 'Individual',
    'weight' => 9999,
    'groups' => [],
    'blocks' => [],
  ];
  $layoutDocs = [$layoutLabel . '.yml' => [
    'schema_version' => 1,
    'type' => 'contact-layouts.item',
    'entity' => 'ContactLayout',
    'dependencies' => [],
    'item' => $layoutRow,
  ]];
  $reportRow = [
    'name' => $reportName,
    'title' => $token . ' Disposable QA (inactive)',
    'report_id' => $template,
    'description' => 'Disposable Bundle 3 STAGE regression fixture',
    'is_active' => 0,
  ];
  $reportDocs = [$reportName . '.yml' => [
    'schema_version' => 1,
    'type' => 'report-instances.item',
    'entity' => 'ReportInstance',
    'dependencies' => [],
    'item' => $reportRow,
  ]];

  // All previews and validation precede the first mutation.
  bundle3Validate($layout, $layoutDocs, 'Layout initial');
  bundle3Validate($report, $reportDocs, 'Report initial');
  bundle3AssertCounts($layout->import($layoutDocs, TRUE), 1, 0, 0, 'Layout initial preview');
  bundle3AssertCounts($report->import($reportDocs, TRUE), 1, 0, 0, 'Report initial preview');

  bundle3AssertCounts($layout->import($layoutDocs, FALSE), 1, 0, 0, 'Layout create');
  $layoutCreated = bundle3LayoutRows($layoutLabel);
  bundle3Require(count($layoutCreated) === 1 && (int) $layoutCreated[0]['id'] > 0, 'Layout was not persisted with one exact identity.');
  bundle3AssertCounts($layout->import($layoutDocs, TRUE), 0, 0, 1, 'Layout repeat preview');
  bundle3AssertCounts($layout->import($layoutDocs, FALSE), 0, 0, 1, 'Layout repeat import');
  $layoutExport = bundle3ExportOne($layout, 'label', $layoutLabel);
  bundle3Require(!isset($layoutExport['item']['id']), 'Layout export leaked a local id.');
  bundle3AssertCounts($layout->import(['round-trip.yml' => $layoutExport], TRUE), 0, 0, 1, 'Layout export round trip');
  $layoutDocs[$layoutLabel . '.yml']['item']['weight'] = 9998;
  bundle3AssertCounts($layout->import($layoutDocs, TRUE), 0, 1, 0, 'Layout update preview');
  bundle3AssertCounts($layout->import($layoutDocs, FALSE), 0, 1, 0, 'Layout update');
  $updatedLayout = bundle3LayoutRows($layoutLabel);
  bundle3Require(count($updatedLayout) === 1 && (int) $updatedLayout[0]['weight'] === 9998, 'Layout update not independently persisted.');
  bundle3AssertCounts($layout->import($layoutDocs, FALSE), 0, 0, 1, 'Layout idempotent update');

  bundle3AssertCounts($report->import($reportDocs, FALSE), 1, 0, 0, 'Report create');
  $reportCreated = bundle3ReportRows($reportName);
  bundle3Require(count($reportCreated) === 1 && (int) $reportCreated[0]['id'] > 0, 'Report was not persisted with one exact identity.');
  bundle3AssertCounts($report->import($reportDocs, TRUE), 0, 0, 1, 'Report repeat preview');
  bundle3AssertCounts($report->import($reportDocs, FALSE), 0, 0, 1, 'Report repeat import');
  $reportExport = bundle3ExportOne($report, 'name', $reportName);
  bundle3Require(!isset($reportExport['item']['id']), 'Report export leaked a local id.');
  bundle3AssertCounts($report->import(['round-trip.yml' => $reportExport], TRUE), 0, 0, 1, 'Report export round trip');
  $reportDocs[$reportName . '.yml']['item']['description'] = 'Disposable Bundle 3 STAGE regression fixture updated';
  bundle3AssertCounts($report->import($reportDocs, TRUE), 0, 1, 0, 'Report update preview');
  bundle3AssertCounts($report->import($reportDocs, FALSE), 0, 1, 0, 'Report update');
  $updatedReport = bundle3ReportRows($reportName);
  bundle3Require(count($updatedReport) === 1 && ($updatedReport[0]['description'] ?? '') === $reportDocs[$reportName . '.yml']['item']['description'], 'Report update not independently persisted.');
  bundle3AssertCounts($report->import($reportDocs, FALSE), 0, 0, 1, 'Report idempotent update');
  echo "All Bundle 3 disposable create/update/round-trip/idempotency assertions passed.\n";
}
catch (\Throwable $e) {
  $failure = $e;
  echo 'BUNDLE 3 CERTIFICATION FAILED: ' . $e->getMessage() . "\n";
}
finally {
  $cleanupErrors = bundle3Cleanup($layoutLabel, $reportName);
  if ($cleanupErrors) {
    echo 'Fixture cleanup FAILED for token ' . $token . ': ' . implode(' | ', $cleanupErrors) . "\n";
    throw new \RuntimeException('Bundle 3 cleanup failed; inspect exact token-owned fixtures before retry.');
  }
  echo 'Fixture cleanup PASS: no ContactLayout or ReportInstance fixture records remain for ' . $token . ".\n";
}
if ($failure !== NULL) {
  throw $failure;
}
echo "BUNDLE 3 STAGE DISPOSABLE CRUD: PASS.\n";
