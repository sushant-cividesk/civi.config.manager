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

## Performance, staging, and operation model

Large-site execution follows a bounded-memory model:

- API4 collection reads expose generators and request bounded pages; ascending numeric-`id` scans use `id > last_id` keyset paging where explicitly safe, while other providers use guarded bounded offset paging; single-row lookups request one row only.
- Standard API3 contributed-provider `get` reads use bounded pages. Custom collection actions are used only when their provider semantics can be handled safely; incomplete/repeating pagination fails closed.
- YAML directories expose one-document-at-a-time iterators. Materialized reads remain compatibility wrappers for legacy/custom handlers.
- High-volume built-in handlers stream Export, validation, and Import. Compact identity/hash/dependency metadata is retained where a whole-operation view is required instead of retaining every parsed document.
- Provider identity multiplicity and rename safety use compact indexes rather than O(n²) rescans.
- Synchronize scans compact identity/hash summaries first. Field-level details are calculated only when an administrator requests one object, and summary rendering is paginated.
- The extension never raises the host PHP `memory_limit`. A provider that cannot be read safely fails closed instead of being silently truncated.

A real Export is a staged filesystem transaction rather than a sequence of independent live writes:

1. acquire the Configuration Manager operation lock for the sync root;
2. stream desired YAML into an isolated staging workspace;
3. validate global output-path uniqueness and build compact dependency/manifest metadata;
4. fingerprint/re-read active CiviCRM before publication and abort if it changed during staging;
5. journal/replace changed YAML;
6. move confirmed stale YAML only after all replacements are ready;
7. publish `manifest.yml` last;
8. restore the journal if publication fails; and
9. accept the new local baseline only after successful publication.

Import preserves the complete-preflight barrier. Static validation, dependency checks, rename/identity/provider checks, and handler dry-run complete before the first write. Every create/update phase finishes before delete-missing can begin. Any create/update failure prevents the global delete phase. Compact active fingerprints are checked again before writes and before delete-missing so an external/manual change becomes a conflict instead of a blind overwrite.

### Persistent web operations, ambiguity, and progress

Alpha63 persists browser Export/Import metadata in `civicrm_civicfg_job` and `civicrm_civicfg_job_item` and uses CiviCRM SQL Queue as the durable control plane. Queue items contain compact work-unit identifiers/payloads, never full YAML bodies. Export is split across prepare, handler/provider staging, metadata finalization, final active-state verification + YAML publication, per-handler baseline, and completion. Import is split across a complete non-writing preflight, create/update handlers, delete-missing handlers in reverse dependency order, baseline, and completion. The existing global barriers remain authoritative: every preflight check precedes any write, and every create/update handler must succeed before the first delete-missing handler can run.

High-volume CiviRules and contributed extension providers implement `ChunkedStreamingHandlerInterface`. A provider is scanned once into `DiskRowSpool`; compact identity/fingerprint counts stay in memory and the row bodies are replayed from disk to build staged YAML. `StagedExportWorkspace` persists a compact file index containing hashes, names, dependencies, provider metadata and monitor-only status. This lets extension indexes and reverse dependency metadata be finalized without repeatedly parsing the entire staged YAML tree.

Duplicate/unproven source identities are represented as monitor-only snapshots. Deterministic filenames include a canonical content fingerprint plus occurrence number; occurrence is snapshot bookkeeping, not portable identity. Synchronize therefore compares stable fingerprint multiplicity. Intentional monitor-only source rows never authorize CRUD/delete and do not block unrelated safe imports. A source item marked portable which is ambiguous on the target is instead a blocking conflict. Delete-missing is evaluated per identity: duplicate target identities are skipped while unrelated unique identities may remain eligible.

A persistent per-job workspace lives under the system temporary directory, outside the managed YAML root. Staging/read/baseline work units are explicitly retry-safe and replace their keyed temporary output/accounting on replay. Live-mutating Import units are not replayed when their prior result is indeterminate. The final Export verification and publication are deliberately one non-retry work unit: separating them would create a race in which active CiviCRM could change after verification but before publish.

YAML publication has two rollback layers. Normal exceptions restore the existing rollback journal immediately. Alpha63 also persists `publish-state.json` before each live filesystem mutation; if PHP is hard-killed, the next reviewed callback restores overwritten/deleted paths and removes newly-created partial paths before blocking the job. `manifest.yml` remains the last publication marker.

The queue consumer uses a short worker lock, while the persistent job row acts as the logical operation lock across HTTP requests. Synchronous CLI/UI mutations also refuse to overlap an active queued job for the same sync root. Before `runNext(FALSE)`, the web request releases an active PHP session lock so WordPress/CiviCRM status polls can observe durable heartbeats rather than appearing frozen.

Progress is intentionally semantic rather than cosmetic. The server persists current phase, named work unit, processed record count, heartbeat, memory telemetry, and optional known totals. When no reliable total exists, the UI shows `Running` plus `Phase N of M`/processed records instead of a fabricated percentage or misleading `Step X of Y`. A browser refresh reconnects to persisted state; the UI does not claim that closing the browser supplies an independent daemon.

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
