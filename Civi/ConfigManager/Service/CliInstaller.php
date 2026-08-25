<?php
namespace Civi\ConfigManager\Service;

use Civi\ConfigManager\Version;

/**
 * Installs one project launcher and one shared global dispatcher.
 *
 * The extension-owned bin/civicfg remains the only CLI implementation. The
 * launchers resolve the active site's extension path at runtime and never
 * hard-code a project-specific extension path.
 */
class CliInstaller {
  private const MANAGED_MARKER = 'Managed by Configuration Manager extension';

  private ConfigManager $manager;

  public function __construct(?ConfigManager $manager = NULL) {
    $this->manager = $manager ?: new ConfigManager();
  }

  public function install(): array {
    $result = [
      'ok' => TRUE,
      'extension_cli' => $this->extensionRoot() . '/bin/civicfg',
      'vendor_launcher' => NULL,
      'global_launcher' => NULL,
      'registry' => $this->registryFile(),
      'installed' => [],
      'removed_legacy' => [],
      'skipped' => [],
      'errors' => [],
    ];

    $this->cleanupLegacyWrappers($result);

    $vendorBin = $this->composerVendorBin();
    if ($vendorBin !== NULL) {
      $this->installLauncher($vendorBin . DIRECTORY_SEPARATOR . 'civicfg', $result, 'vendor_launcher');
    }

    $registry = $this->readRegistry();
    $globalTarget = $this->registeredGlobalLauncher($registry);
    if ($globalTarget === NULL) {
      $globalBin = $this->globalBinDirectory();
      $globalTarget = $globalBin === NULL ? NULL : $globalBin . DIRECTORY_SEPARATOR . 'civicfg';
    }

    if ($globalTarget !== NULL) {
      if ($this->installLauncher($globalTarget, $result, 'global_launcher')) {
        try {
          $this->registerSite($globalTarget);
        }
        catch (\Throwable $e) {
          $result['errors'][] = $e->getMessage();
        }
      }
    }
    else {
      $result['skipped'][] = 'Global civicfg not installed: no safe writable directory already available in PATH. Set CIVICFG_GLOBAL_BIN_DIR to choose one explicitly.';
    }

    $result['ok'] = empty($result['errors']);
    return $result;
  }

  public function status(): array {
    $extensionCli = $this->extensionRoot() . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'civicfg';
    $vendorBin = $this->composerVendorBin();
    $vendorTarget = $vendorBin === NULL ? NULL : $vendorBin . DIRECTORY_SEPARATOR . 'civicfg';
    $registry = $this->readRegistry();
    $registeredGlobal = trim((string) ($registry['global_launcher'] ?? ''));
    $globalTarget = $registeredGlobal !== '' ? $registeredGlobal : NULL;
    $installationId = $this->installationIdentifier();

    return [
      'extension_cli' => $extensionCli,
      'extension_cli_available' => is_file($extensionCli) && is_executable($extensionCli),
      'vendor_launcher' => $vendorTarget,
      'vendor_launcher_available' => $vendorTarget !== NULL && is_file($vendorTarget) && $this->isManagedWrapper($vendorTarget),
      'global_launcher' => $globalTarget,
      'global_launcher_available' => $globalTarget !== NULL && is_file($globalTarget) && $this->isManagedWrapper($globalTarget),
      'registry' => $this->registryFile(),
      'registered' => isset($registry['sites'][$installationId]),
    ];
  }

  public function uninstall(): array {
    $result = [
      'ok' => TRUE,
      'removed' => [],
      'removed_legacy' => [],
      'skipped' => [],
      'errors' => [],
    ];

    $vendorBin = $this->composerVendorBin();
    if ($vendorBin !== NULL) {
      $this->removeManagedFile($vendorBin . DIRECTORY_SEPARATOR . 'civicfg', $result, 'removed');
    }

    $this->cleanupLegacyWrappers($result);
    $this->unregisterSite($result);
    $result['ok'] = empty($result['errors']);
    return $result;
  }

  public function ensureSiteIdentifier(): string {
    return $this->manager->getSiteIdentifier();
  }

  private function installLauncher(string $target, array &$result, string $resultKey): bool {
    $dir = dirname($target);
    if (!is_dir($dir) && !@mkdir($dir, 0775, TRUE) && !is_dir($dir)) {
      $result['errors'][] = 'Could not create CLI directory: ' . $dir;
      return FALSE;
    }
    if (!is_writable($dir)) {
      $result['errors'][] = 'CLI directory is not writable: ' . $dir;
      return FALSE;
    }
    if (is_file($target) && !$this->isManagedWrapper($target)) {
      $result['skipped'][] = $target . ' (existing non-managed file)';
      return FALSE;
    }

    $contents = $this->buildDispatcherScript();
    if (is_file($target) && $this->isManagedWrapper($target) && @file_get_contents($target) === $contents) {
      $result[$resultKey] = $target;
      return TRUE;
    }

    if (@file_put_contents($target, $contents, LOCK_EX) === FALSE) {
      $result['errors'][] = 'Could not write CLI launcher: ' . $target;
      return FALSE;
    }
    @chmod($target, 0775);
    $result[$resultKey] = $target;
    $result['installed'][] = $target;
    return TRUE;
  }

  private function buildDispatcherScript(): string {
    $extensionKey = Version::EXTENSION_KEY;
    $template = <<<'BASH'
#!/usr/bin/env bash
# Managed by Configuration Manager extension. Do not edit manually.
set -euo pipefail
extension_key=__EXTENSION_KEY__

cv_cmd="$(command -v cv || true)"
if [[ -z "${cv_cmd}" && -x "$(dirname "$0")/cv" ]]; then
  cv_cmd="$(dirname "$0")/cv"
fi
if [[ -z "${cv_cmd}" ]]; then
  echo "Configuration Manager CLI requires the CiviCRM cv command in PATH or beside this launcher." >&2
  exit 2
fi

extension_bin="$("${cv_cmd}" ev 'try {
  $key = "__EXTENSION_KEY_RAW__";
  $system = CRM_Extension_System::singleton();
  $manager = $system->getManager();
  $status = strtolower((string) $manager->getStatus($key));
  if (!in_array($status, ["installed", "enabled"], true)) {
    fwrite(STDERR, "Configuration Manager extension is not enabled on this site.\n");
    exit(2);
  }
  $mapper = method_exists($system, "getMapper") ? $system->getMapper() : null;
  $base = "";
  if ($mapper && method_exists($mapper, "keyToBasePath")) {
    $base = (string) $mapper->keyToBasePath($key);
  }
  elseif ($mapper && method_exists($mapper, "getBasePath")) {
    $base = (string) $mapper->getBasePath($key);
  }
  if ($base === "") {
    fwrite(STDERR, "Could not resolve Configuration Manager extension path.\n");
    exit(2);
  }
  echo rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "bin" . DIRECTORY_SEPARATOR . "civicfg";
} catch (Throwable $e) {
  fwrite(STDERR, $e->getMessage() . "\n");
  exit(2);
}')"

if [[ -z "${extension_bin}" || ! -x "${extension_bin}" ]]; then
  echo "Configuration Manager extension CLI could not be resolved for the current CiviCRM site." >&2
  exit 2
fi

export CIVICFG_CV="${cv_cmd}"
exec "${extension_bin}" "$@"
BASH;

    return strtr($template, [
      '__EXTENSION_KEY__' => escapeshellarg($extensionKey),
      '__EXTENSION_KEY_RAW__' => addslashes($extensionKey),
    ]);
  }

  private function composerVendorBin(): ?string {
    $root = rtrim($this->manager->getProjectRoot(), DIRECTORY_SEPARATOR);
    if ($root === '') {
      return NULL;
    }

    $candidates = [];

    // CiviDesk/legacy Drupal projects often keep a site-local Composer vendor
    // tree under web/sites/default rather than at the detected CMS docroot.
    // Check that layout before walking ancestors so lifecycle install can
    // create vendor/bin/civicfg on real projects, not only in test fixtures.
    $candidates[] = $root . DIRECTORY_SEPARATOR . 'sites' . DIRECTORY_SEPARATOR . 'default';
    if (basename($root) !== 'web') {
      $candidates[] = $root . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'sites' . DIRECTORY_SEPARATOR . 'default';
    }

    $cursor = $root;
    for ($i = 0; $i < 5; $i++) {
      $candidates[] = $cursor;
      $parent = dirname($cursor);
      if ($parent === $cursor) {
        break;
      }
      $cursor = $parent;
    }

    foreach (array_values(array_unique($candidates)) as $candidate) {
      $vendor = $candidate . DIRECTORY_SEPARATOR . 'vendor';
      if (!is_dir($vendor)) {
        continue;
      }
      if (!is_file($vendor . DIRECTORY_SEPARATOR . 'autoload.php') && !is_dir($vendor . DIRECTORY_SEPARATOR . 'composer')) {
        continue;
      }
      $bin = $vendor . DIRECTORY_SEPARATOR . 'bin';
      if ((is_dir($bin) && is_writable($bin)) || (!is_dir($bin) && is_writable($vendor))) {
        return $bin;
      }
    }

    return NULL;
  }

  private function globalBinDirectory(): ?string {
    $explicit = trim((string) getenv('CIVICFG_GLOBAL_BIN_DIR'));
    if ($explicit !== '') {
      $explicit = $this->expandHome($explicit);
      $parent = is_dir($explicit) ? $explicit : dirname($explicit);
      if ((is_dir($explicit) && is_writable($explicit)) || (!is_dir($explicit) && is_dir($parent) && is_writable($parent))) {
        return $explicit;
      }
      return NULL;
    }

    $home = rtrim($this->expandHome((string) getenv('HOME')), DIRECTORY_SEPARATOR);
    $preferred = [];
    if ($home !== '') {
      $preferred[] = $home . DIRECTORY_SEPARATOR . '.local' . DIRECTORY_SEPARATOR . 'bin';
      $preferred[] = $home . DIRECTORY_SEPARATOR . 'bin';
    }
    $preferred[] = '/usr/local/bin';
    $preferred[] = '/opt/homebrew/bin';

    $pathDirs = $this->pathDirectories();
    foreach ($preferred as $dir) {
      if (in_array($dir, $pathDirs, TRUE) && $this->directoryIsWritableOrCreatable($dir)) {
        return $dir;
      }
    }

    foreach ($pathDirs as $dir) {
      if (basename($dir) !== 'bin' || !$this->directoryIsWritableOrCreatable($dir)) {
        continue;
      }
      if ($home !== '' && (strpos($dir, $home . DIRECTORY_SEPARATOR) === 0 || $dir === $home)) {
        return $dir;
      }
    }

    return NULL;
  }

  /**
   * Return TRUE when a launcher directory exists and is writable or can be
   * created below the first writable existing ancestor.
   *
   * This matters for common PATH entries such as $HOME/.local/bin: shells may
   * advertise the directory before it exists, and extension install should be
   * able to create it instead of silently giving up on the global command.
   */
  private function directoryIsWritableOrCreatable(string $dir): bool {
    if (is_dir($dir)) {
      return is_writable($dir);
    }

    $cursor = $dir;
    while (!is_dir($cursor)) {
      $parent = dirname($cursor);
      if ($parent === $cursor) {
        return FALSE;
      }
      $cursor = $parent;
    }

    return is_writable($cursor);
  }

  private function pathDirectories(): array {
    $path = (string) getenv('PATH');
    if ($path === '') {
      return [];
    }
    $dirs = [];
    foreach (explode(PATH_SEPARATOR, $path) as $dir) {
      $dir = trim($this->expandHome($dir));
      if ($dir !== '') {
        $dirs[] = rtrim($dir, DIRECTORY_SEPARATOR);
      }
    }
    return array_values(array_unique($dirs));
  }

  private function cleanupLegacyWrappers(array &$result): void {
    $names = [
      'cvcfg', 'config-export', 'ce', 'config-import', 'ci', 'config-diff', 'cdf',
      'config-validate', 'cval', 'civicfg-env', 'civicfg-path',
    ];
    $dirs = [];
    $root = rtrim($this->manager->getProjectRoot(), DIRECTORY_SEPARATOR);
    if ($root !== '') {
      $dirs[] = $root . DIRECTORY_SEPARATOR . 'bin';
      if (basename($root) === 'web') {
        $dirs[] = dirname($root) . DIRECTORY_SEPARATOR . 'bin';
      }
    }
    $dirs[] = '/var/www/html/bin';
    foreach ($this->pathDirectories() as $dir) {
      $dirs[] = $dir;
    }

    foreach (array_values(array_unique($dirs)) as $dir) {
      foreach ($names as $name) {
        $this->removeManagedFile($dir . DIRECTORY_SEPARATOR . $name, $result, 'removed_legacy');
      }
    }
  }

  private function removeManagedFile(string $target, array &$result, string $bucket): void {
    if (!is_file($target)) {
      return;
    }
    if (!$this->isManagedWrapper($target)) {
      return;
    }
    if (!@unlink($target)) {
      $result['errors'][] = 'Could not remove managed CLI launcher: ' . $target;
      return;
    }
    $result[$bucket][] = $target;
  }

  private function registryFile(): string {
    $override = trim((string) getenv('CIVICFG_REGISTRY_DIR'));
    if ($override !== '') {
      return rtrim($this->expandHome($override), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'installations.json';
    }
    $xdg = trim((string) getenv('XDG_CONFIG_HOME'));
    if ($xdg !== '') {
      return rtrim($this->expandHome($xdg), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'civicfg' . DIRECTORY_SEPARATOR . 'installations.json';
    }
    $home = rtrim($this->expandHome((string) getenv('HOME')), DIRECTORY_SEPARATOR);
    if ($home !== '') {
      return $home . DIRECTORY_SEPARATOR . '.config' . DIRECTORY_SEPARATOR . 'civicfg' . DIRECTORY_SEPARATOR . 'installations.json';
    }
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'civicfg-' . getmyuid() . DIRECTORY_SEPARATOR . 'installations.json';
  }

  private function readRegistry(): array {
    $file = $this->registryFile();
    if (!is_file($file)) {
      return ['version' => 1, 'global_launcher' => '', 'sites' => []];
    }
    $data = json_decode((string) @file_get_contents($file), TRUE);
    if (!is_array($data)) {
      return ['version' => 1, 'global_launcher' => '', 'sites' => []];
    }
    $data['sites'] = (array) ($data['sites'] ?? []);
    return $data;
  }

  private function registeredGlobalLauncher(array $registry): ?string {
    $target = trim((string) ($registry['global_launcher'] ?? ''));
    if ($target === '' || !is_file($target) || !$this->isManagedWrapper($target) || !is_writable(dirname($target))) {
      return NULL;
    }
    return $target;
  }

  private function registerSite(string $globalTarget): void {
    $file = $this->registryFile();
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0775, TRUE) && !is_dir($dir)) {
      throw new \RuntimeException('Could not create Configuration Manager CLI registry directory: ' . $dir);
    }
    $registry = $this->readRegistry();
    $siteId = $this->manager->getSiteIdentifier();
    $installationId = $this->installationIdentifier();
    $registry['version'] = 1;
    $registry['global_launcher'] = $globalTarget;
    $registry['sites'][$installationId] = [
      'site_id' => $siteId,
      'project_root' => $this->manager->getProjectRoot(),
      'registered_at' => date(DATE_ATOM),
    ];
    ksort($registry['sites'], SORT_STRING);
    $json = json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === FALSE || @file_put_contents($file, $json . PHP_EOL, LOCK_EX) === FALSE) {
      throw new \RuntimeException('Could not write Configuration Manager CLI registry: ' . $file);
    }
  }

  private function unregisterSite(array &$result): void {
    $file = $this->registryFile();
    $registry = $this->readRegistry();
    unset($registry['sites'][$this->installationIdentifier()]);

    if (!empty($registry['sites'])) {
      $json = json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
      if ($json === FALSE || @file_put_contents($file, $json . PHP_EOL, LOCK_EX) === FALSE) {
        $result['errors'][] = 'Could not update Configuration Manager CLI registry: ' . $file;
      }
      return;
    }

    $globalTarget = trim((string) ($registry['global_launcher'] ?? ''));
    if ($globalTarget !== '') {
      $this->removeManagedFile($globalTarget, $result, 'removed');
    }
    if (is_file($file) && !@unlink($file)) {
      $result['errors'][] = 'Could not remove empty Configuration Manager CLI registry: ' . $file;
    }
    $dir = dirname($file);
    if (is_dir($dir)) {
      @rmdir($dir);
    }
  }


  private function installationIdentifier(): string {
    $root = rtrim($this->manager->getProjectRoot(), DIRECTORY_SEPARATOR);
    $resolved = $root !== '' ? realpath($root) : FALSE;
    $projectRoot = $resolved !== FALSE ? $resolved : $root;
    return 'installation-' . substr(hash('sha256', $this->manager->getSiteIdentifier() . '|' . $projectRoot), 0, 24);
  }

  private function expandHome(string $path): string {
    if ($path === '~' || strpos($path, '~/') === 0) {
      $home = rtrim((string) getenv('HOME'), DIRECTORY_SEPARATOR);
      if ($home !== '') {
        return $home . substr($path, 1);
      }
    }
    return $path;
  }

  private function extensionRoot(): string {
    return dirname(__DIR__, 3);
  }

  private function isManagedWrapper(string $file): bool {
    $contents = @file_get_contents($file);
    return is_string($contents) && strpos($contents, self::MANAGED_MARKER) !== FALSE;
  }
}
