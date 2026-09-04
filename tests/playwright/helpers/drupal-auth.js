const { expect } = require('@playwright/test');

async function pageDiagnostic(page) {
  const heading = await page.locator('h1').first().textContent().catch(() => '');
  return `url=${page.url()} title=${await page.title()} h1=${(heading || '').trim()}`;
}

/**
 * Authenticate a targeted Drupal site and prove the resulting browser session
 * before checking Configuration Manager permission/access.
 *
 * @param {import('@playwright/test').Page} page
 * @param {{baseUrl: URL, username: string, password?: string, loginUrl?: string}} options
 */
async function loginToConfigurationManager(page, options) {
  const { baseUrl, username, password = '', loginUrl = '' } = options;
  const loginUrlPage = new URL('/user/login', baseUrl).href;
  const settingsUrl = new URL('/civicrm/admin/config-manager?reset=1&op=settings', baseUrl).href;

  if (loginUrl) {
    await page.goto(loginUrl, { waitUntil: 'domcontentloaded' });
  }
  else {
    if (!password) {
      throw new Error('Targeted Drupal UI QA requires either a local Drupal one-time login URL or CIVICRM_ADMIN_PASS.');
    }
    await page.goto(loginUrlPage, { waitUntil: 'domcontentloaded' });
  }
  const loginForm = page.locator('form#user-login-form');

  // Drupal may redirect an already-authenticated browser away from /user/login.
  if (loginUrl && await loginForm.count()) {
    throw new Error(`Drupal one-time login did not establish a session for user "${username}". ${await pageDiagnostic(page)}`);
  }
  if (!loginUrl && await loginForm.count()) {
    const usernameField = loginForm.locator('input[name="name"], #edit-name').first();
    const passwordField = loginForm.locator('input[name="pass"], #edit-pass').first();
    const submit = loginForm.locator('#edit-submit, button[type="submit"], input[type="submit"]').first();

    await expect(usernameField, `Drupal login username field missing. ${await pageDiagnostic(page)}`).toBeVisible();
    await expect(passwordField, `Drupal login password field missing. ${await pageDiagnostic(page)}`).toBeVisible();
    await expect(submit, `Drupal login submit control missing. ${await pageDiagnostic(page)}`).toBeVisible();

    await usernameField.fill(username);
    await passwordField.fill(password);
    await Promise.all([
      page.waitForLoadState('domcontentloaded'),
      submit.click(),
    ]);
  }

  const remainingLoginForm = page.locator('form#user-login-form');
  const loginError = page.locator('.messages--error, [data-drupal-messages] .messages--error, .alert-danger').first();
  const sessionCookies = await page.context().cookies(baseUrl.href);
  const hasDrupalSession = sessionCookies.some(cookie => /^S?SESS/.test(cookie.name));

  if (await remainingLoginForm.count() || !hasDrupalSession) {
    const errorText = (await loginError.textContent().catch(() => ''))?.trim();
    const detail = errorText ? ` Drupal reported: ${errorText}` : '';
    throw new Error(`Drupal authentication failed for user "${username}".${detail} ${await pageDiagnostic(page)}`);
  }

  await page.goto(settingsUrl, { waitUntil: 'domcontentloaded' });
  const block = page.locator('.crm-configmanager-block');
  if (!(await block.count())) {
    const heading = ((await page.locator('h1').first().textContent().catch(() => '')) || '').trim();
    const permissionHint = /access denied/i.test(heading)
      ? ` Drupal authentication succeeded, but user "${username}" does not have access to Configuration Manager Settings on this target.`
      : '';
    throw new Error(`Configuration Manager Settings did not load after confirmed Drupal authentication.${permissionHint} ${await pageDiagnostic(page)}`);
  }

  await expect(block).toBeVisible();
}

module.exports = {
  loginToConfigurationManager,
  pageDiagnostic,
};
