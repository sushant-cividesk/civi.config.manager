# Changelog

## 0.1.0-alpha68.4-core

- Grouped missing managed Import dependencies into order-independent deterministic review components with affected types, Saved Config files, blocker reasons, and planned actions.
- Added explicit **Fix and preview again** and proven-safe **Exclude component and build new preview** paths, showing the complete conservative exclusion scope and remaining Import scope before confirmation; unsafe or whole-import exclusion remains blocked.
- Recomputed every exclusion server-side, discarded prior UI review state, reran complete validation/preflight, and issued a new immutable plan ID instead of mutating an approved plan.
- Added the same reduced-preview boundary to API4/CLI, lifecycle cleanup for reduced-plan session state, focused unit/mutation coverage, and UI contracts.
- Extracted Import action orchestration from `MainPage.php` and kept the page below 1,000 lines while the dependency/exclusion policy lives in a focused service.

> **Evidence boundary:** dependency-free syntax/contract checks pass in the authoring environment. Full DDEV `composer qa:fast` plus real browser/runtime validation must be rerun for this new alpha before it is treated as runtime-validated.

## 0.1.0-alpha68.3.2-core

- Removed unused delete-phase state from the immutable Import-plan PHPUnit fixture after PHPStan correctly reported it as write-only dead code.
- Kept the fixture behavior focused on the create/update write boundary it actually asserts; no production Import, delete-missing, queue, provider, or Saved Config behavior changed.

## 0.1.0-alpha68.3.1-core

- Fixed reviewed-plan tamper validation so an extra or unknown submitted type cannot be normalized away and silently treated as the original reviewed type selection.
- Corrected the queue regression expectation to use the actual `import_preflight` action while separately asserting the user-facing `preflight` phase.
- Strengthened the regression test to prove the exact reviewed type selection still validates before the tampered selection is rejected.

## 0.1.0-alpha68.3-core

- Bound Import apply to an immutable server-side reviewed plan instead of trusting mutable browser/API/CLI type values.
- Persisted private short-lived plan IDs with integrity checks and fingerprints for site/sync-root identity, Configuration Scope + Config Ignore, manifest, provider/runtime capability, implementation version, managed Saved Config content, and managed Current CiviCRM state.
- Reused an unchanged reviewed plan across Import page refresh/reconnect; stale, expired, tampered, wrong-site, wrong-scope, changed-provider, changed-Saved-Config, or changed-Current-CiviCRM plans fail closed before new writes.
- Required the same reviewed plan boundary for synchronous UI, persistent queue, API4, and `civicfg import --yes --plan <plan_id>`. Direct write-mode service calls without a reviewed plan are rejected.
- Kept existing per-handler before-write and before-delete conflict checks as a second safety barrier, and made reviewed plans single-use after apply starts.
- Added plan-store, stale-state, queue-boundary, CLI/API, lifecycle, refresh/reconnect, and mutation regression coverage.
- Corrected extension-provider request filtering so colon-delimited semantic provider type keys are preserved.
- Kept Export behavior isolated from Import review-plan state; no provider received broader CRUD/delete authority.

## 0.1.0-alpha68.2.1-core

- Fixed the stale missing-dependency PHPUnit expectation to match the already-adopted **Saved Config** / **Current CiviCRM** terminology.
- Renamed the focused regression method for the same terminology; production validation/import behavior is unchanged.
- This is a test-only hotfix for the Alpha68.2 QA failure reported on DDEV.

## 0.1.0-alpha68.2-core

- Kept normal Settings management cards unchanged while adding a collapsed **Other detected providers** section for provider inventory entries that are discovered but are not represented by a configurable Settings card.
- Clarified the inventory count: provider entries discovered from installed extensions are not the same thing as configuration types that Configuration Manager is prepared to manage.
- Grouped additional providers into client-facing safety states: **Managed through Extensions**, **Create + update through Extensions**, **Export + compare**, **Review only**, **Not offered separately**, **Cannot be managed automatically**, and **Unavailable**.
- Kept extension keys, API/entity identifiers, action metadata, reason codes, and raw provider diagnostics behind **Technical details** instead of exposing them in the normal explanation.
- Preserved fail-closed admission and scope behavior: provider discovery remains metadata-only and this checkpoint grants no new create, update, delete, or import authority.
- Added independent provider-classification/selection tests, a deliberate hidden-provider mutation proof, and a Playwright user-flow regression for the progressive-disclosure boundary.

> **Evidence boundary:** dependency-free source/behavior checks are recorded in `docs/RELEASE_NOTES_0.1.0-alpha68.2-core.md`. A real CiviCRM browser/runtime pass is still required to validate the actual provider mix and layout on DEV. ML-001 multilingual canonicalization remains a separate later-Alpha blocker before Beta2.

## 0.1.0-alpha68.1-core

- Corrected Settings capability presentation so handlers that intentionally disable delete-missing show **Create + update** instead of **Full management**. Fully managed handlers now use the shorter **Managed** label. No provider received broader create, update, or delete authority.
- Kept runtime safety downgrades canonical in provider inventory: a provider declared as fully managed but found export-only at runtime remains **Export + compare** instead of being re-promoted by static metadata.
- Replaced redundant **Not Yet Saved** prose with an explicit Export next action, while keeping destructive Import wording conservative for types whose automatic removal is not proven safe.
- Hid collision-resistant Profile Field filename/hash suffixes from normal Synchronize, Import, and Export cards. User-facing titles now come from semantic Profile Field identity where available, while the real Saved Config path remains unchanged and is still available in Synchronize details.
- Finished a bounded client-facing terminology cleanup around **Saved Config** and **Current CiviCRM** while retaining **YAML** when the literal `.yml`/`.yaml` file format is the subject.
- Recorded multilingual false-drift issue **ML-001** as a later-Alpha/Beta2 blocker. Language canonicalization is deliberately not changed in this maintenance build.

> **Evidence boundary:** local source/unit/static QA is recorded in `docs/RELEASE_NOTES_0.1.0-alpha68.1-core.md`. Multilingual normalization, real CiviCRM runtime validation, and browser validation remain separate follow-up work.

## 0.1.0-alpha68-core
- Separated automatically protected ambiguous export objects from user-selected Monitor only scope in the UI: Last Export now calls these Review only, exposes the affected Saved Config paths, and explains why automatic create/update/remove is disabled.

- Reset stale Configuration Manager scope/dependency, watch/health, and browser operation-result state on uninstall/fresh install; missing baselines now suppress stale Last Export/Last Import panels even when a browser session outlives a CLI reinstall.
- Preserved the existing Configuration Manager page structure while simplifying client-facing terminology to Saved Config, Current CiviCRM, Not Yet Saved, and Not in Current CiviCRM.
- Added total and per-type Saved Config counts to the existing UI plus persistent Last Export and Last Import result panels with compact created/updated/unchanged/removed/warning/error summaries.
- Simplified long-running progress wording to parts and items checked, kept unknown totals explicit, and deliberately omitted unproven ETA calculations.
- Added queued-export created-versus-updated accounting before publish so the persistent result can distinguish new Saved Configs from changed ones without weakening atomic publication.
- Unified export result accounting so synchronous/API exports and queued exports classify creates versus updates at the staged-workspace preview boundary, and completion notices now render from the same normalized summary as Last Export.
- Hardened targeted Playwright QA for Drupal Buildkit/DDEV: prove a Drupal session before CiviCRM access checks, distinguish bad login from missing Configuration Manager permission, fail early when Chromium is missing, and prefer a local Drush one-time login for `.ddev.site` targets so DEV/STAGE smoke tests do not depend on hard-coded site passwords. Added `npm run test:ui:dev` and `npm run test:ui:stage` quick commands.
- Clarified extension-owned provider visibility: only proven-safe managed providers appear in the filter, with detected monitor-only/unsupported providers reviewed in Settings. Added compact next-action guidance for failed operation summaries while broader structured error/CRUD work remains on the Alpha68 roadmap.

> **Evidence boundary:** source-level and local QA must pass before this checkpoint is treated as DEV-validated. Real browser/runtime validation remains required.

## 1.0.0-beta1

- First clean beta release of the alpha64 real-world hardening line. The runtime behavior is intentionally unchanged from the alpha64 code that passed the supported-PHP QA matrix, stress gates, hook/CLI coverage, dependency audit, and production-package checks.
- Promotes the release metadata to `1.0.0-beta1` / `beta` and keeps the tag-to-`info.xml` identity gate so `v1.0.0-beta1` can only publish this exact version.
- Official installation remains the attached runtime-complete `civi.config.manager-1.0.0-beta1.zip`, which contains extension runtime code/assets plus locked production `vendor/` dependencies only. Repository tests, docs, scripts, Composer metadata, logs, and CI files are not included in that installable artifact.
- The Git repository/tag remains the auditable source tree used to run release QA. Downstream deployment/package manifests that require a production-clean payload should consume the GitHub release ZIP rather than GitHub's automatic source archive.

> **Beta1 validation boundary:** this is the first clean beta intended for controlled real-project deployment. Continue validating WordPress and Drupal Export -> immediate Synchronize -> repeat Export, the BMT CiviRules ambiguity case, and DEV-only Import before broader production promotion.

## Older development history

Alpha67 and earlier development entries are preserved in [`docs/history/CHANGELOG_PRE_ALPHA68.md`](docs/history/CHANGELOG_PRE_ALPHA68.md). The protected `1.0.0-beta1` entry remains above because it is still the release baseline.
