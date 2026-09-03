# Configuration Manager for CiviCRM

Configuration Manager brings a Git-friendly configuration workflow to CiviCRM. It exports supported configuration to YAML, compares YAML with the active database, validates changes, and safely imports supported configuration between environments.

**Extension key:** `civi.config.manager`
**Admin path:** `civicrm/admin/config-manager`
**CLI:** `civicfg`
**Format:** YAML
**Version:** see `info.xml`

## What it does

Configuration Manager is designed for controlled DEV -> STAGE -> PROD configuration promotion.

- Export supported CiviCRM configuration to portable YAML.
- Review and commit YAML in Git.
- Compare active CiviCRM with the YAML baseline.
- Preview imports before any write occurs.
- Create, update, and delete only where the handler/provider is proven safe.
- Track accepted baselines and distinguish active drift from YAML changes.
- Monitor configuration without necessarily managing it.
- Expose the same behavior through the admin UI, API4, and `civicfg` CLI.

YAML is the deployable source of truth for managed configuration. Local state tables contain only rebuildable operational metadata and accepted comparison baselines.

## Safety model

The extension is intentionally conservative.

- Fresh installs begin with configuration types set to **Ignore** until an administrator chooses what to manage or monitor.
- Import always performs a complete non-writing preflight first.
- Site identity, dependencies, possible renames, provider capabilities, and unsafe identities are checked before writes.
- Weak or ambiguous identities remain export/compare or monitor-only.
- Create/update completes before delete-missing begins.
- A create/update failure prevents the delete phase from starting.
- Cross-site import is disabled by default and should be enabled only for a reviewed migration.
- Missing or unreadable providers fail closed; they are never treated as an authoritative empty configuration set.

The protected release is `v1.0.0-beta1`; current work continues on numbered development alphas until Beta2 gates pass. See the [project status and master checklist](docs/PROJECT_STATUS.md), [Architecture](docs/ARCHITECTURE.md), and [Testing](docs/TESTING.md).

## Large-site execution

Alpha63 keeps the bounded-memory streaming model introduced in alpha62 and makes the web execution model genuinely multi-unit. High-volume CiviRules and discovered extension providers are scanned once into a private disk spool while compact identity multiplicities are calculated, then temporary YAML is built from that spool. Staging also records compact identity/hash/name/dependency metadata so finalization does not repeatedly parse thousands of YAML documents merely to rebuild indexes.

A web Export is a durable plan: prepare private workspace -> scan/stage handler or provider units -> finalize compact metadata -> re-scan active CiviCRM immediately before publication -> publish the verified snapshot with `manifest.yml` last -> record baselines -> complete. A durable publication journal can restore the previous coherent YAML tree if a PHP worker dies after live filesystem mutation begins. Import similarly uses full preflight -> create/update units -> delete-missing units -> baseline -> complete; no delete unit can run if preflight or any create/update unit fails.

The extension does **not** raise PHP `memory_limit`. `composer qa:stress` runs both the alpha62 API/YAML iterator stress and alpha63 disk-spool/persistent-workspace recovery stress under a 256 MB ceiling. Providers which cannot be read safely fail closed instead of being silently truncated.

### Ambiguous identities

A duplicate or otherwise unproven source identity is preserved as a **monitor-only snapshot**. Each occurrence receives a deterministic fingerprint/occurrence filename, remains visible to Export/Synchronize, and has automatic create/update/delete disabled. Intentional monitor-only YAML does not block unrelated safe imports. This is different from a source YAML item which was proven portable but matches multiple rows on the target: that is a blocking preflight conflict and causes zero writes. Delete-missing safety is evaluated per identity, so one ambiguous row cannot authorize deletion and also does not disable safe cleanup for unrelated unique rows. Local database IDs are never portable identity.

### Web progress

CiviCRM SQL Queue stores one durable item per bounded work unit rather than one giant Export/Import request. The browser advances at most one queue item per request and polls persisted status. WordPress/PHP session locks are released before worker advancement so status polls remain responsive. Progress text names the actual action and configuration scope (for example, `Scanning active CiviCRM — CiviRules Actions` or `Safety verification before publishing YAML`), reports real phases/processed records/heartbeats, and does not fabricate a percentage when the total is unknown. Browser refresh reconnects to the saved job; the UI does not claim that closing the browser creates an independent background worker.

Retry-safe read/stage/baseline units may be replayed after an interrupted worker. Indeterminate live-mutating units are blocked rather than blindly replayed. CLI operations continue to use the same ConfigManager safety/service layer synchronously and respect an active persistent job lock for the same sync root.

## Supported configuration

Built-in handlers cover the core configuration used by most CiviCRM deployments, including:

- Extensions and safely discovered extension-owned configuration
- Option Groups and Values
- Contact, Relationship, and Location Types
- Tags, including portable parent-tag references
- Profiles / UF Groups and Profile Fields
- Financial Types and Payment Processors
- Custom Groups and Fields
- CiviCRM Settings through an explicit allowlist
- Message Templates
- Dedupe Rules
- Scheduled Jobs
- SearchKit Saved Searches and Displays
- FormBuilder Afforms
- Contact Layouts when the Contact Layout Editor provider is available; known nested local references are translated semantically
- Traditional CiviReport instances using `report_id + name` portable identity
- Site Tokens
- CiviRules configuration where portable identity is proven

Capability is evaluated at runtime. A readable provider is not automatically considered write-safe. Alpha69's newly added Tags, Profiles/Profile Fields, Contact Layouts, and Report Instances currently permit reviewed create/update behavior only; delete-missing remains disabled until independent disposable-runtime preservation proof is recorded.

## Config Ignore

Configuration Manager uses one **Config Ignore** rule list for both whole YAML files and individual values:

```text
path/to/file.yml
path/to/file.yml:dot.path
```

The first form ignores the whole file. The second keeps the file managed and ignores only the named value. Wildcards are supported in the YAML path portion.

Five safety/runtime rules are built in and always active:

```text
extensions/civi.config.manager.yml
extensions/*/api3/Job/*.yml:item.last_run
extensions/*/api3/Job/*.yml:item.last_run_end
scheduled-jobs/*.yml:item.scheduled_run_date
site-tokens/*.yml:item.modified_date
```

The self-extension YAML is excluded to prevent Configuration Manager from changing its own extension state during an import. The four field-level rules remove proven runtime timestamps without hiding the rest of those configuration objects.

Administrators can add project-specific whole-file or field-level rules in the same Settings control. Older `civicfg_ignore_values` data is still read for compatibility and is migrated into the unified Config Ignore setting the next time Settings is saved.

Do not use broad timestamp wildcards. Fields such as `created_date`, `modified_date`, or environment settings may be meaningful for other entity types and should be ignored only when the handler or project explicitly proves they are non-portable.

## Installation

Install the extension in a normal CiviCRM extension directory and enable it through CiviCRM.

**Official release ZIPs are runtime-complete.** They must contain `vendor/autoload.php` and the locked Symfony YAML runtime, so a site administrator must not need to run Composer after installing a release ZIP. Maintainers build that artifact with:

```bash
composer package:release
```

A Git/source checkout is developer source rather than the installable release artifact. For a source checkout without `vendor/`, either run:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
```

or use a host with a complete PHP ext-yaml runtime (`yaml_parse_file()` **and** `yaml_emit()`). Configuration Manager deliberately has no hand-written YAML serializer: if neither Symfony YAML nor complete ext-yaml is available, Export fails closed before writing YAML.

Relative `civicfg_sync_dir` values are resolved from CiviCRM's active CMS root (Drupal, WordPress, or Standalone), not from the location of `civicrm.settings.php`. This keeps the UI and `civicfg` CLI on the same YAML tree.

Then enable the extension and open:

```text
/civicrm/admin/config-manager
```

The extension supports PHP 7.4+ and is intended for CiviCRM 5.x and 6.x. Optional providers are detected at runtime.

## Quick start

The Settings scope browser is designed to stay responsive as provider support grows. It renders the saved scope and runtime capability first, then loads metadata-only provider ownership/safety details asynchronously. Use the search box or group filter to focus on Core, Contributed, Custom, Backup / monitor-only, or Unavailable providers. Item inventories remain lazy and are fetched only when **Choose items** is opened.

1. Open **Configuration Manager -> Settings**.
2. Choose the configuration types to manage or monitor.
3. Run **Export** to create the initial YAML baseline.
4. Commit the YAML directory to Git.
5. Deploy the YAML to the target environment.
6. Run **Validate** and **Import Preview**.
7. Review every blocker, warning, create, update, and delete.
8. Apply only after the preflight is clean.

For normal environment promotion, keep the same site identifier across the environment family. Cross-site import should remain disabled.

## Configuration scope

Each configuration type can be assigned one of four policies:

- **Manage everything** - export, diff, validate, and import all supported items.
- **Manage selected items** - manage only explicitly selected semantic items.
- **Monitor only** - detect drift without writing YAML or importing changes.
- **Ignore** - exclude the type from management and monitoring.

Scope changes do not take effect until settings are saved.

## CLI

The authoritative CLI implementation is `bin/civicfg`. On enable, Configuration Manager attempts to expose `civicfg` through a project Composer launcher and a safe shared launcher in an existing or creatable `PATH` bin directory.

Typical commands:

```bash
civicfg status --json
civicfg export --write
civicfg diff
civicfg validate
civicfg import --dry-run
```

The CLI delegates to the same API/service layer as the UI. See [CLI documentation](docs/CLI.md) for installation, global launcher behavior, permissions, and all commands.

## Repository layout

```text
Civi/Api4/                         API4 facade/actions
Civi/ConfigManager/Handler/       configuration handlers
Civi/ConfigManager/Service/       orchestration, identity, state, CLI lifecycle
Civi/ConfigManager/Storage/       YAML storage
Civi/ConfigManager/UI/            admin UI support
bin/civicfg                        CLI entry point
settings/                          CiviCRM settings metadata
templates/                         Smarty templates
tests/                             unit, scenario, integration, and CI QA
docs/                              architecture and operator/developer documentation
```

## Development and QA

Fast verification:

```bash
composer validate --strict
composer qa:fast
composer test:hook
composer test:cli
```

Full isolated CiviCRM QA requires Docker and must be run from a **host repository checkout**, not from inside `ddev ssh`:

```bash
composer qa:full
```

Browser/UI QA:

```bash
composer qa:full-ui
```

The browser gate uses one browser stack: JavaScript Playwright + axe against the same disposable CiviCRM runtime used by the real integration suite. This keeps local and GitHub browser evidence aligned without a second Composer project or browser dependency graph.

Run `composer qa:browser` from a Docker-capable host checkout. For a targeted existing DEV site, use the existing JavaScript browser suite directly:

```bash
CIVICFG_BASE_URL=https://dev.example.test \
CIVICRM_ADMIN_USER=admin \
CIVICRM_ADMIN_PASS=... \
npm run test:ui
```

No browser-specific commands are part of the production `civicfg` CLI.

Do not publish a release solely because unit tests pass. The full isolated integration suite and a reviewed real migration dry-run are release gates for destructive import behavior.

## Documentation

- [Architecture](docs/ARCHITECTURE.md) - system design and safety boundaries
- [CLI](docs/CLI.md) - command usage and launcher lifecycle
- [Testing](docs/TESTING.md) - test strategy and release verification
- [QA Automation](docs/QA_AUTOMATION.md) - isolated CI architecture
- [Permissions](docs/PERMISSIONS.md) - CiviCRM permission model
- [Extension Hooks](docs/EXTENSION_HOOKS.md) - custom provider/handler integration
- [Release and Upgrade Policy](docs/RELEASE_AND_UPGRADE_POLICY.md) - compatibility and release rules
- [Detailed Reference](docs/DETAILED_REFERENCE.md) - extended behavior and maintainer notes moved out of this landing page
- [Changelog](CHANGELOG.md) - release-by-release history

## License

AGPL-3.0-or-later. See `info.xml` for package metadata.


### Alpha67.4.2 targeted DEV mode

`npm run test:ui` now detects its context. With the disposable QA fixture state present it runs the full seeded browser suite. Without that fixture, an explicit `CIVICFG_BASE_URL` runs a read-only targeted smoke instead. Targeted mode does not execute fixture-dependent import/watch/cross-site mutations.
