<?php
namespace Civi\ConfigManager\UI;
use Civi\ConfigManager\Version;

/**
 * Loads Configuration Manager UI assets through CiviCRM.
 *
 * A tiny critical stylesheet is also exposed to the Smarty template so layout
 * and hidden modal markup are stable before the external CSS request finishes.
 */
class AssetLoader {

  public function addResources(): void {
    $resources = \CRM_Core_Resources::singleton();
    $this->addStyleFileEarly($resources, 'css/configmanager.css');
    $resources->addScriptFile(Version::EXTENSION_KEY, 'js/configmanager.js');
    $resources->addScriptFile(Version::EXTENSION_KEY, 'js/settings-provider-browser.js');
  }

  public function getCriticalCss(): string {
    $file = dirname(__DIR__, 3) . '/css/configmanager-preload.css';
    return is_file($file) ? (string) file_get_contents($file) : '';
  }

  private function addStyleFileEarly(\CRM_Core_Resources $resources, string $file): void {
    if (!method_exists($resources, 'addStyleFile')) {
      return;
    }

    try {
      $method = new \ReflectionMethod($resources, 'addStyleFile');
      $paramCount = $method->getNumberOfParameters();
      if ($paramCount >= 4) {
        $resources->addStyleFile(Version::EXTENSION_KEY, $file, -1000, 'html-header');
      }
      elseif ($paramCount >= 3) {
        $resources->addStyleFile(Version::EXTENSION_KEY, $file, -1000);
      }
      else {
        $resources->addStyleFile(Version::EXTENSION_KEY, $file);
      }
    }
    catch (\Throwable $e) {
      $resources->addStyleFile(Version::EXTENSION_KEY, $file);
    }
  }
}
