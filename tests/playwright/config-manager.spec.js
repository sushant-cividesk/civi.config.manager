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

test.describe('Configuration Manager scope settings', () => {
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

  test('shows mode-dependent controls and keeps item discovery lazy', async ({ page }) => {
    let pickerRequests = 0;
    page.on('request', request => {
      if (request.url().includes('op=scope-options-json')) pickerRequests += 1;
    });

    await page.goto('/civicrm/admin/config-manager?reset=1&op=settings', { waitUntil: 'domcontentloaded' });
    await expect(page.getByText('How should each configuration type be handled?', { exact: true })).toHaveCount(1);
    await expect(page.getByText('How should this configuration be handled?', { exact: true })).toHaveCount(0);
    const row = page.locator('[data-civicfg-scope-row="scheduled-jobs"]');
    await expect(row).toBeVisible();
    expect(pickerRequests).toBe(0);

    const mode = row.locator('[data-civicfg-scope-mode]');
    const selectedControls = row.locator('[data-civicfg-selected-controls]');
    await mode.selectOption('selected');
    await expect(selectedControls).toBeVisible();
    await expect(row.getByText('Monitor everything else in this type')).toBeVisible();

    await row.getByRole('button', { name: 'Choose items' }).click();
    await expect(page.locator('#civicfg-scope-picker-modal')).toBeVisible();
    await expect(page.locator('[data-civicfg-scope-option]').first()).toBeVisible();
    await expect(page.locator('#civicfg-scope-picker-status')).not.toContainText('Loading current CiviCRM items...');
    expect(pickerRequests).toBe(1);

    const pickerItems = page.locator('.civicfg-scope-picker-item');
    const total = await pickerItems.count();
    expect(total).toBeGreaterThan(0);
    const search = page.locator('#civicfg-scope-picker-search');
    await search.fill('__civicfg_no_such_item__');
    await expect(page.locator('.civicfg-scope-picker-item:not(.is-filtered)')).toHaveCount(0);
    await expect(page.locator('#civicfg-scope-picker-status')).toContainText('0 of ' + total + ' item(s) shown');
    await search.fill('');
    await expect(page.locator('.civicfg-scope-picker-item:not(.is-filtered)')).toHaveCount(total);
    await expect(page.locator('#civicfg-scope-picker-status')).toContainText(total + ' item(s) available');

    await page.locator('[data-civicfg-scope-picker-close]').first().click();
    await mode.selectOption('watch');
    await expect(selectedControls).toBeHidden();
  });

  test('supports expanded collapsible settings and Drupal-style bulk scope changes', async ({ page }) => {
    await page.goto('/civicrm/admin/config-manager?reset=1&op=settings', { waitUntil: 'domcontentloaded' });
    const scopeDetails = page.locator('details.civicfg-scope-settings');
    const advanced = page.locator('details.civicfg-advanced-settings');
    await expect(scopeDetails).toHaveAttribute('open', '');
    await expect(advanced).toHaveAttribute('open', '');

    await page.screenshot({ path: path.join(artifactDir, 'settings-expanded.png'), fullPage: true });

    await scopeDetails.locator('summary').click();
    await expect(scopeDetails).not.toHaveAttribute('open', '');
    await scopeDetails.locator('summary').click();
    await expect(scopeDetails).toHaveAttribute('open', '');
    await advanced.locator('summary').click();
    await expect(advanced).not.toHaveAttribute('open', '');
    await advanced.locator('summary').click();
    await expect(advanced).toHaveAttribute('open', '');

    const rows = page.locator('[data-civicfg-scope-row]');
    const selectable = page.locator('[data-civicfg-scope-select]:not([disabled])');
    expect(await selectable.count()).toBe(await rows.count());
    await page.locator('[data-civicfg-scope-select-all]').check();
    await expect(page.locator('[data-civicfg-scope-selected-count]')).toContainText((await rows.count()) + ' selected');
    await page.locator('[data-civicfg-scope-bulk-mode]').selectOption('ignore');
    await page.locator('[data-civicfg-scope-bulk-apply]').click();
    for (let i = 0; i < await rows.count(); i++) {
      await expect(rows.nth(i).locator('[data-civicfg-scope-mode]')).toHaveValue('ignore');
    }
    await page.screenshot({ path: path.join(artifactDir, 'settings-bulk-ignore.png'), fullPage: true });

    // Apply only changes the form; persistence still requires Save settings.
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-civicfg-scope-bulk-mode]')).toHaveValue('');
    await expect(page.locator('[data-civicfg-scope-row]').first().locator('[data-civicfg-scope-mode]')).not.toHaveValue('ignore');

    // Save an all-Ignore policy to exercise the same first-run guidance that a
    // fresh installation receives before the administrator opts configuration in.
    await page.locator('[data-civicfg-scope-select-all]').check();
    await page.locator('[data-civicfg-scope-bulk-mode]').selectOption('ignore');
    await page.locator('[data-civicfg-scope-bulk-apply]').click();
    await page.getByRole('button', { name: 'Save settings' }).click();
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('[data-civicfg-onboarding]')).toBeVisible();
    await page.screenshot({ path: path.join(artifactDir, 'settings-first-run-guide.png'), fullPage: true });
  });

  test('persists the reviewed cross-site import switch and can turn it back off', async ({ page }) => {
    await page.goto('/civicrm/admin/config-manager?reset=1&op=settings', { waitUntil: 'domcontentloaded' });
    const advanced = page.locator('details.civicfg-advanced-settings');
    await expect(advanced).toHaveAttribute('open', '');
    const checkbox = advanced.locator('input[name="allow_cross_site_import"]');
    await expect(checkbox).toBeVisible();

    if (!(await checkbox.isChecked())) {
      await checkbox.check();
      await page.getByRole('button', { name: 'Save settings' }).click();
      await page.waitForLoadState('domcontentloaded');
    }
    await expect(page.locator('details.civicfg-advanced-settings')).toHaveAttribute('open', '');
    await expect(page.locator('input[name="allow_cross_site_import"]')).toBeChecked();

    await page.locator('input[name="allow_cross_site_import"]').uncheck();
    await page.getByRole('button', { name: 'Save settings' }).click();
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('details.civicfg-advanced-settings')).toHaveAttribute('open', '');
    await expect(page.locator('input[name="allow_cross_site_import"]')).not.toBeChecked();
  });

  test('updates the civicrm.settings.php example from current choices', async ({ page }) => {
    await page.goto('/civicrm/admin/config-manager?reset=1&op=settings', { waitUntil: 'domcontentloaded' });
    const row = page.locator('[data-civicfg-scope-row="message-templates"]');
    await row.locator('[data-civicfg-scope-mode]').selectOption('watch');

    const example = page.locator('#civicfg-scope-settings-example');
    await expect(example).toContainText("'message-templates' => [");
    await expect(example).toContainText("'mode' => 'watch'");
  });
});
