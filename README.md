# Configuration Manager

Configuration Manager is a CiviCRM extension that exports selected CiviCRM configuration to YAML, compares the active database with the YAML sync directory, and imports supported YAML changes back into CiviCRM.

- Extension key: `civi.config.manager`
- UI title: `Configuration Manager`
- Admin path: `civicrm/admin/config-manager`
- File format: YAML
- Current build: read from `info.xml`; this ZIP is `0.1.0-alpha61-core`
- Supported CiviCRM target: 5.x and 6.x

For release-by-release history, see `CHANGELOG.md`. For manual QA and round-trip checks, see `docs/TESTING.md`. Update the changelog and any affected current-behavior docs whenever a functional change is made.

Runtime YAML parsing uses the extension's bundled Symfony YAML dependency when the host CMS/CiviCRM stack does not already provide it. ZIP builds must therefore include production Composer dependencies.

## Development and beta policy

`0.1.0-alpha61-core` continues development on top of the complete alpha60 codebase. The extension is still pre-publication, so internal architecture can still be corrected before wider publication. Existing beta/alpha functionality and release history remain intact; no new beta, tag, or release is implied by this development build.

## Purpose

The extension is intended to provide a Drupal-style configuration workflow for CiviCRM:

1. Export configuration from CiviCRM to YAML.
2. Review and commit YAML changes in Git.
3. Move the YAML directory between environments.
4. Preview and import supported YAML changes into CiviCRM.

The YAML directory is treated as the deployable source of truth for supported configuration types. The current alpha development build continues from the latest internal beta codebase for development-project testing. Import can now create, update, and delete supported records, but only after preview and explicit confirmation.

Alpha60 additionally hardens generic API3 contributed-provider restore: create/update payloads are limited to fields accepted by the provider's create API when a usable `getfields` specification is available, and virtual provider imports such as SQLTasks remain isolated from unrelated extension-provider YAML through both CLI and UI preview/apply.

## Alpha61 safe first-run scope

Fresh installations now start with configuration types ignored until an administrator explicitly chooses what to manage or monitor. Existing installations without the fresh-install marker keep the historical `Manage everything` fallback, so upgrading does not silently disable an established workflow. The Settings page includes first-run guidance, bulk scope actions, and expanded collapsible management/advanced sections.

Local full QA does not require CiviCRM Buildkit: `composer qa:full` runs the isolated Docker integration suite without browser tests, while `composer qa:full-ui` runs the same stack plus Playwright and review screenshots.
Run those standalone QA commands from the **host repository checkout**, not from inside `ddev ssh`; they require host Docker/Compose. Generated `tests/ci/artifacts/` output is disposable and ignored by Git. When selected scope enables **Monitor everything else in this type**, saving the scope captures the initial local watch baseline so later watch scans can immediately report changes.
Watch-only detections are also retained in a bounded local recent-history list, so detecting another watched change (or running a later no-op scan) does not erase what was previously detected. **Scan Watched Config** reopens the Watched Configuration panel after the scan; the panel separates the latest scan from retained history and provides a history-only clear action that does not reset monitoring fingerprints. Local `qa:full-ui` can use the pinned Playwright Docker image when host Node/npm is unavailable, and Mailpit is no longer published to a host port, avoiding collisions with an existing local Mailpit/DDEV service.

## Current UI

The admin UI has four tabs.

### Synchronize

Shows the current difference between **managed** active CiviCRM configuration and managed YAML. Before the first export there is no baseline, so the page shows one initial-export prompt instead of listing every existing CiviCRM record as a difference. After the baseline exists, the main cards use concise human wording and keep API/capability/dependency/identity metadata inside `Details`.

Available actions:

- `Export` writes active managed CiviCRM changes to YAML. If a temporary type filter is active, related dependency-sensitive types are included automatically and the filter is cleared after export so the next Synchronize view shows the full managed status.
- `Import` opens an import preview for supported managed YAML-to-CiviCRM changes.
- `Validate` checks managed YAML structure and handler compatibility.
- `Details` shows the complete field-level comparison for a changed file.
- `Scan Watched Config` explicitly scans watch-only configuration and stores local fingerprints without adding those objects to YAML.

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

Controls the sync directory and the universal Configuration Scope policy.

Settings include:

- Sync Directory
- Configuration Scope: `Manage everything`, `Manage selected items`, `Monitor only`, or `Ignore` for each supported configuration type
- Lazy item pickers for `Manage selected items`, with optional monitoring of everything else
- Settings Allowlist as the safety boundary for CiviCRM settings that are eligible for scope/export
- Config Ignore

`Manage selected items` normally uses the Settings item picker: Configuration Manager lazily loads only the chosen configuration type, shows current CiviCRM labels, and stores a stable semantic selector automatically. Advanced selectors remain available for automation or missing items. A numeric ID or `id:123` is a local source selector only; exported YAML never uses that ID as the cross-environment identity. Stable names/keys, `key:<portable-config-key>`, and `path:<full-relative-yaml-path>` are also supported.

`Monitor only` and `Monitor everything else` are deliberately non-destructive. Watched objects are fingerprinted only during an explicit watch scan; they are not exported to YAML and cannot be imported, restored, or deleted until they are moved into managed scope.

Config Ignore accepts one relative YAML path or wildcard per line. Ignored files are skipped during diff, validate, export, and import. `extensions/civi.config.manager.yml` and legacy self-extension YAML keys are ignored by default to avoid self-management loops; remove it only if you intentionally want this extension to manage its own extension status.

Config Ignore Values accepts field-level rules in `path/to/file.yml:dot.path` format. Example: `settings/theme_frontend.yml:item.value` lets dev/stage/prod keep a different local theme while other managed settings remain portable. Ignored values are removed before diff, export, import, single-file preview, and ZIP download.

The Site Identifier is generated automatically and written to `manifest.yml`. A cloned dev/stage/prod database keeps the same value, so same-site environment sync works without manual setup. A different site receives a different value and import validation blocks the YAML unless Experimental Cross-site Import is enabled for a reviewed one-off migration.

Large contributed/custom extension API records are exported as split files under `extensions/<extension-key>/<api>/<entity>/<item>.yml`. The main `extensions/<extension-key>.yml` file keeps the extension status and safe settings, plus a `config_index` so related split files stay connected without creating one very large YAML file.

Generated/read-only provider records are intentionally skipped. For example, Mosaico base templates are derived from packaged extension files and contain local site URLs, so `MosaicoBaseTemplate` YAML is not exported/imported; user-created `MosaicoTemplate` records remain managed. If old `api3/MosaicoBaseTemplate/*.yml` files exist from an earlier alpha, run Export once to remove them from the sync directory.

The default scope is `Manage everything`, preserving the existing full-export workflow. In `Manage selected items`, only selected portable config keys participate in export/diff/validate/import. Existing YAML for a selector that is temporarily missing in CiviCRM is preserved and reported rather than silently removed. YAML for unselected objects is never interpreted as permission to delete those objects from CiviCRM.

Managed ZIP and single-file downloads also enforce the effective scope. A stale YAML backup left behind after deselecting an object may remain on disk for safety, but it is omitted from the managed archive; an unselected active object cannot be fetched by crafting a single-export request.

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

### Code-owned Configuration Scope

Configuration Scope can also be deployment-owned in `civicrm.settings.php` through CiviCRM's normal domain setting override:

```php
global $civicrm_setting;
$civicrm_setting['domain']['civicfg_scope'] = [
  'message-templates' => [
    'mode' => 'selected',
    'selectors' => ['12', '25'],
    'watch_unmanaged' => TRUE,
  ],
  'scheduled-jobs' => [
    'mode' => 'watch',
  ],
  'payment-processors' => [
    'mode' => 'ignore',
  ],
];
```

When `civicfg_scope` is code-owned, the Settings UI shows the scope as locked and will not overwrite it. Numeric selectors remain source selectors only; the export manifest maps configured selectors to semantic portable config keys for cross-environment matching.

## CLI terminal access

The extension owns one real CLI implementation at `bin/civicfg`. On install/enable it may create a single Composer launcher at `<vendor>/bin/civicfg` and one shared global `civicfg` dispatcher. The global dispatcher contains no project-specific extension path; it uses `cv` from `PATH` or a sibling Composer `vendor/bin/cv` launcher to bootstrap the current site and resolves the enabled extension path at runtime. Drupal 7/non-Composer sites therefore do not require a `vendor/bin` directory.

Configuration Manager does not create project-bin aliases, `/var/www/html/bin` copies, or shell PATH helper files. Existing non-managed `civicfg` commands are never overwritten. A local ownership registry lets several projects share the global dispatcher safely; uninstall removes the global dispatcher only after the final registered project is removed. See `docs/CLI.md`.

## API4 and CLI automation

The UI and CLI use the same API4 backend.

```bash
cv api4 ConfigManager.status
cv api4 ConfigManager.listTypes
cv api4 ConfigManager.diff
cv api4 ConfigManager.validate
cv api4 ConfigManager.watch
cv api4 ConfigManager.scopeGet
cv api4 ConfigManager.scopeItems type=message-templates
cv api4 ConfigManager.scopeSet type=message-templates mode=selected selectors='["key:..."]' watchUnmanaged=1
cv api4 ConfigManager.crossSiteStatus
cv api4 ConfigManager.crossSiteSet allowed=1
cv api4 ConfigManager.export dryRun=1
cv api4 ConfigManager.export dryRun=0
cv api4 ConfigManager.import dryRun=1 type=option-groups
cv api4 ConfigManager.import dryRun=0 yes=1 type=option-groups
```

Preferred CLI usage:

```bash
civicfg status
civicfg diff
civicfg validate
civicfg watch
civicfg scope --json
civicfg scope-items --type message-templates --json
civicfg scope-set --type message-templates --mode selected --selector 'key:<portable-config-key>' --watch-unmanaged
civicfg cross-site-import
civicfg cross-site-import --allow
civicfg cross-site-import --deny
civicfg export --write
civicfg export --type searchkit-saved-searches --write
civicfg import --dry-run
civicfg import --yes
```

The extension-local `ext/civi.config.manager/bin/civicfg` remains a direct fallback. Composer projects can additionally use `vendor/bin/civicfg`.

## Managed configuration types

Configuration Scope applies generically to every registered handler. Item-level `Manage selected items` is strongest for handlers that export one YAML file per object. CiviCRM Settings now export one YAML file per allowlisted setting, so the Settings Allowlist remains the safety boundary while Configuration Scope can manage/watch/ignore eligible settings just like other split-file configuration. Extension-owned providers can also be selected by stable key or YAML path when their discovered configuration is safely portable.

Current export/diff/validate support includes:

- Extensions
- Option Groups and Values
- Contact Types
- Relationship Types
- Location Types
- Financial Types
- Payment Processors, sanitized
- Custom Groups and Fields
- CiviCRM Settings (one file per allowlisted setting)
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
- CiviCRM Settings (one file per allowlisted setting)
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

Configuration types that can be scoped per object are exported as one YAML file per item wherever practical. Current split-file examples include:

- `contact-types/<name>.yml`
- `relationship-types/<name>.yml`
- `location-types/<name>.yml`
- `financial/<name>.yml`
- `payment-processors/<name>.yml`
- `dedupe-rules/<name>.yml`
- `searchkit/saved-searches/<name>.yml`
- `searchkit/displays/<saved-search>__<display>.yml`
- `formbuilder/afforms/<name>.yml`
- `scheduled-jobs/<name>.yml`
- `settings/<setting-name>.yml`
- `message-templates/system/<name>.yml`
- `message-templates/user/<name>.yml`
- `custom-data/groups/<name>.yml`
- `extensions/<extension-key>.yml`

Split files use a stable semantic identity and do not carry their local source database ID as the portable identity. CiviCRM setting files use the setting name as identity and never export a sensitive setting even if it is mistakenly added to the local allowlist. Message Templates use `workflow_name + is_default` for system workflow templates; user templates require a unique title before automatic writes are considered safe. Existing development collection YAML for handlers converted to split files remains accepted where the handler provides transitional import support, while a current full export rewrites the managed state into split files.

Extension-owned settings are stored in `extensions/<extension-key>.yml`; larger extension-owned API config is split into `extensions/<extension-key>/<api>/<entity>/<item>.yml` and linked from the extension file with `config_index`. The export manifest records `managed_scope` using semantic config keys; selected source selectors may be mapped to those keys so target environments do not need matching numeric IDs.

The export manifest is written to `manifest.yml`. Its `exported_with` value is read from `info.xml` at runtime, so the extension version only needs to be changed in `info.xml` for generated export metadata.

## Safety rules

- In `Manage all`, import can delete supported records that are present in CiviCRM but missing from YAML. Delete actions are shown as destructive actions in the import preview. Review the import preview before applying.
- In `Manage selected`, bulk delete-missing is disabled centrally. An object being absent from a selective YAML set never authorizes deletion of an unselected CiviCRM object.
- Watch-only and ignored objects are never import/delete candidates. Watch fingerprints live in local operational state, not portable YAML.
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

The status report is deliberately cheap. It never runs a full configuration diff from `hook_civicrm_check()` or an ordinary CiviCRM page request. It reports the initial-export requirement or reads the cached result from the last explicit managed scan.

The cached status can report:

- The initial YAML export has not been done.
- The last explicit managed scan found pending differences.
- The last explicit managed scan was in sync.
- YAML exists but no explicit scan has been recorded yet.

Run Synchronize or `civicfg diff` to refresh managed health. Run `civicfg watch` (or the UI watch action) to refresh watch-only state. This keeps Configuration Manager from becoming a performance tax on unrelated CiviCRM requests.

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

### CLI launcher lifecycle

`bin/civicfg` is the only implementation. Managed Composer/global launchers delegate to it dynamically; legacy generated aliases are removed only when they carry the Configuration Manager managed marker. CLI status checks are read-only. See `docs/CLI.md`.

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
- CLI uses the single `civicfg` command with `export`, `import`, `diff`, `validate`, and `status` subcommands.
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

Use the same subcommands through the global dispatcher, Composer launcher, or extension-local script:

```bash
civicfg status
civicfg export --write
civicfg diff
civicfg validate
civicfg watch
civicfg import --dry-run
civicfg import --yes
```

See `docs/CLI.md` for Composer/non-Composer behavior, ownership, registry, and uninstall details.

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


## Alpha 59 Notes

Alpha59 refines the alpha58 universal scope foundation for day-to-day administrator use:

- Settings now uses plain-language, mode-aware scope cards instead of showing an always-visible raw selector textarea for every type.
- `Manage selected items` provides a lazy searchable picker. Opening Settings does not enumerate configuration records; only the requested type is exported/discovered when its picker opens.
- Picker selections are stored as semantic `key:` selectors automatically. Local numeric IDs remain available only as advanced bootstrap selectors.
- The Settings page generates a copyable `civicrm.settings.php` example from the current scope choices, and code-owned scope remains read-only in the UI.
- Each registered handler shows a cheap capability label: full management, export/compare only, or mixed provider capabilities for contributed-extension configuration.
- Expected contributed-provider identity limitations are reported as compatibility information rather than YAML validation warnings; genuinely invalid or unsafe YAML still produces warnings/errors.
- Extension status changes now explain both sides in plain language, e.g. `YAML Installed but disabled → CiviCRM Enabled`.
- Large change lists stay collapsed by default after the summary when many individual items need review.
- Full relative `path:` selectors are now consistent between Settings help and runtime matching.
- The alpha59 hotfix makes picker search deterministic, adds API4/CLI scope and cross-site policy controls, and verifies the reviewed cross-site switch from UI/service/CLI tests.
- Contributed-provider target matching now correctly allows a strong identity with zero existing target matches to proceed to CREATE; only duplicate target identities are ambiguous. Created provider records are read back immediately so a provider cannot silently report success without producing restorable configuration.
- Expected backup/monitor-only provider limitations no longer inflate import warning counts, and non-write-safe contributed provider files are shown as backup-only rather than offering an impossible Restore action.
- Normal extension install/enable/disable plans are treated as expected import actions rather than warnings, and Message Template pickers prefer administrator-facing titles while retaining stable workflow identities underneath.
- SQLTasks remains handled inside the contributed-provider engine. The pinned 3.0.0-alpha3 provider has API3 create/update/delete semantics but its `Sqltask.get` requires one numeric ID, so Configuration Manager uses only its reviewed BAO `generator()`/`exportData()` pair for read-only collection enumeration; writes stay on API3 and nested task configuration remains preserved.
- Added service, static-analysis, unit, CLI, integration-fixture, and browser coverage for lazy scope discovery, picker search, mode-dependent controls, portable selectors, cross-site policy, target create identity safety, missing selected records, settings-file examples, and compatibility reporting.

## Alpha 58 Notes

Alpha58 introduces universal configuration scope and performance-safe monitoring on top of alpha57:

- Every registered configuration handler can be `Manage all`, `Manage selected`, `Watch only`, or `Ignore`; selected mode can optionally watch the remaining active objects.
- Numeric IDs are accepted only as source selectors. `manifest.yml` persists semantic config keys and selector mappings so selected objects remain portable across environments with different database IDs.
- Message Templates now use explicit portable identities and support safe selective management of customized system templates and unique user templates.
- Contact Types, Relationship Types, Location Types, Financial Types, Payment Processors, and Dedupe Rules now export as split item files so they can participate in item-level scope where practical.
- Watch-only state is stored in a local disposable table and refreshed only by an explicit watch scan. Watched objects never enter YAML and never become import/delete candidates.
- `hook_civicrm_check()` now reads cached health instead of running a full diff. Settings avoids virtual-provider discovery, and the Export page reuses its existing export preview instead of exporting every handler a second time just to populate the single-file selector.
- Synchronize suppresses the initial all-CiviCRM difference flood and shows concise human change descriptions after a baseline exists; generated API/capability/dependency/identity metadata stays in Details.
- Selected scope centrally disables bulk delete-missing, preserving the rule that absence from selective YAML never means an unselected active object should be removed.
- Managed ZIP and single-file downloads enforce the same effective scope: stale deselected YAML backups may remain safely on disk, but they are not packaged as managed configuration and crafted single-file requests cannot bypass selection.

## Alpha 56 Notes

Alpha56 replaces development-only identity/fingerprint internals with a stable semantic configuration model while preserving the existing export/import/diff/validate product workflows. YAML remains the portable source of truth. Database IDs and filenames are not trusted as cross-environment identities.

Key changes:

- semantic `config_key` identities with explicit confidence (`EXPLICIT`, `API_VERIFIED`, `DISCOVERED_UNIQUE`, `AMBIGUOUS`); ambiguous identities remain visible for export/diff but are not considered safe for automatic generic writes;
- deterministic, type-preserving SHA-256 fingerprints with a versioned canonicalization format;
- exact/path-aware runtime, ignored, and sensitive fields plus ordered/unordered collection metadata;
- semantic API4 reference export/import support for metadata-hook providers;
- local `civicrm_civicfg_object_state`, `civicrm_civicfg_baseline`, and `civicrm_civicfg_identity_alias` tables for rebuildable scan state, accepted three-way baselines, and confirmed identity renames;
- baseline-aware states such as active drift, YAML change, synchronized change, both-changed, and field-level conflict/non-conflicting divergence;
- conservative possible-rename suggestions which never auto-match or auto-write until explicitly confirmed;
- contributed-extension compatibility reporting (`FULL`, `PARTIAL`, `NO_PORTABLE_CONFIG`, `UNSUPPORTED`, `ERROR`) and broader real-fixture QA;
- the finalized single-implementation CLI lifecycle described above.

Because this extension has not yet been published for client use, alpha56 intentionally does not preserve obsolete development-only CLI aliases or weak fingerprint/identity formats.

## Alpha 55 Notes

- Continues directly from the full `0.1.0-beta2` codebase; beta1/beta2 history and beta-only functionality are retained.
- Synchronize status text now describes the current comparison state instead of assuming a change direction.
- The developer integration hook path is explicitly documented and regression-tested for custom/contributed extensions using normal CiviCRM hook dispatch.
- Generic API3 extension discovery now understands nested `Entity/Action.php` layouts and safe custom `get-all...` collection actions, and can hydrate listed records through `get` for fuller export data.
- Generic contrib exports remove common non-portable runtime timestamps such as `last_modified`, preventing source-site modification times from creating false drift or being sent back during updates on another environment.
- Extension setting matching accepts conservative singular namespaces such as `sqltask_*` for plural extension keys such as `sqltasks`, while retaining the existing secret/sensitive-setting safeguards.
- SQL Tasks remains a real-world QA fixture only; the production implementation stays generic and does not hard-code `de.systopia.sqltasks`.

## Extension integration hook

Other extensions can make their own APIv4-backed configuration exportable/importable with `hook_civicfg_entityDefinitions()`. This is the preferred integration path because it only requires metadata: entity name, stable key fields, export fields, ignored runtime fields, sensitive fields, and dependencies. See `docs/EXTENSION_HOOKS.md`.

Alpha61 follow-up hardening also discovers API3 capabilities directly from `Entity/Action.php` provider files before falling back to runtime `getactions`. SQLTasks 3.0.0-alpha3 is handled as a narrowly reviewed declarative provider from its installed `Sqltask/Create.php`/`Deletetask.php` files, while its BAO read adapter is loaded only when rows are actually read. Extension base-path lookup is resilient per provider and can conservatively fall back to the configured extensions directory for an installed key, avoiding discovery loss from stale mapper state in isolated CLI/QA bootstrap.
