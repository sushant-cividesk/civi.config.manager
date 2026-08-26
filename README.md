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

See [Architecture](docs/ARCHITECTURE.md) for the design and [Testing](docs/TESTING.md) for release gates.

## Large-site execution

Configuration Manager is designed to keep collection work bounded rather than asking PHP/CiviCRM to materialize an entire site configuration in one API call. Core and contributed-provider collection reads use bounded pages where the provider supports paging, Manage Everything avoids item-selector copies, large validation/import passes retain compact dependency metadata, and managed ZIP creation processes one handler at a time.

The extension does **not** raise PHP `memory_limit` as a workaround. Standard API3 `get` providers are paged; custom contributed collection actions that cannot demonstrate safe offset paging fail closed rather than being silently truncated or looped indefinitely. Import and validation also avoid immediately running another full synchronization scan in the same HTTP request; the redirected Synchronize request performs that verification with a fresh request memory budget.

For very large sites, prefer the CLI for unattended operations so web-proxy request timeouts are not part of the execution path. The UI and CLI still use the same service and safety rules.

## Supported configuration

Built-in handlers cover the core configuration used by most CiviCRM deployments, including:

- Extensions and safely discovered extension-owned configuration
- Option Groups and Values
- Contact, Relationship, and Location Types
- Financial Types and Payment Processors
- Custom Groups and Fields
- CiviCRM Settings through an explicit allowlist
- Message Templates
- Dedupe Rules
- Scheduled Jobs
- SearchKit Saved Searches and Displays
- FormBuilder Afforms
- Site Tokens
- CiviRules configuration where portable identity is proven

Capability is evaluated at runtime. A readable provider is not automatically considered write-safe.

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

Release packages should include production Composer dependencies. For a source checkout without `vendor/`:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
```

Then enable the extension and open:

```text
/civicrm/admin/config-manager
```

The extension supports PHP 7.4+ and is intended for CiviCRM 5.x and 6.x. Optional providers are detected at runtime.

## Quick start

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
