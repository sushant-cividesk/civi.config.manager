<?php
/**
 * Import-readiness presentation regression. No CiviCRM bootstrap or writes.
 */
declare(strict_types=1);

function ts(string $message): string {
  return $message;
}

require_once dirname(__DIR__, 2) . '/Civi/ConfigManager/UI/PresenterImportFeedbackTrait.php';

$presenter = new class() {
  use \Civi\ConfigManager\UI\PresenterImportFeedbackTrait;
};
$planId = str_repeat('a', 48);
$cases = [
  ['permission', [FALSE, ['tags'], 0, ['ok' => TRUE], $planId, []]],
  ['dependencies', [TRUE, ['tags'], 0, ['ok' => TRUE], $planId, [['id' => 'blocked']]]],
  ['validation', [TRUE, ['tags'], 2, ['ok' => TRUE], $planId, []]],
  ['validation', [TRUE, ['tags'], 0, ['ok' => FALSE, 'error' => 'bad'], $planId, []]],
  ['no_changes', [TRUE, [], 0, ['ok' => TRUE], $planId, []]],
  ['unreviewed_plan', [TRUE, ['tags'], 0, ['ok' => TRUE], '', []]],
  ['unreviewed_plan', [TRUE, ['tags'], 0, ['ok' => TRUE], '../not-a-plan', []]],
  ['ready', [TRUE, ['tags'], 0, ['ok' => TRUE], $planId, []]],
];
$count = 0;
foreach ($cases as [$expected, $args]) {
  $result = $presenter->getImportReadiness(...$args);
  if (($result['reason'] ?? NULL) !== $expected || ($result['ready'] ?? NULL) !== ($expected === 'ready') ||
    ($expected === 'ready' ? $result['next_action'] !== '' : trim($result['next_action']) === '')) {
    fwrite(STDERR, 'Bundle 5 readiness mismatch: ' . json_encode([$expected, $result]) . "\n");
    exit(1);
  }
  $count++;
}
$source = (string) file_get_contents(dirname(__DIR__, 2) . '/Civi/ConfigManager/UI/MainPageResponseTrait.php');
$template = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/CRM/Configmanager/Page/Partials/Import.tpl');
if (strpos($source, "'importReadiness'") === FALSE || strpos($template, 'data-civicfg-import-readiness') === FALSE ||
  strpos($template, '$importErrorCount eq 0') === FALSE) {
  fwrite(STDERR, "Bundle 5 readiness must remain presentation-only and leave apply safety checks intact.\n");
  exit(1);
}
$count++;
echo 'Bundle 5 Import readiness OK (' . $count . " checks; no writes).\n";
