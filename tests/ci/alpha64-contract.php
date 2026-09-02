<?php
/**
 * Dependency-free alpha64 release/runtime hardening contract.
 */
$root = dirname(__DIR__, 2);
$checks = 0;
$failures = [];

$read = static function(string $relative) use ($root): string {
  $path = $root . '/' . $relative;
  if (!is_file($path)) {
    throw new RuntimeException('Missing contract source file: ' . $relative);
  }
  return (string) file_get_contents($path);
};

$assert = static function(bool $condition, string $message) use (&$checks, &$failures): void {
  $checks++;
  if (!$condition) {
    $failures[] = $message;
  }
};

$info = $read('info.xml');
$composer = $read('composer.json');
$manager = $read('Civi/ConfigManager/Service/ConfigManager.php');
$extensions = $read('Civi/ConfigManager/Handler/ExtensionHandler.php');
$simpleYaml = $read('Civi/ConfigManager/Util/SimpleYaml.php');
$mainPage = $read('Civi/ConfigManager/UI/MainPage.php');
$fileTransfer = $read('Civi/ConfigManager/UI/FileTransfer.php');
$js = $read('js/configmanager.js');
$gitignore = $read('.gitignore');
$releaseBuilder = $read('scripts/build-release.sh');
$releaseWorkflow = $read('.github/workflows/release.yml');
$composerAudit = $read('tests/ci/composer-audit.sh');

$assert((bool) preg_match('/<version>[^<]+<\/version>/', $info), 'info.xml must declare a non-empty release version.');
$assert((bool) preg_match('/<releaseDate>\d{4}-\d{2}-\d{2}<\/releaseDate>/', $info), 'info.xml must declare an ISO release date.');
$assert(strpos($manager, 'CRM_Utils_System::cmsRootPath()') !== FALSE, 'Relative sync paths must prefer the active CiviCRM CMS root.');
$assert(strpos($simpleYaml, "function_exists('yaml_parse_file')") !== FALSE && strpos($simpleYaml, "function_exists('yaml_emit')") !== FALSE, 'Source runtime fallback requires complete ext-yaml read/write support.');
$assert(strpos($simpleYaml, 'private static function dumpValue') === FALSE && strpos($simpleYaml, 'private static function dumpArray') === FALSE, 'Production must not contain the old hand-written YAML serializer.');
$assert(strpos($simpleYaml, 'set_error_handler') !== FALSE && strpos($simpleYaml, 'restore_error_handler') !== FALSE, 'ext-yaml warnings must be isolated from protocol output.');
$assert(strpos($extensions, 'generic_config_admitted') !== FALSE && strpos($extensions, 'Generic discovery must never execute provider collection actions') !== FALSE, 'Generic provider discovery must be explicitly admitted and metadata/file driven.');
$assert(strpos($mainPage, 'while (ob_get_level() > 0)') !== FALSE && strpos($fileTransfer, 'while (ob_get_level() > 0)') !== FALSE, 'Terminal JSON endpoints must discard buffered CMS/plugin output.');
$assert(strpos($js, "expected JSON but the server returned HTML") !== FALSE, 'Browser JSON handling must report HTML/protocol failures explicitly.');
$assert(strpos($composer, '"package:release": "bash scripts/build-release.sh"') !== FALSE, 'Composer must expose the production package builder.');
$assert(strpos($releaseBuilder, 'RUNTIME_DIRS=(') !== FALSE && strpos($releaseBuilder, 'RUNTIME_FILES=(') !== FALSE, 'Release builder must use an explicit runtime allowlist.');
$assert(strpos($releaseBuilder, '--no-dev') !== FALSE && strpos($releaseBuilder, 'vendor/autoload.php') !== FALSE, 'Release builder must bundle production Composer dependencies.');
$assert(strpos($releaseBuilder, 'FORBIDDEN_TOP_LEVEL=(') !== FALSE && strpos($releaseBuilder, 'tests') !== FALSE && strpos($releaseBuilder, 'docs') !== FALSE, 'Release builder must reject development-only material.');
$assert(strpos($gitignore, "vendor/\n") !== FALSE && strpos($gitignore, "dist/\n") !== FALSE, 'Local vendor and generated dist artifacts must remain untracked.');
$assert(strpos($releaseWorkflow, "tags:\n      - 'v*'") !== FALSE && strpos($releaseWorkflow, 'Verify tag matches info.xml version') !== FALSE, 'Tagged releases must verify tag/info.xml version identity.');
$assert(strpos($releaseWorkflow, 'composer qa:fast') !== FALSE && strpos($releaseWorkflow, 'composer qa:stress') !== FALSE && strpos($releaseWorkflow, 'tests/ci/composer-audit.sh') !== FALSE, 'Tagged releases must gate fast QA, stress, and dependency audit.');
$assert(strpos($composerAudit, 'composer audit --locked --no-interaction') !== FALSE && strpos($composerAudit, 'is_transient_transport_failure') !== FALSE, 'The required Composer audit may retry transport failures but must retain the locked security-audit boundary.');
$assert(strpos($releaseWorkflow, 'gh release create') !== FALSE && strpos($releaseWorkflow, '--prerelease') !== FALSE, 'Tagged alpha/beta/RC releases must publish the runtime artifact as a GitHub pre-release.');


$canonicalizer = $read('Civi/ConfigManager/Service/Canonicalizer.php');
$presenter = $read('Civi/ConfigManager/UI/Presenter.php');
$mainPage = $read('Civi/ConfigManager/UI/MainPage.php');
$assert(strpos($canonicalizer, 'public const VERSION = 2;') !== FALSE, 'Canonicalization version must invalidate pre-fix baselines after operational metadata rules change.');
$assert(strpos($canonicalizer, "'monitor_only'") !== FALSE && strpos($canonicalizer, "'identity_portable'") !== FALSE && strpos($canonicalizer, "'ambiguity'") !== FALSE, 'Operational ambiguity/safety metadata must not create false configuration drift.');
$assert(strpos($mainPage, "'reset=1&op=diff-detail-json', FALSE, NULL, FALSE") !== FALSE, 'Lazy diff endpoint URL must be generated raw before Smarty escapes the attribute.');
$assert(strpos($presenter, "'reset=1&op=' . \$key, FALSE, NULL, FALSE") !== FALSE, 'Navigation URLs must be generated raw before Smarty escapes them.');
$assert(strpos($js, 'function civicfgNormalizeUrl') !== FALSE && strpos($js, "url.indexOf('&amp;')") !== FALSE, 'Machine-consumed URLs must defensively normalize repeated HTML ampersand escaping before fetch/navigation use.');

if ($failures) {
  fwrite(STDERR, 'alpha64 contract FAILED (' . count($failures) . '/' . $checks . ")\n");
  foreach ($failures as $failure) {
    fwrite(STDERR, '- ' . $failure . "\n");
  }
  exit(1);
}

echo 'alpha64 contract OK (' . $checks . " checks)\n";
