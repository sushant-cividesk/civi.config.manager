<?php
/**
 * Dependency-free composition smoke for stateful concern extraction.
 *
 * Checks that the legacy classes remain loadable without Composer and that
 * their public orchestration and safety entry points still exist. PHP
 * compatibility/static source contracts test method behavior separately.
 */
declare(strict_types=1);
$root = dirname(__DIR__, 2);

spl_autoload_register(static function(string $class) use ($root): void {
  $prefix = 'Civi\\ConfigManager\\';
  if (strpos($class, $prefix) !== 0) {
    return;
  }
  $path = $root . '/Civi/ConfigManager/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
  if (is_file($path)) {
    require_once $path;
  }
});

// This file is deliberately loaded directly, as in legacy PHP-only tests.
require_once $root . '/Civi/ConfigManager/UI/Presenter.php';

$checks = 0;
$check = static function(bool $ok, string $message) use (&$checks): void {
  $checks++;
  if (!$ok) {
    fwrite(STDERR, "Structural composition failed: {$message}\n");
    exit(1);
  }
};

$contracts = [
  'Civi\\ConfigManager\\Service\\ConfigManager' => [
    'export' => 'public', 'diff' => 'public', 'validate' => 'public',
    'import' => 'public', 'createImportReviewPlan' => 'public',
    'queuedExportVerifyAndPublish' => 'public',
    'queuedImportDeleteMissing' => 'public', 'getHealth' => 'public',
    'shouldIgnorePath' => 'public',
  ],
  'Civi\\ConfigManager\\Handler\\ExtensionHandler' => [
    'getDiscoveredProviderInventory' => 'public',
    'iterateExport' => 'public', 'validate' => 'public',
    'importIterable' => 'public', 'setDeleteMissingEnabled' => 'public',
    'deleteMissingBundledConfig' => 'private',
  ],
  'Civi\\ConfigManager\\Handler\\AbstractHandler' => [
    'diffFromExports' => 'public', 'structuredChanges' => 'protected',
    'api4Iterate' => 'protected', 'api4Delete' => 'protected',
  ],
  'Civi\\ConfigManager\\UI\\MainPage' => [
    'run' => 'public', 'requireValidCsrfToken' => 'private',
    'jsonAdvanceOperation' => 'private', 'assignTemplate' => 'private',
  ],
  'Civi\\ConfigManager\\UI\\Presenter' => [
    'buildTypeRows' => 'public', 'statusLabel' => 'public',
    'buildImportPlan' => 'public', 'extractImportMessages' => 'public',
  ],
  'Civi\\ConfigManager\\Handler\\EntityDefinitionHandler' => [
    'iterateExport' => 'public', 'importIterable' => 'public',
    'resolveReferencesForImport' => 'private',
  ],
];

foreach ($contracts as $className => $methods) {
  $check(class_exists($className), "missing class {$className}");
  $class = new \ReflectionClass($className);
  $check(count($class->getTraits()) > 0, "missing composed concerns in {$className}");
  foreach ($methods as $methodName => $visibility) {
    $check($class->hasMethod($methodName), "missing {$className}::{$methodName}");
    $method = $class->getMethod($methodName);
    $actual = $method->isPublic() ? 'public' : ($method->isProtected() ? 'protected' : 'private');
    $check($actual === $visibility, "visibility changed for {$className}::{$methodName}");
  }
}

echo 'Structural composition OK (' . $checks . " checks; direct-load and public API boundaries).\n";
