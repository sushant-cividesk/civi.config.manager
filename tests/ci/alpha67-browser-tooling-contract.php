<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$runner = file_get_contents($root . '/tests/ci/run-browser-php.sh');
$standalone = file_get_contents($root . '/tests/ci/run-standalone.sh');
$composer = json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$xml = file_get_contents($root . '/tests/browser-php/phpunit.xml.dist');
$cli = file_get_contents($root . '/bin/civicfg');

$checks = [
  'root command uses orchestrator' => ($composer['scripts']['qa:browser-php'] ?? '') === 'bash tests/ci/run-browser-php.sh',
  'runner fails closed without base URL' => strpos($runner, 'CIVICFG_BASE_URL is required') !== false && strpos($runner, 'exit 2') !== false,
  'runner refuses nested browser vendor' => strpos($runner, 'Refusing to use tests/browser-php/vendor') !== false,
  'runner installs vendor outside repo' => strpos($runner, 'COMPOSER_VENDOR_DIR="${vendor_dir}"') !== false,
  'standalone preserves runtime flag' => strpos($standalone, '[[ "${RUN_PHP_UI_TESTS:-false}" == "true" ]]') !== false,
  'standalone supplies real local base URL' => strpos($standalone, 'CIVICFG_BASE_URL="http://127.0.0.1:${CIVICRM_HTTP_PORT}"') !== false,
  'standalone calls shared browser runner' => strpos($standalone, 'bash "${EXTENSION_ROOT}/tests/ci/run-browser-php.sh"') !== false,
  'phpunit does not depend on nested vendor bootstrap' => strpos($xml, 'vendor/autoload.php') === false,
  'cli exposes browser QA command' => strpos($cli, 'civicfg qa-browser --base-url URL') !== false,
  'cli exposes explicit legacy cleanup' => strpos($cli, 'qa-browser-clean') !== false && strpos($cli, 'Preview only') !== false,
  'cli rejects password argv' => strpos($cli, 'Do not pass passwords on the command line') !== false,
  'cli delegates to canonical runner' => strpos($cli, 'exec bash "$browser_runner"') !== false,
];

foreach ($checks as $label => $passed) {
  if (!$passed) {
    fwrite(STDERR, "alpha67 browser tooling contract failed: {$label}\n");
    exit(1);
  }
}

echo 'alpha67 browser tooling contract OK (' . count($checks) . " checks)\n";
