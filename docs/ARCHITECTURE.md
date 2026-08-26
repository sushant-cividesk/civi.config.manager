# Architecture

Configuration Manager is a conservative configuration-management layer for CiviCRM. It separates portable YAML from local operational state and routes UI, CLI, and API4 operations through one service layer so the same safety rules apply everywhere.

This document describes current architecture only. Release history belongs in [`../CHANGELOG.md`](../CHANGELOG.md).

## System overview

```text
Admin UI / civicfg / API4
          |
          v
Civi\Api4\ConfigManager actions
          |
          v
ConfigManager service
          |
          +--> HandlerRegistry --> type-specific handlers --> CiviCRM APIs
          |
          +--> ConfigIdentity / Canonicalizer / dependency policy
          |
          +--> StateStore / baseline classifier
          |
          +--> YamlFileStorage
                     |
                     v
               portable YAML
```

**Portable state:** YAML files committed/deployed between environments.
**Local state:** accepted baselines, fingerprints, watch history, and identity aliases stored in the local CiviCRM database.

Local tables never replace YAML as the deployable source of truth.

## Core components

### API4 facade

`Civi/Api4/ConfigManager.php` exposes the supported automation surface, including status, type discovery, export, diff, validate, import, and identity-alias confirmation.

The `civicfg` CLI delegates to these operations instead of implementing a second configuration engine.

### ConfigManager service

`Civi/ConfigManager/Service/ConfigManager.php` is the orchestration layer. It owns:

- sync-directory resolution;
- scope filtering;
- export and dry-run export;
- field-level ignore processing;
- diff and baseline-aware state classification;
- validation and dependency checks;
- import preflight and apply sequencing;
- stale YAML cleanup;
- accepted baseline updates; and
- health/status information.

### Handler registry

`HandlerRegistry` defines built-in configuration types and dependency order. Each handler owns one type and is responsible for export, diff, validation, and import behavior for that type.

Handlers must fail closed when identity, provider availability, or write capability is uncertain.

### YAML storage

`YamlFileStorage` reads and writes the configured sync directory and enforces relative-path and symlink safety. Files use human-readable names, but filenames are not authoritative cross-environment identity.

## Semantic identity

Numeric database IDs are local implementation details and are not portable identity.

`ConfigIdentity` prefers stable semantic keys in this order:

1. explicit key fields or an explicit exported key;
2. stable machine fields such as `key`, `machine_name`, or `name`;
3. weak labels/titles for read-only comparison only; and
4. filename fallback only when no stronger identity exists.

Identity metadata records how the key was derived and whether it is safe for automatic writes. Ambiguous identities remain visible for backup, diff, and validation but cannot authorize destructive synchronization.

A filename change with the same semantic identity is storage movement, not automatically a delete/create pair.

## Canonicalization and runtime values

`Canonicalizer` produces deterministic, type-preserving values before hashing. Associative keys are sorted, scalar types are preserved, and only explicitly declared unordered collections are reordered.

Runtime-only, sensitive, and operational metadata is removed before portable comparison when the handler or core policy explicitly defines it.

Configuration Manager exposes one Config Ignore rule model. A rule without `:` excludes a whole YAML file/path; a `path.yml:dot.path` rule excludes only one value while keeping the rest of the file managed.

Five rules are built in:

```text
extensions/civi.config.manager.yml
extensions/*/api3/Job/*.yml:item.last_run
extensions/*/api3/Job/*.yml:item.last_run_end
scheduled-jobs/*.yml:item.scheduled_run_date
site-tokens/*.yml:item.modified_date
```

These rules are always active. `civicfg_ignore_paths` is the canonical administrator setting and may contain both forms. The older `civicfg_ignore_values` setting is read only for upgrade compatibility and is migrated into the unified rule list when Settings is saved. Whole-file and value-level filtering still use separate internal execution paths so a field rule can never accidentally suppress an entire configuration object.

## Baselines and drift state

A two-way comparison can only say whether YAML and active CiviCRM differ. An accepted baseline makes three-way classification possible.

Important states include:

- `IN_SYNC`
- `ONLY_IN_YAML`
- `ONLY_IN_CIVICRM`
- `ACTIVE_DRIFT`
- `YAML_CHANGE`
- `SYNCED_CHANGE`
- `BOTH_CHANGED`

For `BOTH_CHANGED`, canonical values are compared by path to distinguish non-conflicting divergence from a true conflict.

Baselines advance only after accepted synchronization events such as a successful real export or successful import. Running status or diff does not silently accept drift.

## Local state

The extension uses local tables for rebuildable operational intelligence:

- `civicrm_civicfg_object_state`
- `civicrm_civicfg_baseline`
- `civicrm_civicfg_identity_alias`

Object state can be rebuilt from active CiviCRM and YAML. Identity aliases exist only to preserve continuity after an explicitly reviewed machine-key rename.

## Scope model

Configuration Scope is stored per handler/type:

- **Manage everything**
- **Manage selected items**
- **Monitor only**
- **Ignore**

Fresh installs default to Ignore. Existing installations preserve their prior policy unless explicitly changed.

Selected-item scope stores semantic selectors rather than relying on local numeric IDs. Watch-only configuration is fingerprinted locally and does not enter managed YAML.

## Import safety pipeline

Import is source-of-truth only for handlers that explicitly support writes.

The pipeline is:

```text
load YAML
   |
   v
complete non-writing preflight
   |- site identity
   |- YAML/schema validation
   |- dependency availability
   |- rename/collision detection
   |- provider capability and identity safety
   `- per-handler dry-run
   |
   v
create/update phase
   |
   +-- any failure --> stop; no delete phase
   |
   v
delete-missing phase in reverse dependency order
   |
   v
accept new baseline
```

Key invariants:

- site mismatch does not hide later preflight blockers;
- dependencies planned earlier in the same import may satisfy later dry-run checks;
- ambiguous provider metadata cannot authorize delete-missing;
- local numeric-ID-only junction records are not treated as portable cross-site configuration;
- possible renames block real import until reviewed;
- the UI does not expose Apply when preflight is blocked.

## Contributed-extension configuration

Extension-owned configuration is discovered through safe settings attribution, API4/API3 provider discovery, and explicit Configuration Manager hooks.

Discovery and write safety are separate decisions. A provider may be readable and exportable while still being backup/monitor-only.

Generic discovery must not guess provider-specific API context or portable identity. Providers with incomplete capability or ambiguous identity fail closed for automatic CRUD.

See [`EXTENSION_HOOKS.md`](EXTENSION_HOOKS.md) for supported integration points.

## Dependencies and semantic references

Handlers and metadata-driven definitions may declare deployment dependencies and semantic reference fields.

On export, local numeric references are converted to stable semantic references where supported. On import, those references are resolved to the target environment's local IDs. Unresolved references are blockers, not silently copied numeric IDs.

Create/update runs in dependency order. Delete-missing runs in reverse order to reduce parent/child deletion hazards.

## CLI architecture

`bin/civicfg` is the authoritative CLI implementation.

`CliInstaller` can expose it through:

- a project Composer `vendor/bin/civicfg` launcher, including legacy Drupal `sites/default/vendor/bin`; and
- a shared `civicfg` launcher in a safe writable or creatable `PATH` bin directory.

The shared launcher does not hardcode one project's extension path. It bootstraps the current CiviCRM site with `cv`, verifies the extension is enabled, resolves the extension path at runtime, and delegates to that site's `bin/civicfg`.

See [`CLI.md`](CLI.md) for lifecycle and troubleshooting details.

## UI architecture

The page wrapper remains thin. UI responsibilities are separated into request parsing, presentation, file transfer, permissions, and asset loading under `Civi/ConfigManager/UI/`.

The UI calls the same service layer as API4/CLI. It does not maintain a separate import or diff implementation.

## Extension points

Two supported extension mechanisms are available:

- metadata-driven entity/provider definitions for configuration that follows the generic model; and
- custom handler registration for configuration requiring specialized semantics.

Custom integrations must provide stable identity, explicit capability boundaries, and deterministic canonicalization. They must not bypass preflight or delete safety.

## Performance and batching model

Large-site execution follows a bounded-memory model while preserving the same synchronous safety semantics:

- standard API4 collection reads are paged centrally; single-row lookups request one row only;
- standard API3 contributed-provider `get` reads use bounded `limit`/`offset` pages instead of `limit = 0`;
- custom API3 collection actions are paged only when they demonstrate offset progress, otherwise the operation fails closed rather than truncating or looping;
- provider identity multiplicity is indexed once in O(n), and possible-rename discovery buckets by non-identity content before exact comparison instead of comparing every added item with every missing item;
- Manage Everything bypasses item-selector partition work, which avoids a second high-volume export array;
- the export queue is the single owner of full YAML documents while reverse-dependency/stale-file passes use in-place updates and compact integer indexes;
- validation and import dependency planning keep names/dependency metadata rather than retaining every parsed YAML document across all handlers;
- canonical state is hashed once and reused when both canonical data and its fingerprint are required;
- managed ZIP creation yields one handler/file at a time; and
- post-write verification is performed by the next Synchronize request instead of running another full diff inside the import/validation HTTP request.

The extension intentionally does not increase the host PHP memory limit. If a contributed provider cannot provide a safely pageable read surface, Configuration Manager reports that provider as unavailable/incomplete rather than weakening correctness. A future persisted background queue can add resumable multi-request execution without changing handler/import semantics, but it is not required for the bounded collection behavior above.

## QA architecture

Fast QA covers syntax, scenarios, PHPUnit, and static analysis. Full QA starts a disposable CiviCRM stack with Docker Compose and real pinned extension fixtures.

The application test network is internal-only and PHP mail is intercepted. Full QA verifies extension lifecycle, CLI availability, round-trip behavior, contributed-provider behavior, source immutability, and blocked outbound network access.

Full QA must run from a host checkout with Docker available; it is intentionally not runnable from inside a normal `ddev ssh` container.

See [`QA_AUTOMATION.md`](QA_AUTOMATION.md) and [`TESTING.md`](TESTING.md).

## Design principles

1. **Portable identity over local IDs.**
2. **One service layer for UI, API, and CLI.**
3. **Readability does not imply write safety.**
4. **No destructive action before complete preflight.**
5. **Runtime noise is excluded narrowly, never with broad guesses.**
6. **YAML remains understandable without local state tables.**
7. **Failures are explicit and fail closed.**
