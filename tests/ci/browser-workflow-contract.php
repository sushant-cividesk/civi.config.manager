<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$composer = json_decode((string) file_get_contents($root . '/composer.json'), TRUE);
$package = json_decode((string) file_get_contents($root . '/package.json'), TRUE);
$targeted = (string) file_get_contents($root . '/tests/playwright/targeted-smoke.spec.js');
$drupalAuth = (string) file_get_contents($root . '/tests/playwright/helpers/drupal-auth.js');
$drupalAuthTest = (string) file_get_contents($root . '/tests/playwright/drupal-auth.spec.js');
$uiRunner = (string) file_get_contents($root . '/tests/ci/run-ui-tests.js');
$playwrightConfig = (string) file_get_contents($root . '/playwright.config.js');
$standalone = (string) file_get_contents($root . '/tests/ci/run-standalone.sh');
$qaFull = (string) file_get_contents($root . '/.github/workflows/qa-full.yml');
$release = (string) file_get_contents($root . '/.github/workflows/release.yml');
$cli = (string) file_get_contents($root . '/bin/civicfg');
$cliTests = (string) file_get_contents($root . '/tests/phpunit/Unit/CliCommandTest.php');

$checks = [
  'single browser stack directory' => !is_dir($root . '/tests/browser-php'),
  'browser command uses disposable runtime' => ($composer['scripts']['qa:browser'] ?? '') === 'RUN_UI_TESTS=true bash tests/ci/run-standalone.sh',
  'npm UI command dispatches by fixture context' => ($package['scripts']['test:ui'] ?? '') === 'node tests/ci/run-ui-tests.js',
  'targeted smoke does not require disposable fixture state' => strpos($targeted, 'ui-fixture-state.json') === FALSE,
  'targeted smoke uses dedicated Drupal authentication helper' => strpos($targeted, "require('./helpers/drupal-auth')") !== FALSE && strpos($targeted, 'loginToConfigurationManager') !== FALSE,
  'targeted login uses Drupal user login form explicitly' => strpos($drupalAuth, "new URL('/user/login', baseUrl)") !== FALSE && strpos($drupalAuth, "form#user-login-form") !== FALSE,
  'targeted login proves Drupal session before checking CiviCRM access' => strpos($drupalAuth, "/^S?SESS/") !== FALSE && strpos($drupalAuth, 'Drupal authentication failed') !== FALSE,
  'targeted login distinguishes authenticated permission denial' => strpos($drupalAuth, 'Drupal authentication succeeded, but user') !== FALSE,
  'Drupal auth harness proves success credentials and permission failure paths' => substr_count($drupalAuthTest, 'loginToConfigurationManager') >= 3 && strpos($drupalAuthTest, 'Unrecognized username or password') !== FALSE && strpos($drupalAuthTest, 'allowConfigManager: false') !== FALSE,
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
