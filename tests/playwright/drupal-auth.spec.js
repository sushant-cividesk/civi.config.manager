const http = require('node:http');
const { once } = require('node:events');
const { test, expect } = require('@playwright/test');
const { loginToConfigurationManager } = require('./helpers/drupal-auth');

function loginPage(error = '') {
  return `<!doctype html><html><head><title>Log in</title></head><body>
    <h1>Log in</h1>
    ${error ? `<div class="messages--error">${error}</div>` : ''}
    <form id="user-login-form" method="post" action="/user/login">
      <input id="edit-name" name="name" />
      <input id="edit-pass" name="pass" type="password" />
      <button id="edit-submit" type="submit">Log in</button>
    </form>
  </body></html>`;
}

async function createDrupalMock({ allowConfigManager = true } = {}) {
  const server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://127.0.0.1');
    const authenticated = /(?:^|;\s*)SESSmock=authenticated(?:;|$)/.test(req.headers.cookie || '');

    if (req.method === 'GET' && url.pathname === '/user/reset/1/123/hash/login') {
      res.writeHead(302, {
        Location: '/user',
        'Set-Cookie': 'SESSmock=authenticated; Path=/; HttpOnly; SameSite=Lax',
      });
      res.end();
      return;
    }

    if (req.method === 'GET' && url.pathname === '/user/login') {
      if (authenticated) {
        res.writeHead(302, { Location: '/user' });
        res.end();
        return;
      }
      res.writeHead(200, { 'Content-Type': 'text/html' });
      res.end(loginPage());
      return;
    }

    if (req.method === 'POST' && url.pathname === '/user/login') {
      let body = '';
      req.on('data', chunk => { body += chunk; });
      req.on('end', () => {
        const fields = new URLSearchParams(body);
        if (fields.get('name') === 'admin' && fields.get('pass') === 'admin') {
          res.writeHead(302, {
            Location: '/user',
            'Set-Cookie': 'SESSmock=authenticated; Path=/; HttpOnly; SameSite=Lax',
          });
          res.end();
          return;
        }
        res.writeHead(200, { 'Content-Type': 'text/html' });
        res.end(loginPage('Unrecognized username or password.'));
      });
      return;
    }

    if (url.pathname === '/user') {
      if (!authenticated) {
        res.writeHead(302, { Location: '/user/login' });
        res.end();
        return;
      }
      res.writeHead(200, { 'Content-Type': 'text/html' });
      res.end('<!doctype html><html><head><title>Account</title></head><body><h1>Account</h1></body></html>');
      return;
    }

    if (url.pathname === '/civicrm/admin/config-manager') {
      if (!authenticated || !allowConfigManager) {
        res.writeHead(403, { 'Content-Type': 'text/html' });
        res.end('<!doctype html><html><head><title>Access denied</title></head><body><h1>Access denied</h1></body></html>');
        return;
      }
      res.writeHead(200, { 'Content-Type': 'text/html' });
      res.end('<!doctype html><html><head><title>Configuration Manager</title></head><body><h1>Configuration Manager</h1><div class="crm-configmanager-block">Settings</div></body></html>');
      return;
    }

    res.writeHead(404, { 'Content-Type': 'text/plain' });
    res.end('Not found');
  });

  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  const address = server.address();
  return {
    baseUrl: new URL(`http://127.0.0.1:${address.port}`),
    close: () => new Promise(resolve => server.close(resolve)),
  };
}

test.describe('Drupal targeted authentication helper', () => {
  test('proves Drupal session before Configuration Manager access', async ({ page }) => {
    const mock = await createDrupalMock();
    try {
      await loginToConfigurationManager(page, {
        baseUrl: mock.baseUrl,
        username: 'admin',
        password: 'admin',
      });
      await expect(page.locator('.crm-configmanager-block')).toBeVisible();
      const cookies = await page.context().cookies(mock.baseUrl.href);
      expect(cookies.some(cookie => cookie.name === 'SESSmock')).toBe(true);
    }
    finally {
      await mock.close();
    }
  });

  test('uses a Drupal one-time login URL without requiring the account password', async ({ page }) => {
    const mock = await createDrupalMock();
    try {
      await loginToConfigurationManager(page, {
        baseUrl: mock.baseUrl,
        username: 'admin',
        loginUrl: new URL('/user/reset/1/123/hash/login', mock.baseUrl).href,
      });
      await expect(page.locator('.crm-configmanager-block')).toBeVisible();
      const cookies = await page.context().cookies(mock.baseUrl.href);
      expect(cookies.some(cookie => cookie.name === 'SESSmock')).toBe(true);
    }
    finally {
      await mock.close();
    }
  });

  test('reports a Drupal credential failure before CiviCRM access', async ({ page }) => {
    const mock = await createDrupalMock();
    try {
      await expect(loginToConfigurationManager(page, {
        baseUrl: mock.baseUrl,
        username: 'admin',
        password: 'wrong',
      })).rejects.toThrow(/Drupal authentication failed.*Unrecognized username or password/i);
    }
    finally {
      await mock.close();
    }
  });

  test('distinguishes confirmed authentication from missing Configuration Manager access', async ({ page }) => {
    const mock = await createDrupalMock({ allowConfigManager: false });
    try {
      await expect(loginToConfigurationManager(page, {
        baseUrl: mock.baseUrl,
        username: 'admin',
        password: 'admin',
      })).rejects.toThrow(/authentication succeeded.*does not have access to Configuration Manager Settings/i);
    }
    finally {
      await mock.close();
    }
  });
});
