<?php
namespace Civi\ConfigManager\Service;

use Civi\ConfigManager\Version;

/**
 * ConfigManagerEnvironment methods from ConfigManager.
 *
 * Methods run on the original owning object and retain the same state and
 * access modifiers. Do not instantiate this trait separately.
 */
trait ConfigManagerEnvironmentTrait {

  public function getSyncDir(): string {
    $dir = trim((string) \Civi::settings()->get('civicfg_sync_dir'));
    if ($dir === '' || $dir === '../civicrm-config') {
      $dir = 'civicrm-config';
    }

    if ($this->isUrlPath($dir)) {
      throw new \RuntimeException('Sync Directory Must Be A Server File Path, Not A URL.');
    }

    if ($dir[0] !== DIRECTORY_SEPARATOR) {
      $dir = rtrim($this->getProjectRoot(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $dir;
    }

    return $this->normalizePath($dir);
  }

  public function getDefaultSyncDirSetting(): string {
    return 'civicrm-config';
  }

  public function getSiteIdentifier(): string {
    $siteId = trim((string) \Civi::settings()->get('civicfg_site_id'));
    if ($this->isValidSiteIdentifier($siteId)) {
      return $siteId;
    }
    $siteId = $this->generateSiteIdentifier();
    \Civi::settings()->set('civicfg_site_id', $siteId);
    return $siteId;
  }

  public function isValidSiteIdentifier(string $siteId): bool {
    return $siteId !== '' && (bool) preg_match('/^[A-Za-z0-9_.:-]+$/', $siteId);
  }

  private function generateSiteIdentifier(): string {
    try {
      $bytes = random_bytes(16);
    }
    catch (\Throwable $e) {
      $bytes = sha1(uniqid('', TRUE) . microtime(TRUE), TRUE);
    }
    $hex = bin2hex($bytes);
    return 'civicfg-' . substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
  }

  public function confirmIdentityAlias(string $providerKey, string $oldConfigKey, string $newConfigKey): array {
    $providerKey = trim($providerKey);
    $oldConfigKey = trim($oldConfigKey);
    $newConfigKey = trim($newConfigKey);
    if ($providerKey === '' || $oldConfigKey === '' || $newConfigKey === '') {
      throw new \InvalidArgumentException('Provider key, old config key, and new config key are required.');
    }
    if ($oldConfigKey === $newConfigKey) {
      throw new \InvalidArgumentException('Identity alias requires two different configuration keys.');
    }
    $prefix = $providerKey . '|';
    if (strpos($oldConfigKey, $prefix) !== 0 || strpos($newConfigKey, $prefix) !== 0) {
      throw new \InvalidArgumentException('Identity alias keys must belong to the supplied configuration provider.');
    }

    $oldIdentity = [
      'provider_key' => $providerKey,
      'config_key' => $oldConfigKey,
      'identity_hash' => hash('sha256', $oldConfigKey),
    ];
    $newIdentity = [
      'provider_key' => $providerKey,
      'config_key' => $newConfigKey,
      'identity_hash' => hash('sha256', $newConfigKey),
    ];
    (new StateStore())->confirmAlias($oldIdentity, $newIdentity);

    return [
      'ok' => TRUE,
      'provider_key' => $providerKey,
      'old_config_key' => $oldConfigKey,
      'new_config_key' => $newConfigKey,
      'message' => 'Configuration identity rename confirmed. The alias will preserve baseline continuity after YAML is re-exported.',
    ];
  }

  public function getProjectRoot(): string {
    // Ask the active CiviCRM UF implementation first. This is the only source
    // here which understands the actual CMS root across Drupal, WordPress, and
    // Standalone in both HTTP and CLI contexts. In particular, WordPress CLI
    // does not reliably populate DOCUMENT_ROOT and civicrm.settings.php often
    // lives under wp-content/uploads/civicrm, which is not the project root.
    try {
      if (class_exists('CRM_Utils_System')) {
        // CRM_Utils_System proxies CMS-specific methods to the active UF
        // implementation. WordPress/Drupal each implement cmsRootPath().
        $cmsRoot = \CRM_Utils_System::cmsRootPath();
        if (is_string($cmsRoot) && trim($cmsRoot) !== '' && is_dir($cmsRoot)) {
          return $this->normalizePath($cmsRoot);
        }
      }
    }
    catch (\Throwable $e) {
      // Keep deterministic fallbacks for early bootstrap/test environments.
    }

    foreach ($this->getProjectRootCandidates() as $candidate) {
      if ($candidate !== '' && is_dir($candidate)) {
        return $this->normalizePath($candidate);
      }
    }

    try {
      $config = \CRM_Core_Config::singleton();
      if (!empty($config->configAndLogDir)) {
        return $this->normalizePath(dirname((string) $config->configAndLogDir));
      }
    }
    catch (\Throwable $e) {
      // Fall through to the process working directory.
    }

    return $this->normalizePath((string) getcwd());
  }

  private function getProjectRootCandidates(): array {
    $candidates = [];

    // These constants are CMS-aware and remain useful when ConfigManager is
    // instantiated in a partial/test bootstrap without a complete userSystem.
    if (defined('CIVICRM_CMSDIR')) {
      $candidates[] = (string) CIVICRM_CMSDIR;
    }
    if (defined('ABSPATH')) {
      $candidates[] = rtrim((string) ABSPATH, '/\\');
    }
    if (defined('DRUPAL_ROOT')) {
      $candidates[] = (string) DRUPAL_ROOT;
    }

    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
      $candidates[] = rtrim((string) $_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR);
    }

    foreach ($this->getSettingsFileCandidates() as $settingsFile) {
      $dir = dirname($settingsFile);
      if (basename($dir) === 'default' && basename(dirname($dir)) === 'sites') {
        $candidates[] = dirname($dir, 2);
      }
      $candidates[] = dirname($settingsFile);
      $candidates[] = dirname($settingsFile, 2);
    }

    try {
      $config = \CRM_Core_Config::singleton();
      if (!empty($config->userFrameworkResourceURL)) {
        // No-op. Referencing config here keeps the method safe across CMS variants.
      }
      if (!empty($config->configAndLogDir)) {
        $candidates[] = dirname((string) $config->configAndLogDir);
        $candidates[] = dirname((string) $config->configAndLogDir, 2);
        $candidates[] = dirname((string) $config->configAndLogDir, 3);
      }
    }
    catch (\Throwable $e) {
      // Ignore config discovery errors; other candidates may still work.
    }

    $candidates[] = (string) getcwd();

    return array_values(array_unique(array_filter($candidates, 'is_string')));
  }

  private function getSettingsFileCandidates(): array {
    $candidates = [];
    if (defined('CIVICRM_SETTINGS_PATH')) {
      $candidates[] = CIVICRM_SETTINGS_PATH;
    }
    if (!empty($_SERVER['CIVICRM_SETTINGS'])) {
      $candidates[] = $_SERVER['CIVICRM_SETTINGS'];
    }
    if (!empty($_ENV['CIVICRM_SETTINGS'])) {
      $candidates[] = $_ENV['CIVICRM_SETTINGS'];
    }
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
      $candidates[] = rtrim((string) $_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR) . '/sites/default/civicrm.settings.php';
    }
    return array_values(array_unique(array_filter($candidates, 'is_string')));
  }

  private function isUrlPath(string $path): bool {
    return (bool) preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $path);
  }

  private function normalizePath(string $path): string {
    $prefix = '';
    if (strpos($path, DIRECTORY_SEPARATOR) === 0) {
      $prefix = DIRECTORY_SEPARATOR;
    }

    $parts = [];
    foreach (explode(DIRECTORY_SEPARATOR, $path) as $part) {
      if ($part === '' || $part === '.') {
        continue;
      }
      if ($part === '..') {
        array_pop($parts);
        continue;
      }
      $parts[] = $part;
    }

    return $prefix . implode(DIRECTORY_SEPARATOR, $parts);
  }

  private function getManifestData(array $managedScope = []): array {
    $siteId = $this->getSiteIdentifier();
    $manifest = [
      'schema_version' => 1,
      'extension' => Version::EXTENSION_KEY,
      'format' => 'yaml',
      'exported_with' => Version::get(),
      'civicrm_min_version' => '5.0',
      'created_by' => 'Configuration Manager',
    ];
    if ($managedScope) {
      ksort($managedScope, SORT_NATURAL | SORT_FLAG_CASE);
      $manifest['managed_scope'] = $managedScope;
    }
    if ($siteId !== '') {
      $manifest['site_id'] = $siteId;
      $manifest['site_policy'] = 'same-site-environments';
    }
    return $manifest;
  }

}
