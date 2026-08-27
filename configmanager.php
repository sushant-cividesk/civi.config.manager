<?php
/**
 * Configuration Manager (civi.config.manager).
 */

if (!defined('CIVICRM_UF')) {
  return;
}

/**
 * Basic PSR-4 style autoloader for this extension.
 */
spl_autoload_register(function ($class) {
  $prefixes = [
    'Civi\\Api4\\Action\\ConfigManager\\' => __DIR__ . '/Civi/Api4/Action/ConfigManager/',
    'Civi\\Api4\\' => __DIR__ . '/Civi/Api4/',
    'Civi\\ConfigManager\\' => __DIR__ . '/Civi/ConfigManager/',
    'CRM_Configmanager_' => __DIR__ . '/CRM/Configmanager/',
  ];

  foreach ($prefixes as $prefix => $baseDir) {
    if (strpos($class, $prefix) !== 0) {
      continue;
    }
    $relative = substr($class, strlen($prefix));
    if ($prefix === 'CRM_Configmanager_') {
      $relative = str_replace('_', '/', $relative);
    }
    else {
      $relative = str_replace('\\', '/', $relative);
    }
    $file = $baseDir . $relative . '.php';
    if (is_file($file)) {
      require_once $file;
    }
  }
});


/**
 * Runtime/install helper for local state schema and managed CLI launchers.
 */
function _configmanager_initialize_fresh_install_defaults(): void {
  if (!class_exists('Civi')) {
    return;
  }
  $current = \Civi::settings()->get('civicfg_scope_default_mode');
  if (!is_string($current) || !in_array($current, ['all', 'selected', 'watch', 'ignore'], TRUE)) {
    // Fresh installs are deliberately opt-in. Existing installations do not
    // receive this setting during upgrades, so their historical default
    // remains Manage everything until an administrator explicitly changes it.
    \Civi::settings()->set('civicfg_scope_default_mode', 'ignore');
  }
}

function _configmanager_lifecycle(bool $installCli = TRUE, bool $removeCli = FALSE): void {
  try {
    $installer = new \Civi\ConfigManager\Service\CliInstaller();
    $installer->ensureSiteIdentifier();
    $stateStore = new \Civi\ConfigManager\Service\StateStore();
    $operationStore = new \Civi\ConfigManager\Service\OperationStore();
    if ($removeCli) {
      $installer->uninstall();
      $operationStore->dropSchema();
      $stateStore->dropSchema();
    }
    else {
      $stateStore->ensureSchema();
      $operationStore->ensureSchema();
      if ($installCli) {
        $installer->install();
      }
    }
  }
  catch (\Throwable $e) {
    if (class_exists('Civi')) {
      \Civi::log()->warning('Configuration Manager lifecycle helper failed: ' . $e->getMessage());
    }
  }
}

/**
 * Implements hook_civicrm_install().
 */
function configmanager_civicrm_install() {
  _configmanager_initialize_fresh_install_defaults();
  _configmanager_lifecycle(TRUE, FALSE);
}

/**
 * Implements hook_civicrm_enable().
 */
function configmanager_civicrm_enable() {
  _configmanager_lifecycle(TRUE, FALSE);
}

/**
 * Implements hook_civicrm_uninstall().
 */
function configmanager_civicrm_uninstall() {
  _configmanager_lifecycle(FALSE, TRUE);
}

/**
 * Implements hook_civicrm_config().
 */
function configmanager_civicrm_config(&$config) {
  $templateDir = __DIR__ . '/templates';
  if (isset($config->templateCompileDir) && class_exists('CRM_Core_Smarty')) {
    CRM_Core_Smarty::singleton()->addTemplateDir($templateDir);
  }
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 */
function configmanager_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  $metaDataFolders[] = __DIR__ . '/settings';
}



/**
 * Implements hook_civicrm_permission().
 */
function configmanager_civicrm_permission(&$permissions) {
  foreach (\Civi\ConfigManager\UI\Permission::definitions() as $name => $definition) {
    $permissions[$name] = $definition;
  }
}

/**
 * Implements hook_civicrm_xmlMenu().
 */
function configmanager_civicrm_xmlMenu(&$files) {
  $files[] = __DIR__ . '/xml/Menu/configmanager.xml';
}

/**
 * Implements hook_civicrm_navigationMenu().
 */
function configmanager_civicrm_navigationMenu(&$menu) {
  _configmanager_insert_navigation_menu($menu, 'Administer/System Settings', [
    'label' => ts('Configuration Manager'),
    'name' => 'configuration_manager',
    'url' => 'civicrm/admin/config-manager?reset=1',
    'permission' => \Civi\ConfigManager\UI\Permission::ACCESS,
    'operator' => NULL,
    'separator' => FALSE,
    'active' => 1,
  ]);
}


/**
 * Implements hook_civicrm_check().
 */
function configmanager_civicrm_check(&$messages) {
  try {
    $manager = new \Civi\ConfigManager\Service\ConfigManager();
    $health = $manager->getHealth();
    $url = \CRM_Utils_System::url('civicrm/admin/config-manager', 'reset=1&op=sync', TRUE, NULL, FALSE, TRUE);
    $message = ts('%1 <a href="%2">Review Configuration Manager</a>.', [
      1 => (string) ($health['message'] ?? ''),
      2 => $url,
    ]);
    $title = ts((string) ($health['title'] ?? 'Configuration Manager'));
    $level = (string) ($health['level'] ?? 'warning');
    if ($level === 'info') {
      $severity = \Psr\Log\LogLevel::INFO;
      $icon = 'fa-check';
    }
    elseif ($level === 'error') {
      $severity = \Psr\Log\LogLevel::ERROR;
      $icon = 'fa-exclamation-triangle';
    }
    else {
      $severity = \Psr\Log\LogLevel::WARNING;
      $icon = 'fa-exclamation-triangle';
    }

    $messages[] = new \CRM_Utils_Check_Message(
      'configmanager_sync_status',
      $message,
      $title,
      $severity,
      $icon
    );
  }
  catch (\Throwable $e) {
    $messages[] = new \CRM_Utils_Check_Message(
      'configmanager_sync_status_error',
      ts('Configuration Manager could not read the sync status: %1', [1 => $e->getMessage()]),
      ts('Configuration Manager: Status check failed'),
      \Psr\Log\LogLevel::WARNING,
      'fa-exclamation-triangle'
    );
  }
}

/**
 * Implements hook_civicrm_managed().
 */
function configmanager_civicrm_managed(&$entities) {
  // Reserved for future managed entities.
}

/**
 * Insert navigation item into a path.
 */
function _configmanager_insert_navigation_menu(&$menu, $path, $item) {
  $parts = explode('/', $path);
  $current =& $menu;
  foreach ($parts as $part) {
    $found = NULL;
    foreach ($current as $key => &$entry) {
      if (!empty($entry['attributes']['name']) && $entry['attributes']['name'] === $part) {
        $found = $key;
        break;
      }
      if (!empty($entry['attributes']['label']) && $entry['attributes']['label'] === $part) {
        $found = $key;
        break;
      }
    }
    if ($found === NULL) {
      return;
    }
    if (!isset($current[$found]['child'])) {
      $current[$found]['child'] = [];
    }
    $current =& $current[$found]['child'];
  }

  $maxKey = empty($current) ? 0 : max(array_keys($current));
  $current[$maxKey + 1] = ['attributes' => $item];
}
