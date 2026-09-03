const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const root = path.resolve(__dirname, '../..');
const artifactDir = process.env.QA_ARTIFACT_DIR || path.join(root, 'tests/ci/artifacts');
const statePath = path.join(artifactDir, 'ui-fixture-state.json');
const hasFixture = fs.existsSync(statePath);
const hasTarget = Boolean(process.env.CIVICFG_BASE_URL);

let spec;
if (hasFixture) {
  spec = 'tests/playwright/config-manager.spec.js';
  console.log('UI QA mode: disposable fixture suite');
}
else if (hasTarget) {
  spec = 'tests/playwright/targeted-smoke.spec.js';
  console.log('UI QA mode: read-only targeted-site smoke');
}
else {
  console.error('UI QA requires either tests/ci/artifacts/ui-fixture-state.json or CIVICFG_BASE_URL.');
  process.exit(2);
}

const executable = process.platform === 'win32' ? 'npx.cmd' : 'npx';
const result = spawnSync(executable, ['playwright', 'test', spec], {
  cwd: root,
  env: process.env,
  stdio: 'inherit',
});

if (result.error) {
  console.error(result.error.message);
  process.exit(2);
}
process.exit(result.status === null ? 2 : result.status);
