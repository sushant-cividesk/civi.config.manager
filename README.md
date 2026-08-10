# Configuration Manager

Configuration Manager is a CiviCRM extension that exports selected CiviCRM configuration to YAML, compares the active database with the YAML sync directory, and imports supported YAML changes back into CiviCRM.

- Extension key: `civi.config.manager`
- UI title: `Configuration Manager`
- Admin path: `civicrm/admin/config-manager`
- File format: YAML
- Current build: read from `info.xml`; this ZIP is `0.1.0-beta2`
- Supported CiviCRM target: 5.x and 6.x

For release-by-release history, see `CHANGELOG.md`. For manual QA and round-trip checks, see `docs/TESTING.md`. Update the changelog and any affected current-behavior docs whenever a functional change is made.

## Internal beta release policy

This beta is intended for Cividesk/internal development-project testing before wider production use. Future changes must be incremental and compatibility-aware: preserve existing YAML formats where possible, document any behavior changes, include migration/upgrade notes for existing users, and avoid breaking current extension installs without an explicit upgrade path.

## Purpose

The extension is intended to provide a Drupal-style configuration workflow for CiviCRM:

1. Export configuration from CiviCRM to YAML.
2. Review and commit YAML changes in Git.
3. Move the YAML directory between environments.
4. Preview and import supported YAML changes into CiviCRM.

The YAML directory is treated as the deployable source of truth for supported configuration types. This extension is currently an internal beta for development-project testing. Import can now create, update, and delete supported records, but only after preview and explicit confirmation.

## Current UI

The admin UI has four tabs.

### Synchronize

Shows the current difference between active CiviCRM configuration and YAML files.

Available actions:

- `Export` writes active CiviCRM changes to YAML. If a temporary type filter is active, related dependency-sensitive types are included automatically and the filter is cleared after export so the next Synchronize view shows the full managed status.
- `Import` opens an import preview for supported YAML-to-CiviCRM changes.
- `Validate` checks YAML structure and handler compatibility.
- `Diff` shows field-level details for a changed file.

### Import

Reviews YAML files in the sync directory and applies supported changes to CiviCRM.

Current import behavior:

- Supported handlers treat YAML as the source of truth.
- Import can create records that exist in YAML but not in CiviCRM.
- Import can update records that differ between YAML and CiviCRM.
- Import can delete supported records that exist in CiviCRM but not in YAML. Actual imports apply create/update first and then delete missing records in reverse dependency order where supported.
- The UI uses a confirmation modal before applying import changes. The user must review the warning and type `IMPORT`.
- CiviCRM may assign a new numeric ID when a deleted record is recreated from YAML; dependencies should rely on stable machine names wherever possible.
- Unsupported handlers are shown as not ready instead of applying partial changes.

The Import tab also supports uploading a single YAML file or a ZIP archive into the sync directory before previewing changes.

### Export

Exports active CiviCRM configuration to YAML.

Available options:

- Full export to the sync directory.
- ZIP download of the current sync directory.
- Single-file preview.
- Single-file YAML download.

### Settings

Controls the sync directory and the managed type filter.

Settings include:

- Sync Directory
- Managed Types
- Settings Allowlist
- Config Ignore

Config Ignore accepts one relative YAML path or wildcard per line. Ignored files are skipped during diff, validate, export, and import. `extensions/civi.config.manager.yml` and legacy self-extension YAML keys are ignored by default to avoid self-management loops; remove it only if you intentionally want this extension to manage its own extension status.

Config Ignore Values accepts field-level rules in `path/to/file.yml:dot.path` format. Example: `settings/civicrm.settings.yml:items.theme_frontend` lets dev/stage/prod keep different local theme or color settings while the rest of `settings/civicrm.settings.yml` remains managed. Ignored values are removed before diff, export, import, single-file preview, and ZIP download.

The Site Identifier is generated automatically and written to `manifest.yml`. A cloned dev/stage/prod database keeps the same value, so same-site environment sync works without manual setup. A different site receives a different value and import validation blocks the YAML unless Experimental Cross-site Import is enabled for a reviewed one-off migration.

Large contributed/custom extension API records are exported as split files under `extensions/<extension-key>/<api>/<entity>/<item>.yml`. The main `extensions/<extension-key>.yml` file keeps the extension status and safe settings, plus a `config_index` so related split files stay connected without creating one very large YAML file.

Generated/read-only provider records are intentionally skipped. For example, Mosaico base templates are derived from packaged extension files and contain local site URLs, so `MosaicoBaseTemplate` YAML is not exported/imported; user-created `MosaicoTemplate` records remain managed. If old `api3/MosaicoBaseTemplate/*.yml` files exist from an earlier alpha, run Export once to remove them from the sync directory.

Leaving Managed Types unchecked means all supported handlers are managed. If Managed Types is changed to a subset after YAML files already exist, the old YAML files are left on disk but ignored by status, diff, export, validate, and import until that type is enabled again. The extension does not delete those files automatically.

## Sync Directory

The Sync Directory must be a server-local filesystem path. It is not a URL and not a desktop/Finder path.

Recommended default:

```text
civicrm-config
```

Absolute path example:

```text
/var/www/html/civicrm-buildkit/build/drupal-civi/civicrm-config
```

Rules:

- Relative paths resolve from the CMS/project root where possible.
- `../civicrm-config` is treated as the legacy form of `civicrm-config`.
- Export creates the sync directory if the parent directory is writable by the web/PHP user.
- URL-style values such as `https://...` are rejected.
- Do not point the sync directory at a public upload directory containing live files or secrets.

### Code-owned Sync Directory

For environment-specific deployments, define the path in `civicrm.settings.php`:

```php
global $civicrm_setting;
$civicrm_setting['domain']['civicfg_sync_dir'] = '/var/www/html/civicrm-buildkit/build/drupal-civi/civicrm-config';
```

When this setting is present, the UI shows the Sync Directory as locked and does not allow UI edits to override the code-defined value.

## CLI terminal access

After install/enable, the extension installs managed wrappers into project/shared `bin` directories and writes `civicfg-env` / `civicfg-path` helpers. For beta testing, use project-level PATH rather than editing shell profiles automatically:

```bash
. /var/www/html/bin/civicfg-env
command -v civicfg
civicfg status
```

For a permanent developer setup, add the project/shared bin directory to the shell profile or project `.envrc`. See `docs/CLI.md`.

## API4 and CLI automation

The UI and CLI wrappers use the same API4 backend.

```bash
cv api4 ConfigManager.status
cv api4 ConfigManager.listTypes
cv api4 ConfigManager.diff
cv api4 ConfigManager.validate
cv api4 ConfigManager.export dryRun=1
cv api4 ConfigManager.export dryRun=0
cv api4 ConfigManager.import dryRun=1 type=option-groups
cv api4 ConfigManager.import dryRun=0 yes=1 type=option-groups
```

Preferred CLI commands are available under the extension `bin/` directory:

| Main command | Alias | Purpose |
|---|---|---|
| `bin/config-export` | `bin/ce` | Export active CiviCRM configuration to YAML. |
| `bin/config-import` | `bin/ci` | Preview or apply YAML configuration import. |
| `bin/config-diff` | `bin/cdf` | Show YAML vs active CiviCRM differences. |
| `bin/config-validate` | `bin/cval` | Validate YAML and dependency safety. |
| `bin/civicfg` | `bin/cvcfg` | General wrapper for all operations. |

Examples:

```bash
ext/civi.config.manager/bin/ce --write
ext/civi.config.manager/bin/ce --type searchkit-saved-searches --write
ext/civi.config.manager/bin/ci --dry-run
ext/civi.config.manager/bin/ci --yes
ext/civi.config.manager/bin/cdf
ext/civi.config.manager/bin/cval
ext/civi.config.manager/bin/civicfg ce --write
ext/civi.config.manager/bin/civicfg ci --yes
```

On install/enable, the extension attempts to create project-level wrappers in `<project-root>/bin` for `civicfg`, `cvcfg`, `config-export`, `ce`, `config-import`, `ci`, `config-diff`, `cdf`, `config-validate`, and `cval`. Existing non-managed files are never overwritten. The project-level wrappers check whether the extension is installed/enabled before delegating to the extension `bin/civicfg` script.

## Managed configuration types

Current export/diff/validate support includes:

- Extensions
- Option Groups and Values
- Contact Types
- Relationship Types
- Location Types
- Financial Types
- Payment Processors, sanitized
- Custom Groups and Fields
- CiviCRM Settings Allowlist
- Message Templates
- Dedupe Rules
- Scheduled Jobs
- SearchKit Saved Searches
- SearchKit Displays
- FormBuilder Afforms
- Site Tokens, when `SiteToken` API4 exists
- Contributed/custom extension settings and extension-provided config, bundled under each extension YAML file when safely discoverable
- CiviRules, alpha support when CiviRules API4 entities exist

Current create/update import support includes:

- Extensions, conservative install/enable/disable only. Extension status changes exported from CiviCRM can be imported back from YAML, including disable, when the extension code is available. Uninstall/delete is not performed, and Configuration Manager skips disabling itself so the import can finish safely.
- Option Groups and Values
- Contact Types
- Relationship Types
- Location Types
- Financial Types
- Custom Groups and Fields
- CiviCRM Settings Allowlist
- Message Templates
- Dedupe Rules
- Scheduled Jobs
- SearchKit Saved Searches
- SearchKit Displays
- FormBuilder Afforms
- Site Tokens, when `SiteToken` API4 exists
- Contributed/custom extension settings and extension-provided config, bundled under each extension YAML file when safely discoverable
- CiviRules, alpha support when CiviRules API4 entities exist

Payment Processors remain export/diff only because exported data is sanitized and may omit environment-specific or secret values.

## YAML layout

Most stable config types are stored as collection files, for example `extensions/extensions.yml` or `option-groups/*.yml`. High-churn config types are stored as one YAML file per item:

- `searchkit/saved-searches/<name>.yml`
- `searchkit/displays/<saved-search>__<display>.yml` for new exports, with older `<display>.yml` files still accepted
- `formbuilder/afforms/<name>.yml`
- `scheduled-jobs/<name>.yml`
- `message-templates/system/<name>.yml`
- `message-templates/user/<name>.yml`
- `custom-data/groups/<name>.yml`
- `extensions/<extension-key>.yml`

Each split file uses `type: <handler>.item`, stores the editable record under `item`, and includes a `dependencies` section where dependencies are detectable. Export also adds `required_by` reverse metadata where another YAML file depends on the current item, making dependency review easier from either direction. Extension-owned settings are stored in `extensions/<extension-key>.yml`; larger extension-owned API config is split into `extensions/<extension-key>/<api>/<entity>/<item>.yml` and linked from the extension file with `config_index`. Collection files use `type: <handler>.collection` and an `items` list. Existing collection files for these handlers are still accepted for import, but the current export format rewrites them as split files.

The export manifest is written to `manifest.yml`. Its `exported_with` value is read from `info.xml` at runtime, so the extension version only needs to be changed in `info.xml` for generated export metadata.

## Safety rules

- Import can delete supported records that are present in CiviCRM but missing from YAML. Delete actions are shown as destructive actions in the import preview. Review the import preview before applying.
- Machine names are treated as identities.
- Suspected machine-name renames are warned and skipped.
- Dependency metadata is validated where available. Missing managed YAML dependencies are treated as import-blocking errors to avoid broken relationships. Reverse `required_by` metadata is also checked and reported as a warning when it appears stale or incomplete.
- Large scalar values such as HTML message-template bodies are truncated in UI previews; the YAML and field-level diff still carry the complete value.
- Payment processor secrets are never exported.
- Live transactional data is never exported.
- ZIP upload only stages YAML files under the configured sync directory.
- SearchKit Saved Searches, SearchKit Displays, FormBuilder Afforms, and Scheduled Jobs are exported as one YAML file per item so small changes are easier to review.
- Split item files include dependency metadata where the extension can detect it. SearchDisplay files declare their SavedSearch dependency; SavedSearch files declare related SearchDisplays; Afform files declare referenced SearchKit displays where detectable.
- Custom field exports store `option_group_name` instead of numeric `option_group_id` where possible, so YAML is safer across environments. Legacy YAML with numeric option group IDs is still accepted during validation/import.
- Option values are validated using the full option value entry, not just the `name` field, because some core CiviCRM option groups legitimately reuse option value names with different stored values.
- Config Ignore can be used to intentionally leave environment-specific YAML files unmanaged.
- Temporary filtered exports include related dependency-sensitive config types automatically. For example, SearchKit Saved Searches, SearchKit Displays, and FormBuilder Afforms are exported together because they commonly reference each other. Custom Groups and Fields can include Option Groups and Contact Types. Relationship Types can include Contact Types. The UI warns before exporting a filtered set when dependency types will be added, and the confirmation uses `EXPORT` to distinguish it from destructive imports.
- After a filtered export, the UI clears the temporary filter and reloads the full managed diff to avoid showing a misleading In Sync state for only the filtered subset. POST actions redirect after completion, so browser refresh does not resubmit export/import forms.

## System status integration

The extension implements a CiviCRM status check.

The status report warns when:

- The initial YAML export has not been done.
- The sync directory exists but has no YAML files.
- CiviCRM and YAML have pending differences.

When the database and YAML are in sync, the status check reports an informational in-sync notice. CiviCRM displays these checks anywhere normal system-check notices are shown, including the status report page and admin login notification flow.

## Permissions

The extension defines granular permissions:

- `access CiviCRM configuration manager`
- `export CiviCRM configuration`
- `import CiviCRM configuration`
- `administer CiviCRM configuration manager`

Users with `administer CiviCRM` are treated as superusers for this extension.

See `docs/PERMISSIONS.md` for details.

## Development notes

Important source areas:

- `CRM/Configmanager/Page/Main.php` - thin CiviCRM page wrapper.
- `Civi/Api4/*` - API4 facade and actions.
- `Civi/ConfigManager/Service/*` - orchestration and handler registry.
- `Civi/ConfigManager/Handler/*` - config-type handlers.
- `Civi/ConfigManager/Storage/YamlFileStorage.php` - YAML file storage.
- `Civi/ConfigManager/UI/*` - UI request, presenter, transfer, permissions, assets.
- `templates/CRM/Configmanager/Page/*.tpl` - Smarty templates and partials.
- `css/configmanager.css` - scoped UI styles.
- `css/configmanager-preload.css` - tiny critical preload stylesheet.
- `js/configmanager.js` - vanilla JavaScript interactions.

See `docs/ARCHITECTURE.md` for the implementation structure and `docs/IMPLEMENTATION_PLAN.md` for current technical decisions.


## Alpha37 Notes

- Added Config Ignore to skip selected YAML files from diff, validate, export, and import. This is useful for environment-specific configuration and for avoiding self-management of this extension.
- `extensions/civi.config.manager.yml` is ignored by default. Exporting this extension's own status can create a circular dependency because the extension must stay enabled to finish imports.
- SearchDisplay import now uses `saved_search_id.name + name` as the stable identity. This avoids duplicate `Table` display failures when a target site already has extension-provided SearchKit displays.
- New SearchDisplay exports include the SavedSearch name in the filename to avoid collisions. Older display filenames are still read.
- Already-existing records are treated as warnings when they can be matched safely instead of as hard errors.

## Alpha36 Notes

- Extension status is now exported as one YAML file per extension key under `extensions/`, instead of one large collection file. This makes future extension-specific config files easier to review and group.
- Import/export/validate/settings forms show a full-page progress overlay and disable controls while the request is running, which helps prevent double submits.
- Import failures are saved across the post/redirect/get flow so the next page can show the exact handler/file error instead of only a generic toast.
- Site Tokens now have an optional handler. It exports/imports `SiteToken` API4 records when that API4 entity is available and clearly blocks import when the target site lacks the provider.
- Custom Groups and Fields now support YAML-source deletes for missing custom fields and non-reserved missing custom groups. Option group references are resolved by `option_group_name` where possible.
- CiviRules has an alpha handler for common CiviRules API4 entities when the CiviRules extension exposes them. This still needs real-world testing with rule triggers, conditions, actions, and extension-provided rule components.


### CLI wrappers

The extension includes dedicated command wrappers in `bin/` for teams that prefer short commands over raw API4 calls. Run them from a bootstrapped CiviCRM project where `cv` is available. On install/enable, Configuration Manager also attempts to install project-level wrappers in `<project-root>/bin` so teams can run `civicfg`, `ce`, `ci`, `cdf`, and `cval` from the project without typing the extension path.

```bash
ext/civi.config.manager/bin/config-export --write
ext/civi.config.manager/bin/ce --type searchkit-saved-searches --write
ext/civi.config.manager/bin/config-import --dry-run
ext/civi.config.manager/bin/ci --yes
ext/civi.config.manager/bin/config-diff
ext/civi.config.manager/bin/config-validate
```

The wrappers call the same API4 backend as the UI, so dependency expansion, Config Ignore, validation, and import safety rules are shared.

### Config Ignore

Config Ignore accepts one relative YAML path or wildcard per line. Ignored files are skipped during diff, validate, export, import, single-file preview, and ZIP download. Do not ignore a YAML file that is a dependency of a non-ignored YAML file. Validation will show a dependency warning or error when it can detect this situation.

`extensions/civi.config.manager.yml` is ignored by default to avoid self-management loops while the extension is running an import.

### Environment workflow

The safest target workflow is one site codebase moving configuration between its own environments: dev, stage, and production. Cross-site imports are possible but require extra review because extensions, sample data, IDs, and contributed-extension defaults can differ between sites.



## Alpha 41 Notes

- Removed the separate `extension-config` and `extension-settings` managed types from the registry to prevent hundreds of duplicate YAML files.
- Bundled safely discoverable contributed/custom extension settings and extension API config under each `extensions/<extension-key>.yml` file.
- Generic extension config discovery now skips CiviCRM core component extensions and already-managed core handlers so operational data such as line items, events, financial accounts, SearchKit, and FormBuilder is not duplicated.
- Added option-value delete/revert support for non-reserved option values that exist in CiviCRM but are missing from YAML. Reserved option values are still skipped with a warning.
- Import summary counts now include nested option value, bundled extension setting, and bundled extension config create/update/delete results.

## Alpha 40 Notes

- Added generic contributed/custom extension support instead of hard-coded handlers for individual extensions.
- Generic Extension Entity Config discovers installed extension API4/APIv3 entities and exports records with stable identities under `extension-config/<extension>/<api>/<entity>/<item>.yml`.
- Generic Extension-specific Settings discovers non-secret settings from Setting metadata and installed-extension namespaces; password/secret/token/API-key style names are blocked.
- Dependency validation now gives clearer messages when required YAML is missing or when old YAML still contains local numeric IDs.
- If a provider extension/API entity is unavailable on the target site, validation/import reports the missing provider instead of fataling.

## Alpha 39 Notes

- Config Ignore is applied consistently to diff, validate, import, export, single-file preview, and ZIP download. Ignored DB-only records are hidden from Synchronize when their generated YAML path matches an ignore rule.
- Saving Config Ignore now checks for detectable non-ignored YAML files that depend on ignored YAML files and warns the administrator.
- CLI aliases are available as `config-export`/`ce`, `config-import`/`ci`, `config-diff`/`cdf`, `config-validate`/`cval`, and `civicfg`/`cvcfg`. Use `-h` or `--help` for usage, and `-y`/`--yes` for import apply.
- UI compatibility styles were adjusted so buttons and panels render more consistently across CiviCRM core themes.


## Alpha 42 Notes

- Extension status/settings remain in `extensions/<extension-key>.yml`. Generic extension-owned API config is split by item under the same extension directory.
- Field-level ignore rules use `path.yml:dot.path` and are intended for environment-specific values, not dependencies or required identities.
- The Site Identifier is generated automatically for one site family across dev/stage/prod; Experimental Cross-site Import remains a reviewed migration tool, not a general cross-site synchronization guarantee.

## Alpha 43 Notes

- Site Identifier is now automatic and read-only in the UI. It is stored in CiviCRM settings and exported to `manifest.yml`.
- Cross-site Import is labelled experimental and should stay disabled for normal dev/stage/prod workflows.
- Export adds reverse `required_by` metadata in addition to forward `dependencies`, so dependency review works both directions.
- Project-level CLI wrappers are installed when possible without overwriting non-managed files, and they warn if the extension is disabled.
- Button styling is normalized inside the Configuration Manager page for CiviCRM core/custom theme compatibility.

## Alpha 45 Notes

- The machine key is now `civi.config.manager`. The visible UI name remains `Configuration Manager`.
- The Synchronize screen includes per-file Revert and Ignore actions. Revert makes the selected YAML match active CiviCRM. Ignore can save either a whole-file ignore rule or selected field-level ignore rules.
- Extension-owned config filters are discovered dynamically from supported contributed/custom extension APIs. If an enabled extension exposes safe importable config entities, those entities can appear as separate filter/managed-type options.
- Generic extension config export skips read-only/generated API entities that cannot be recreated or updated through API. This avoids broken cross-environment imports for provider-generated records.


## Alpha 46 Notes

- Revert on the Synchronize screen now applies YAML back into active CiviCRM for the selected file and its dependency closure. It no longer rewrites YAML from the current database value.
- Managed Types and Filter Config Types now render extension-owned config more cleanly, with the provider extension shown as secondary text.
- Sync status language now distinguishes changed fields, added-in-CiviCRM files, and added-in-YAML files instead of calling every difference a change.
- `menubar_color` and `menubar_position` are included in the recommended settings allowlist so Riverlea menu-bar environment differences can be detected or ignored field-by-field.

## Alpha 47 Notes

- Synchronize now keeps the technical YAML/file view but adds plain-language explanations so non-developers can see whether a record was changed, added in CiviCRM, added in YAML, or removed.
- Managed type filters are grouped into standard CiviCRM config and extension-owned config discovered from enabled contrib/custom extensions.
- Whole-file ignore now avoids leaving stale extension config index references when the ignored file belongs to split extension-owned config.
- Field-level ignore UI now automatically selects the field-level option when fields are checked and clears fields when whole-file ignore is chosen.

## CLI usage

The extension ships with `bin/civicfg` plus aliases: `ce`, `ci`, `cdf`, `cval`, `cvcfg`, `config-export`, `config-import`, `config-diff`, and `config-validate`. On install/enable, Configuration Manager attempts to install project wrappers in these locations when writable:

- `<cms-docroot>/bin`
- `<project-root>/bin` when the CMS docroot is named `web`
- `/var/www/html/bin` in DDEV/buildkit containers when writable

Examples from a CiviCRM build:

```bash
civicfg status
ce --write
ci --dry-run
ci --yes
cdf --type settings
cval
```

If the wrapper is not in `PATH`, call it by path, for example:

```bash
/var/www/html/build/dcivi-dev/bin/ce --write
/var/www/html/bin/civicfg status
```

Wrappers are managed files. They are not written over existing non-managed files. When the extension is disabled or unavailable, the wrapper stops with a clear warning instead of running stale code.

## Alpha 48 Notes

- Sync, import, and export review screens now show shorter plain-language descriptions for common changed fields such as contact type labels, option value weights, extension settings, and extension-owned config records.
- Review cards were restyled to make changed/added/removed records easier to scan across CiviCRM themes.
- Config Ignore field selection is more robust: checking a field switches to field-level ignore, while switching back to whole-file ignore clears field selections.
- Generic extension settings discovery now also reads runtime settings stored in `civicrm_setting`, so extensions such as SQLTasks can export additional `sqltasks_*` values even when they are not fully described by setting metadata.
- Generic API3 discovery was broadened for contributed/custom extensions that expose importable API records but do not publish `getactions` consistently. Read-only/generated entities are still skipped.

## Automated QA

This repository includes a fast GitHub Actions workflow for every push/pull request and a manually triggered full CiviCRM Standalone workflow. The full workflow creates an isolated database and YAML directory, blocks outbound application networking and email delivery, generates disposable API4 fixtures, tests CLI/API/service round trips, and can run Playwright UI/UX and accessibility checks.

See `docs/QA_AUTOMATION.md` and `tests/scenarios/README.md`.

## Alpha 49 Notes

- Added push-ready GitHub Actions workflows: `QA - Fast` for every push/pull request and `QA - Full CiviCRM Extension` for manual disposable CiviCRM Standalone runs.
- The fast workflow runs Composer validation, PHP syntax, scenario contracts, unit tests, PHPStan, a dedicated metadata-hook unit check, and Composer audit. PHPCS/PHP-compatibility cleanup is intentionally outside the required fast path until the existing style baseline is fixed.
- The full workflow runs the extension in an isolated CiviCRM container with disposable MariaDB, Mailpit, blocked PHP mail, sanitized artifacts, API/CLI smoke tests, integration fixtures, and optional Playwright checks.
- The YAML sync root may now be a symlink to support dev/stage shared-config test setups; files and subdirectories inside the sync root are still protected against traversal and symlink escapes.


## Alpha 54 Notes

- Added stronger automated coverage for the preferred `hook_civicfg_entityDefinitions()` integration path.
- The metadata-hook tests now cover stable-key export, collection YAML, where/order metadata, composite keys, update/create/dry-run/delete-missing imports, import-disabled definitions, invalid YAML validation, sensitive-field blocking, and ignored-field diff behavior.
- GitHub fast and full workflows now include a dedicated required `composer test:hook` / `EntityDefinitionHandlerTest` step so hook regressions are easy to spot in Actions.

## Extension integration hook

Other extensions can make their own APIv4-backed configuration exportable/importable with `hook_civicfg_entityDefinitions()`. This is the preferred integration path because it only requires metadata: entity name, stable key fields, export fields, ignored runtime fields, sensitive fields, and dependencies. See `docs/EXTENSION_HOOKS.md`.
