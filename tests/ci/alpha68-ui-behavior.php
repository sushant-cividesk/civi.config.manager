<?php

declare(strict_types=1);

if (!function_exists('ts')) {
  function ts(string $text, array $params = []): string {
    foreach ($params as $key => $value) {
      $text = str_replace('%' . (string) $key, (string) $value, $text);
    }
    return $text;
  }
}

require_once __DIR__ . '/../../Civi/ConfigManager/UI/OperationResultPresenter.php';
require_once __DIR__ . '/../../Civi/ConfigManager/Service/SavedConfigInventory.php';

use Civi\ConfigManager\Service\SavedConfigInventory;
use Civi\ConfigManager\UI\OperationResultPresenter;

$checks = 0;
$assert = static function(bool $condition, string $message) use (&$checks): void {
  $checks++;
  if (!$condition) {
    fwrite(STDERR, "alpha68 UI behavior failed: {$message}\n");
    exit(1);
  }
};

// Independent expected values: do not derive them from production helpers.
$inventory = (new SavedConfigInventory())->count([
  'manifest.yml',
  'profiles/groups/a.yml',
  'profiles/fields/a__phone.yml',
  'profiles/fields/a__email.yml',
  'tags/a.yml',
], [
  'profiles' => 'profiles/groups',
  'profile-fields' => 'profiles/fields',
  'tags' => 'tags',
]);
$assert($inventory['total'] === 5, 'total Saved Config count must include manifest and managed YAML paths');
$assert($inventory['by_type']['profiles'] === 1, 'Profiles count must be one');
$assert($inventory['by_type']['profile-fields'] === 2, 'Profile Fields count must be two');
$assert($inventory['by_type']['tags'] === 1, 'Tags count must be one');

$presenter = new OperationResultPresenter();
$export = $presenter->exportSummary([
  'ok' => TRUE,
  'created_count' => 2,
  'updated_count' => 3,
  'skipped' => ['same.yml'],
  'deleted' => ['old.yml'],
  'monitor_only' => 2,
], ['saved_config_count' => 1107], ['status' => 'complete', 'finished_at' => '2026-09-04 16:00:00']);
$assert($export['ok'] === TRUE, 'successful export must render successful');
$assert($export['created'] === 2 && $export['updated'] === 3, 'export created/updated counts must stay distinct');
$assert($export['unchanged'] === 1 && $export['removed'] === 1, 'export unchanged/removed counts must be preserved');
$assert($export['saved_config_count'] === 1107, 'persistent export result must show current Saved Config inventory');
$exportMessage = $presenter->exportMessage($export);
$assert(strpos($exportMessage, '2 Saved Config file(s) created') !== FALSE, 'export completion message must report the same created count as Last Export');
$assert(strpos($exportMessage, '3 updated') !== FALSE, 'export completion message must report the same updated count as Last Export');
$assert(strpos($exportMessage, '5 Saved Config file(s) updated') === FALSE, 'export completion message must not collapse all writes into updated');
$devExport = $presenter->exportSummary([
  'ok' => TRUE,
  'created_count' => 1108,
  'updated_count' => 0,
  'written' => array_fill(0, 1108, 'written.yml'),
  'skipped' => ['manifest.yml'],
  'monitor_only' => 2,
], ['saved_config_count' => 1108]);
$devMessage = $presenter->exportMessage($devExport);
$assert(strpos($devMessage, '1108 Saved Config file(s) created, 0 updated') !== FALSE, 'DEV regression: toast must agree with Last Export 1108 Created / 0 Updated');
$assert(strpos($devMessage, '1108 Saved Config file(s) updated') === FALSE, 'DEV regression: toast must not relabel created files as updated');

$import = $presenter->importSummary([
  'ok' => TRUE,
  'items' => [[
    'create' => 1,
    'update' => 2,
    'delete' => 1,
    'skip' => 4,
    'values' => ['create' => 2, 'update' => 1, 'delete' => 0, 'skip' => 1],
  ]],
], ['status' => 'complete']);
$assert($import['created'] === 3, 'import created count must include nested handler groups');
$assert($import['updated'] === 3, 'import updated count must include nested handler groups');
$assert($import['removed'] === 1, 'import removed count must be preserved');
$assert($import['unchanged'] === 5, 'import unchanged count must include nested handler groups');
$assert(!array_key_exists('items', $import), 'persistent import summary must not retain large item payloads');

$failed = $presenter->importSummary([
  'ok' => FALSE,
  'items' => [['errors' => [['message' => 'Unsafe removal blocked.']]]],
], ['status' => 'failed']);
$assert($failed['ok'] === FALSE, 'failed import must never render successful');
$assert($failed['problem'] === 'Unsafe removal blocked.', 'failed import must preserve an actionable problem');

echo "alpha68 UI behavior OK ({$checks} checks)\n";
