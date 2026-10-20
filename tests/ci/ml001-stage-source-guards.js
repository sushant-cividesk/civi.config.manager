/* ML-001 real-runtime read-only fixture guard. */
'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const {spawnSync} = require('child_process');
const root = path.resolve(__dirname, '../..');
const file = path.join(root, 'tests/manual/ml001-stage-source-locale-smoke.php');
const cases = [
  ['', /Explicit ML001_SOURCE_LOCALE_TEST=YES_STAGE_ONLY opt-in is required/],
  ['YES_STAGE_ONLY', /restricted to the DDEV STAGE Drupal webroot/],
];
for (const [value, message] of cases) {
  const result = spawnSync('php', [file], {
    cwd: root, env: {...process.env, ML001_SOURCE_LOCALE_TEST: value}, encoding: 'utf8',
  });
  assert.notStrictEqual(result.status, 0);
  assert.match(result.stderr + result.stdout, message);
}
const source = fs.readFileSync(file, 'utf8');
assert(source.includes("removeListener('civi.afform.get'"), 'fixture cleanup must be present');
assert(source.includes('$before->apply()'), 'restore original locale in finally');
for (const forbidden of [
  '->import(', '->create(', '->update(', '->delete(', '->save(',
  'file_put_contents(', 'unlink(', '->setRecords(',
]) {
  assert(!source.includes(forbidden), 'manual stage validation must not write: ' + forbidden);
}
console.log('ML-001 source-locale STAGE proof guard: PASS (opt-in, root, no-writes).');
