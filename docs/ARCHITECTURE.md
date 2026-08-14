# Architecture

Configuration Manager is organized around an API4/service layer, type-specific handlers, portable YAML storage, semantic configuration identity, local state/baseline tracking, and a thin CiviCRM UI/CLI layer.

Release history is maintained in `../CHANGELOG.md`. This document describes the current architecture only.

## Runtime flow

```text
UI / civicfg / cv api4
  -> Civi\Api4\ConfigManager actions
  -> Civi\ConfigManager\Service\ConfigManager
  -> HandlerRegistry
  -> HandlerInterface implementations
  -> semantic identity + canonicalization + state services
  -> YamlFileStorage / SimpleYaml
```

YAML is the portable/deployable configuration source. Configuration Manager's database tables store only local operational intelligence and accepted comparison baselines.

## API4 facade

`Civi/Api4/ConfigManager.php` exposes the supported automation interface:

- `ConfigManager.status`
- `ConfigManager.listTypes`
- `ConfigManager.export`
- `ConfigManager.diff`
- `ConfigManager.validate`
- `ConfigManager.import`
- `ConfigManager.confirmIdentityAlias`

The API4 actions live under `Civi/Api4/Action/ConfigManager`. The `civicfg` CLI delegates to these API4 operations rather than duplicating configuration business logic.

## Service layer

`Civi/ConfigManager/Service/ConfigManager.php` coordinates:

- Sync directory resolution.
- Managed handler filtering.
- Full export and dry-run export.
- Diff calculation and baseline-aware state enrichment.
- YAML validation.
- Import preview and two-phase import apply.
- Dependency-aware stale YAML cleanup.
- Accepted baseline updates after successful export/import.
- Identity-alias confirmation.
- Manifest writing with version metadata read from `info.xml`.
- System-status and CLI health reporting.

Supporting services provide focused responsibilities:

- `ConfigIdentity` derives deterministic semantic configuration identities and confidence levels.
- `Canonicalizer` produces type-preserving canonical values and versioned SHA-256 fingerprints.
- `DiffStateClassifier` classifies two-way and baseline-aware three-way state.
- `ConfigStateManager` connects handlers, canonical values, fingerprints, baselines, and object-state reporting.
- `StateStore` persists rebuildable object state, accepted baselines, and confirmed identity aliases.
- `CliInstaller` owns the project/global CLI launcher lifecycle.

The service resolves relative sync directories from the CMS/project root where possible. The legacy value `../civicrm-config` is normalized to `civicrm-config`.

## Semantic identity

Numeric database IDs and YAML filenames are not authoritative cross-environment identities.

Every exported object receives a semantic `config_key` through `ConfigIdentity`. Identity preference is:

1. Explicit `key_fields` or an explicit exported key.
2. Strong machine fields such as `key`, `machine_name`, `name`, `name_a_b`, or `workflow_name`.
3. A weak `title`/`label` identity only as an ambiguous read/diff fallback.
4. Filename fallback only when no semantic identity exists; it is always marked ambiguous and is not safe for automatic writes.

Identity metadata includes:

- `provider_key`
- `config_key`
- `identity_hash` (SHA-256 of provider + semantic key)
- `identity_method`
- `identity_confidence`
- `write_safe`

Current confidence values are `EXPLICIT`, `API_VERIFIED`, `DISCOVERED_UNIQUE`, and `AMBIGUOUS`. Weak/ambiguous generic extension identities remain visible to export/diff/validation but are not automatically created, updated, or deleted.

A filename can change while the semantic identity remains unchanged. Such a change is treated as a storage rename, not as a configuration delete/create pair.

## Canonical fingerprints

`Canonicalizer` creates deterministic, type-preserving values before hashing. It:

- Sorts associative-map keys.
- Preserves scalar types (`1`, `"1"`, `true`, `null`, and `""` remain distinct).
- Normalizes line endings.
- Preserves list order by default.
- Sorts only collection paths explicitly declared unordered.
- Removes only exact/path-aware ignored, runtime, sensitive, and known operational metadata.

Content fingerprints use SHA-256 and carry a `canonical_version`. If canonicalization rules change in a later development version, old fingerprints are not treated as directly comparable.

Operational metadata such as YAML schema/type markers, semantic identity declarations, dependency/index metadata, provider capabilities, and identity-confidence labels is excluded from the content fingerprint so changes in Configuration Manager's storage/discovery behavior do not appear as CiviCRM configuration drift. The portable configuration values themselves, including semantic references, remain fingerprinted.

## Baselines and three-way state

A normal two-way comparison can truthfully report only whether YAML and active CiviCRM match. An accepted baseline allows Configuration Manager to distinguish the direction and overlap of later changes.

Without a baseline, the normal states are:

- `IN_SYNC`
- `DIFFERENT`
- `ONLY_IN_YAML`
- `ONLY_IN_CIVICRM`

With a baseline, the state engine can additionally report:

- `ACTIVE_DRIFT` - YAML still matches the baseline but active CiviCRM changed.
- `YAML_CHANGE` - active CiviCRM still matches the baseline but YAML changed.
- `SYNCED_CHANGE` - YAML and active CiviCRM match each other at a state different from the baseline.
- `BOTH_CHANGED` - YAML and active CiviCRM both changed away from the baseline.

For `BOTH_CHANGED`, canonical baseline/YAML/active values are compared by path. The result is either `NON_CONFLICTING_DIVERGENCE` or `CONFLICT` when the same field changed differently.

Baselines advance only after an accepted synchronization event such as a successful real export or successful import. Running diff/status does not silently move the baseline.

## Local state tables

The extension owns three tables:

- `civicrm_civicfg_object_state` - rebuildable latest scan/fingerprint state.
- `civicrm_civicfg_baseline` - accepted canonical comparison baseline.
- `civicrm_civicfg_identity_alias` - explicitly confirmed old/new semantic identities for rename continuity.

`civicrm_civicfg_object_state` is disposable and can be rebuilt from YAML plus active CiviCRM. YAML never depends on these tables to remain understandable or deployable.

Confirmed identity aliases preserve baseline continuity after an intentional machine-key rename. A rename suggestion is informational only: Configuration Manager does not automatically turn a suspected rename into an update. Real import is blocked while a possible rename still appears as a create/delete pair; the operator must review and align the accepted identity first.

The tables are created on install/enable/upgrade and removed when Configuration Manager is uninstalled.

## Handler registry

`Civi/ConfigManager/Service/HandlerRegistry.php` defines the built-in config handlers and their order.

The current built-in handlers cover:

- Extensions
- Option Groups and Values
- Contact Types
- Relationship Types
- Location Types
- Financial Types
- Payment Processors
- Custom Groups and Fields
- CiviCRM Settings Allowlist
- Message Templates
- Dedupe Rules
- Scheduled Jobs
- SearchKit Saved Searches
- SearchKit Displays
- FormBuilder Afforms

Other extensions can add metadata-driven API4 definitions through `hook_civicfg_entityDefinitions()` or advanced custom handlers through `hook_civicfg_configTypes()`.

Custom handlers should implement `Civi\ConfigManager\Handler\HandlerInterface`.

## Handler contract

Each handler is responsible for one config type and implements:

- `getType()` - machine name used in filters/API calls.
- `getLabel()` - human-readable label.
- `getDirectory()` - sync directory subdirectory.
- `getWeight()` - dependency/order priority.
- `export()` - returns YAML file definitions from active CiviCRM config.
- `diff()` - compares active config with YAML files.
- `validate()` - checks YAML format and identity requirements.
- `import()` - applies supported YAML changes.

`AbstractHandler` provides semantic matching, canonical fingerprinting, focused field diffs, conservative rename suggestions, and validation defaults. Import defaults to `not_implemented` unless a handler overrides it.

Handlers can expose canonicalization metadata through `getCanonicalizationOptions()` so runtime/sensitive paths and collection ordering are consistent between export, diff, state fingerprints, and baselines.

## Contributed/custom extension configuration

Enabled extension configuration is discovered through safe settings attribution, API4/API3 provider discovery, and public Configuration Manager hooks.

Discovery and write safety are deliberately separate. A provider can be visible to export/diff without being safe for automatic import. Generic provider records with strong stable identities and supported write actions can be managed automatically; weak or generated providers are classified instead of guessed.

The real-fixture compatibility suite classifies each extension/provider as one of:

- `FULL`
- `PARTIAL`
- `NO_PORTABLE_CONFIG`
- `UNSUPPORTED`
- `ERROR` (provider discovery/read or test execution failure)

`NO_PORTABLE_CONFIG` is a valid result for an installed extension and is not a test failure.

The production engine does not contain extension-name special cases for fixtures such as SQL Tasks. Real contrib extensions are used to improve generic discovery and provider metadata; explicit integration hooks are used only when provider semantics cannot be inferred safely.

## Semantic references and dependencies

Metadata-driven API4 definitions can declare `reference_fields`. On export, local numeric references are resolved into semantic API4 references containing stable key fields. On import, the semantic reference is resolved to the target environment's local ID. Declared references fail closed: an unresolved local ID is never exported as portable config, and imports accept only the configured target entity/provider and exact stable key fields.

For example, an environment-local `custom_group_id` should be represented by a stable group key where supported rather than copied as the raw numeric ID.

Reference definitions may also declare a `dependency_type`, allowing the existing dependency validation and ordering system to reason about the referenced configuration. Create/update operations run before delete operations, and deletes run in reverse handler order to reduce parent/child removal hazards.

## YAML file strategy

Handlers can export collection files or split item files. Collection files remain suitable for stable low-volume configuration. Split item files are used for high-churn or large records so Git diffs stay reviewable.

Split-file YAML normally contains stable identity metadata and one portable record under `item`. The filename is a readable storage name only; semantic matching uses `config_key`/key fields instead of relying on the filename.

## Import model

Imports are YAML-source-of-truth for handlers that explicitly support writes.

- Create/update/delete capability is provider-specific.
- Weak/ambiguous identities are not automatically written.
- YAML can revert active values to the accepted portable state.
- The UI asks for confirmation before applying imports.
- Dependency validation blocks missing managed dependencies.
- Create/update runs before deletes; delete handling runs in reverse handler order.
- Possible machine-identity renames are shown for review and block real import while they remain unresolved.
- Unsupported handlers report `not_implemented` instead of partially applying changes.
- A successful applied import advances the baseline. A baseline-write failure is reported as a state warning and does not incorrectly claim that the already-applied import failed.

## CLI lifecycle

The authoritative CLI implementation is `bin/civicfg` inside this extension.

`CliInstaller` may expose it through:

- one optional Composer `<vendor>/bin/civicfg` launcher for a Composer project; and
- one shared global `civicfg` dispatcher in a safe writable directory already on `PATH` (or explicitly configured by `CIVICFG_GLOBAL_BIN_DIR`).

The global dispatcher contains no project-specific extension path. At runtime it uses `cv` from `PATH` or a sibling Composer `vendor/bin/cv` launcher to bootstrap the current CiviCRM site, verifies Configuration Manager is enabled, resolves the current extension base path through CiviCRM, and delegates to that site's `bin/civicfg`.

A small ownership registry lets multiple local projects share the global dispatcher. Uninstall unregisters the current local project and removes the managed global dispatcher only when the last registered project is gone. Non-managed files are never overwritten or deleted.

Configuration Manager does not install project-root aliases, `/var/www/html/bin` copies, or shell PATH helper files. See `CLI.md`.

## Storage layer

`Civi/ConfigManager/Storage/YamlFileStorage.php` reads and writes YAML under the configured sync directory. It supports nested directories and enforces path/symlink safety.

`Civi/ConfigManager/Util/SimpleYaml.php` uses available YAML support when possible and includes a simple fallback for the extension's supported YAML structures.

## UI layer

The route/page wrapper is intentionally thin:

- `CRM/Configmanager/Page/Main.php`

UI logic is split into focused classes:

- `Civi/ConfigManager/UI/MainPage` - page controller.
- `Civi/ConfigManager/UI/Request` - request parsing.
- `Civi/ConfigManager/UI/Presenter` - display rows, labels, summaries, rename warnings, and diff view data.
- `Civi/ConfigManager/UI/FileTransfer` - upload, ZIP handling, preview, and download.
- `Civi/ConfigManager/UI/Permission` - permission constants and checks.
- `Civi/ConfigManager/UI/AssetLoader` - CiviCRM resource loading.

## Templates and assets

Templates are CiviCRM-compatible Smarty files. Runtime CSS/JavaScript is dependency-free; no Node/npm build is required to use the extension.

## Settings ownership

`civicfg_sync_dir` can be UI-managed or code-owned. When defined in `civicrm.settings.php`, the UI treats the sync directory as environment-owned configuration and locks the field to avoid accidental changes.

## Config ignore

`civicfg_ignore_paths` stores relative YAML paths or simple wildcard patterns skipped by diff, validate, export, and import. `extensions/civi.config.manager.yml` is ignored by default to avoid self-management loops while the extension is running imports.

Field-level Config Ignore rules remain supported separately from handler/provider runtime and sensitive-field metadata.
