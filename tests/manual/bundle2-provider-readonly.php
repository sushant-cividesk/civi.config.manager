<?php
/**
 * Read-only Bundle 2 API4 probe against a bootstrapped Drupal/CiviCRM site.
 *
 * Run from the Drupal webroot with:
 * drush php:script /var/www/html/ext/civi.config.manager/tests/manual/bundle2-provider-readonly.php
 *
 * Never calls API4 create/update/delete. All imports are dry runs; only a
 * bounded sample is checked. CMS/CiviCRM bootstrap may update caches.
 */

declare(strict_types=1);

use Civi\ConfigManager\Handler\EntityDefinitionHandler;
use Civi\ConfigManager\Handler\ProfileFieldHandler;
use Civi\ConfigManager\Handler\TagHandler;
use Civi\ConfigManager\Service\CoreEntityDefinitions;

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

if (!class_exists('Drupal') || !\Drupal::getContainer()->has('civicrm')) {
  throw new \RuntimeException('Run this probe with drush php:script from an enabled Drupal/CiviCRM site.');
}
\Drupal::service('civicrm')->initialize();

$activeFile = realpath((new \ReflectionClass(TagHandler::class))->getFileName());
$expectedRoot = realpath($extensionRoot);
if (!$activeFile || !$expectedRoot || strpos($activeFile, $expectedRoot . DIRECTORY_SEPARATOR) !== 0) {
  throw new \RuntimeException('The loaded Configuration Manager handler does not belong to this source checkout.');
}

$handlers = [
  new TagHandler(),
  new EntityDefinitionHandler('profiles', CoreEntityDefinitions::metadataDriven()['profiles']),
  new ProfileFieldHandler(),
];

$allOk = TRUE;
echo "Bundle 2 read-only provider probe (3 exported items per provider max)\n";
echo "No API4 create/update/delete; dry-run imports only.\n";

foreach ($handlers as $handler) {
  $type = $handler->getType();
  try {
    $documents = [];
    foreach ($handler->iterateExport() as $export) {
      $filename = (string) ($export['filename'] ?? '');
      if ($filename === '' || !isset($export['data']) || !is_array($export['data'])) {
        throw new \RuntimeException('Provider yielded an invalid export document.');
      }
      if (isset($documents[$filename])) {
        throw new \RuntimeException('Provider yielded duplicate portable filename within the sample.');
      }
      $documents[$filename] = $export['data'];
      if (count($documents) >= 3) {
        break;
      }
    }
    if (!$documents) {
      echo $type . ": no records available for sampling; not certified.\n";
      $allOk = FALSE;
      continue;
    }
    $validation = $handler->validate($documents);
    $preview = $handler->import($documents, TRUE);
    if (empty($preview['dry_run'])) {
      throw new \RuntimeException('Handler did not confirm dry-run mode.');
    }
    $ok = !empty($validation['valid']) && !empty($preview['ok']);
    echo $type . ': sample=' . count($documents)
      . ' validation=' . (!empty($validation['valid']) ? 'PASS' : 'FAIL')
      . ' dry_run=' . (!empty($preview['ok']) ? 'PASS' : 'FAIL')
      . ' create=' . (int) ($preview['create'] ?? 0)
      . ' update=' . (int) ($preview['update'] ?? 0)
      . ' skip=' . (int) ($preview['skip'] ?? 0) . "\n";
    if (!$ok) {
      $allOk = FALSE;
      foreach (array_slice(array_merge((array) ($validation['errors'] ?? []), (array) ($preview['errors'] ?? [])), 0, 3) as $error) {
        echo '  issue: ' . substr((string) ($error['message'] ?? 'Unknown provider error'), 0, 180) . "\n";
      }
    }
  }
  catch (\Throwable $e) {
    echo $type . ': BLOCKED (' . get_class($e) . '): ' . substr($e->getMessage(), 0, 180) . "\n";
    $allOk = FALSE;
  }
}

echo $allOk ? "Read-only provider sample checks passed.\n" : "Some provider samples failed or lacked evidence.\n";
if (!$allOk) {
  exit(1);
}
