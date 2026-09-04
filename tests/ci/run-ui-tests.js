const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { resolveDrupalLoginUrl } = require('./drupal-login-url');

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

const localPlaywright = path.join(root, 'node_modules', '.bin', process.platform === 'win32' ? 'playwright.cmd' : 'playwright');
if (!fs.existsSync(localPlaywright)) {
  console.error('Browser QA dependencies are not installed. Run `npm install` in the extension directory, then retry.');
  process.exit(2);
}
const { chromium } = require('@playwright/test');
const chromiumPath = chromium.executablePath();
if (!chromiumPath || !fs.existsSync(chromiumPath)) {
  console.error('Playwright Chromium is not installed for this environment. Run `npx playwright install chromium` in the extension directory, then retry.');
  process.exit(2);
}
const executable = localPlaywright;
const childEnv = { ...process.env };
if (!hasFixture && hasTarget && !childEnv.CIVICFG_DRUPAL_LOGIN_URL) {
  const baseUrl = new URL(childEnv.CIVICFG_BASE_URL);
  const username = childEnv.CIVICRM_ADMIN_USER || 'admin';
  if (baseUrl.hostname.endsWith('.ddev.site')) {
    const localLoginUrl = resolveDrupalLoginUrl({ baseUrl, username });
    if (localLoginUrl) {
      childEnv.CIVICFG_DRUPAL_LOGIN_URL = localLoginUrl;
      console.log(`Drupal QA authentication: local Drush one-time login for ${username} (password unchanged).`);
    }
    else if (!childEnv.CIVICRM_ADMIN_PASS) {
      console.error('Could not generate a local Drupal one-time login URL and CIVICRM_ADMIN_PASS is not set.');
      console.error('For Buildkit/DDEV, run inside `ddev ssh`, or set CIVICFG_DRUPAL_ROOT to the target Drupal web root.');
      process.exit(2);
    }
    else {
      console.log('Drupal QA authentication: local Drush login discovery unavailable; falling back to CIVICRM_ADMIN_PASS.');
    }
  }
}
if (!hasFixture && hasTarget && !Object.prototype.hasOwnProperty.call(childEnv, 'CIVICFG_IGNORE_HTTPS_ERRORS')) {
  // Local DDEV/Buildkit sites commonly use a developer CA that Chromium does
  // not trust inside the web container. Targeted smoke is read-only and may
  // opt into certificate tolerance without weakening disposable CI.
  childEnv.CIVICFG_IGNORE_HTTPS_ERRORS = '1';
}

const result = spawnSync(executable, ['test', spec], {
  cwd: root,
  env: childEnv,
  stdio: 'inherit',
});

if (result.error) {
  console.error(result.error.message);
  process.exit(2);
}
process.exit(result.status === null ? 2 : result.status);
