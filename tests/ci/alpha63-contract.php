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
  $contents = @file_get_contents($root . '/' . $relative);
  if ($contents === FALSE) {
    throw new RuntimeException('Could not read ' . $relative);
  }
  return $contents;
};

$civirules = $read('Civi/ConfigManager/Handler/CiviRulesHandler.php');
$extensions = $read('Civi/ConfigManager/Handler/ExtensionHandler.php');
$chunked = $read('Civi/ConfigManager/Handler/ChunkedStreamingHandlerInterface.php');
$spool = $read('Civi/ConfigManager/Service/DiskRowSpool.php');
$manager = $read('Civi/ConfigManager/Service/ConfigManager.php');
$workspace = $read('Civi/ConfigManager/Service/StagedExportWorkspace.php');
$operationWorkspace = $read('Civi/ConfigManager/Service/OperationWorkspace.php');
$operationStore = $read('Civi/ConfigManager/Service/OperationStore.php');
$queue = $read('Civi/ConfigManager/Service/QueuedOperationService.php');
$js = $read('js/configmanager.js');
$css = $read('css/configmanager.css');
$info = $read('info.xml');
$composer = $read('composer.json');
$simpleYaml = $read('Civi/ConfigManager/Util/SimpleYaml.php');
$mainPage = $read('Civi/ConfigManager/UI/MainPage.php');
$fileTransfer = $read('Civi/ConfigManager/UI/FileTransfer.php');
$releaseBuilder = $read('scripts/build-release.sh');

$assert(strpos($info, '<version>') !== FALSE && strpos($info, '</version>') !== FALSE, 'info.xml must declare an extension version while preserving the alpha63 architecture contract.');
$assert(strpos($composer, '"analyse": "PHPSTAN_TURBO=0 phpstan analyse') !== FALSE, 'PHP 7.4 QA must disable optional PHPStan Turbo before static analysis.');
$assert(strpos($composer, '"package:release": "bash scripts/build-release.sh"') !== FALSE, 'Release packaging must have an explicit runtime-complete Composer command.');
$assert(strpos($releaseBuilder, 'composer install') !== FALSE && strpos($releaseBuilder, '--no-dev') !== FALSE && strpos($releaseBuilder, 'vendor/autoload.php') !== FALSE && strpos($releaseBuilder, 'Symfony\\Component\\Yaml\\Yaml') !== FALSE, 'Official release packaging must install and verify production Symfony YAML dependencies.');
$assert(strpos($simpleYaml, "function_exists('yaml_emit')") !== FALSE && strpos($simpleYaml, "function_exists('yaml_parse_file')") !== FALSE, 'Source checkouts may use only complete ext-yaml read/write capability.');
$assert(strpos($simpleYaml, 'private static function dumpValue') === FALSE && strpos($simpleYaml, 'private static function dumpArray') === FALSE, 'Production must not fall back to a hand-written YAML serializer.');
$assert(strpos($simpleYaml, 'set_error_handler') !== FALSE && strpos($simpleYaml, 'restore_error_handler') !== FALSE, 'ext-yaml warnings must be isolated before they can corrupt JSON/CLI protocol output.');
$assert(strpos($manager, 'CRM_Utils_System::cmsRootPath()') !== FALSE, 'Relative sync paths must prefer the authoritative active CMS root in CLI and web contexts.');
$assert(strpos($manager, 'Storage\\SimpleYaml::parseFile') === FALSE && strpos($manager, 'SimpleYaml::parseFile($manifestPath)') !== FALSE, 'Manifest-aware managed YAML detection must use the real SimpleYaml helper.');
$assert(strpos($mainPage, 'while (ob_get_level() > 0)') !== FALSE && strpos($fileTransfer, 'while (ob_get_level() > 0)') !== FALSE && strpos($js, 'expected JSON but the server returned HTML') !== FALSE, 'JSON endpoints and lazy diff UI must fail cleanly when CMS/plugin output would otherwise corrupt JSON.');
$assert(strpos($chunked, 'interface ChunkedStreamingHandlerInterface extends StreamingHandlerInterface') !== FALSE, 'Alpha63 must expose the durable chunked export contract.');
$assert(strpos($civirules, 'ChunkedStreamingHandlerInterface') !== FALSE && strpos($extensions, 'ChunkedStreamingHandlerInterface') !== FALSE, 'CiviRules and extension providers must support durable export units.');
$assert(strpos($civirules, 'new DiskRowSpool()') !== FALSE && strpos($extensions, 'new DiskRowSpool()') !== FALSE, 'High-volume CiviRules/extension providers must spool one provider scan to disk.');
$assert(strpos($spool, 'JSON_UNESCAPED_SLASHES') !== FALSE && strpos($spool, 'yield $decoded') !== FALSE, 'Provider spool must be disk-backed and replay rows iteratively.');
$assert(strpos($civirules, "str_pad((string) \$occurrence, 2, '0', STR_PAD_LEFT)") !== FALSE, 'Ambiguous CiviRules snapshots must get deterministic occurrence filenames.');
$assert(strpos($civirules, "'monitor_only' => !\$portable") !== FALSE, 'Ambiguous CiviRules source rows must be marked monitor-only.');
$assert(strpos($civirules, "'content_fingerprint' => \$fingerprint") !== FALSE && strpos($civirules, "'group_count' => \$groupCount") !== FALSE, 'Ambiguous CiviRules metadata must preserve multiset group/fingerprint information.');
$assert(strpos($civirules, 'target conflict: portable identity') !== FALSE, 'Portable CiviRules YAML which is ambiguous on target must be a blocking conflict.');
$assert(strpos($civirules, 'other proven-safe configuration may continue importing') !== FALSE, 'Intentional monitor-only CiviRules rows must not block unrelated safe imports.');
$assert(strpos($civirules, 'delete-missing skipped duplicate identity') !== FALSE, 'CiviRules delete safety must be per identity.');
$assert(strpos($extensions, "'monitor_only' => \$monitorOnly") !== FALSE, 'Generic contributed-provider ambiguous rows must be monitor-only snapshots.');
$assert(strpos($extensions, 'unrelated safe identities may continue') !== FALSE, 'Monitor-only extension provider rows must not block unrelated safe import.');
$assert(strpos($extensions, 'target conflict: portable source identity') !== FALSE, 'Portable contributed-provider source identity must block on target ambiguity.');
$assert(strpos($extensions, 'genericApi4ConfigAdmission') !== FALSE && strpos($extensions, "'generic_config_admitted'") !== FALSE, 'Generic extension CRUD entities must pass an explicit configuration-admission gate before export/import.');
$assert(strpos($extensions, 'Generic API3 entity discovered from provider files only') !== FALSE, 'Generic API3 providers must not be auto-managed without a reviewed/explicit provider contract.');
$assert(strpos($extensions, 'api4EntityDeclarativelyReadable') !== FALSE && strpos($extensions, 'Generic discovery must never execute provider collection actions') !== FALSE, 'Generic API4 provider admission must be metadata-only and must not execute get() during discovery.');
$assert(strpos($extensions, 'Generic API3 introspection is intentionally file-only') !== FALSE, 'Generic API3 discovery must be file-only so contributed actions are not executed during provider discovery.');
$assert(strpos($extensions, '$identitySafety = $importable ? \'UNVERIFIED\' : \'EXCLUDED\';') !== FALSE, 'Compatibility diagnostics must not read providers which were excluded by the admission firewall.');
$assert(strpos($civirules, "array_key_exists('identity_portable', \$file)") !== FALSE, 'Legacy CiviRules YAML without alpha63 identity_portable metadata must not be silently downgraded to monitor-only.');
$assert(strpos($extensions, '$providerDeleteSafe[$definitionKey] = !empty($providerDeleteSafe[$definitionKey]) || $safe;') !== FALSE, 'Delete-missing capability must not be disabled provider-wide by one monitor-only identity.');
$assert(strpos($manager, 'public function buildQueuedExportPlan') !== FALSE && strpos($manager, 'public function buildQueuedImportPlan') !== FALSE, 'Export and import must use explicit durable work-unit plans.');
$assert(substr_count($manager, "'action' => 'export_stage'") >= 1 && strpos($manager, "'action' => 'export_verify_publish'") !== FALSE, 'Export queue plan must separate staging from final safety verification/publication.');
$assert(strpos($manager, "'action' => 'import_preflight'") !== FALSE && strpos($manager, "'action' => 'import_create_update'") !== FALSE && strpos($manager, "'action' => 'import_delete_missing'") !== FALSE, 'Import queue plan must preserve preflight/create-update/delete phases.');
$assert(strpos($manager, '$result[\'delete_phase_skipped\'] = TRUE;') !== FALSE, 'Queued create/update failure must explicitly mark delete-missing as skipped.');
$assert(strpos($queue, 'if (!$ok)') !== FALSE && strpos($queue, '$store->failJob($jobId, $message, $result);') !== FALSE, 'A failed queued work unit must terminate the durable job before later delete-missing work can run.');
$assert(strpos($operationWorkspace, "'civicfg-alpha63'") !== FALSE && strpos($operationWorkspace, "'state.json'") !== FALSE, 'Durable job workspace must live outside managed YAML and persist state.');
$assert(strpos($operationWorkspace, 'configAndLogDir') !== FALSE, 'Durable queued-operation workspace should prefer persistent CiviCRM ConfigAndLog storage instead of container /tmp.');
$assert(strpos($workspace, "'publish-state.json'") !== FALSE && strpos($workspace, 'recoverIncompletePublish') !== FALSE, 'YAML publication rollback state must survive a killed worker.');
$assert(strpos($workspace, 'persistPublishState($journal, $newPaths)') !== FALSE, 'Rollback intent must be persisted before live YAML mutation.');
$assert(strpos($manager, 'recoverQueuedExportPublish') !== FALSE && strpos($queue, 'recoverQueuedExportPublish') !== FALSE, 'Indeterminate publish work must attempt durable rollback before blocking.');
$assert(strpos($queue, '@session_write_close()') !== FALSE, 'Long queue advancement must release the PHP session so WordPress status polling remains responsive.');
$assert(strpos($queue, 'runNext(FALSE)') !== FALSE, 'Browser advancement must consume at most one persistent queue item per request.');
$assert(strpos($queue, "!empty(\$item['retry_safe'])") !== FALSE, 'Only explicitly retry-safe work units may be replayed after an interrupted worker.');
$assert(strpos($operationStore, 'payload_json MEDIUMTEXT') !== FALSE && strpos($operationStore, 'retry_safe TINYINT') !== FALSE && strpos($operationStore, 'sequence_no INT') !== FALSE, 'Job items must persist durable payload/retry/ordering metadata.');
$assert(strpos($operationStore, 'information_schema.COLUMNS') !== FALSE, 'Alpha63 operation schema upgrade must be idempotent for existing alpha62 tables.');
$assert(strpos($operationStore, 'progress_known') !== FALSE && strpos($operationStore, 'phase_index') !== FALSE && strpos($operationStore, 'item_total') !== FALSE, 'Progress persistence must distinguish known totals, phases, and work-unit totals.');
$assert(strpos($js, 'progress_known') !== FALSE && strpos($js, 'Phase ') !== FALSE, 'UI must render honest known/unknown progress with named phases.');
$assert(strpos($js, "'Step ' + completed + ' of '") === FALSE, 'UI must not present heterogeneous queue work as misleading Step X of Y progress.');
$assert(strpos($js, 'heartbeatAgeText') !== FALSE && strpos($js, 'refreshing this page will reconnect') !== FALSE, 'UI must show heartbeat/reconnect guidance.');
$assert(strpos($js, 'Live YAML will not change until safety verification succeeds') !== FALSE, 'Export startup UX must state the real staging safety boundary.');
$assert(strpos($css, '@keyframes civicfg-progress') === FALSE, 'Unknown progress must not use fake bouncing animation.');
$assert(strpos($css, '.civicfg-progress-unknown') !== FALSE, 'Unknown-total progress must have explicit non-fake styling.');
$assert(strpos($manager, "'monitor_only' => (int) (\$state['monitor_only'] ?? 0)") !== FALSE, 'Export result must report preserved monitor-only objects.');

if ($failures) {
  fwrite(STDERR, 'alpha63 contract FAILED (' . count($failures) . '/' . $checks . ")\n");
  foreach ($failures as $failure) {
    fwrite(STDERR, ' - ' . $failure . "\n");
  }
  exit(1);
}

echo 'alpha63 contract OK (' . $checks . " checks)\n";
