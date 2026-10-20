/* Guarded Bundle 3 STAGE test must refuse unapproved and wrong-root execution. */
'use strict';
const assert = require('assert');
const path = require('path');
const {spawnSync} = require('child_process');
const script = path.join(__dirname, '../manual/bundle3-provider-disposable-crud.php');
const root = path.resolve(__dirname, '../..');
const unapproved = spawnSync('php', [script], {
  cwd: root,
  env: {...process.env, CIVICFG_BUNDLE3_DISPOSABLE_CRUD: ''},
  encoding: 'utf8',
});
assert.notStrictEqual(unapproved.status, 0);
assert.match(unapproved.stderr + unapproved.stdout, /Explicit CIVICFG_BUNDLE3_DISPOSABLE_CRUD=YES_STAGE_ONLY opt-in is required/);
const wrongRoot = spawnSync('php', [script], {
  cwd: root,
  env: {...process.env, CIVICFG_BUNDLE3_DISPOSABLE_CRUD: 'YES_STAGE_ONLY'},
  encoding: 'utf8',
});
assert.notStrictEqual(wrongRoot.status, 0);
assert.match(wrongRoot.stderr + wrongRoot.stdout, /restricted to the DDEV STAGE Drupal webroot/);
console.log('Bundle 3 disposable STAGE guards OK (no CiviCRM/bootstrap writes).');
