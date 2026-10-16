# Code structure — structural checkpoint R0.2

**Baseline:** `b8463cafd011e52a10e8c0f1d5e20f0ae95a1a26` (2026-10-16). The development version in `info.xml` remains `0.1.0-alpha68.6.1-core`. No version increment, public API change, database migration, provider permission change, or feature claim accompanies this layout-only refactor.

## Why these boundaries

The six largest production PHP files combined mutable state, long workflows and helper methods. Their layout made it expensive to locate a change and risky to add another condition in the wrong place. R0.2 distributes existing **byte-identical method bodies** into named concerns, while keeping the original classes as their compatibility facades and retaining every visibility/signature. These concern traits deliberately execute in the **same PHP object context**, preserving `$this`, private properties, inheritance, subclass overrides, late static binding and transaction/lock behavior. They are **not independent services or a new dependency-injection layer**.

This is a source-layout and navigation improvement, **not proof of full functional equivalence on real CiviCRM runtimes**. Favor focused application services for *new* logic when a true independently testable boundary exists. Do not add new broad traits or generic pass-through wrappers by default. The earlier actual services `ConfigIgnoreRules` and `ExtensionProviderIdentity` remain separate responsibilities.

## Source size before and after

| Original production file | Original lines | R0.2 lines | Responsibility retained in facade |
|---|---:|---:|---|
| `Service/ConfigManager.php` | 5,632 | 114 | Existing constructor, shared dependencies, Config Ignore delegate API |
| `Handler/ExtensionHandler.php` | 3,248 | 72 | Handler configuration, state and public type/metadata defaults |
| `Handler/AbstractHandler.php` | 979 | 418 | Base handler comparison and common defaults |
| `UI/MainPage.php` | 992 | 269 | Admin request routing and permission/CSRF entry points |
| `UI/Presenter.php` | 934 | 280 | Tabs, overview and import-plan presentation |
| `Handler/EntityDefinitionHandler.php` | 760 | 572 | API4 entity description, export and import coordination |

At this checkpoint there are **94 production PHP files** and the largest is `GenericApi4CollectionHandler.php` (608 lines). Test fixtures and historical documentation are not counted as production source and have not been arbitrarily split.

The only additional production script exceeding the PHP target was
`js/configmanager.js` (1,247 lines). `js/configmanager-scope.js` now contains
the 526-line Settings/scope-picker body (531 lines including module wrapper).
The shared `js/configmanager.js` entry point is 729 lines. `AssetLoader.php`
loads the scope module **before** the entry point; it passes the original
URL-normalization and JSON-response helpers to the scope initializer, without
adding a dependency on a JS package manager, build tool, or new global utility.
A missing optional Settings module is reported in the browser console without
breaking Export/Import form event handlers. Both module files are still plain
vanilla JavaScript for CiviCRM 5.x/6.x support.


## Find the code by responsibility

### Configuration orchestration (`Civi/ConfigManager/Service/`)

The public service remains `ConfigManager`. Its 15 named traits group existing code:

| File prefix (`ConfigManager*Trait.php`) | Owned behavior |
|---|---|
| `Environment` | CMS/project roots, sync paths, site identity and manifest data |
| `ProviderCatalog` | Handler registration, provider inventory, selector capabilities |
| `Scope` | Managed/monitor/ignore scope policies, picker items and dependencies |
| `ManagedFiles` | Managed YAML loading, scope filtering, current-manifest/setup state |
| `DirectExport` | Synchronous export orchestration |
| `QueuedExport` | Export work-unit plan, staging/finalization/publish and recovery |
| `Snapshot` | Workspace/active-state fingerprinting and optimistic guards |
| `ExportStaging` | Staged files, index pruning, reverse-dependency metadata |
| `Diff` | Compact synchronization diff, rename suggestions, lazy detail |
| `Validation` | Dependency/manifest validation, import filters and warnings |
| `ImportReview` | Review-plan creation/validation and stale-plan guards |
| `ImportExecution` | Synchronous import and fail-closed preflight helpers |
| `QueuedImport` | Reviewed queued import stages, delete-phase gates and completion |
| `Revert` | Reverting a single YAML config, dependency paths and ignore additions |
| `Monitoring` | Watch baselines, health state, scan history and notifications |

Config Ignore normalization, persistence, and filtering remain in the existing real collaborator `ConfigIgnoreRules.php`.

### Extension/configuration providers (`Civi/ConfigManager/Handler/`)

`ExtensionHandler` keeps its historic public API and shared cache/capability flags. Its 11 concern traits are: `ExtensionProviderInventory` (read-only listings/compatibility), `ExtensionProviderExport`, `ExtensionProviderImport`, `ExtensionProviderImportSafety`, `ExtensionProviderFilters`, `ExtensionProviderSettings`, `ExtensionProviderDiscovery` (finding API files/definitions), `ExtensionProviderMetadata` (API3/API4 metadata probes), `ExtensionProviderCollection` (provider row reads/cleaning), `ExtensionProviderIdentityAccess` (identity queries and mutation methods), and `ExtensionProviderUtility`. `ExtensionProviderIdentity.php` continues to own the *pure* portable identity policy.

**Safety boundary:** Discovery and inventory must remain metadata/file driven. They must not invoke provider collection reads or trigger any create/update/delete action. Keep mutation review/admission and destructive delete guards unchanged. Preserve weak/ambiguous identity fail-closed behavior and cache invalidation.

### Other large entry points

- `AbstractHandler`: `HandlerStructuredDiffTrait.php` owns structured diff formatting; `HandlerApi4TransportTrait.php` owns paginated API4 access and create/update/delete wrappers.
- `MainPage`: `MainPageOperationEndpointsTrait.php` owns queue/JSON operations, `MainPageSettingsTrait.php` owns settings helpers, and `MainPageResponseTrait.php` owns diff/provider endpoints and template assignment.
- `Presenter`: `PresenterChangeDescriptionsTrait.php` owns human-readable diff labels and formatting; `PresenterImportFeedbackTrait.php` owns Import summaries and errors.
- `EntityDefinitionHandler`: `EntityReferenceMappingTrait.php` owns nested reference resolution and validation.

Every facade explicitly includes its same-directory traits via literal `require_once`. This preserves bootstraps/tests that load a PHP class directly without Composer autoload. Production namespaces and imports are unchanged.

## Change safety and evidence

**R0.3 compatibility correction:** The facade must retain `private
isIgnoredPath(string $relativePath): bool` because `Scope`, `Snapshot`,
`ManagedFiles`, export-staging and Import paths still call it. This method
forwards to `ConfigIgnoreRules::shouldIgnorePath()`; do not reimplement matching
logic in traits or delete the bridge without updating every original internal
call site and its tests. PHPUnit source contracts should use
`tests/ci/source-contract-reader.php` for split PHP facades and the actual
`js/configmanager-scope.js` for Settings-only browser assertions.


- **Method preservation:** The 440 methods in the six source classes retain byte-identical definition and body text from commit `b8463ca` (SHA-256 verified before/after). Reflection confirms all 440 names, visibilities, static flags, parameter counts and return types unchanged.
- **Basic behavior tests:** `php tests/ci/refactor-boundaries-smoke.php` (26), `php tests/ci/refactor-composition-smoke.php` (72), Alpha62/63/64 contracts, Alpha68 UI/lifecycle state/behavior, browser-workflow contract, PHP lint, and available Node behavioral checks. `node tests/ci/configmanager-scope-composition-test.js` checks script ordering,
  Settings initialization, existing forms, and nonfatal missing-module behavior.
  An independent before/after DOM-ready fixture recorded identical query order
  (27 selectors), document event registrations (2), and error output.

- **Contracts:** `tests/ci/source-contract-reader.php` follows only literal, same-directory `*Trait.php` references and throws when required source is missing. The original safety assertions still inspect implementation text after splitting; contracts were **not disabled or relaxed**.
- **Source only:** Avoid packaging `.git`, Finder metadata, `vendor/`, `node_modules/` or generated caches; preserve executable file modes. The source ZIP is not an installable Beta2 release.
- **Deferred:** DDEV/Composer PHPUnit, genuine CiviCRM API4/provider behavior, browser workflows and cross-environment Imports remain to be verified. No claim of release readiness or ML-001 resolution.

## Extension rules for future development

1. Inspect the relevant trait and its owning class; do not read all 15 service traits just to change a scope label.
2. Change the canonical owning concern; keep one policy for each invariant. Prefer true collaborators for new pure policies and data access with clear inputs.
3. Preserve `ConfigManager`/handler public entry points, shared state and optional override behavior. Do not move database writes into metadata discovery.
4. Use focused smoke and failure-path tests. A green static contract is not a substitute for CiviCRM API4 behavior.
5. Update [Beta2 bundles](BETA2_DEVELOPMENT_BATCHES.md), [status](PROJECT_STATUS.md), [testing](TESTING.md) and relevant release documentation with each implemented feature.

**Next planned work:** Bundle 1 ML-001 (locale-independent Afform drift), followed by A67-07 Sync state correctness. Scope and release gates remain unchanged; see [Beta2 priorities](BETA2_PRIORITIES.md).
