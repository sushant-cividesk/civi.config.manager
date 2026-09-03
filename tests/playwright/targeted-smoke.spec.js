const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;

const baseUrl = new URL(process.env.CIVICFG_BASE_URL);
const canonicalBaseUrl = new URL(process.env.CIVICFG_CANONICAL_BASE_URL || baseUrl.href);
const blockedRequests = new WeakMap();

async function installNetworkGuard(page) {
  const blocked = [];
  blockedRequests.set(page, blocked);
  await page.route('**/*', async route => {
    const requestUrl = new URL(route.request().url());
    if (requestUrl.origin === canonicalBaseUrl.origin && canonicalBaseUrl.origin !== baseUrl.origin) {
      requestUrl.protocol = baseUrl.protocol;
      requestUrl.host = baseUrl.host;
      await route.continue({ url: requestUrl.toString() });
      return;
    }
    if ((requestUrl.protocol === 'http:' || requestUrl.protocol === 'https:') && requestUrl.origin !== baseUrl.origin) {
      blocked.push(route.request().url());
      await route.abort('blockedbyclient');
      return;
    }
    await route.continue();
  });
}

async function login(page) {
  await page.goto('/civicrm/login', { waitUntil: 'domcontentloaded' });
  const password = page.locator('input[type="password"]').first();
  if (await password.count()) {
    const username = page.locator('input[name="name"], input[name="username"], input[type="email"], #edit-name').first();
    await expect(username).toBeVisible();
    await expect(password).toBeVisible();
    await username.fill(process.env.CIVICRM_ADMIN_USER || 'admin');
    await password.fill(process.env.CIVICRM_ADMIN_PASS || '');
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('domcontentloaded');
  }
}

test.describe('Configuration Manager targeted DEV smoke', () => {
  test.beforeEach(async ({ page }) => {
    await installNetworkGuard(page);
    await login(page);
  });

  test.afterEach(async ({ page }) => {
    expect(blockedRequests.get(page) || [], 'Targeted UI smoke attempted external network requests.').toEqual([]);
  });

  test('renders core navigation and provider safety without unsafe bypass', async ({ page }) => {
    const consoleErrors = [];
    const pageErrors = [];
    page.on('console', message => {
      if (message.type() === 'error') consoleErrors.push(message.text());
    });
    page.on('pageerror', error => pageErrors.push(error.message));

    await page.goto('/civicrm/admin/config-manager?reset=1&op=settings', { waitUntil: 'domcontentloaded' });
    const block = page.locator('.crm-configmanager-block');
    await expect(block).toBeVisible();
    await expect(page.getByText('What should Configuration Manager manage?', { exact: true })).toBeVisible();
    await expect(block.getByRole('link', { name: 'Synchronize', exact: true })).toBeVisible();
    await expect(block.getByRole('link', { name: 'Import', exact: true })).toBeVisible();
    await expect(block.getByRole('link', { name: 'Export', exact: true })).toBeVisible();
    await expect(block.getByRole('link', { name: 'Settings', exact: true })).toBeVisible();

    const firstSafety = page.locator('details.civicfg-provider-safety').first();
    await expect(firstSafety).toBeVisible();
    await expect(page.getByText(/Continue anyway/i)).toHaveCount(0);

    const results = await new AxeBuilder({ page })
      .include('.crm-configmanager-block')
      .withTags(['wcag2a', 'wcag2aa'])
      .analyze();
    const blocking = results.violations.filter(violation => violation.impact === 'serious' || violation.impact === 'critical');
    expect(blocking).toEqual([]);
    expect(consoleErrors, `Browser console errors: ${consoleErrors.join('\n')}`).toEqual([]);
    expect(pageErrors, `Uncaught page errors: ${pageErrors.join('\n')}`).toEqual([]);
  });
});
