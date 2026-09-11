'use strict';

const assert = require('assert');
const browser = require('../../js/settings-provider-browser.js');

// Requirement: provider grouping must be deterministic and fail closed.
// Failure mode: an unavailable or rejected provider appears as manageable core/contrib/custom configuration.
assert.strictEqual(browser.classifyProvider({capability: 'unavailable', admitted: false, registration_source: 'core_handler'}), 'unavailable');
assert.strictEqual(browser.classifyProvider({capability: 'export_only', admitted: true, registration_source: 'core_handler'}), 'limited');
assert.strictEqual(browser.classifyProvider({capability: 'full', admitted: true, registration_source: 'core_handler', owner: 'civi.config.manager'}), 'core');
assert.strictEqual(browser.classifyProvider({capability: 'full', admitted: true, registration_source: 'entity_definition_hook', owner: 'org.example.feature'}), 'contributed');
assert.strictEqual(browser.classifyProvider({capability: 'full', admitted: true, registration_source: 'config_types_hook', owner: 'hook-provider'}), 'custom');

// Requirement: search must match operator-facing identity, not technical metadata alone.
// Failure mode: an administrator cannot find a provider by label, type, or owner.
const provider = {
  label: 'Example Rules',
  type: 'example-rules',
  owner: 'org.example.rules',
  registration_source: 'entity_definition_hook',
  capability: 'full',
  capability_reason: 'Portable provider.',
};
assert.strictEqual(browser.matchesSearch(provider, 'rules'), true);
assert.strictEqual(browser.matchesSearch(provider, 'example-rules'), true);
assert.strictEqual(browser.matchesSearch(provider, 'org.example'), true);
assert.strictEqual(browser.matchesSearch(provider, 'payment processor'), false);

console.log('provider browser behavior OK (9 checks)');

// Requirement: the provider count shown by inventory must not imply that every
// discovered API provider is a separately configurable Settings type.
// Failure mode: detected extension providers disappear after hydration, leaving
// administrators with a large inventory count but no explanation for the gap.
const detectedProviders = [
  {provider_key: 'handler:extensions', type: 'extensions', label: 'Extensions', registration_source: 'core_handler', owner: 'civi.config.manager', admitted: true, capability: 'full'},
  {provider_key: 'extensions:de.systopia.sqltasks:api4:SqlTask', type: 'extensions:de.systopia.sqltasks:api4:sqltask', label: 'Sql Task', registration_source: 'automatic_extension_api', owner: 'de.systopia.sqltasks', admitted: true, capability: 'full', capability_reason_code: 'reviewed_adapter'},
  {provider_key: 'extensions:org.example.createupdate:api4:ExampleConfig', type: 'extensions:org.example.createupdate:api4:exampleconfig', label: 'Create Update Config', registration_source: 'automatic_extension_api', owner: 'org.example.createupdate', admitted: true, capability: 'managed_no_delete', capability_reason_code: 'portable_identity_and_field_policy'},
  {provider_key: 'extensions:org.example.readonly:api4:ExampleConfig', type: 'extensions:org.example.readonly:api4:exampleconfig', label: 'Example Config', registration_source: 'automatic_extension_api', owner: 'org.example.readonly', admitted: true, capability: 'export_only', capability_reason_code: 'portable_identity_and_field_policy'},
  {provider_key: 'extensions:org.example.ambiguous:api4:ExampleRule', type: 'extensions:org.example.ambiguous:api4:examplerule', label: 'Example Rule', registration_source: 'automatic_extension_api', owner: 'org.example.ambiguous', admitted: true, capability: 'review_only', capability_reason_code: 'ambiguous_identity'},
  {provider_key: 'extensions:org.civicrm.afform:api4:Afform', type: 'extensions:org.civicrm.afform:api4:afform', label: 'Afform', registration_source: 'automatic_extension_api', owner: 'org.civicrm.afform', admitted: false, capability: 'unsupported', capability_reason_code: 'dedicated_or_excluded_extension'},
  {provider_key: 'extensions:org.example.business:api4:ParticipantThing', type: 'extensions:org.example.business:api4:participantthing', label: 'Participant Thing', registration_source: 'automatic_extension_api', owner: 'org.example.business', admitted: false, capability: 'unsupported', capability_reason_code: 'business_data_marker'},
  {provider_key: 'handler:missing-feature', type: 'missing-feature', label: 'Missing Feature', registration_source: 'config_types_hook', owner: 'org.example.missing', admitted: false, capability: 'unavailable', capability_reason_code: 'handler_unavailable'},
  {provider_key: 'rejected:config_types_hook:broken:0', type: 'broken', label: 'Broken', registration_source: 'config_types_hook', owner: 'hook-provider', admitted: false, capability: 'unavailable', capability_reason_code: 'registry_duplicate_handler_type'},
];

assert.strictEqual(browser.classifyDetectedProvider(detectedProviders[1]), 'managed', 'admitted extension provider should explain that it is managed through Extensions');
assert.strictEqual(browser.classifyDetectedProvider(detectedProviders[2]), 'create_update', 'provider with delete disabled must be presented as create/update-only');
assert.strictEqual(browser.classifyDetectedProvider(detectedProviders[3]), 'export_only', 'read-only provider should remain export/compare-only');
assert.strictEqual(browser.classifyDetectedProvider(detectedProviders[4]), 'review_only', 'explicit review-only provider should remain review-only');
assert.strictEqual(browser.classifyDetectedProvider(detectedProviders[5]), 'not_separate', 'dedicated/excluded provider should be explained as not offered separately');
assert.strictEqual(browser.classifyDetectedProvider(detectedProviders[6]), 'unsupported', 'business-data candidate must remain unsupported automatically');
assert.strictEqual(browser.classifyDetectedProvider(detectedProviders[7]), 'unavailable', 'unavailable provider must remain unavailable');

const supplemental = browser.selectDetectedProviders(detectedProviders, ['extensions', 'missing-feature']);
assert.deepStrictEqual(
  supplemental.map((provider) => provider.provider_key),
  [
    'extensions:de.systopia.sqltasks:api4:SqlTask',
    'extensions:org.civicrm.afform:api4:Afform',
    'extensions:org.example.ambiguous:api4:ExampleRule',
    'extensions:org.example.business:api4:ParticipantThing',
    'extensions:org.example.createupdate:api4:ExampleConfig',
    'extensions:org.example.readonly:api4:ExampleConfig',
  ],
  'only non-card, non-rejected detected providers should appear in progressive disclosure'
);

const detectedSummary = browser.summarizeDetectedProviders(supplemental);
assert.deepStrictEqual(detectedSummary, {
  total: 6,
  managed: 1,
  create_update: 1,
  export_only: 1,
  review_only: 1,
  not_separate: 1,
  unsupported: 1,
  unavailable: 0,
});

assert.strictEqual(
  browser.detectedProviderReason(detectedProviders[6]),
  'This provider looks like business or transactional data, so automatic configuration management is blocked.',
  'provider reasons should be client-facing instead of exposing admission internals by default'
);
assert.strictEqual(
  browser.detectedProviderReason(detectedProviders[5]),
  'This provider is not offered as a separate configuration type because it is handled by a dedicated type or intentionally excluded from generic management.',
  'dedicated providers should explain why they are detected but not separately configurable'
);

const unknownReason = {provider_key: 'extensions:org.example.unknown:api4:Unknown', type: 'extensions:org.example.unknown:api4:unknown', label: 'Unknown', admitted: false, capability: 'unsupported', capability_reason_code: 'new_internal_reason', capability_reason: 'RAW_INTERNAL_PROVIDER_DETAIL'};
assert.strictEqual(
  browser.detectedProviderReason(unknownReason),
  'This provider was detected, but automatic management is blocked until its portability and safety are proven.',
  'unknown provider reason codes must not expose raw technical diagnostics in the normal explanation'
);
assert.strictEqual(
  browser.providerInventoryStatus(191, 18, 171, 2),
  'Provider safety details loaded: 191 provider entries detected. 18 configuration types available in Settings. 171 additional providers explained under Other detected providers. 2 registrations rejected.',
  'inventory status must distinguish detected provider entries from configurable Settings types'
);

console.log('provider discovery visibility OK (13 checks)');
