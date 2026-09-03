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
