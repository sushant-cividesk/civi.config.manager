const fs = require('node:fs');
const path = require('node:path');
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;

const artifactDir = process.env.QA_ARTIFACT_DIR || path.resolve(__dirname, '../ci/artifacts');
const statePath = path.join(artifactDir, 'ui-fixture-state.json');
const state = JSON.parse(fs.readFileSync(statePath, 'utf8'));
const baseUrl = new URL(process.env.CIVICFG_BASE_URL || 'http://127.0.0.1:8760');
const blockedRequests = new WeakMap();

async function login(page) {
  await page.goto('/civicrm/login');
  const password = page.locator('input[type="password"]').first();
  if (await password.count()) {
    const username = page.locator(
      'input[name="name"], input[name="username"], input[type="email"], #edit-name'
    ).first();
    await expect(username).toBeVisible();
    await expect(password).toBeVisible();
    await username.fill(process.env.CIVICRM_ADMIN_USER || 'admin');
    await password.fill(process.env.CIVICRM_ADMIN_PASS || 'qa-admin-password');
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('domcontentloaded');
  }
}

test.describe('Configuration Manager isolated UI', () => {
  test.beforeEach(async ({ page }) => {
    const blocked = [];
    blockedRequests.set(page, blocked);
    await page.route('**/*', async route => {
      const requestUrl = new URL(route.request().url());
      if (
        (requestUrl.protocol === 'http:' || requestUrl.protocol === 'https:') &&
        requestUrl.origin !== baseUrl.origin
      ) {
        blocked.push(route.request().url());
        await route.abort('blockedbyclient');
        return;
      }
      await route.continue();
    });
    await login(page);
  });

  test.afterEach(async ({ page }) => {
    expect(blockedRequests.get(page) || [], 'UI tests attempted external network requests.').toEqual([]);
  });

  test('shows the isolated pending change and core actions', async ({ page }) => {
    const consoleErrors = [];
    const pageErrors = [];
    page.on('console', message => {
      if (message.type() === 'error') consoleErrors.push(message.text());
    });
    page.on('pageerror', error => pageErrors.push(error.message));

    await page.goto('/civicrm/admin/config-manager?reset=1&op=sync', { waitUntil: 'domcontentloaded' });

    const block = page.locator('.crm-configmanager-block');
    await expect(block).toBeVisible();
    await expect(block.getByRole('link', { name: 'Synchronize', exact: true })).toBeVisible();
    await expect(block.getByRole('link', { name: 'Import', exact: true })).toBeVisible();
    await expect(block.getByRole('link', { name: 'Export', exact: true })).toBeVisible();
    await expect(block.getByRole('link', { name: 'Settings', exact: true })).toBeVisible();
    await expect(block.getByRole('button', { name: 'Export', exact: true })).toBeVisible();
    await expect(block.getByRole('button', { name: 'Validate', exact: true })).toBeVisible();
    await expect(block.getByText(state.relative_path, { exact: true })).toBeVisible();
    await expect(block.getByText(/Difference\(s\)/)).toBeVisible();

    expect(consoleErrors, `Browser console errors: ${consoleErrors.join('\n')}`).toEqual([]);
    expect(pageErrors, `Uncaught page errors: ${pageErrors.join('\n')}`).toEqual([]);
  });

  test('requires review and the exact IMPORT confirmation word', async ({ page }) => {
    await page.goto('/civicrm/admin/config-manager?reset=1&op=import', { waitUntil: 'domcontentloaded' });

    const block = page.locator('.crm-configmanager-block');
    await expect(block.getByText(state.relative_path, { exact: true })).toBeVisible();
    await block.locator('form[data-civicfg-confirm-modal] button[type="submit"]').first().click();

    const modal = page.locator('#civicfg-confirm-modal');
    await expect(modal).toBeVisible();
    const apply = modal.locator('[data-civicfg-confirm-apply]');
    const reviewed = modal.locator('#civicfg-confirm-reviewed');
    const phrase = modal.locator('#civicfg-confirm-text');

    await expect(apply).toBeDisabled();
    await reviewed.check();
    await phrase.fill('import');
    await expect(apply).toBeDisabled();
    await phrase.fill('IMPORT');
    await expect(apply).toBeEnabled();
  });

  test('has no serious or critical accessibility violations in the extension UI', async ({ page }) => {
    await page.goto('/civicrm/admin/config-manager?reset=1&op=sync', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('.crm-configmanager-block')).toBeVisible();

    const results = await new AxeBuilder({ page })
      .include('.crm-configmanager-block')
      .withTags(['wcag2a', 'wcag2aa'])
      .analyze();
    const blocking = results.violations.filter(violation =>
      violation.impact === 'serious' || violation.impact === 'critical'
    );
    expect(blocking).toEqual([]);
  });
});
