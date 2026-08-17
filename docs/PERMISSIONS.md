# Permissions

Configuration Manager defines granular permissions so sites can separate read, export, import, and administration responsibilities.

Release history is maintained in `../CHANGELOG.md`.

## Permission list

### access CiviCRM configuration manager

Allows a user to open the Configuration Manager UI and view status, pending changes, validation results, and diff details.

Recommended for:

- Technical administrators
- Developers
- Release managers

### export CiviCRM configuration

Allows a user to export active CiviCRM configuration to YAML, download the sync directory as a ZIP archive, and download single YAML files.

This permission can overwrite files in the sync directory.

Recommended for:

- Developers
- Release managers

### import CiviCRM configuration

Allows a user to stage YAML or ZIP files and apply supported create/update imports into CiviCRM.

This permission changes active CiviCRM configuration.

Recommended for:

- Release managers
- Senior technical administrators

### administer CiviCRM configuration manager

Allows a user to change Configuration Manager settings such as managed/watch/ignored scope, selectors, and the settings allowlist.

This also allows editing the Sync Directory when the directory is not code-owned.

Recommended for:

- Technical administrators responsible for deployment policy

## Superuser behavior

Users with `administer CiviCRM` are treated as superusers for this extension and can perform all Configuration Manager actions.

## Route and API behavior

- The admin route requires `access CiviCRM configuration manager`.
- UI actions perform additional permission checks before export, import, upload, download, and settings operations.
- API4 actions use matching permissions.

## Code-owned Sync Directory

When `civicfg_sync_dir` is set in `civicrm.settings.php`, users cannot change the Sync Directory from the UI, even if they have Configuration Manager administration permission.

Other settings remain editable according to normal permissions unless they are also overridden through `civicrm.settings.php`. Configuration Scope can be code-owned with `$civicrm_setting['domain']['civicfg_scope']`; the UI then displays it as locked.

## Production recommendation

For production environments:

- Grant access/status visibility to technical administrators.
- Grant export permission only to trusted developers or release managers.
- Grant import permission only to release managers or senior administrators.
- Grant administer permission only to the person/team controlling deployment policy.
