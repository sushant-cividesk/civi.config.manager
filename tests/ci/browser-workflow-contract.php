<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$composer = json_decode((string) file_get_contents($root . '/composer.json'), TRUE);
$package = json_decode((string) file_get_contents($root . '/package.json'), TRUE);
$targeted = (string) file_get_contents($root . '/tests/playwright/targeted-smoke.spec.js');
$fullUi = (string) file_get_contents($root . '/tests/playwright/config-manager.spec.js');
$uiFixture = (string) file_get_contents($root . '/tests/integration/UiFixture.php');
$drupalAuth = (string) file_get_contents($root . '/tests/playwright/helpers/drupal-auth.js');
$drupalAuthTest = (string) file_get_contents($root . '/tests/playwright/drupal-auth.spec.js');
$drupalLoginResolver = (string) file_get_contents($root . '/tests/ci/drupal-login-url.js');
$uiRunner = (string) file_get_contents($root . '/tests/ci/run-ui-tests.js');
$playwrightConfig = (string) file_get_contents($root . '/playwright.config.js');
$standalone = (string) file_get_contents($root . '/tests/ci/run-standalone.sh');
$ddevStateful = (string) file_get_contents($root . '/tests/ci/run-ddev-stateful-ui.sh');
$dockerBrowser = (string) file_get_contents($root . '/tests/ci/run-playwright-docker.sh');
$qaFull = (string) file_get_contents($root . '/.github/workflows/qa-full.yml');
$release = (string) file_get_contents($root . '/.github/workflows/release.yml');
$cli = (string) file_get_contents($root . '/bin/civicfg');
$cliTests = (string) file_get_contents($root . '/tests/phpunit/Unit/CliCommandTest.php');

$checks = [
  'single browser stack directory' => !is_dir($root . '/tests/browser-php'),
  'browser command uses disposable runtime' => ($composer['scripts']['qa:browser'] ?? '') === 'RUN_UI_TESTS=true bash tests/ci/run-standalone.sh',
  'npm UI command dispatches by fixture context' => ($package['scripts']['test:ui'] ?? '') === 'node tests/ci/run-ui-tests.js',
  'npm exposes quick DEV and STAGE targeted commands' => isset($package['scripts']['test:ui:dev'], $package['scripts']['test:ui:stage']),
  'targeted smoke does not require disposable fixture state' => strpos($targeted, 'ui-fixture-state.json') === FALSE,
  'disposable UI fixture seeds deterministic A68-09 dependency and independent drift' => strpos($uiFixture, 'missingDependencyName') !== FALSE && strpos($uiFixture, 'RelationshipType::create') !== FALSE && strpos($uiFixture, "'type' => 'option-groups'") !== FALSE,
  'stateful browser suite proves unsafe exclusion has no bypass' => strpos($fullUi, 'refuses dependency exclusion when it would leave nothing to import') !== FALSE && strpos($fullUi, 'leave nothing to import') !== FALSE,
  'stateful browser suite proves safe reduced plan survives refresh' => strpos($fullUi, 'builds a fresh reduced plan and reconnects to the exact plan after refresh') !== FALSE && strpos($fullUi, "fill(word)") !== FALSE,
  'stateful browser suite invalidates stale reviewed plan after Saved Config mutation' => strpos($fullUi, 'does not reconnect a reviewed plan after an unrelated Saved Config export makes it stale') !== FALSE && strpos($fullUi, 'not.toBe(stalePlanId)') !== FALSE,
  'stateful browser suite checks canonical reduced Import outcome labels' => strpos($fullUi, 'shows canonical reduced-Import outcome accounting after apply') !== FALSE && strpos($fullUi, "toContainText('Remaining Difference')") !== FALSE,
  'targeted smoke uses dedicated Drupal authentication helper' => strpos($targeted, "require('./helpers/drupal-auth')") !== FALSE && strpos($targeted, 'loginToConfigurationManager') !== FALSE,
  'targeted login uses Drupal user login form explicitly' => strpos($drupalAuth, "new URL('/user/login', baseUrl)") !== FALSE && strpos($drupalAuth, "form#user-login-form") !== FALSE,
  'targeted login proves Drupal session before checking CiviCRM access' => strpos($drupalAuth, "/^S?SESS/") !== FALSE && strpos($drupalAuth, 'Drupal authentication failed') !== FALSE,
  'targeted login accepts local one-time Drupal login URL' => strpos($targeted, 'CIVICFG_DRUPAL_LOGIN_URL') !== FALSE && strpos($drupalAuth, 'loginUrl') !== FALSE,
  'targeted login prefers explicit password over generated one-time URL' => strpos($targeted, 'if (password)') !== FALSE && strpos($targeted, "loginUrl = '';") !== FALSE,
  'targeted DDEV login generates a fresh one-time URL per test context' => strpos($targeted, 'resolveDrupalLoginUrl({ baseUrl, username })') !== FALSE,
  'targeted login distinguishes authenticated permission denial' => strpos($drupalAuth, 'Drupal authentication succeeded, but user') !== FALSE,
  'Drupal auth harness proves password one-time-login and permission failure paths' => substr_count($drupalAuthTest, 'loginToConfigurationManager') >= 4 && strpos($drupalAuthTest, '/user/reset/1/123/hash/login') !== FALSE && strpos($drupalAuthTest, 'Unrecognized username or password') !== FALSE && strpos($drupalAuthTest, 'allowConfigManager: false') !== FALSE,
  'local DDEV login resolver uses Drush without password mutation' => strpos($drupalLoginResolver, 'user:login') !== FALSE && strpos($drupalLoginResolver, 'CIVICFG_DRUPAL_ROOT') !== FALSE && strpos($drupalLoginResolver, 'user:password') === FALSE,
  'npm exposes isolated Drupal auth harness test' => ($package['scripts']['test:ui:harness'] ?? '') === 'playwright test tests/playwright/drupal-auth.spec.js',
  'targeted runner tolerates local developer CA without weakening fixture suite' => strpos($uiRunner, "CIVICFG_IGNORE_HTTPS_ERRORS = '1'") !== FALSE,
  'browser runner requires repository-local Playwright binary' => strpos($uiRunner, "'node_modules', '.bin'") !== FALSE,
  'browser runner explains npm install prerequisite' => strpos($uiRunner, "Run `npm install` in the extension directory, then retry.") !== FALSE,
  'browser runner fails early when Chromium is missing' => strpos($uiRunner, "Run `npx playwright install chromium` in the extension directory, then retry.") !== FALSE,
  'browser runner does not auto-install Playwright through npx' => strpos($uiRunner, "spawnSync('npx'") === FALSE && strpos($uiRunner, 'spawnSync("npx"') === FALSE,
  'Playwright certificate tolerance is explicitly opt-in' => strpos($playwrightConfig, "process.env.CIVICFG_IGNORE_HTTPS_ERRORS === '1'") !== FALSE,
  'no PHP browser composer command' => !isset($composer['scripts']['qa:browser-php']),
  'standalone owns JS browser flag' => strpos($standalone, 'RUN_UI_TESTS') !== FALSE,
  'standalone has no PHP browser flag' => strpos($standalone, 'RUN_PHP_UI_TESTS') === FALSE,
  'standalone runs JS Playwright' => strpos($standalone, 'npm run test:ui') !== FALSE,
  'standalone keeps Docker browser fallback' => strpos($standalone, 'run-playwright-docker.sh') !== FALSE,
  'Docker browser fallback copies Drupal login resolver needed by shared fixture suite' => strpos($dockerBrowser, 'drupal-login-url.js') !== FALSE,
  'stateful DDEV browser runner is DDEV-only and always wires cleanup' => strpos($ddevStateful, '*.ddev.site') !== FALSE && strpos($ddevStateful, 'UiFixture.php cleanup') !== FALSE && strpos($ddevStateful, 'trap cleanup') !== FALSE,
  'stateful DDEV browser runner refuses to overwrite prior fixture state' => strpos($ddevStateful, 'Refusing to overwrite an existing UI fixture state') !== FALSE,
  'full GitHub QA uses canonical browser command' => strpos($qaFull, 'run: composer qa:browser') !== FALSE,
  'release GitHub QA uses canonical browser command' => strpos($release, 'run: composer qa:browser') !== FALSE,
  'no Playwright-PHP workflow wording' => strpos($qaFull . $release, 'Playwright-PHP') === FALSE,
  'production CLI has no removed browser QA commands' => strpos($cli, 'qa-browser') === FALSE,
  'CLI tests contain no removed browser QA cases' => strpos($cliTests, 'testQaBrowser') === FALSE,
];

foreach ($checks as $label => $passed) {
  if (!$passed) {
    fwrite(STDERR, 'browser workflow contract failed: ' . $label . PHP_EOL);
    exit(1);
  }
}

echo 'browser workflow contract OK (' . count($checks) . ' checks)' . PHP_EOL;
