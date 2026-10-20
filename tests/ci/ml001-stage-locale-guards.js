/* ML-001 diagnostic must not bootstrap or touch CiviCRM without STAGE guard. */
'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const {spawnSync} = require('child_process');
const root = path.resolve(__dirname, '../..');
const file = path.join(root, 'tests/manual/ml001-stage-locale-probe.php');
for (const [value, message] of [
  ['', /Explicit ML001_STAGE_PROBE=YES_STAGE_ONLY opt-in is required/],
  ['YES_STAGE_ONLY', /restricted to the DDEV STAGE Drupal webroot/],
]) {
  const outcome = spawnSync('php', [file], {
    cwd: root,
    env: {...process.env, ML001_STAGE_PROBE: value},
    encoding: 'utf8',
  });
  assert.notStrictEqual(outcome.status, 0);
  assert.match(outcome.stderr + outcome.stdout, message);
}
const source = fs.readFileSync(file, 'utf8');
assert.match(source, /removeListener\('civi\.afform\.get'/);
assert.match(source, /setLanguage\(\$language\)/);
for (const prohibited of [
  '->import(', '->export(', '->create(', '->update(', '->delete(',
  'file_put_contents(', 'unlink(', '->save(', '->setRecords(',
]) {
  assert(!source.includes(prohibited), 'read-only diagnostic must not call ' + prohibited);
}
console.log('ML-001 guarded STAGE locale probe: no-opt-in, wrong-root and no-write checks PASS.');
