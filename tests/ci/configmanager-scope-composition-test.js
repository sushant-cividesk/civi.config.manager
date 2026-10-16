/* Dependency-free UI composition smoke for the scope Settings module. */
'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const root = path.resolve(__dirname, '../..');
const loader = fs.readFileSync(path.join(root, 'Civi/ConfigManager/UI/AssetLoader.php'), 'utf8');
const scopeEntry = "'js/configmanager-scope.js'";
const mainEntry = "'js/configmanager.js'";
assert(loader.indexOf(scopeEntry) >= 0, 'scope module must be registered');
assert(loader.indexOf(scopeEntry) < loader.indexOf(mainEntry), 'scope must load before the shared UI entry point');

function harness(includeScope) {
  const selected = [];
  const events = [];
  const errors = [];
  const document = {
    readyState: 'complete',
    querySelectorAll(selector) { selected.push(selector); return []; },
    querySelector() { return null; },
    getElementById() { return null; },
    addEventListener(type) { events.push(type); },
  };
  const window = {
    location: { href: 'https://example.org/civicrm/admin/config-manager' },
    setTimeout() {},
    console: { error(message) { errors.push(message); } },
  };
  const context = vm.createContext({window, document, console, URL, fetch() { throw new Error('Unexpected provider read'); }});
  if (includeScope) {
    vm.runInContext(fs.readFileSync(path.join(root, 'js/configmanager-scope.js'), 'utf8'), context, {filename:'configmanager-scope.js'});
    assert.strictEqual(typeof window.civicfgInitScopeSettings, 'function');
    const original = window.civicfgInitScopeSettings;
    window.civicfgInitScopeSettings = function(...args) {
      assert.strictEqual(args.length, 2, 'URL and JSON helpers must be passed to Settings');
      selected.push('__scope_module_called__');
      return original(...args);
    };
  }
  vm.runInContext(fs.readFileSync(path.join(root, 'js/configmanager.js'), 'utf8'), context, {filename:'configmanager.js'});
  return {selected, events, errors};
}

const loaded = harness(true);
assert(loaded.selected.includes('__scope_module_called__'), 'scope initializer did not execute');
assert(loaded.selected.includes('[data-civicfg-scope-row]'), 'existing scope controls were not initialized');
assert(loaded.selected.includes('.crm-configmanager-block [data-civicfg-open]'), 'existing diff modal controls were lost');
assert(loaded.selected.includes('.crm-configmanager-block form'), 'existing operation forms were not initialized');
assert(loaded.events.includes('keydown'), 'existing modal keyboard behavior was lost');
assert.strictEqual(loaded.errors.length, 0, 'unexpected Settings initialization error');

const absent = harness(false);
assert(absent.errors.some(message => message.includes('Settings controls were not loaded')), 'missing dependency must be visible');
assert(absent.selected.includes('.crm-configmanager-block form'), 'a missing Settings module must not disable other forms');

console.log('Configuration Manager split JS OK (registration order, Settings initialization, fallback, other UI flows).');
