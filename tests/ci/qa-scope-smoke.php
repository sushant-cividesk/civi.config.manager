<?php
/**
 * QA scope guard: test code executes, production-only style scans stay narrow.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$composer = json_decode((string) file_get_contents($root . '/composer.json'), TRUE);
if (!is_array($composer) || !is_array($composer['scripts'] ?? NULL)) {
  fwrite(STDERR, "QA scope: invalid Composer scripts.\n");
  exit(1);
}
$scripts = $composer['scripts'];
foreach (['compatibility7.4', 'compatibility8.0', 'compatibility8.1', 'compatibility8.3'] as $alias) {
  $command = (string) ($scripts[$alias] ?? '');
  if (strpos($command, ' CRM Civi configmanager.php settings') === FALSE ||
    preg_match('/\btests\b/', $command)) {
    fwrite(STDERR, 'QA scope: test files unexpectedly included in ' . $alias . "\n");
    exit(1);
  }
}
$phpcs = (string) file_get_contents($root . '/phpcs.xml.dist');
if (strpos($phpcs, '<exclude-pattern>*/tests/*</exclude-pattern>') === FALSE ||
  preg_match('~<file>\s*tests\s*</file>~', $phpcs)) {
  fwrite(STDERR, "QA scope: PHPCS must exclude the entire tests tree.\n");
  exit(1);
}
$phpstan = (string) file_get_contents($root . '/phpstan.neon.dist');
$parts = explode('  paths:', $phpstan, 2);
$paths = isset($parts[1]) ? explode('  tmpDir:', $parts[1], 2)[0] : '';
// Runtime bootstrap/scanFiles can reference test helpers; only analysis
// target paths must be excluded.
if (!isset($parts[1]) || preg_match('~^\s*-\s*tests/\S+~m', $paths)) {
  fwrite(STDERR, "QA scope: PHPStan target paths contain tests.\n");
  exit(1);
}
$php74 = (string) file_get_contents($root . '/tests/ci/php74-signatures-smoke.php');
if (strpos($php74, "\$root . '/tests'") !== FALSE) {
  fwrite(STDERR, "QA scope: PHP 7.4 signature scan contains tests.\n");
  exit(1);
}
$lint = (string) file_get_contents($root . '/tests/ci/php-lint.sh');
if (strpos($lint, 'settings tests') === FALSE ||
  strpos((string) ($scripts['test:unit'] ?? ''), 'phpunit') === FALSE ||
  !in_array('@test:unit', (array) ($scripts['qa:fast'] ?? []), TRUE)) {
  fwrite(STDERR, "QA scope: PHPUnit or test syntax coverage was disabled.\n");
  exit(1);
}
echo "QA scope separation OK (production style/compatibility, runnable tests).\n";
