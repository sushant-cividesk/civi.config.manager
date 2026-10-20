/* CLI safety gate: invalid options never reach a CiviCRM invocation. */
'use strict';
const assert = require('assert');
const fs = require('fs');
const os = require('os');
const path = require('path');
const {spawnSync} = require('child_process');

const root = path.resolve(__dirname, '../..');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'civicfg-cli-'));
const fakeCv = path.join(tmp, 'cv');
fs.writeFileSync(fakeCv, '#!/bin/sh\nprintf "%s\\n" "$@"\n', {mode: 0o700});
const env = {...process.env, CIVICFG_CV: fakeCv};
const run = (...args) => spawnSync('bash', [path.join(root, 'bin/civicfg'), ...args], {cwd: root, env, encoding: 'utf8'});
try {
  const blocked = [
    [['import', '--write'], /Import --write is not supported/],
    [['import', '--write', '--plan', 'a'.repeat(48)], /Import --write is not supported/],
    [['import', '--yes', '--dry-run', '--plan', 'a'.repeat(48)], /cannot be combined with --dry-run/],
    [['import', '--dry-run', '--yes', '--plan', 'a'.repeat(48)], /cannot be combined with --dry-run/],
    [['import', '--yes'], /requires --plan/],
    [['import', '--yes', '--plan', '../invalid'], /48-character plan_id/],
    [['export', '--yes'], /Export uses --write/],
    [['export', '--write', '--dry-run'], /cannot combine --write and --dry-run/],
    [['diff', '--write'], /only valid for Export or Import/],
    [['watch', '--yes'], /only valid for Export or Import/],
    [['validate', '--dry-run'], /only valid for Export or Import/],
  ];
  for (const [args, reason] of blocked) {
    const res = run(...args);
    assert.notStrictEqual(res.status, 0, args.join(' ') + ' must fail');
    assert.match(res.stderr, reason);
    assert.doesNotMatch(res.stdout, /api4 ConfigManager/);
  }
  const permitted = [
    [['import'], /ConfigManager.import/],
    [['import', '--dry-run', '--json'], /ConfigManager.import/],
    [['import', '--yes', '--plan', 'a'.repeat(48)], /planId=a{48}/],
    [['export', '--write'], /ConfigManager.export/],
    [['diff', '--type', 'tags'], /ConfigManager.diff/],
  ];
  for (const [args, target] of permitted) {
    const res = run(...args);
    assert.strictEqual(res.status, 0, args.join(' ') + ': ' + res.stderr);
    assert.match(res.stdout, target);
  }
  console.log('Bundle 5 CLI safety and existing valid operations OK (' + (blocked.length + permitted.length) + ' checks).');
}
finally {
  fs.rmSync(tmp, {recursive: true, force: true});
}
