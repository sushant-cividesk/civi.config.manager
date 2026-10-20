const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { loginToConfigurationManager } = require('./helpers/drupal-auth');
const { resolveDrupalLoginUrl } = require('../ci/drupal-login-url');

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



test.describe('Configuration Manager targeted site smoke', () => {
  test.beforeEach(async ({ page }) => {
    await installNetworkGuard(page);
    const username = process.env.CIVICRM_ADMIN_USER || 'admin';
    const password = process.env.CIVICRM_ADMIN_PASS || '';
    let loginUrl = process.env.CIVICFG_DRUPAL_LOGIN_URL || '';

    // Prefer repeatable password authentication when explicitly provided.
    // Otherwise generate a fresh single-use Drupal ULI for every test context.
    if (password) {
      loginUrl = '';
    }
    else if (!loginUrl && baseUrl.hostname.endsWith('.ddev.site')) {
      loginUrl = resolveDrupalLoginUrl({ baseUrl, username });
    }

    await loginToConfigurationManager(page, {
      baseUrl,
      username,
      password,
      loginUrl,
    });
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
    await expect(providerState).toContainText(/Provider safety details loaded:/i);
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

  test('Settings mode cards and lazy picker retain keyboard access without saving', async ({ page }) => {
    let pickerRequests = 0;
    page.on('request', request => {
      if (request.url().includes('op=scope-options-json')) pickerRequests++;
    });
    await page.goto('/civicrm/admin/config-manager?reset=1&op=settings', { waitUntil: 'domcontentloaded' });
    const row = page.locator('[data-civicfg-scope-row="scheduled-jobs"]');
    await expect(row).toBeVisible();
    const source = row.locator('[data-civicfg-scope-mode]');
    const selectedMode = row.locator('[data-civicfg-mode-choice][value="selected"]');
    const watchMode = row.locator('[data-civicfg-mode-choice][value="watch"]');
    if (await selectedMode.isDisabled()) {
      test.skip(true, 'Scope is intentionally locked or this provider is unavailable; manual access check required.');
    }
    await expect(source).toHaveAttribute('name', 'scope_mode[scheduled-jobs]');
    expect(pickerRequests).toBe(0);
    await selectedMode.check();
    await expect(source).toHaveValue('selected');
    await expect(row.locator('[data-civicfg-selected-controls]')).toBeVisible();
    await expect(row.locator('[data-civicfg-scope-available-count]')).toContainText('Item count loads when Choose items is opened.');
    const trigger = row.locator('[data-civicfg-scope-picker]');
    await trigger.click();
    const modal = page.locator('#civicfg-scope-picker-modal');
    await expect(modal).toBeVisible();
    await expect(modal.locator('#civicfg-scope-picker-search')).toBeFocused();
    await expect(modal.locator('#civicfg-scope-picker-status')).not.toContainText('Loading current CiviCRM items...');
    expect(pickerRequests).toBe(1);
    const availableCount = await modal.locator('.civicfg-scope-picker-item:not(.is-missing)').count();
    await expect(row.locator('[data-civicfg-scope-available-count]'))
      .toContainText(availableCount + ' available in Current CiviCRM');
    await expect(modal.locator('[data-civicfg-picker-selection-count]')).toContainText('item(s) selected for management');
    await page.keyboard.press('Escape');
    await expect(modal).toBeHidden();
    await expect(trigger).toBeFocused();
    await watchMode.check();
    await expect(source).toHaveValue('watch');
    await expect(row.locator('[data-civicfg-selected-controls]')).toBeHidden();
    await expect(page.locator('[data-civicfg-scope-unsaved]')).toBeVisible();
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-civicfg-scope-unsaved]')).toBeHidden();
  });

  test('keeps Import fail-closed language and outcome accounting visible', async ({ page }) => {
    await page.goto('/civicrm/admin/config-manager?reset=1&op=import', { waitUntil: 'domcontentloaded' });
    const block = page.locator('.crm-configmanager-block');
    await expect(block).toBeVisible();
    await expect(page.getByText(/Continue anyway/i)).toHaveCount(0);

    const blocker = page.locator('.civicfg-import-blockers');
    if (await blocker.count()) {
      await expect(blocker).toContainText('Import is blocked by dependency component(s).');
      await expect(page.getByText('Fix and preview again', { exact: true }).first()).toBeVisible();
      const excludeButton = page.getByRole('button', { name: 'Exclude component and build new preview' });
      if (await excludeButton.count()) {
        await expect(page.getByText('Full exclusion scope:', { exact: true }).first()).toBeVisible();
        await expect(page.getByText('Remaining Import scope after exclusion:', { exact: true }).first()).toBeVisible();
      }
    }

    const protectedPlan = page.getByText('Reviewed preview protected.', { exact: true });
    if (await protectedPlan.count()) {
      await expect(protectedPlan).toBeVisible();
    }
  });

});
