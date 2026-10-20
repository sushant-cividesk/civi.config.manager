/* No opt-in or wrong webroot must halt before CiviCRM bootstrap. */
'use strict';
const assert = require('assert');
const path = require('path');
const {spawnSync} = require('child_process');
const root = path.resolve(__dirname, '../..');
const file = path.join(root, 'tests/manual/bundle6-stage-readonly-profile.php');
const cases = [
  ['', /Explicit CIVICFG_BUNDLE6_PROFILE=YES_STAGE_ONLY opt-in is required/],
  ['YES_STAGE_ONLY', /restricted to the DDEV STAGE Drupal webroot/],
];
for (const [value, expected] of cases) {
  const result = spawnSync('php', [file], {
    cwd: root,
    env: {...process.env, CIVICFG_BUNDLE6_PROFILE: value},
    encoding: 'utf8',
  });
  assert.notStrictEqual(result.status, 0);
  assert.match(result.stdout + result.stderr, expected);
}
const source = require('fs').readFileSync(file, 'utf8');
for (const name of ['getAllHandlers', 'getScopeTypeOptions', 'getProviderInventory']) {
  assert(source.includes(name), 'must measure ' + name);
}
for (const forbidden of ['->import(', '->export(', '->saveScopePolicies(', '->delete(', '->set(']) {
  assert(!source.includes(forbidden), 'no write actions in read-only profiler: ' + forbidden);
}
console.log('Bundle 6 profiling environment and no-write source guards OK.');
