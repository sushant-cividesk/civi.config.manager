<?php
/**
 * Read-only Bundle 3 provider evidence on disposable Drupal/CiviCRM STAGE.
 *
 * Run from /var/www/html/build/dcivi-stage/web:
 * drush php:script /var/www/html/ext/civi.config.manager/tests/manual/bundle3-provider-readonly.php
 *
 * This script never requests provider create/update/delete. It samples up to
 * three portable exports, validation and dry-run preview per provider.
 * CiviCRM bootstrap may still refresh operational caches.
 */

declare(strict_types=1);

use Civi\ConfigManager\Handler\ContactLayoutHandler;
use Civi\ConfigManager\Handler\ReportInstanceHandler;

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

$expectedWebroot = realpath('/var/www/html/build/dcivi-stage/web');
if (!$expectedWebroot || realpath(getcwd()) !== $expectedWebroot) {
  throw new \RuntimeException('STOP: Bundle 3 probe is restricted to DDEV STAGE.');
}
if (!class_exists('Drupal') || !\Drupal::getContainer()->has('civicrm')) {
  throw new \RuntimeException('STOP: A bootstrapped Drupal/CiviCRM STAGE site is required.');
}
\Drupal::service('civicrm')->initialize();
$db = (string) \CRM_Core_DAO::singleValueQuery('SELECT DATABASE()');
if (!preg_match('/^dcivistage[a-z0-9_]*$/i', $db)) {
  throw new \RuntimeException('STOP: Not the expected disposable DDEV STAGE database.');
}
$activeFile = realpath((new \ReflectionClass(ContactLayoutHandler::class))->getFileName());
$root = realpath($extensionRoot);
if (!$root || !$activeFile || strpos($activeFile, $root . DIRECTORY_SEPARATOR) !== 0) {
  throw new \RuntimeException('STOP: Wrong Configuration Manager checkout loaded.');
}

echo "Bundle 3 STAGE provider probe (read-only; at most 3 samples per provider).\n";
$incomplete = FALSE;
$failed = FALSE;
foreach ([new ContactLayoutHandler(), new ReportInstanceHandler()] as $handler) {
  $type = $handler->getType();
  try {
    $availability = $handler->getRuntimeAvailability();
    if (empty($availability['available']) || ($availability['management_capability'] ?? '') === 'export_only') {
      echo $type . ": unavailable or read-only capability; real certification remains pending.\n";
      $incomplete = TRUE;
      continue;
    }
    $docs = [];
    foreach ($handler->iterateExport() as $export) {
      $filename = (string) ($export['filename'] ?? '');
      if ($filename === '' || !is_array($export['data'] ?? NULL) || isset($docs[$filename])) {
        throw new \RuntimeException('Invalid or duplicate portable export filename.');
      }
      $docs[$filename] = $export['data'];
      if (count($docs) >= 3) {
        break;
      }
    }
    if (!$docs) {
      echo $type . ": no sample records; real certification remains pending.\n";
      $incomplete = TRUE;
      continue;
    }
    $validation = $handler->validate($docs);
    $preview = $handler->import($docs, TRUE);
    $ok = !empty($validation['valid']) && !empty($preview['ok']) && !empty($preview['dry_run'])
      && (int) ($preview['create'] ?? -1) === 0
      && (int) ($preview['update'] ?? -1) === 0
      && (int) ($preview['delete'] ?? -1) === 0
      && (int) ($preview['skip'] ?? -1) === count($docs);
    echo $type . ': samples=' . count($docs) . ' export-validate-preview=' . ($ok ? 'PASS' : 'FAIL')
      . ' creates=' . (int) ($preview['create'] ?? -1)
      . ' updates=' . (int) ($preview['update'] ?? -1)
      . ' skips=' . (int) ($preview['skip'] ?? -1) . "\n";
    if (!$ok) {
      $failed = TRUE;
    }
  }
  catch (\Throwable $e) {
    echo $type . ': BLOCKED (' . get_class($e) . '): ' . substr($e->getMessage(), 0, 180) . "\n";
    $failed = TRUE;
  }
}
if ($failed) {
  echo "Bundle 3 read-only probe: FAIL; no provider writes were requested.\n";
  exit(1);
}
if ($incomplete) {
  echo "Bundle 3 read-only probe: INCOMPLETE; missing providers or fixtures.\n";
  exit(2);
}
echo "Bundle 3 read-only provider sample: PASS (not CRUD or cross-environment certification).\n";
