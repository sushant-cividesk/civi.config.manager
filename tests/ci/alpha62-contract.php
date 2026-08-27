<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$failures = [];
$checks = 0;

$assert = static function(bool $condition, string $message) use (&$failures, &$checks): void {
  $checks++;
  if (!$condition) {
    $failures[] = $message;
  }
};

$read = static function(string $relative) use ($root): string {
  $path = $root . '/' . $relative;
  $contents = @file_get_contents($path);
  if ($contents === FALSE) {
    throw new RuntimeException('Could not read ' . $relative);
  }
  return $contents;
};

$manager = $read('Civi/ConfigManager/Service/ConfigManager.php');
$abstract = $read('Civi/ConfigManager/Handler/AbstractHandler.php');
$storage = $read('Civi/ConfigManager/Storage/YamlFileStorage.php');
$workspace = $read('Civi/ConfigManager/Service/StagedExportWorkspace.php');
$civirules = $read('Civi/ConfigManager/Handler/CiviRulesHandler.php');
$extensions = $read('Civi/ConfigManager/Handler/ExtensionHandler.php');
$identity = $read('Civi/ConfigManager/Service/ConfigIdentity.php');
$mainPage = $read('Civi/ConfigManager/UI/MainPage.php');
$queue = $read('Civi/ConfigManager/Service/QueuedOperationService.php');
$operationStore = $read('Civi/ConfigManager/Service/OperationStore.php');
$js = $read('js/configmanager.js');
$css = $read('css/configmanager.css');

$assert(strpos($abstract, 'function api4Iterate(') !== FALSE && strpos($abstract, 'yield $row;') !== FALSE, 'API4 collection reads must expose a generator.');
$assert(strpos($abstract, "\$action->addWhere('id', '>', \$lastId)") !== FALSE && strpos($abstract, '$useIdCursor') !== FALSE, 'Numeric id-ordered API4 scans must use a stable keyset cursor where safe.');
$assert(strpos($storage, 'function iterateDirectory(string $directory): \\Generator') !== FALSE, 'YAML storage must expose an iterative directory reader.');
$assert(strpos($storage, 'return iterator_to_array($this->iterateDirectory($directory), TRUE);') !== FALSE, 'Materialized YAML reads must be a compatibility wrapper over the iterator.');
$assert(strpos($manager, 'new StagedExportWorkspace($storage)') !== FALSE, 'Export must use the staged workspace.');
$assert(strpos($workspace, "if (isset(\$this->files[\$relative]))") !== FALSE, 'Staged export must hard-block duplicate YAML paths.');
$assert(strpos($workspace, "if (isset(\$this->files['manifest.yml']))") !== FALSE, 'Staged export must publish manifest.yml separately/last.');
$assert(substr_count($manager, 'OperationLock::acquire(') >= 3, 'Export, import, and revert must share the Configuration Manager operation lock.');
$assert(strpos($manager, 'assertActiveSnapshotMatches') !== FALSE, 'Export must revalidate active fingerprints before publishing.');
$assert(strpos($manager, 'assertManagedActiveSnapshotMatches') !== FALSE, 'Import must use optimistic active-snapshot checks.');
$assert(strpos($manager, 'buildCompactStreamingDiff') !== FALSE && strpos($manager, "'details_lazy' => TRUE") !== FALSE, 'Synchronize must use compact summary-first diffing with lazy details.');
$assert(strpos($manager, 'public function getDiffDetail(string $relativePath): array') !== FALSE, 'Field-level diff detail must be loaded on demand.');
$assert(strpos($mainPage, 'diff-detail-json') !== FALSE && strpos($js, 'data-civicfg-lazy-detail-host') !== FALSE, 'UI must expose/use the lazy diff-detail endpoint.');
$assert(strpos($mainPage, '$diffPerPage = 100;') !== FALSE, 'Synchronize summaries must be paginated to a bounded page size.');
$assert(strpos($extensions, "'org.civicoop.civirules'") !== FALSE, 'Generic extension discovery must skip CiviRules provider data owned by the dedicated handler.');
$assert(strpos($civirules, "'identity_portable' => \$portable") !== FALSE && strpos($civirules, "'AMBIGUOUS'") !== FALSE, 'CiviRules duplicate/non-portable identities must be explicitly marked ambiguous.');
$assert(strpos($identity, "array_key_exists('identity_portable', \$data)") !== FALSE, 'Generic identity discovery must respect handler-declared non-portable identities.');
$assert(strpos($mainPage, "CRM_Core_Key::validate(\$token, 'civicfg_config_manager')") !== FALSE, 'Mutating web actions must validate the Configuration Manager CSRF key.');
$assert(strpos($mainPage, "CRM_Core_Key::get('civicfg_config_manager')") !== FALSE, 'The UI must generate the Configuration Manager CSRF key.');
$assert(strpos($queue, 'runNext(FALSE)') !== FALSE, 'Persistent web operations must advance CiviCRM Queue one item at a time.');
$assert(strpos($queue, "(string) \$item['status'] === 'complete'") !== FALSE, 'Completed queue items must be retry-idempotent.');
$assert(strpos($queue, "(string) \$item['status'] === 'running'") !== FALSE && strpos($queue, 'blocked instead of replaying') !== FALSE, 'Indeterminate mid-item retries must fail closed rather than blind replay.');
$assert(strpos($operationStore, 'civicrm_civicfg_job') !== FALSE && strpos($operationStore, 'civicrm_civicfg_job_item') !== FALSE, 'Persistent operation/job-item schema must be present.');
$assert(strpos($js, 'operation-start-json') !== FALSE && strpos($js, 'links.step_url') !== FALSE && strpos($js, 'links.status_url') !== FALSE, 'Browser progress must use persistent start/step/status endpoints.');
$assert(strpos($js, 'style.width = percent') !== FALSE, 'Progress-bar width must come from server progress percentage.');
$assert(strpos($css, '@keyframes civicfg-progress') === FALSE, 'Fake animated/bouncing progress keyframes must be absent.');
$assert(preg_match('/\.civicfg-progress-fill\s*\{[^}]*width:\s*0/s', $css) === 1, 'Progress fill must start at 0 rather than a fake fixed percentage.');
$assert(strpos($manager, 'iterateManagedYamlFilesForHandler') !== FALSE && strpos($manager, 'validateManagedYamlForHandler') !== FALSE, 'Import/validation must use generator-backed YAML for streaming handlers.');
$assert(strpos($manager, "'delete_phase_skipped' => TRUE") !== FALSE || strpos($manager, "\$result['delete_phase_skipped'] = TRUE") !== FALSE, 'Create/update failure must explicitly skip the delete-missing phase.');
$assert(strpos($manager, 'acceptYamlBaselineItem') !== FALSE, 'Baseline acceptance must process YAML one document at a time.');

$postForms = 0;
$csrfInputs = 0;
foreach (glob($root . '/templates/CRM/Configmanager/Page/Partials/*.tpl') ?: [] as $file) {
  $tpl = (string) file_get_contents($file);
  $postForms += preg_match_all('/<form\b[^>]*method="post"/i', $tpl, $unused);
  $csrfInputs += preg_match_all('/name="civicfg_csrf"/', $tpl, $unused2);
}
$assert($postForms > 0 && $postForms === $csrfInputs, 'Every POST form must include a CSRF token (' . $postForms . ' forms, ' . $csrfInputs . ' tokens).');

if ($failures) {
  fwrite(STDERR, "alpha62 contract FAILED (" . count($failures) . "/" . $checks . ")\n");
  foreach ($failures as $failure) {
    fwrite(STDERR, ' - ' . $failure . "\n");
  }
  exit(1);
}

echo 'alpha62 contract OK (' . $checks . " checks)\n";
