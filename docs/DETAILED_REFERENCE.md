# Configuration Manager Detailed Reference

This document preserves detailed current-behavior notes that are useful for maintainers and advanced operators but are intentionally kept out of the repository landing page.

For the concise product overview and quick start, see [`../README.md`](../README.md). For system design, see [`ARCHITECTURE.md`](ARCHITECTURE.md). Release history belongs in [`../CHANGELOG.md`](../CHANGELOG.md).

---


Configuration Manager is a CiviCRM extension that exports selected CiviCRM configuration to YAML, compares the active database with the YAML sync directory, and imports supported YAML changes back into CiviCRM.

- Extension key: `civi.config.manager`
- UI title: `Configuration Manager`
- Admin path: `civicrm/admin/config-manager`
- File format: YAML
- Current build: read from `info.xml`; this source is `0.1.0-alpha68.1-core`, developed from the protected `v1.0.0-beta1` baseline
- Supported CiviCRM target: 5.x and 6.x

For release-by-release history, see `CHANGELOG.md`. For manual QA and round-trip checks, see `docs/TESTING.md`. Update the changelog and any affected current-behavior docs whenever a functional change is made.

Runtime YAML parsing/dumping uses the extension's bundled Symfony YAML dependency when the host CMS/CiviCRM stack does not already provide it. Official ZIP builds include production Composer dependencies. A source checkout may use ext-yaml only when both `yaml_parse_file()` and `yaml_emit()` are available; there is no production hand-written YAML serializer.

## Development and beta policy

`v1.0.0-beta1` remains the protected first clean beta of the real-world hardening line. Current work continues on numbered development alphas; `1.0.0-beta2` is not released until the checklist and evidence gates in [`PROJECT_STATUS.md`](PROJECT_STATUS.md) pass and publication is explicitly approved.

Alpha63 specifically adds deterministic monitor-only snapshots for duplicate/unproven identities, per-identity delete safety, disk-spooled one-pass provider reads, durable multi-item Queue plans, persistent staging metadata, hard-interruption YAML publish recovery, WordPress session-lock release, and semantic phase/heartbeat progress. The safety distinction is deliberate: intentional source monitor-only rows are skipped without blocking unrelated safe items, while a source identity which was proven portable but becomes ambiguous on the target is a blocking preflight conflict.

## Purpose

The extension is intended to provide a Drupal-style configuration workflow for CiviCRM:

1. Export configuration from CiviCRM to YAML.
2. Review and commit YAML changes in Git.
3. Move the YAML directory between environments.
4. Preview and import supported YAML changes into CiviCRM.

The YAML directory is treated as the deployable source of truth for supported configuration types. The current alpha development build continues from the latest internal beta codebase for development-project testing. Import can now create, update, and delete supported records, but only after preview and explicit confirmation.

Alpha60 additionally hardens generic API3 contributed-provider restore: create/update payloads are limited to fields accepted by the provider's create API when a usable `getfields` specification is available, and virtual provider imports such as SQLTasks remain isolated from unrelated extension-provider YAML through both CLI and UI preview/apply.

## Legacy CiviCRM 5.76 / PHP 7.4 compatibility

The extension source remains compatible with PHP 7.4 and CiviCRM 5.76-era Drupal 7 installations. Fast CI includes PHP 7.4, and Composer dependency resolution is pinned to a PHP 7.4.33 platform so a production `composer install --no-dev` selects a compatible Symfony YAML release. Optional providers such as SearchKit displays, FormBuilder Afforms, CiviContribute-backed financial entities, SiteToken, or CiviRules are detected at runtime. If a provider is unavailable, Configuration Manager reports it explicitly and fails closed for that managed type; it never interprets a missing API4 class as an authoritative empty set or uses that absence to delete existing YAML/configuration.

For a source checkout or package without `vendor/`, install runtime dependencies inside the extension directory before enabling import/validation:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
```

`vendor/` is intentionally not committed. Release/installable packages should bundle the resulting production `vendor/` directory, or the target host must provide the PHP yaml extension.

After installation, `civicfg status` reports the active PHP/CiviCRM versions, YAML parser, and any optional configuration provider that is unavailable on the current site. It also reports whether Symfony YAML is available, whether the extension-local `vendor/autoload.php` is present, and whether PHP ext-yaml provides parser/emitter functions. The CiviCRM System Status check is an error when neither Symfony YAML nor a complete ext-yaml read/write runtime is available. This makes older Drupal 7/CiviCRM environments fail visibly and safely instead of silently writing YAML with an incomplete fallback.

## Runtime CRUD and provider safeguards

Configuration Manager checks the runtime API surface before advertising automatic management. **Managed** is the concise label for the normal reviewed management path. **Create + update** is used when a handler deliberately disables delete-missing even though restore/import create and update are supported. **Export + compare** means automatic restore/import is not enabled. These labels describe the handler's reviewed policy; automatic removal remains provider-specific and is never inferred merely because an API exposes delete. A readable provider with missing write actions is downgraded rather than being allowed to fail later during import. Unavailable providers remain fail-closed. SQLTasks prefers native API4 `SqlTask` when present and retains the reviewed API3/BAO path only as a legacy fallback.

Admin messages use explicit semantic styling so active errors remain red, warnings amber, and successful status messages green even on older Drupal/CiviCRM themes.

SQLTasks compatibility is version-aware: releases with native API4 `SqlTask` use API4; SQLTasks 2.2.x uses the extension's public API3 `getalltasks` collection plus `get` hydration and the existing `create`/`deletetask` write actions. No nonexistent BAO class is required on 2.2.x. Unavailable optional providers keep their saved scope for recovery but only **Ignore** can be newly selected until the provider becomes usable.

## Alpha61 safe first-run scope

Fresh installations now start with configuration types ignored until an administrator explicitly chooses what to manage or monitor. Existing installations without the fresh-install marker keep the historical `Manage everything` fallback, so upgrading does not silently disable an established workflow. The Settings page includes first-run guidance, bulk scope actions, and expanded collapsible management/advanced sections.
Scope cards also show deployment relationships for configuration that commonly references another managed type (for example Custom Data to Option Groups/Contact Types, Relationship Types to Contact Types, SearchKit Displays to Saved Searches, FormBuilder to SearchKit, and extension-backed CiviRules/Site Tokens). These relationships are guidance rather than forced scope expansion: risky combinations are highlighted live and after save, and an administrator may explicitly promote ignored/monitor-only related types to **Manage everything**. Import validation still checks the actual dependency graph and may accept a dependency already present on the target site.
Scope changes are intentionally explicit: changing a dropdown or using bulk **Apply** edits the form only until **Save scope changes** / **Save settings** is submitted. While scope edits are unsaved, Settings shows a warning that Export, Import, Validate, and Synchronize are still using the last saved policy. `Manage everything` writes all supported items for that type; `Manage selected items` keeps only selected items managed; `Monitor only` stays out of YAML; and `Ignore` remains excluded from both YAML and watch scanning.

Local full QA does not require CiviCRM Buildkit: `composer qa:full` runs the isolated Docker integration suite without browser tests, while `composer qa:full-ui` runs the same stack plus Playwright and review screenshots.
Run those standalone QA commands from the **host repository checkout**, not from inside `ddev ssh`; they require host Docker/Compose. Generated `tests/ci/artifacts/` output is disposable and ignored by Git. When selected scope enables **Monitor everything else in this type**, saving the scope captures the initial local watch baseline so later watch scans can immediately report changes.
Watch-only detections are also retained in a bounded local recent-history list, so detecting another watched change (or running a later no-op scan) does not erase what was previously detected. **Scan Watched Config** reopens the Watched Configuration panel after the scan; the panel separates the latest scan from retained history and provides a history-only clear action that does not reset monitoring fingerprints. Local `qa:full-ui` can use the pinned Playwright Docker image when host Node/npm is unavailable, and Mailpit is no longer published to a host port, avoiding collisions with an existing local Mailpit/DDEV service.

## Current UI

The admin UI has four tabs.

### Synchronize

Shows the current difference between **managed** active CiviCRM configuration and managed YAML. The status is explicit: **Setup Required** when nothing is managed or watched, **Monitoring Only** for watch-only scope, **Initial Export Required** when managed scope exists without a YAML baseline, and **In Sync** only after a managed baseline exists and the managed diff is empty. This prevents a fresh all-Ignore configuration from being presented as synchronized. After the baseline exists, the main cards use concise human wording and keep API/capability/dependency/identity metadata inside `Details`.
If a managed handler or contributed provider cannot be read completely, Synchronize reports **Error**, keeps the detailed provider error visible, preserves any safe partial backup files, and does not claim the site is In Sync.

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
- Import can delete supported records that exist in CiviCRM but not in YAML. A complete dry-run preflight runs first and reports site-identity, YAML/dependency, rename, provider-capability, and handler blockers together before any database write.
- Dependencies included in the same managed import are recognized during dry-run even when an earlier handler still needs to create them on the target. Ambiguous/backup-only provider identities and database-local numeric identities never authorize automatic delete-missing. Suspected machine-name renames block both automatic rename and deletion of the existing identity.
- Actual imports apply create/update first for all managed types and start reverse-order delete-missing only when that entire write phase succeeds. A create/update runtime failure stops destructive cleanup and is reported as a partial apply requiring review/restore before retry.
- The UI uses a confirmation modal before applying import changes. The Apply action is unavailable while the complete preflight has blocking errors; after a clean preview the user must review the warning and type `IMPORT`.
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

Config Ignore uses one rule list. `path/to/file.yml` or a wildcard excludes an entire YAML file; `path/to/file.yml:dot.path` excludes only that value while keeping the rest of the file managed. Example: `settings/theme_frontend.yml:item.value` lets dev/stage/prod keep a different local theme while other settings in the file remain portable. Rules apply consistently to diff, validate, export, import, single-file preview, and ZIP download as appropriate.

`extensions/civi.config.manager.yml` is always ignored to avoid self-management loops. Four proven volatile job/token timestamp fields are also built-in field-level ignores. Older values stored in `civicfg_ignore_values` remain readable for compatibility and are migrated into the unified Config Ignore setting when Settings is saved.

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

The extension owns one real CLI implementation at `bin/civicfg`. On install/enable it creates a Composer launcher when a writable project vendor tree is available (including legacy Drupal `sites/default/vendor/bin`) and installs one shared global `civicfg` dispatcher when the runtime PATH exposes a safe writable or creatable bin directory such as `$HOME/.local/bin`. The global dispatcher contains no project-specific extension path; it uses `cv` from `PATH` or a sibling Composer `vendor/bin/cv` launcher to bootstrap the current site and resolves the enabled extension path at runtime. Drupal 7/non-Composer sites therefore do not require a project-root `vendor/bin` directory.

Configuration Manager does not create project-bin aliases, `/var/www/html/bin` copies, or shell PATH helper files. Existing non-managed `civicfg` commands are never overwritten. A local ownership registry lets several projects share the global dispatcher safely; uninstall removes the global dispatcher only after the final registered project is removed. See `docs/CLI.md`.

## API4 and CLI automation

The UI and CLI use the same API4 backend.

```bash
cv api4 ConfigManager.status
cv api4 ConfigManager.listTypes
cv api4 ConfigManager.providerInventory
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
civicfg import --dry-run --exclude-component <component_id> --json
civicfg import --yes --plan <plan_id>
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
- Dependency metadata is validated where available. Missing managed YAML dependencies are treated as import-blocking errors to avoid broken relationships. Reverse `required_by` metadata is also checked and reported as a warning when it appears stale or incomplete. Custom Group dependency metadata treats `extends_entity_column_value` as ContactType IDs only for contact-based groups, and generic extension `afsearch...` references are tracked as FormBuilder Afform dependencies so local entity IDs are not mistaken for portable identities.
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

## CLI usage

Use the same subcommands through the global dispatcher, Composer launcher, or extension-local script:

```bash
civicfg status
civicfg export --write
civicfg diff
civicfg validate
civicfg watch
civicfg import --dry-run
civicfg import --dry-run --exclude-component <component_id> --json
civicfg import --yes --plan <plan_id>
```

See `docs/CLI.md` for Composer/non-Composer behavior, ownership, registry, and uninstall details.

## Automated QA

This repository includes a fast GitHub Actions workflow for every push/pull request and a full CiviCRM Standalone workflow that runs automatically on pull requests or manually on demand. The full workflow creates an isolated database and YAML directory, blocks outbound application networking and email delivery, generates disposable API4 fixtures, tests CLI/API/service round trips and preservation boundaries, and runs Playwright on pull requests. Tagged packaging also requires the real-CiviCRM/browser job to pass.

See `docs/QA_AUTOMATION.md` and `tests/scenarios/README.md`.

## Extension integration hook

Other extensions can make their own APIv4-backed configuration exportable/importable with `hook_civicfg_entityDefinitions()`. This is the preferred integration path because it only requires metadata: entity name, stable key fields, export fields, ignored runtime fields, sensitive fields, and dependencies. See `docs/EXTENSION_HOOKS.md`.

Alpha61 follow-up hardening also discovers API3 capabilities directly from `Entity/Action.php` provider files before falling back to runtime `getactions`. SQLTasks 3.0.0-alpha3 is handled as a narrowly reviewed declarative provider from its installed `Sqltask/Create.php`/`Deletetask.php` files, while its BAO read adapter is loaded only when rows are actually read. Extension base-path lookup is resilient per provider and can conservatively fall back to the configured extensions directory for an installed key, avoiding discovery loss from stale mapper state in isolated CLI/QA bootstrap.

## Early coverage expansion in alpha67.7

Alpha67.6 lands the source implementation for four client-required configuration families ahead of the planned Alpha69 milestone while preserving the deny-by-default provider model. Tags, Profiles/UF Groups, and Profile Fields reuse `EntityDefinitionHandler`; Contact Layouts and traditional Report Instances use focused reviewed adapters because their persistence shapes cannot be represented safely by the generic collection handler alone.

- **Tags** use `name` identity and convert `parent_id` to a semantic Tag reference. Entity-tag assignments are business data and are never exported/imported by this handler.
- **Profiles** use UFGroup `name`; **Profile Fields** use a reviewed adapter because one Profile may contain multiple instances of the same `field_name`. Identity includes the owning Profile plus semantic field type/location qualifiers, with label only as a tie-breaker for otherwise identical repeated fields. UF Group and Location Type IDs resolve only at the target API boundary.
- **Contact Layouts** use `label` only when it is unique. Known Group, Profile, Custom Group, and Relationship Type IDs inside layout arrays are represented semantically. Unknown scalar `*_id` values block export/import rather than leaking target-local IDs.
- **Report Instances** prefer `report_id + name` through APIv3. Legacy rows with a blank `name` may use `report_id + title` only when that fallback is unambiguous. Missing `report_id`, blank name+title, or duplicate fallback identities still block safely. `form_values`, permission, group-role, active/reserved state, title and description are managed; navigation IDs and email recipient fields remain excluded.

All four families are create/update-only in this checkpoint. Delete-missing is intentionally false until A69-05 disposable-runtime preservation tests prove deletion semantics independently. Source/unit coverage is not a substitute for the required real-CiviCRM DEV → target round trip.

## Historical alpha notes

Older implementation notes were moved to [`history/DETAILED_REFERENCE_ALPHA_NOTES.md`](history/DETAILED_REFERENCE_ALPHA_NOTES.md) so this reference stays focused on current behavior.
