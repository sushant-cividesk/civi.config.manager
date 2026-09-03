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

async function pageDiagnostic(page) {
  const heading = await page.locator('h1').first().textContent().catch(() => '');
  return `url=${page.url()} title=${await page.title()} h1=${(heading || '').trim()}`;
}

async function login(page) {
  const settingsPath = '/civicrm/admin/config-manager?reset=1&op=settings';
  await page.goto(settingsPath, { waitUntil: 'domcontentloaded' });
  if (await page.locator('.crm-configmanager-block').count()) return;

  const loginCandidates = [page.url(), new URL('/user/login', baseUrl).href, new URL('/civicrm/login', baseUrl).href];
  let foundLogin = false;
  for (const candidate of [...new Set(loginCandidates)]) {
    if (page.url() !== candidate) await page.goto(candidate, { waitUntil: 'domcontentloaded' });
    const password = page.locator('input[type="password"]:visible').first();
    if (!(await password.count())) continue;
    const loginForm = password.locator('xpath=ancestor::form[1]');
    const username = loginForm.locator('input[name="name"], input[name="username"], input[type="email"], #edit-name').first();
    const submit = loginForm.locator('button[type="submit"]:visible, input[type="submit"]:visible').first();
    await expect(username, `Login username field missing: ${await pageDiagnostic(page)}`).toBeVisible();
    await expect(password, `Login password field missing: ${await pageDiagnostic(page)}`).toBeVisible();
    await expect(submit, `Login submit control missing: ${await pageDiagnostic(page)}`).toBeVisible();
    await username.fill(process.env.CIVICRM_ADMIN_USER || 'admin');
    await password.fill(process.env.CIVICRM_ADMIN_PASS || '');
    await submit.click();
    await page.waitForLoadState('domcontentloaded');
    foundLogin = true;
    break;
  }

  if (!foundLogin) {
    throw new Error(`Unable to find an authenticated session or supported login form. ${await pageDiagnostic(page)}`);
  }

  await page.goto(settingsPath, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.crm-configmanager-block'), `Authentication/access did not reach Configuration Manager Settings. ${await pageDiagnostic(page)}`).toBeVisible();
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

    const providerSearch = page.locator('[data-civicfg-provider-search]');
    const providerFilter = page.locator('[data-civicfg-provider-group-filter]');
    const providerState = page.locator('[data-civicfg-provider-inventory-state]');
    await expect(providerSearch).toBeVisible();
    await expect(providerFilter).toBeVisible();
    await expect(providerState).toContainText(/Provider safety details loaded for/i);
    await expect(page.locator('[data-civicfg-provider-group]:not([hidden])').first()).toBeVisible();

    await providerSearch.fill('configuration-type-that-does-not-exist');
    await expect(page.locator('[data-civicfg-provider-empty]')).toBeVisible();
    await providerSearch.fill('');

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
