<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$runner = file_get_contents($root . '/tests/ci/run-browser-php.sh');
$standalone = file_get_contents($root . '/tests/ci/run-standalone.sh');
$composer = json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$xml = file_get_contents($root . '/tests/browser-php/phpunit.xml.dist');
$cli = file_get_contents($root . '/bin/civicfg');

$checks = [
  'root PHP browser command is self-contained' => ($composer['scripts']['qa:browser-php'] ?? '') === 'bash tests/ci/run-browser-php-self-contained.sh',
  'targeted browser command remains explicit' => ($composer['scripts']['qa:browser-php:target'] ?? '') === 'bash tests/ci/run-browser-php.sh',
  'combined browser command owns disposable runtime' => strpos((string) ($composer['scripts']['qa:browser'] ?? ''), 'run-standalone.sh') !== false,
  'target runner fails closed without base URL' => strpos($runner, 'CIVICFG_BASE_URL is required') !== false && strpos($runner, 'exit 2') !== false,
  'target runner requires explicit password' => strpos($runner, 'CIVICRM_ADMIN_PASS is required') !== false,
  'runner refuses nested browser vendor' => strpos($runner, 'Refusing to use tests/browser-php/vendor') !== false,
  'runner installs vendor outside repo' => strpos($runner, 'COMPOSER_VENDOR_DIR="${vendor_dir}"') !== false,
  'phpunit fails on skipped browser tests' => strpos($xml, 'failOnSkipped="true"') !== false,
  'phpunit treats zero assertion tests as risky' => strpos($xml, 'beStrictAboutTestsThatDoNotTestAnything="true"') !== false,
  'standalone has independent JS browser flag' => strpos($standalone, 'RUN_JS_UI_TESTS="${RUN_JS_UI_TESTS:-${RUN_UI_TESTS:-false}}"') !== false,
  'standalone has independent PHP browser flag' => strpos($standalone, 'RUN_PHP_UI_TESTS="${RUN_PHP_UI_TESTS:-false}"') !== false,
  'standalone supplies real local base URL' => strpos($standalone, 'CIVICFG_BASE_URL="http://127.0.0.1:${CIVICRM_HTTP_PORT}"') !== false,
  'standalone calls shared target runner' => strpos($standalone, 'bash "${EXTENSION_ROOT}/tests/ci/run-browser-php.sh"') !== false,
  'phpunit does not depend on nested vendor bootstrap' => strpos($xml, 'vendor/autoload.php') === false,
  'cli exposes self-contained browser QA' => strpos($cli, 'civicfg qa-browser [--base-url URL]') !== false,
  'cli exposes explicit legacy cleanup' => strpos($cli, 'qa-browser-clean') !== false && strpos($cli, 'Preview only') !== false,
  'cli rejects password argv' => strpos($cli, 'Do not pass passwords on the command line') !== false,
  'cli requires target password environment' => strpos($cli, 'Targeted browser QA requires CIVICRM_ADMIN_PASS') !== false,
  'cli delegates no-URL QA to self-contained runner' => strpos($cli, 'run-browser-php-self-contained.sh') !== false,
  'cli delegates targeted QA to canonical runner' => strpos($cli, 'exec bash "$browser_runner"') !== false,
];

foreach ($checks as $label => $passed) {
  if (!$passed) {
    fwrite(STDERR, "alpha67 browser tooling contract failed: {$label}\n");
    exit(1);
  }
}

echo 'alpha67 browser tooling contract OK (' . count($checks) . " checks)\n";
