<?php
namespace Civi\ConfigManager\Service;

use Civi\ConfigManager\Version;

/**
 * Installs terminal CLI wrappers without requiring root access.
 * Existing non-managed files are never overwritten.
 */
class CliInstaller {
  private ConfigManager $manager;

  public function __construct(?ConfigManager $manager = NULL) {
    $this->manager = $manager ?: new ConfigManager();
  }

  public function install(): array {
    $result = ['ok' => TRUE, 'bin_dirs' => [], 'installed' => [], 'path_helpers' => [], 'skipped' => [], 'errors' => []];
    foreach ($this->getBinDirs() as $binDir) {
      $result['bin_dirs'][] = $binDir;
      if (!is_dir($binDir) && !@mkdir($binDir, 0775, TRUE) && !is_dir($binDir)) {
        $result['ok'] = FALSE;
        $result['errors'][] = 'Could not create CLI bin directory: ' . $binDir;
        continue;
      }
      if (!is_writable($binDir)) {
        $result['ok'] = FALSE;
        $result['errors'][] = 'CLI bin directory is not writable: ' . $binDir;
        continue;
      }
      foreach ($this->commands() as $name => $command) {
        $target = $binDir . DIRECTORY_SEPARATOR . $name;
        if (is_file($target) && !$this->isManagedWrapper($target)) {
          $result['skipped'][] = $target . ' (existing non-managed file)';
          continue;
        }
        $script = $this->buildWrapperScript($command);
        if (@file_put_contents($target, $script) === FALSE) {
          $result['ok'] = FALSE;
          $result['errors'][] = 'Could not write CLI wrapper: ' . $target;
          continue;
        }
        @chmod($target, 0775);
        $result['installed'][] = $target;
      }
      foreach ($this->pathHelpers($binDir) as $name => $script) {
        $target = $binDir . DIRECTORY_SEPARATOR . $name;
        if (is_file($target) && !$this->isManagedWrapper($target)) {
          $result['skipped'][] = $target . ' (existing non-managed file)';
          continue;
        }
        if (@file_put_contents($target, $script) === FALSE) {
          $result['ok'] = FALSE;
          $result['errors'][] = 'Could not write CLI PATH helper: ' . $target;
          continue;
        }
        @chmod($target, $name === 'civicfg-env' ? 0664 : 0775);
        $result['path_helpers'][] = $target;
      }
    }
    return $result;
  }

  public function uninstall(): array {
    $result = ['ok' => TRUE, 'bin_dirs' => [], 'removed' => [], 'skipped' => [], 'errors' => []];
    foreach ($this->getBinDirs() as $binDir) {
      $result['bin_dirs'][] = $binDir;
      foreach (array_merge(array_keys($this->commands()), array_keys($this->pathHelpers($binDir))) as $name) {
        $target = $binDir . DIRECTORY_SEPARATOR . $name;
        if (!is_file($target)) {
          continue;
        }
        if (!$this->isManagedWrapper($target)) {
          $result['skipped'][] = $target . ' (existing non-managed file)';
          continue;
        }
        if (!@unlink($target)) {
          $result['ok'] = FALSE;
          $result['errors'][] = 'Could not remove CLI wrapper: ' . $target;
          continue;
        }
        $result['removed'][] = $target;
      }
    }
    return $result;
  }

  public function ensureSiteIdentifier(): string {
    return $this->manager->getSiteIdentifier();
  }

  private function getBinDirs(): array {
    $root = rtrim($this->manager->getProjectRoot(), DIRECTORY_SEPARATOR);
    $dirs = [];
    if ($root !== '') {
      $dirs[] = $root . DIRECTORY_SEPARATOR . 'bin';
      // Drupal/Backdrop usually report the CMS docroot. Add the Composer project
      // root one level above /web so commands also work from the site root.
      if (basename($root) === 'web') {
        $dirs[] = dirname($root) . DIRECTORY_SEPARATOR . 'bin';
      }
    }

    // Buildkit/DDEV convenience: allow a shared /var/www/html/bin when writable.
    if ((string) getenv('CIVICFG_DISABLE_SHARED_BIN') !== '1' && is_dir('/var/www/html') && is_writable('/var/www/html')) {
      $dirs[] = '/var/www/html/bin';
    }

    // Optional explicit terminal-level bin directory for beta/internal installs.
    // This is safer than editing shell profiles automatically. Example:
    // CIVICFG_GLOBAL_BIN_DIR="$HOME/.local/bin" cv ext:enable civi.config.manager
    foreach (['CIVICFG_GLOBAL_BIN_DIR', 'CIVICFG_BIN_DIR'] as $envName) {
      $dir = trim((string) getenv($envName));
      if ($dir !== '') {
        $dirs[] = $this->expandHome($dir);
      }
    }

    // If a known safe terminal bin directory is already in PATH and writable,
    // install there too so civicfg works directly in that terminal environment.
    foreach ($this->pathDirectories() as $dir) {
      if ($this->isPreferredWritablePathBin($dir)) {
        $dirs[] = $dir;
      }
    }

    return array_values(array_unique($dirs));
  }

  private function pathDirectories(): array {
    $path = (string) getenv('PATH');
    if ($path === '') {
      return [];
    }
    $dirs = [];
    foreach (explode(PATH_SEPARATOR, $path) as $dir) {
      $dir = trim($dir);
      if ($dir === '') {
        continue;
      }
      $dirs[] = $this->expandHome($dir);
    }
    return array_values(array_unique($dirs));
  }

  private function isPreferredWritablePathBin(string $dir): bool {
    if (!is_dir($dir) || !is_writable($dir)) {
      return FALSE;
    }
    $real = realpath($dir) ?: $dir;
    $home = rtrim($this->expandHome((string) getenv('HOME')), DIRECTORY_SEPARATOR);

    if (in_array($real, ['/usr/local/bin', '/opt/homebrew/bin', '/var/www/html/bin'], TRUE)) {
      return TRUE;
    }
    if ($home !== '' && (strpos($real, $home . DIRECTORY_SEPARATOR) === 0 || $real === $home)) {
      return preg_match('#/(bin|\\.local/bin)$#', $real) === 1;
    }
    return strpos($real, '/var/www/html/') === 0 && basename($real) === 'bin';
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

  private function commands(): array {
    return [
      'civicfg' => '',
      'cvcfg' => '',
      'config-export' => 'config-export',
      'ce' => 'ce',
      'config-import' => 'config-import',
      'ci' => 'ci',
      'config-diff' => 'config-diff',
      'cdf' => 'cdf',
      'config-validate' => 'config-validate',
      'cval' => 'cval',
    ];
  }

  private function pathHelpers(string $binDir): array {
    return [
      'civicfg-env' => $this->buildEnvHelper($binDir),
      'civicfg-path' => $this->buildPathHelper($binDir),
    ];
  }

  private function buildEnvHelper(string $binDir): string {
    $quoted = $this->shellSingleQuote($binDir);
    return <<<SH
# Managed by Configuration Manager extension. Do not edit manually.
# Source this file to add Configuration Manager wrappers to this shell:
#   . {$binDir}/civicfg-env
civicfg_bin_dir={$quoted}
case ":\${PATH:-}:" in
  *":\${civicfg_bin_dir}:"*) ;;
  *) export PATH="\${civicfg_bin_dir}:\${PATH:-}" ;;
esac
unset civicfg_bin_dir
SH;
  }

  private function buildPathHelper(string $binDir): string {
    $quoted = $this->shellSingleQuote($binDir);
    return <<<BASH
#!/usr/bin/env bash
# Managed by Configuration Manager extension. Do not edit manually.
set -euo pipefail
bin_dir={$quoted}

case "\${1:-}" in
  --check)
    case ":\${PATH:-}:" in
      *":\${bin_dir}:"*) echo "Configuration Manager CLI bin is in PATH: \${bin_dir}" ;;
      *) echo "Configuration Manager CLI bin is not in PATH: \${bin_dir}" >&2; exit 1 ;;
    esac
    ;;
  --shell)
    printf 'export PATH=%q:"\$PATH"\\n' "\${bin_dir}"
    ;;
  *)
    echo "Configuration Manager CLI wrappers are installed in: \${bin_dir}"
    echo "For this terminal session, run:"
    echo "  . \${bin_dir}/civicfg-env"
    echo "To make it permanent, add this line to your shell profile or project .envrc:"
    printf '  export PATH=%q:"\$PATH"\\n' "\${bin_dir}"
    ;;
esac
BASH;
  }

  private function buildWrapperScript(string $command): string {
    $commandLine = $command === ''
      ? 'exec "$extension_bin" "$@"'
      : 'exec "$extension_bin" ' . escapeshellarg($command) . ' "$@"';
    $template = <<<'BASH'
#!/usr/bin/env bash
# Managed by Configuration Manager extension. Do not edit manually.
set -euo pipefail
extension_bin=__EXTENSION_BIN__
extension_key=__EXTENSION_KEY__

if ! command -v cv >/dev/null 2>&1; then
  echo "Configuration Manager CLI requires the CiviCRM cv command in PATH." >&2
  exit 2
fi

status="$(cv ev 'try { $s = CRM_Extension_System::singleton()->getManager()->getStatus("__EXTENSION_KEY_RAW__"); echo $s ?: "missing"; } catch (Throwable $e) { echo "unknown"; }' 2>/dev/null || true)"
case "${status}" in
  installed|enabled) ;;
  disabled)
    echo "Configuration Manager extension is disabled. Enable ${extension_key} before running civicfg." >&2
    exit 2
    ;;
  *)
    echo "Configuration Manager extension is not installed/enabled on this site (status: ${status:-unknown})." >&2
    exit 2
    ;;
esac

if [[ ! -x "$extension_bin" ]]; then
  echo "Configuration Manager extension CLI is missing or not executable: $extension_bin" >&2
  exit 2
fi

__COMMAND_LINE__
BASH;
    return strtr($template, [
      '__EXTENSION_BIN__' => escapeshellarg($this->extensionRoot() . '/bin/civicfg'),
      '__EXTENSION_KEY__' => escapeshellarg(Version::EXTENSION_KEY),
      '__EXTENSION_KEY_RAW__' => addslashes(Version::EXTENSION_KEY),
      '__COMMAND_LINE__' => $commandLine,
    ]);
  }

  private function shellSingleQuote(string $value): string {
    return "'" . str_replace("'", "'\\''", $value) . "'";
  }

  private function extensionRoot(): string {
    return dirname(__DIR__, 3);
  }

  private function isManagedWrapper(string $file): bool {
    $contents = @file_get_contents($file);
    return is_string($contents) && strpos($contents, 'Managed by Configuration Manager extension') !== FALSE;
  }
}
