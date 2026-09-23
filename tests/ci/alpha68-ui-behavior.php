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
require_once __DIR__ . '/../../Civi/ConfigManager/UI/Presenter.php';
require_once __DIR__ . '/../../Civi/ConfigManager/Service/SavedConfigInventory.php';
require_once __DIR__ . '/../../Civi/ConfigManager/Service/ConfigIdentity.php';
require_once __DIR__ . '/../../Civi/ConfigManager/Service/ConfigScope.php';
require_once __DIR__ . '/../../Civi/ConfigManager/Service/HandlerRegistry.php';
require_once __DIR__ . '/../../Civi/ConfigManager/Service/ImportPlanStore.php';
require_once __DIR__ . '/../../Civi/ConfigManager/Service/ImportReviewPlanGuard.php';
require_once __DIR__ . '/../../Civi/ConfigManager/Service/ConfigManager.php';

use Civi\ConfigManager\Service\ConfigManager;
use Civi\ConfigManager\Service\ConfigScope;
use Civi\ConfigManager\Service\ConfigIdentity;
use Civi\ConfigManager\Service\HandlerRegistry;
use Civi\ConfigManager\Service\SavedConfigInventory;
use Civi\ConfigManager\UI\OperationResultPresenter;
use Civi\ConfigManager\UI\Presenter;

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
  'review_only_items' => [
    ['type' => 'civirules', 'label' => 'CiviRules', 'path' => 'civirules/rules/legacy.yml'],
    ['type' => 'extensions', 'label' => 'Extension Config', 'path' => 'extensions/example/ambiguous.yml'],
  ],
], ['saved_config_count' => 1107], ['status' => 'complete', 'finished_at' => '2026-09-04 16:00:00']);
$assert($export['ok'] === TRUE, 'successful export must render successful');
$assert($export['created'] === 2 && $export['updated'] === 3, 'export created/updated counts must stay distinct');
$assert($export['unchanged'] === 1 && $export['removed'] === 1, 'export unchanged/removed counts must be preserved');
$assert($export['saved_config_count'] === 1107, 'persistent export result must show current Saved Config inventory');
$assert($export['review_only'] === 2, 'automatically protected objects must be labeled Review only in the UI summary');
$assert(count($export['review_only_items']) === 2, 'Review only count must expose compact inspectable item descriptors');
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
  'review_only_items' => [
    ['type' => 'civirules', 'label' => 'CiviRules', 'path' => 'civirules/actions/legacy.yml'],
    ['type' => 'extensions', 'label' => 'Extension Config', 'path' => 'extensions/example/ambiguous.yml'],
  ],
], ['saved_config_count' => 1108]);
$devMessage = $presenter->exportMessage($devExport);
$assert(strpos($devMessage, '1108 Saved Config file(s) created, 0 updated') !== FALSE, 'DEV regression: toast must agree with Last Export 1108 Created / 0 Updated');
$assert(strpos($devMessage, '1108 Saved Config file(s) updated') === FALSE, 'DEV regression: toast must not relabel created files as updated');
$assert(strpos($devMessage, '2 configuration object(s) were saved for review') !== FALSE, 'automatically protected objects must use review wording rather than explicit Monitor only scope wording');
$assert(strpos($devMessage, 'monitor-only ambiguous configuration object') === FALSE, 'export notice must not present automatic safety protection as user-selected Monitor only scope');

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


/**
 * Requirement: Settings must distinguish safe create/update from providers
 * whose delete-missing behavior has also been proven safe.
 * Failure mode: a managed-no-delete provider is presented as Full management.
 */
$managerReflection = new ReflectionClass(ConfigManager::class);
$manager = $managerReflection->newInstanceWithoutConstructor();
$capabilityMethod = $managerReflection->getMethod('scopeCapabilityForHandler');
$capabilityMethod->setAccessible(TRUE);
$managedNoDeleteHandler = new class {
  public function getRuntimeAvailability(): array {
    return [
      'available' => TRUE,
      'management_capability' => 'managed_no_delete',
      'reason' => 'Create/update is reviewed; automatic removal is disabled.',
    ];
  }
  public function getProviderMetadata(): array {
    return ['management_capability' => 'managed_no_delete'];
  }
  public function import(array $items, bool $dryRun = TRUE): array {
    return [];
  }
};
$managedNoDeleteCapability = $capabilityMethod->invoke($manager, $managedNoDeleteHandler);
$assert($managedNoDeleteCapability['key'] === 'managed_no_delete', 'managed-no-delete provider must retain its conservative capability key');
$assert($managedNoDeleteCapability['label'] === 'Create + update', 'managed-no-delete provider must not be advertised as Full management');
$assert(strpos((string) $managedNoDeleteCapability['help'], 'removal') !== FALSE, 'managed-no-delete help must make the removal limitation visible');

$fullHandler = new class {
  public function getProviderMetadata(): array {
    return ['management_capability' => 'full'];
  }
  public function import(array $items, bool $dryRun = TRUE): array {
    return [];
  }
};
$fullCapability = $capabilityMethod->invoke($manager, $fullHandler);
$assert($fullCapability['key'] === 'full', 'fully managed provider must retain the full capability key');
$assert($fullCapability['label'] === 'Managed', 'fully managed provider must use the concise client-facing Managed label');

/**
 * Requirement: runtime safety is the canonical provider-inventory capability.
 * Failure mode: declared "full" metadata overwrites a runtime export-only
 * downgrade, leaving the Settings group inconsistent with its capability.
 */
$runtimeLimitedHandler = new class {
  public function getType(): string { return 'runtime-limited'; }
  public function getLabel(): string { return 'Runtime Limited'; }
  public function getWeight(): int { return 20; }
  public function getProviderMetadata(): array {
    return ['owner' => 'example.test', 'management_capability' => 'full'];
  }
  public function getRuntimeAvailability(): array {
    return [
      'available' => TRUE,
      'management_capability' => 'export_only',
      'reason' => 'Provider is readable but a required write action is unavailable.',
    ];
  }
  public function import(array $items, bool $dryRun = TRUE): array { return []; }
};
$runtimeLimitedRegistry = new class($runtimeLimitedHandler) extends HandlerRegistry {
  private $handler;
  public function __construct($handler) { $this->handler = $handler; }
  public function getHandlerRegistrations(): array {
    return [['handler' => $this->handler, 'registration_source' => 'config_types_hook']];
  }
  public function getRegistrationDiagnostics(): array { return []; }
};
$inventoryManager = new ConfigManager($runtimeLimitedRegistry, new ConfigScope(new ConfigIdentity()));
$providerInventory = $inventoryManager->getProviderInventory();
$inventoryProvider = $providerInventory['providers'][0] ?? [];
$assert(($inventoryProvider['capability'] ?? '') === 'export_only', 'provider inventory must preserve a runtime export-only downgrade over declared full metadata');
$assert(($inventoryProvider['capability_reason_code'] ?? '') === 'handler_export_only', 'provider inventory reason code must use the canonical runtime capability');

/**
 * Requirement: normal change cards must not expose Profile Field filename
 * hashes, and Not Yet Saved cards should explain the next action instead of
 * repeating the badge in sentence form.
 * Failure mode: the user sees --<hash> in normal UI and duplicate status text.
 */
$uiPresenter = new Presenter();
$profileDiff = $uiPresenter->extractDiffFiles(['items' => [[
  'type' => 'profile-fields',
  'label' => 'Profile Fields',
  'files' => [[
    'path' => 'profiles/fields/summary_overlay__phone__Home-Phone--7a8859f54f.yml',
    'file' => 'summary_overlay__phone__Home-Phone--7a8859f54f.yml',
    'status' => 'new_in_db',
    'config_key' => 'profile-fields:api4:UFField|key=profile=summary_overlay%7Cfield=phone%7Cfield_type=Phone%7Clocation=Home%7Clabel=Home Phone',
    'change_count' => 0,
    'changes' => [],
  ]],
]]]);
$profileFile = $profileDiff[0] ?? [];
$assert(($profileFile['display_title'] ?? '') === 'Profile Field "Home Phone" in "Summary Overlay"', 'Profile Field title must come from semantic identity instead of the hashed filename');
$assert(strpos((string) ($profileFile['display_title'] ?? ''), '7a8859f54f') === FALSE, 'Profile Field normal title must hide the identity hash');
$assert(($profileFile['show_inline_path'] ?? TRUE) === FALSE, 'Profile Field technical path must stay out of the normal change card');
$assert(($profileFile['summary_sentence'] ?? '') === 'Export this item to add it to Saved Configs.', 'Not Yet Saved summary must give the next action instead of repeating the status');

$profileImportPlan = $uiPresenter->buildImportPlan($profileDiff);
$assert(($profileImportPlan[0]['display_title'] ?? '') === 'Profile Field "Home Phone" in "Summary Overlay"', 'Import preview must keep the semantic Profile Field title');
$assert(($profileImportPlan[0]['show_inline_path'] ?? TRUE) === FALSE, 'Import preview must keep the Profile Field technical path behind progressive disclosure');

$legacyProfileDiff = $uiPresenter->extractDiffFiles(['items' => [[
  'type' => 'profile-fields',
  'label' => 'Profile Fields',
  'files' => [[
    'path' => 'profiles/fields/summary_overlay__phone__Home-Phone--7a8859f54f.yml',
    'status' => 'changed',
    'changes' => [],
  ]],
]]]);
$legacyProfileFile = $legacyProfileDiff[0] ?? [];
$assert(($legacyProfileFile['display_title'] ?? '') === 'Profile Field "Home Phone" in "Summary Overlay"', 'Profile Field filename fallback must derive a semantic title without a config key');
$assert(strpos((string) ($legacyProfileFile['display_title'] ?? ''), '7a8859f54f') === FALSE, 'Profile Field filename fallback must strip the technical collision hash');

$syncTemplate = (string) file_get_contents(__DIR__ . '/../../templates/CRM/Configmanager/Page/Partials/Sync.tpl');
$importTemplate = (string) file_get_contents(__DIR__ . '/../../templates/CRM/Configmanager/Page/Partials/Import.tpl');
$exportTemplate = (string) file_get_contents(__DIR__ . '/../../templates/CRM/Configmanager/Page/Partials/Export.tpl');
$settingsTemplate = (string) file_get_contents(__DIR__ . '/../../templates/CRM/Configmanager/Page/Partials/Settings.tpl');
$providerBrowserSource = (string) file_get_contents(__DIR__ . '/../../js/settings-provider-browser.js');
$assert(strpos($syncTemplate, '{if $file.show_inline_path}<span class="civicfg-muted"><code>{$file.path|escape}</code></span>{/if}') !== FALSE, 'Synchronize must honor Profile Field progressive path disclosure');
$assert(strpos($importTemplate, '{if $item.show_inline_path}<code class="civicfg-file-code">{$item.path|escape}</code>{else}<strong>{$item.display_title|escape}</strong>{/if}') !== FALSE, 'Import preview must use the semantic Profile Field title when its path is technical');
$assert(strpos($exportTemplate, '{if $file.show_inline_path}<code class="civicfg-file-code">{$file.path|escape}</code>{else}<strong>{$file.display_title|escape}</strong>{/if}') !== FALSE, 'Export preview must use the semantic Profile Field title when its path is technical');
$assert(substr_count($importTemplate, 'name="import_plan_id"') >= 2, 'Import actions must submit the opaque reviewed-plan ID from both action areas');
$assert(strpos($importTemplate, 'Reviewed preview protected.') !== FALSE, 'Import must explain that the reviewed preview is protected against stale state');
$assert(strpos($importTemplate, 'If Saved Config, Current CiviCRM, scope, or provider capability changes before apply') !== FALSE, 'Import must explain the stale-plan safety boundary in user language');

/**
 * Supplemental source contract for provider-discovery progressive disclosure.
 * Behavioral classification/selection is independently exercised by
 * provider-browser-test.js; Playwright covers the rendered user boundary.
 */
$assert(strpos($settingsTemplate, 'data-civicfg-provider-discovery') !== FALSE, 'Settings must provide progressive disclosure for detected providers that are not configuration-type cards');
$assert(strpos($settingsTemplate, 'detecting a provider never grants automatic write access') !== FALSE, 'Settings must state that discovery does not grant management authority');
$assert(strpos($settingsTemplate, 'Every registered configuration type is listed below') === FALSE, 'Settings must not imply that every discovered provider becomes a configurable type');
$assert(strpos($providerBrowserSource, 'providerInventoryStatus') !== FALSE, 'provider browser must use a dedicated inventory-status formatter for provider/type count wording');


echo "alpha68 UI behavior OK ({$checks} checks)\n";
