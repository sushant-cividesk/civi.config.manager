<?php
namespace Civi\ConfigManager\UI;

/**
 * Central permission names and checks for Configuration Manager.
 */
class Permission {
  public const ACCESS = 'access CiviCRM configuration manager';
  public const EXPORT = 'export CiviCRM configuration';
  public const IMPORT = 'import CiviCRM configuration';
  public const ADMINISTER = 'administer CiviCRM configuration manager';

  /**
   * Metadata used by hook_civicrm_permission().
   */
  public static function definitions(): array {
    return [
      self::ACCESS => [
        'label' => ts('Access Configuration Manager'),
        'description' => ts('View CiviCRM configuration sync status, pending changes, and validation results.'),
      ],
      self::EXPORT => [
        'label' => ts('Export CiviCRM Configuration'),
        'description' => ts('Export Current CiviCRM configuration to Saved Config files or download YAML/ZIP exports.'),
      ],
      self::IMPORT => [
        'label' => ts('Import CiviCRM Configuration'),
        'description' => ts('Upload or stage Saved Config files and apply supported configuration imports.'),
      ],
      self::ADMINISTER => [
        'label' => ts('Administer Configuration Manager'),
        'description' => ts('Change Configuration Manager sync directory, managed/watch/ignored scope, selectors, and settings allowlist.'),
      ],
    ];
  }

  public static function has(string $permission): bool {
    return \CRM_Core_Permission::check($permission) || \CRM_Core_Permission::check('administer CiviCRM');
  }

  public static function require(string $permission): void {
    if (!self::has($permission)) {
      \CRM_Core_Error::statusBounce(ts('You do not have permission to perform this Configuration Manager action.'));
    }
  }

  public function requireForPage(string $op, string $postAction): void {
    self::require(self::ACCESS);

    if (in_array($op, ['single-export-json', 'download-archive', 'download-single', 'export'], TRUE) || $postAction === 'export_write') {
      self::require(self::EXPORT);
    }

    if ($op === 'import' || in_array($postAction, ['import_apply', 'import_single_yaml', 'import_zip_archive', 'revert_file'], TRUE)) {
      self::require(self::IMPORT);
    }

    if (in_array($op, ['settings', 'scope-options-json', 'provider-inventory-json'], TRUE) || in_array($postAction, ['save_settings', 'ignore_config', 'scan_watch'], TRUE)) {
      self::require(self::ADMINISTER);
    }
  }
}
