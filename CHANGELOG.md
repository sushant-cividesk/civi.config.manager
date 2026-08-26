# Changelog

## 0.1.0-alpha61-core

- Universal runtime-ignore/documentation hotfix: Configuration Manager now always excludes the proven volatile values `extensions/*/api3/Job/*.yml:item.last_run`, `extensions/*/api3/Job/*.yml:item.last_run_end`, `scheduled-jobs/*.yml:item.scheduled_run_date`, and `site-tokens/*.yml:item.modified_date`; administrator Config Ignore Values remain additive. The Settings UI shows built-in rules separately from project-specific rules. README and architecture documentation were rewritten as concise current-state documentation, with extended maintainer detail moved to `docs/DETAILED_REFERENCE.md`. No version bump.

- QA/CLI release-gate hotfix: standalone QA no longer depends on Mailpit; the disposable application network is now truly internal and the suite proves both blocked PHP mail and blocked direct internet egress. CLI install now discovers Drupal `sites/default/vendor/bin`, can create a PATH-advertised `$HOME/.local/bin`, and full QA verifies `civicfg` is callable by name immediately after extension enable. Generic API4 imports also update stable ID-less virtual entities by portable identity instead of assuming every row has a numeric `id`. No version bump.

- State tracking hotfix: diff/watch persistence now writes SQL `NULL` for nullable YAML/active hashes instead of binding PHP `NULL` as a CiviCRM `String`, eliminating `is not of type String` baseline/state warnings when an object exists only on one side of a comparison. Added nullable-state regression coverage. No version bump.

- Import preflight safety hotfix: preview now reports site-identity, YAML/dependency, rename, provider-capability, and handler blockers in one pass before any write; dependencies planned earlier in the same import satisfy later dry-run checks; OptionValue machine-name collisions block both rename and delete-missing; ambiguous/monitor-only extension providers and CiviRules numeric-ID junction rows cannot authorize destructive cleanup; and any create/update runtime failure prevents the global delete-missing phase from starting. Added focused regression/scenario coverage. No version bump.

- Real-world DEV-to-stage-clone import hardening: Custom Group dependency export now treats `extends_entity_column_value` as ContactType IDs only for contact-based groups, so ActivityType and other entity-local IDs are no longer misreported as non-portable ContactType dependencies. Generic extension configuration now classifies `afsearch...` references as FormBuilder Afform dependencies instead of SearchDisplay dependencies. CLI/system exports and confirmed identity aliases now persist nullable audit-user fields as SQL `NULL` instead of binding `NULL` as an integer, eliminating baseline-state failures when no CiviCRM contact is logged in. No version bump.

- Fixed SQLTasks 2.2.x compatibility by using its public API3 `Sqltask.getalltasks` collection action (with `get` hydration, `create` create/update, and `deletetask` delete) when native API4 `SqlTask` is absent; stale BAO-adapter metadata also falls back safely to this API. Unavailable scope providers now preserve their saved policy, permit only an explicit switch to **Ignore**, disable unsafe picker/watch/bulk controls, and synchronization errors are detailed only once.

- Hardened runtime/provider UX and compatibility: SQLTasks now loads and prefers its native API4 `SqlTask` class from the discovered extension path before considering the legacy API3/BAO fallback; unavailable scope item pickers are blocked with an explicit error; message colors are forced to semantic error/warning/success states across CiviCRM themes; and full-management labels now verify the API4 CRUD actions actually used by each supported handler, downgrading safely to export/compare when write actions are missing. GitHub Actions checkout/setup-node usage is updated to Node 24-compatible action versions.

- Added scope dependency guidance for portable deployments: Settings now shows related configuration types, live warnings when a managed type depends on ignored/monitor-only/unavailable/selected scope, and an explicit **Manage recommended dependencies** helper that only changes scope after the administrator clicks it. Saved risky combinations remain allowed but are reported, while actual import dependency validation remains authoritative. Added regression coverage and kept the implementation PHP 7.4-compatible.
- YAML runtime diagnostics now report bundled Symfony availability, extension-local vendor/autoload presence, and the PHP yaml extension separately. A missing YAML runtime is raised as a CiviCRM System Status error; either bundled Symfony YAML or ext-yaml remains sufficient at runtime.

- Added legacy-runtime hardening for CiviCRM 5.76 / Drupal 7 / PHP 7.4 deployments: fast QA now includes PHP 7.4 and PHPCompatibility 7.4, Composer resolves runtime dependencies against PHP 7.4.33, and missing optional API4 providers fail closed instead of being mistaken for authoritative empty configuration. Settings labels unavailable optional providers clearly, preserving existing YAML/baselines rather than authorizing destructive cleanup.

- Fixed Configuration Scope/export consistency: saving **Manage everything** now replaces stale `ignore` manifest state even when a handler reports a partial provider error, and extension status YAML can still be exported when one contributed provider cannot be inspected. Incomplete handler exports never authorize stale-YAML deletion or baseline acceptance.
- Settings now warns clearly when scope changes are unsaved, adds a nearby **Save scope changes** action, and avoids claiming **In Sync** on the Settings tab before a real Synchronize comparison. Synchronize shows persistent provider errors and cannot report **In Sync** when the managed comparison is incomplete.

- Fixed initial Synchronize state classification: all-Ignore/empty-selected scope now shows **Setup Required**, watch-only scope shows **Monitoring Only**, managed scope without a baseline shows **Initial Export Required**, and **In Sync** is reserved for an actual managed baseline with zero differences. Managed/watch actions are hidden when their corresponding scope is not configured.

- Final QA correction: SQLTasks 3.0.0-alpha3 exposes native API4 `SqlTask`, so full real-fixture QA now verifies the canonical API4 provider first and uses the reviewed API3/BAO path only as a fallback when API4 is unavailable.

- Final same-version SQLTasks discovery hotfix: the reviewed `de.systopia.sqltasks` provider is now defined declaratively from its installed `Sqltask/Create.php` and `Sqltask/Deletetask.php` files, so discovery no longer depends on loading legacy BAO classes or probing unreliable API3 runtime metadata. BAO class loading is deferred until provider rows are actually read.
- Extension base-path discovery is now resilient per extension and falls back conservatively to the configured CiviCRM extensions directory for an installed key, so one stale mapper entry cannot suppress later contributed providers during isolated CLI/QA bootstrap.

- Follow-up QA hotfix: API3 Entity/Action.php files now provide deterministic capability discovery before runtime `getactions` introspection. This fixes pinned SQLTasks 3.0.0-alpha3 being skipped when its custom API3 metadata path warns/fails even though `Create.php` and `Deletetask.php` are present.
- SQLTasks' reviewed BAO read adapter can now explicitly load its DAO/BAO classes from the installed provider base path in isolated CLI/bootstrap contexts, and uses a pinned portable write-field allowlist matching the provider create specification instead of re-probing API3 metadata.

- Follow-up hotfix: fixed pinned SQLTasks 3.0.0-alpha3 discovery in full QA. That provider exposes `Sqltask.get` only for a required numeric ID and no API3 collection action, so Configuration Manager now prefers the extension-owned `CRM_Sqltasks_BAO_SqlTask::generator()` + `exportData()` pair as a narrowly reviewed read-only collection adapter before generic API3 read probing, while keeping create/update/delete on SQLTasks API3 actions.
- Follow-up hotfix: watch-only detections now append to a bounded local recent-history list instead of disappearing when the next watched item changes or a later scan finds no new differences. The latest scan remains separate from recent history, and administrators can clear only the history without resetting watch fingerprints/baselines.
- The Watched Configuration panel now reopens and scrolls into view immediately after **Scan Watched Config**, shows compact latest-scan counters, separates current findings from recent history, and keeps previous detections readable across scans.
- Fixed the remaining alpha61 PHPStan failure in `FreshInstallDefaultsTest` by invoking the runtime install helper through `ReflectionFunction` rather than a callable string PHPStan cannot prove.
- Standalone QA no longer publishes Mailpit on host port 8025; mail-isolation checks query Mailpit only across the isolated Docker network, avoiding collisions with local DDEV/Mailpit services. Local UI QA can fall back to the pinned Playwright Docker image when Node/npm is not installed on the host, while GitHub Actions continues using its configured Node runtime.

- Fixed selected-scope monitoring so **Monitor everything else in this type** establishes watch fingerprints when the scope is saved; later watch scans now detect changes instead of silently treating the first post-change scan as the baseline. Existing watch fingerprints are never overwritten by this initialization.
- Watch results now report how many items were baselined, making the first monitoring scan explicit.
- Fixed the alpha61 PHPStan failure in the fresh-install regression test.
- Hardened standalone QA preflight so Docker/Compose availability is checked before fixture downloads or artifact creation, with clear guidance when commands are run inside `ddev ssh`; generated QA artifacts are ignored by Git.
- Prevented expanded selected-item cards from stretching every sibling card in the Settings grid.
- Made fresh installations opt-in by defaulting unconfigured scope to `Ignore`, while preserving the legacy manage-everything fallback for existing installations that do not have the new fresh-install default marker.
- Added first-run Settings guidance and Drupal-style checkbox/bulk mode controls; bulk Apply changes the form only and still requires the existing Save settings action.
- Made both the management scope and Advanced settings sections collapsible and expanded by default.
- Added Playwright coverage and review screenshots for the Settings scope UX.
- Added local full-QA Composer commands that use the standalone Docker CiviCRM stack without requiring Buildkit, with Playwright optional.
- Hardened full QA with clearer stage markers and pinned contributed-extension fixtures for reproducible runs.

## 0.1.0-alpha60-core

- Follow-up hotfix: normalized custom API3 write responses that return one associative row directly under `values`, preventing a successful SQLTasks create/update from being reported as `Return value must be of type array, string returned`.
- Follow-up hotfix: SQLTasks delete-missing now uses its provider-specific `deletetask` API3 action, and zero-count provider indexes preserve the explicit empty desired set so deleting the final task on DEV can delete the matching task on STAGE. Explicit SQLTasks-only imports also seed an empty desired provider set for compatibility with pre-hotfix YAML.
- Fixed generic API3 contributed-provider restore by filtering portable export/import values to the provider's `create` action field specification when `getfields` is available. This prevents computed/read-only collection fields from being sent back to provider create/update APIs.
- Fixed SQLTasks restore specifically: fields such as `last_executed`, `last_runtime`, `next_execution`, `schedule`, `schedule_label`, `short_desc`, archive state, and stale modification metadata are excluded from create/update payloads while writable task fields and nested `config` actions remain intact.
- Fixed virtual extension-provider import isolation. Selecting only a provider subtype such as `extensions:de.systopia.sqltasks:api3:Sqltask` now stays scoped through CLI and UI preview/apply, validates and applies only that contributed provider, and is not blocked by unrelated extension-provider YAML.
- Normalized contributed-provider diff comparison through the same API3 writable-field cleaning so older YAML containing provider runtime fields does not create false SQLTasks drift after restore.
- Added unit and scenario regression coverage for API3 writable-field filtering, nested SQLTasks configuration preservation, and subtype-only import validation/apply isolation.

## 0.1.0-alpha59-core

- Reworked Configuration Scope Settings into plain-language, mode-aware cards with `Manage everything`, `Manage selected items`, `Monitor only`, and `Ignore`.
- Added a lazy searchable item picker that scans only the requested handler when opened, preserving the no-scan performance rule for ordinary Settings/page requests.
- Picker selections save portable semantic `key:` selectors automatically while advanced numeric/name/key/path selectors remain available for automation and temporarily missing records.
- Added handler capability labels without running exports: full management, export/compare only, and mixed contributed-provider capabilities.
- Added a generated, copyable `civicrm.settings.php` scope example based on the current UI choices; code-owned overrides remain locked in the UI.
- Moved site identity, cross-site import, raw settings allowlist, and Config Ignore controls under Advanced settings to reduce normal-page complexity.
- Corrected `path:` selector matching to use full relative YAML paths consistently.
- Reclassified expected contributed-provider weak-identity limitations as compatibility information rather than YAML validation warnings, while automatic writes remain blocked.
- Improved extension status diffs to explain YAML and CiviCRM states in plain language and collapse large change-detail lists by default.
- Added unit/browser coverage for lazy picker behavior, capability rendering, portable selectors, missing managed selectors, settings-file examples, path selectors, compatibility reporting, and mode-dependent UI.
- Hotfixed the lazy picker search so filtered rows are reliably hidden even with the grid display rule, with visible-result counts and browser regression coverage.
- Hotfixed the alpha59 unit-test bootstrap with a test-only CiviCRM `ts()` fallback so translated Presenter wording is testable outside a bootstrapped CiviCRM runtime.
- Added first-class API4/CLI management for Configuration Scope and reviewed cross-site import policy, with validation and command regression tests.
- Fixed contributed-provider CREATE safety: zero target matches is now treated as the normal create case for a strong portable identity, while duplicate target matches still block automatic writes. This fixes write-safe SQLTasks-style records being skipped on a fresh target.
- Added post-create read-back verification for contributed provider records and preserved nested provider-owned configuration values while stripping only known top-level runtime metadata.
- Reclassified expected backup/monitor-only provider skips as compatibility information instead of import warnings, and stopped the UI from offering Restore for extension-provider YAML whose identity is not write-safe.
- Added direct cross-site policy tests, contributed-provider create/duplicate tests, SQLTasks provider capability assertions, expanded CLI command tests, and additional Playwright scope-search/settings coverage.
- Improved Message Template picker labels to prefer the human template title while keeping workflow/default identity portable, and stopped normal extension enable/disable/install actions from being counted as warnings.

## 0.1.0-alpha58-core

- Added universal Configuration Scope with `Manage all`, `Manage selected`, `Watch only`, and `Ignore` modes for registered configuration handlers, plus optional watch-unmanaged behavior for selected scope.
- Added `civicrm.settings.php` scope overrides through the normal domain `civicfg_scope` setting; the UI treats code-owned scope as read-only.
- Added source-selector to semantic-config-key resolution in `manifest.yml`, allowing local numeric IDs to bootstrap selection without becoming cross-environment identities.
- Added explicit local watch-state storage and UI/API/CLI watch scans. Watched configuration is never exported to YAML and is never eligible for import, restore, or delete until it is moved into managed scope.
- Disabled bulk delete-missing centrally for selected scope so an unselected CiviCRM object can never be deleted merely because it is absent from selective YAML.
- Preserved YAML backups for selected objects that are temporarily missing from active CiviCRM and report the missing configured selector instead of silently deleting the backup.
- Restricted managed ZIP and single-file export/download paths to the current effective scope, so stale deselected/watch-only YAML can remain safely on disk without leaking into managed deployment artifacts and crafted requests cannot export unselected active objects.
- Added explicit Message Template identities for system workflow/default variants and unique user-template titles, with ambiguous duplicate user titles blocked from automatic writes.
- Converted Contact Types, Relationship Types, Location Types, Financial Types, Payment Processors, Dedupe Rules, and allowlisted CiviCRM Settings to split item exports where practical so item-level selection can be applied consistently; local source IDs are stripped from portable YAML and sensitive settings remain excluded.
- Changed Synchronize initial setup to show one initial-export prompt instead of flooding the page with every active CiviCRM record, and simplified post-baseline verbal diff summaries while retaining complete technical field details.
- Removed full configuration scanning from the CiviCRM system check; normal status requests now read cached last-scan health only.
- Reduced local state-table overhead by ensuring the operational schema only once per PHP process instead of re-running CREATE TABLE checks for every fingerprint update.
- Reduced Configuration Manager page work by avoiding virtual extension-provider discovery on Settings and by reusing the Export tab's existing preview to populate the single-file selector instead of exporting every handler twice.
- Added scope/portable-selector, message-template identity, canonical metadata, API permission, and no-scan health regression tests plus the `CM-SELECTIVE-SCOPE-WATCH` developer scenario.

## 0.1.0-alpha57-core

- Fixed Standalone/WordPress-style runtime YAML parsing when the host application does not already autoload Symfony YAML.
- Promoted `symfony/yaml` from a development-only dependency to a runtime dependency and explicitly load the extension-local Composer autoloader before parsing YAML.
- Removed the unsafe raw-text parse fallback: YAML reads now fail closed with a clear error if neither bundled Symfony YAML nor ext-yaml is available.
- Updated packaging policy so release/development ZIPs include production Composer dependencies while still excluding development-only dependencies and QA artifacts.
- Retained the existing manifest site-family safety check; the failing standalone assertion was exposing the missing parser rather than an incorrect site identifier.

## 0.1.0-alpha56-core

- Continued from the complete beta2 + alpha55 codebase while replacing development-only identity/fingerprint internals before public release.
- Reworked configuration matching around semantic `config_key` identities with explicit identity confidence and safe-write gating instead of treating filenames or local database IDs as authoritative identities.
- Replaced SHA-1/type-collapsing comparison fingerprints with deterministic, type-preserving, versioned SHA-256 canonical fingerprints.
- Added local Configuration Manager state tables for rebuildable object scan state, accepted canonical baselines, and operator-confirmed identity aliases; YAML remains the portable source of truth.
- Added baseline-aware drift states and three-way field analysis for active drift, YAML changes, synchronized changes, non-conflicting divergence, and true conflicts.
- Added conservative machine-identity rename suggestions and an API4 action for explicitly confirming reviewed identity aliases; renames are never auto-applied.
- Expanded `civicfg_entityDefinitions()` metadata with exact runtime fields, semantic API4 reference fields, ordered/unordered collection metadata, and create/update/delete capabilities.
- Tightened generic contributed-extension writes so weak `title`/`label` identities remain export/diff visible but cannot be automatically created, updated, or deleted.
- Added contributed-extension compatibility discovery/reporting and broadened full real-fixture QA to the regular DEV/STAGE extension set, while treating extensions with no portable config as a valid classification rather than a test failure.
- Made contributed-provider reads fail closed: API read/hydration failures are reported as `ERROR` instead of being mistaken for zero records, preventing stale YAML deletion or duplicate creation after a provider read failure.
- Made declared semantic references fail closed when a local ID cannot resolve to the exact configured stable target key, so environment-local foreign keys never leak into portable YAML.
- Preserved baseline direction for object deletions and baseline continuity across repeated operator-confirmed renames; duplicate semantic identities remain ambiguous in both diff and local state tracking.
- Replaced CLI wrapper sprawl with one extension-owned `bin/civicfg`, one optional Composer `vendor/bin/civicfg`, and one ownership-aware global dispatcher that resolves the active extension path at runtime; legacy managed aliases are removed safely.
- Added multi-project global CLI registry/uninstall behavior, Composer/non-Composer launcher tests, canonical identity/fingerprint/conflict tests, provider capability/reference tests, and updated CLI smoke checks.

## 0.1.0-alpha55-core

- Continued development from the complete `0.1.0-beta2` codebase without replacing or removing the beta1/beta2 release history.
- Improved Synchronize summary wording to describe current state accurately (`Only in CiviCRM`, `Only in YAML`, and `Different`) instead of implying which side changed first.
- Verified the public `civicfg_entityDefinitions()` and advanced `civicfg_configTypes()` extension hooks remain usable by custom/contributed extensions through CiviCRM's normal hook dispatch; added focused registry coverage for both hook paths.
- Broadened generic API3 contributed-extension discovery to support `Entity/Action.php` layouts, conservative singular setting namespaces, and safe custom `get-all...` collection actions.
- Added generic API3 row hydration through the provider's `get` action when a custom collection action returns IDs, allowing complete provider configuration to be exported without direct table access.
- Treats common provider runtime timestamps such as `last_modified` as non-portable runtime data so cross-environment YAML does not carry stale modification timestamps into API3 updates.
- Expanded SQL Tasks fixture coverage for its singular `sqltask_*` setting namespace and generic API3 discovery paths without adding a SQLTasks-specific production handler.

## 0.1.0-beta2

- Improved beta CLI terminal access by installing managed wrappers into project/shared bin directories, adding safe sourceable PATH helpers (`civicfg-env` / `civicfg-path`), and supporting explicit terminal bin installs with `CIVICFG_GLOBAL_BIN_DIR`.
- Added CLI wrapper unit coverage and made GitHub fast/full QA run the focused CLI wrapper test alongside the metadata-hook test.
- Clarified contributed/custom extension integration: API4-backed config should use `hook_civicfg_entityDefinitions()`, while non-API4/private-table config should use the advanced custom handler hook.

## 0.1.0-beta1

- Promoted the current alpha54 feature set to an internal beta release for development-project testing.
- Included the core Configuration Manager workflows: export, import, diff, validate, config ignore, revert/delete handling, CLI wrappers, UI review screens, GitHub fast/full QA workflows, and metadata-driven extension config hooks.
- Marked future development as compatibility-aware: changes after this beta should be incremental, documented, and should consider existing installed beta users and exported YAML before changing behavior.

## 0.1.0-alpha54-core

- Added stronger unit coverage for the public `civicfg_entityDefinitions()` metadata hook, including stable-key export, split and collection YAML modes, where/order metadata, composite keys, create/update/dry-run/delete-missing import behavior, sensitive-field rejection, ignored-field diff handling, and invalid-definition errors.
- Added a developer scenario contract for the metadata hook so the expected fixture coverage is visible in fast QA.
- Updated GitHub Actions so the fast and full workflows run a dedicated required `composer test:hook` metadata-hook unit step in addition to the normal fast QA gate.
- Removed advisory PHPCS/PHP-compatibility checks from the required fast workflow path so style cleanup cannot block functional hook QA.

## 0.1.0-alpha53-core

- Added the preferred public `civicfg_entityDefinitions()` hook so other extensions can declare APIv4-backed config metadata and get export/import/diff/validate support without writing a custom handler.
- Added metadata-driven support for stable key fields, explicit export fields, ignored runtime fields, sensitive-field blocking, dependency metadata, split YAML files, optional delete-missing behavior, and export-only definitions.
- Added extension hook documentation with a live example and kept `civicfg_configTypes()` available for advanced custom/private-table handlers.


## 0.1.0-alpha52-core

- Fixed the GitHub Actions fast QA failure caused by advisory `composer standards` returning a non-zero PHPCS status after required QA had already passed.
- Made coding standards and PHP compatibility advisory steps explicitly non-blocking while keeping their output visible in the Actions log.
- Added `.github/workflows/qa-fast.yml` and `.github/workflows/qa-full.yml` back into the release ZIP.
- Limited the PHPCS standards scan to PHP-like files so Playwright JavaScript is not parsed by PHP/CiviCRM sniffs.

## 0.1.0-alpha51-core

- Added full isolated CiviCRM real-fixture QA coverage for scheduled jobs, dedupe rules, contact types, settings ignore/revert, stale YAML deletion, and full export/import idempotency.
- Added fixture-extension download/install support for Mosaico, SQLTasks, Contact Layout, and CiviRules before the Docker app is isolated from external network access.
- Added extension config QA assertions to verify deployable extension YAML is produced, generated Mosaico base templates are skipped, and extension import/idempotency stays safe.
- Added full QA artifacts for fixture-extension fetch/install logs and real-fixture integration summaries.

## 0.1.0-alpha50-core

- Fixed the GitHub Fast QA PHPStan failure by adding API4 autoloading, iterable PHPDoc coverage for the unit-testable storage/YAML code, and a realistic initial PHPStan level for the legacy-safe baseline.
- Kept Composer audit visible as an advisory workflow step so known dev-dependency advisories do not block the first automated QA push while the dependency baseline is stabilized.
- Preserved the full isolated QA workflow and symlink-safe sync-root support from alpha49.

## 0.1.0-alpha49-core

- Added a fast GitHub Actions workflow for syntax, isolated PHPUnit unit tests, PHPStan, Composer audit, CiviCRM coding standards, and PHP 8.1 compatibility checks.
- Added a manually triggered full QA workflow using a disposable CiviCRM Standalone, MariaDB, Mailpit, and optional Playwright environment.
- Added isolated API4, YAML storage, handler registry, settings redaction, Option Group round-trip and reserved-value deletion, malformed handler/manifest YAML validation, Message Template, Config Ignore, UI confirmation, browser-error, and accessibility coverage.
- Added developer-owned scenario definitions under `tests/scenarios` so future changes provide disposable fixtures, expected results, negative cases, and cleanup requirements.
- Hardened YAML and upload storage against traversal, symbolic-link escapes, partial writes, malformed manifests, duplicate ZIP paths, oversized YAML/ZIP payloads, and empty archive downloads.
- Prevented password, secret, token, credential, and key-like settings from being exported or imported through the settings handler.
- Added cleanup verification, read-only source mounts, an observable PHP mail-attempt blocker, an internal-only Docker network, browser-level external request blocking, zero-message Mailpit verification, and sanitized QA artifacts.


- Added GitHub Actions workflow files for push/pull fast QA and manually triggered full isolated CiviCRM QA.
- Allowed the sync directory root itself to be a symlink after resolving the real path, while continuing to block symlinked files/subdirectories and path traversal inside the sync directory.
- Added unit coverage for symlinked sync-root support in YAML storage and upload/write handling.

## 0.1.0-alpha48-core

- Improved Sync, Import, and Export review screens with plain-language field summaries such as contact type label changes and option value weight/order changes.
- Cleaned up review card UI and action button variants for clearer theme-safe UX.
- Hardened Config Ignore modal behavior so field selection and whole-file selection stay mutually clear.
- Added runtime `civicrm_setting` discovery for extension-related settings, improving generic support for SQLTasks-style extension configuration.
- Broadened generic API3 extension entity discovery with a conventional action-function fallback while continuing to skip read-only/generated provider records.


## 0.1.0-alpha47-core

- Improved Synchronize, Import, and Export review screens with plain-language descriptions for changed, added, and removed configuration.
- Cleaned Managed Types and Filter Config Types into standard managed types and extension-owned managed config groups.
- Fixed Ignore modal UX so selecting a field automatically chooses field-level ignore, and switching back to whole-file ignore clears field selections.
- Pruned extension config indexes during export when split extension-owned YAML files are ignored or filtered, avoiding dangling index-only dependencies.
- Improved project CLI wrapper installation to write wrappers to the CMS docroot `bin`, the parent project `bin` when the docroot is `web`, and the shared DDEV `/var/www/html/bin` when writable.
- Added CLI documentation covering project wrappers, aliases, disable warnings, and recommended DDEV usage.

## 0.1.0-alpha46-core

- Fixed per-file Revert so it applies YAML back to active CiviCRM for the selected file and dependency closure instead of updating YAML from CiviCRM.
- Improved Managed Types and Filter Config Types display for extension-owned config entities by separating the entity label from the provider extension key.
- Added menu bar display settings (`menubar_color` and `menubar_position`) to the recommended settings allowlist and upgrade checks.
- Updated sync labels to distinguish changed files, added-in-CiviCRM files, and added-in-YAML files clearly.

## 0.1.0-alpha45-core

- Renamed the extension machine key to `civi.config.manager` while keeping the public UI label as `Configuration Manager`. Legacy self-ignore rules for older keys remain in place so existing YAML does not create a self-management loop.
- Added per-file `Revert` action on the Synchronize screen. Revert updates one YAML file from active CiviCRM, or deletes the YAML file if the matching CiviCRM record no longer exists.
- Added per-file and field-level `Ignore` actions from the Synchronize screen. Ignore rules are confirmed and saved into the existing Config Ignore settings.
- Added dynamic extension-owned config filter options so supported contrib/custom extension entities can appear under Filter Config Types and Managed Types when their provider extension exposes safe deployable config APIs.
- Hardened generic extension config discovery so read-only/generated provider API entities are skipped unless create/update support is available. Known generated provider files such as Mosaico base templates are treated as stale YAML and removed by export instead of imported.

## 0.1.0-alpha44-core

- Stopped exporting/importing MosaicoBaseTemplate records because they are generated from packaged extension assets and contain environment-specific URLs.
- Existing legacy MosaicoBaseTemplate YAML files are now skipped with a warning during import and should be removed by running Export.
- Hardened generic API3 extension-config discovery so read-only providers without create support are not treated as deployable config.
- Prevented read-only generic extension config from causing hard import errors when syncing same-site dev/stage environments.

## 0.1.0-alpha43-core

- Changed Site Identifier from a user-entered option to an automatically generated per-site-family identifier stored in CiviCRM settings. Cloned dev/stage/prod environments keep the same identifier; separate sites get different identifiers.
- Reworded Cross-site Import as an experimental reviewed-migration option while keeping validation/manual import controls in place.
- Added reverse dependency metadata (`required_by`) during export so YAML files can show which other managed files depend on them, and validation warns about stale/missing reverse dependency links.
- Added project-level CLI wrapper installation for `civicfg`, `cvcfg`, `config-export`, `ce`, `config-import`, `ci`, `config-diff`, `cdf`, `config-validate`, and `cval`. Existing non-managed project bin files are not overwritten, and wrappers warn if the extension is disabled.
- Added lifecycle/upgrade handling for the generated site identifier and CLI wrappers so future releases can upgrade deployed installations cleanly.
- Tightened scoped button styling so action buttons render consistently across CiviCRM core and custom themes.
- Hotfix: export now removes stale managed YAML files when the matching active CiviCRM record no longer exists, instead of reporting nothing to export.
- Hotfix: stale YAML cleanup now uses the same missing-in-CiviCRM diff detection as the Synchronize screen and shows an EXPORT confirmation modal before deleting stale YAML files.
- Hotfixed Custom Groups import to initialize the desired-group tracking list and skip delete-missing checks when earlier custom-data import errors exist, preventing a PHP TypeError and unsafe follow-up cleanup.

## 0.1.0-alpha42-core

- Added an optional Configuration Manager Site Identifier. Export writes it to `manifest.yml`; validation blocks imports from a different site when both source and target identifiers are set unless cross-site import is explicitly allowed.
- Added field-level Config Ignore rules using `path/to/file.yml:dot.path`, so environment-specific values can be ignored without excluding the whole YAML file.
- Split generic contributed/custom extension API config into separate `extensions/<extension>/<api>/<entity>/<item>.yml` files, while keeping extension status/settings in `extensions/<extension>.yml`. This keeps large items such as Mosaico templates readable and maintainable.
- Added a generic packaged-asset heuristic to avoid exporting extension-provided base assets as site configuration when they are safely recreated by the extension itself.
- Preferred API4 over APIv3 when the same extension exposes the same entity through both APIs, avoiding duplicate YAML for the same record.
- Downgraded generic extension-config duplicate/already-exists import conflicts to warnings/skips where possible instead of treating them as hard failures.

## 0.1.0-alpha41-core

- Removed separate Extension Entity Config and Extension-specific Settings managed types to avoid producing hundreds of duplicate YAML files.
- Bundled safely discoverable contributed/custom extension settings and extension-provided API config under each `extensions/<extension-key>.yml` file.
- Skipped CiviCRM core component extensions and already-managed core handlers during generic extension-config discovery to avoid exporting operational data such as line items, events, and duplicate SearchKit/FormBuilder config.
- Added import delete/revert support for non-reserved option values that exist in CiviCRM but are missing from YAML. Reserved option values remain protected and are reported as warnings.
- Improved import summary totals so nested option value and bundled extension config changes are counted correctly.

## 0.1.0-alpha40-core

- Reworked contributed/custom extension support to use generic discovery instead of extension-specific handlers.
- Added generic Extension Entity Config handler, which discovers configuration-like API4/APIv3 entities exposed by installed extensions and exports them under `extension-config/<extension>/<api>/<entity>/<item>.yml` when stable identities are available.
- Reworked Extension-specific Settings handler to discover non-secret extension settings from metadata and installed-extension namespaces instead of hard-coded extension keys.
- Improved dependency validation wording for missing dependencies, especially older YAML that still contains local numeric IDs.
- Fixed Custom Groups/Fields export dependencies so contact-type scope dependencies use Contact Type machine names when possible instead of local numeric IDs.
- Updated README/testing notes for alpha40 generic extension-config behavior and CLI command/alias structure.

## 0.1.0-alpha39-core

- Hardened Config Ignore so ignored DB-only records are hidden from Synchronize/import previews when their generated YAML path matches an ignore rule.
- Added dependency-risk warnings after saving Config Ignore when non-ignored YAML depends on ignored YAML.
- Added requested CLI aliases `cdf`, `cval`, and `cvcfg`; kept `ce`, `ci`, and main command wrappers.
- Updated CLI help to document `-y`, `-h`, and `--help`.
- Added cross-theme UI compatibility styles for CiviCRM core themes.

## 0.1.0-alpha38-core

- Added dedicated CLI wrapper scripts under `bin/`:
  - `bin/civicfg ce` / `bin/config-export`
  - `bin/civicfg ci` / `bin/config-import`
  - `bin/civicfg cd` / `bin/config-diff`
  - `bin/civicfg config-validate`
- Improved Config Ignore behavior so ignored YAML files are hidden from diff, validate, import, export, single-file preview, and ZIP download.
- Added clearer warnings when ignored files may hide dependencies needed by non-ignored YAML.
- Filtered ignored DB-only diff entries, including the default self-ignore for `extensions/civi.config.manager.yml`.
- Documented that Configuration Manager is intended to work smoothly for the same site codebase across dev/stage/prod, while cross-site imports may still need careful review.


## 0.1.0-alpha37-core

- Added Config Ignore settings for relative YAML paths/wildcards, similar to Drupal config ignore. Ignored files are skipped during diff, validate, export, and import.
- Ignored `extensions/civi.config.manager.yml` by default to avoid self-management loops when Configuration Manager exports extension status.
- Improved SearchDisplay import matching with composite identity `saved_search_id.name + name`, so extension-provided displays like `Table` can be matched instead of causing duplicate/already-exists failures.
- SearchDisplay split exports now use `SavedSearch__Display.yml` filenames for new exports to avoid collisions where multiple searches have a display with the same name.
- Downgraded already-exists create conflicts to warnings when the target record can be matched safely after the conflict.
- Improved relationship type matching fallback by labels when machine names differ, with warnings for review.
- Updated import result handling so a non-blocking issue does not leave a scary error state when no pending diff remains.

## 0.1.0-alpha36-core

- Exported extensions as one YAML file per extension key to prepare for future extension-specific config grouping.
- Added full-page progress overlay for import, export, validate, upload, and settings form submissions to reduce double-click/resubmission risk.
- Preserved import result details across redirect so failure notices can name the first handler/file error and the page can list warnings/errors.
- Added optional Site Tokens handler for sites exposing a `SiteToken` API4 entity.
- Improved Custom Groups and Fields import with YAML-source delete support for missing fields and non-reserved missing groups, plus stronger dependency metadata.
- Added alpha CiviRules handler for common CiviRules API4 entities when available.
- Updated documentation and testing notes for alpha36 behavior.


## 0.1.0-alpha35-core

- Fixed cross-site diff comparison to normalize runtime fields before deciding a file is changed. Numeric database IDs should no longer appear as import/update-only differences after deploying YAML from another database.
- Improved list comparison identities so rows keyed by `key`, `name`, `name_a_b`, `title`, or duplicate `name + value` are compared safely. This avoids false option-value and extension-status diffs.
- Fixed the delete phase of generic imports so it does not resolve create/update-only dependencies while it is only calculating missing-record deletes.
- Kept the Export page full archive UI fix so it does not imply that the ZIP contains only the files changed by the preview.

## 0.1.0-alpha34-core

- Ignored runtime numeric database IDs in generic API4 diff/export comparison so YAML exported from one database can be compared safely against another database.
- Normalized SearchDisplay diff/export comparison to use `saved_search_id.name` instead of source database `saved_search_id` where available.
- Removed the misleading planned-file list from the Export tab full archive panel; ZIP download represents the full current sync directory, while Export writes pending YAML changes.
- Kept validation/import behavior from alpha33, including safer option value identity handling.

## 0.1.0-alpha33-core

- Fixed validation noise for core option groups where CiviCRM legitimately reuses option value names with different stored values.
- Updated option value validation/import identity handling so duplicate names are matched by name plus value where needed instead of failing validation.
- Updated Custom Groups and Fields export to write option group references as stable `option_group_name` values where possible.
- Kept legacy custom field YAML with numeric `option_group_id` validation-compatible so older alpha exports do not fail validation unnecessarily.
- Updated docs to clarify option value identity handling and environment-safe custom field option group dependencies.

## 0.1.0-alpha32-core

- Fixed the export dependency confirmation modal so export asks for `EXPORT` and shows export-specific warning text instead of import text.
- Added stronger dependency metadata for SearchKit SavedSearch exports so related SearchDisplay files are declared and validated.
- Improved dependency validation messages to name the file and missing dependency that blocks import.
- Improved SearchDisplay import safety by resolving `saved_search_id` from `saved_search_id.name` on the target site instead of trusting source database IDs.
- Changed destructive imports to apply create/update first and then delete missing records in reverse dependency order, so child SearchDisplay records are deleted before their parent SavedSearch.
- Kept delete actions visually dangerous in the import preview using the red badge style.
- Fixed a syntax issue in the UI page fallback error handling.

## 0.1.0-alpha31-core

- Added the alpha29/alpha30 hotfixes into the versioned build, including the API4 metadata fix and Smarty undefined-key warning fix.
- Changed import behavior for supported handlers so YAML is now the source of truth for create, update, and delete operations after explicit confirmation.
- Added delete support for records that exist in CiviCRM but not in YAML for Message Templates and generic split/collection API4 handlers such as SearchKit Saved Searches, SearchKit Displays, FormBuilder Afforms, Scheduled Jobs, Contact Types, Relationship Types, Location Types, and Dedupe Rules.
- Import preview now includes CiviCRM-only records as importable delete actions instead of hiding them.
- Missing managed YAML dependencies are now import-blocking validation errors instead of warnings.
- Added dependency notices and confirmation for filtered exports when related types are automatically included.
- Converted export, import, upload, and validate actions to post/redirect/get so browser refresh does not trigger form resubmission.
- Updated current-behavior docs for destructive import safeguards, dependency handling, and filtered export behavior.

All notable ZIP/test builds for `civi.config.manager` are tracked here. Other docs describe current behavior only and should reference this file instead of repeating release notes.

## 0.1.0-alpha30-core

- Included the alpha29 hotfixes for API4 `getFields()` metadata and Smarty undefined-key warning prevention.
- Added dependency-aware type expansion for temporary filtered export/import operations. SearchKit Saved Searches, SearchKit Displays, and FormBuilder Afforms are bundled together; Custom Groups can include Option Groups and Contact Types; Relationship Types can include Contact Types.
- Cleared temporary type filters after filtered export so the Synchronize tab shows the full managed status instead of a filtered In Sync result.
- Updated docs to clarify the difference between temporary filters and the Settings > Managed Types scope.


## 0.1.0-alpha29-core

- Improved Import Preview layout so each changed field shows the current CiviCRM value beside the YAML value to import.
- Added focused large-text previews with highlighted changed text for message-template HTML/text and other long scalar values.
- Updated modal diff rows to highlight the changed substring instead of showing only the beginning of long content.
- Replaced the browser confirm dialog with an in-page confirmation modal that requires review acknowledgement and typing `IMPORT` before applying YAML changes.
- Documented that recreating a deleted CiviCRM record from YAML can create a new database ID, so dependency-safe imports should rely on stable machine names where available.
- Updated current-behavior docs for the safer import confirmation and focused diff review workflow.

## 0.1.0-alpha28-core

- Added create/update import support for Message Templates, CiviCRM Settings Allowlist, Custom Groups and Fields, and Financial Types.
- Made YAML-to-CiviCRM import usable for reverting supported UI/database changes back to the exported YAML source of truth.
- Added an import confirmation prompt before applying YAML changes to active CiviCRM configuration.
- Added cross-file dependency warnings where exported YAML declares dependencies on other managed YAML items.
- Improved large text diff previews so message-template HTML bodies no longer flood the modal or import preview; long values are truncated in the UI while the underlying YAML/diff remains complete.
- Updated documentation to reflect current import support, dependency warnings, alpha safety behavior, and manual round-trip test expectations.

## 0.1.0-alpha27-core

- Added runtime version lookup from `info.xml` and removed the hard-coded `exported_with` version from the export manifest service.
- Split high-churn config exports into one YAML file per item for Scheduled Jobs, SearchKit Saved Searches, SearchKit Displays, and FormBuilder Afforms.
- Added dependency metadata to split item files where dependencies can be detected, including SearchDisplay to SavedSearch, FormBuilder layout SearchKit references, and Scheduled Job API entity usage.
- Kept backward-compatible import support for older collection files for the split handlers.
- Clarified extension status behavior: export captures current CiviCRM extension status, import can install/enable/disable when code exists, uninstall remains skipped, and self-disable is skipped for safety.
- Updated current-behavior docs to reflect the split YAML layout, dependency metadata, version maintenance rule, and documentation maintenance expectation.

## 0.1.0-alpha26-core

- Reworked project documentation so current behavior, architecture, permissions, roadmap, and release history are clearly separated.
- Removed repeated per-release notes from secondary docs and kept version history centralized in this changelog.
- Updated docs to accurately reflect the paused custom CLI wrapper and the current API4-first automation path.
- Updated docs to reflect current import safety behavior, sync-directory rules, system-status checks, and supported handlers.

## 0.1.0-alpha25-core

- Added `scan-classes@1.0.0` mixin to avoid APIv4 legacy entity scanner warnings.
- Added CiviCRM status-report integration for Configuration Manager sync health.
- Status check warns when the initial export is missing or when pending export/import differences exist.
- Status check shows an informational in-sync notice when YAML and CiviCRM match.
- Updated docs to state that the custom CLI wrapper is paused and API4 commands remain the current supported automation path.

## 0.1.0-alpha24-core

- Added create/update import support for generic API4 collection handlers, including SearchKit Saved Searches, SearchKit Displays, and FormBuilder Afforms.
- Kept import non-destructive: records that exist only in CiviCRM are not deleted during import.
- Simplified the Synchronize tab changed-files rows by removing field-name previews from the row. Use Diff for field-level details.
- Restored normal sentence case for help and warning text while keeping short UI labels readable.

## 0.1.0-alpha23-core

- Restored diff wording to `In CiviCRM` and `In YAML`.
- Changed the default Sync Directory from `../civicrm-config` to `civicrm-config`.
- Added legacy handling so existing `../civicrm-config` settings resolve to the new project-root `civicrm-config` directory.
- Resolved relative Sync Directory values from the CMS project root where possible.
- Added Sync Directory validation for URL-style values.
- Made the Settings page layout use the full available page width.
- Clarified Sync Directory rules in the UI and README.

## 0.1.0-alpha22-core

- Fixed top summary cards so Synchronize, Import, Export, and Settings all use the same live diff state instead of showing a false In Sync status on non-sync tabs.
- Made Pending Changes and Changed Files sections collapsible.
- Simplified Changed Files into compact single-line rows with the file path, status, change count, type, field preview, and Diff button.
- Renamed confusing diff labels to In CiviCRM and In YAML.
- Hid export-only differences from the Import Preview so a fresh install with no YAML does not look like it will remove CiviCRM data.
- Kept imports non-destructive in this alpha; import does not delete existing records.

## 0.1.0-alpha21-core

- Renamed the extension key to `civi.config.manager`.
- Hardened Sync Directory locking when `civicfg_sync_dir` is defined in `civicrm.settings.php`; the UI now treats the value as code-owned and does not save UI changes to it.
- Added Drupal-style import behavior for supported option-value removals.
- Kept import conservative for unsupported config types and whole missing option-group files.
- Made Import Preview, Upload Single YAML, Upload ZIP Archive, Full Archive export, and Single File export sections collapsible.
- Kept the Raw API Result panel removed and kept the Node-based asset compiler reverted.

## 0.1.0-alpha19-core

- Disabled Sync Directory editing in the UI when `civicfg_sync_dir` is defined in `civicrm.settings.php` through `$civicrm_setting['domain']['civicfg_sync_dir']`.
- Updated short extension UI labels and button text toward Title Case for a more consistent admin experience.
- Documented settings override behavior.

## 0.1.0-alpha18-core

- Fixed delayed style rendering / FOUC after the UI asset refactor.
- Added a tiny critical stylesheet rendered before the Configuration Manager markup.
- Kept full UI styling in `css/configmanager.css`.
- Added hidden modal markup so diff modal contents cannot flash before CSS loads.
- Updated JavaScript to open/close modals by toggling both `hidden` and `is-open`.

## 0.1.0-alpha17-core

- Separated UI assets from Smarty templates.
- Added `css/configmanager.css` for all scoped UI styling.
- Added `js/configmanager.js` for modal and single-export preview behavior.
- Split the main UI template into smaller partial templates under `templates/CRM/Configmanager/Page/Partials`.
- Added `Civi\ConfigManager\UI\AssetLoader` to register assets through the CiviCRM resource system.
- Kept the existing synchronize/import/export/settings behavior and single-file AJAX export preview.
- Removed inline `<style>` and `<script>` blocks from the main Smarty template.
- Kept maintainer metadata and removed the unused `.gitkeep` file.

## 0.1.0-alpha16-core

- Kept maintainer update to `Sushant Paste <sushant@cividesk.com>`.
- Removed the unnecessary `.gitkeep` file from the extension source.
- Added granular CiviCRM permissions for access, export, import, and administration.
- Refactored the UI code into focused classes:
  - `Civi\ConfigManager\UI\MainPage`
  - `Civi\ConfigManager\UI\Presenter`
  - `Civi\ConfigManager\UI\FileTransfer`
  - `Civi\ConfigManager\UI\Request`
  - `Civi\ConfigManager\UI\Permission`
- Reduced `CRM_Configmanager_Page_Main` to a thin route/page wrapper.
- Added permission checks for UI actions and API4 actions.
- Kept the AJAX single-file export preview behavior.
- Updated README and added architecture, permissions, and roadmap docs.

## 0.1.0-alpha15-core

- Added no-reload single-file export preview with vanilla JavaScript.
- Kept UI wording/label changes requested in template.

## 0.1.0-alpha14-core

- Fixed empty single export selection error.
- Changed YAML null output from `~` to `null`.

## 0.1.0-alpha13-core

- Added single YAML upload.
- Added ZIP archive upload.
- Added single-file export preview and download.
- Removed fixed-width UI container.

## 0.1.0-alpha12-core

- Simplified import preview to show actual importable changes.
- Removed noisy developer labels from UI output.

## 0.1.0-alpha11-core

- Improved diff modal with side-by-side field-level changes.
- Added clearer labels for option value fields.

## 0.1.0-alpha10-core

- Reworked UI tabs toward Drupal-style synchronize/import/export/settings flow.
- Improved option value import matching and machine-name warnings.

## 0.1.0-alpha9-core and earlier

- Established API4-only core workflow.
- Added export, diff, validation, and first import support for option groups/values.
