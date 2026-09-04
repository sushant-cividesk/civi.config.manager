const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { extractLoginUrl, resolveDrupalLoginUrl } = require('./drupal-login-url');

const baseUrl = new URL('https://dcivi-stage.civicrm.ddev.site/');
assert.equal(
  extractLoginUrl('/user/reset/1/123/hash/login', baseUrl),
  'https://dcivi-stage.civicrm.ddev.site/user/reset/1/123/hash/login',
  'relative Drush login URLs must resolve against the target site',
);

const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'civicfg-drush-'));
const root = path.join(tmp, 'web');
fs.mkdirSync(root, { recursive: true });
const fakeDrush = path.join(tmp, 'drush');
fs.writeFileSync(fakeDrush, `#!/bin/sh
case " $* " in
  *" --name=admin "*) ;;
  *) echo 'missing --name=admin' >&2; exit 9 ;;
esac
case " $* " in
  *" --uri=https://dcivi-stage.civicrm.ddev.site/ "*) ;;
  *) echo 'missing target --uri' >&2; exit 10 ;;
esac
printf '%s\n' 'https://dcivi-stage.civicrm.ddev.site/user/reset/1/123/hash/login'
`);
fs.chmodSync(fakeDrush, 0o755);

const resolved = resolveDrupalLoginUrl({
  baseUrl,
  username: 'admin',
  drupalRoot: root,
  drush: fakeDrush,
});
assert.equal(
  resolved,
  'https://dcivi-stage.civicrm.ddev.site/user/reset/1/123/hash/login',
  'local Drush login discovery must return the generated target login URL',
);

fs.rmSync(tmp, { recursive: true, force: true });
console.log('Drupal login URL resolver OK (2 checks).');
