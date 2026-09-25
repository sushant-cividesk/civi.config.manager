# Configuration Manager 0.1.0-alpha68.4-core

Alpha68.4 completes the dependency-safe reduced Import-plan architecture (A68-02 through A68-05).

## What changed

- Missing managed dependencies are grouped into order-independent deterministic connected components with affected configuration types, Saved Config files, blocker reasons, and concrete dry-run actions.
- Every blocker keeps **Fix and preview again**. **Exclude component and build new preview** appears only when removing the complete component leaves a non-empty dependency-closed Import scope.
- Exclusion is explicit and confirmed. The preview shows the complete conservative exclusion scope and the remaining Import scope before confirmation. The browser submits only the component ID; the server recomputes the current blocker graph and fails closed if that component changed, disappeared, or is no longer safe to exclude.
- Reduced plans never edit an approved plan. The prior UI plan is discarded, full validation/preflight runs again from current Saved Config and Current CiviCRM, and only the new preview may issue a new immutable `plan_id`.
- API4 and `civicfg import --dry-run --exclude-component <component_id>` use the same server-side reduced-preview boundary. `--exclude-component` cannot be combined with write mode.
- Excluded components remain visible in the Import UI and are explicitly described as remaining Synchronize differences, not applied configuration.
- Import action handling moved out of `MainPage.php`; the page remains below 1,000 lines. The dependency graph and exclusion policy live in a focused service instead of adding another large branch to `ConfigManager.php`.

## Safety evidence added

- Unit coverage for blocker grouping, deterministic component IDs, dependency-closure exclusion, whole-import fail-closed behavior, action summaries, fresh reduced-plan creation, and stale component rejection.
- A mutation gate deliberately forces unsafe whole-import exclusion and requires the regression test to turn red.
- UI/source contracts cover blocker explanation, the safe fix path, explicit whole-component exclusion, and lifecycle cleanup of reduced-plan session state.

## Verification boundary

The source package is syntax/contract checked in the authoring environment. Full PHPUnit/PHPStan/PHPCompatibility and real CiviCRM/browser execution must be rerun in the maintainer DDEV/CI environment before this alpha is treated as runtime-validated. The prior Alpha68.3.2 baseline was reported green on DDEV (305 PHPUnit tests / 2,227 assertions); that evidence does not substitute for rerunning the new Alpha68.4 tests.

## Still pending before Beta2

- A68-07 normalized Applied / Blocked / Excluded / Remaining Difference accounting across UI/API4/CLI/queue.
- A68-08 remaining action/removal wording audit.
- A68-09 real browser coverage for safe/unsafe exclusion, stale rejection, and partial-result wording.
- Locale-independent multilingual canonicalization for any configured language/locale (ML-001).
- Provider CRUD/delete runtime evidence, compatibility matrix, real-runtime QA, and final Beta2 hardening.
