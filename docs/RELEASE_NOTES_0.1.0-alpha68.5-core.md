# Configuration Manager 0.1.0-alpha68.5-core

Alpha68.5 closes the Import result-accounting and unsupported-removal wording work while preserving the Alpha68.4 dependency-safe immutable-plan model.

## What changed

- Added `ImportResultAccounting` as the single canonical interpretation of handler results. UI, API4, CLI and queue results now expose the same `result_accounting` payload: **Applied**, **Blocked**, **Excluded**, **Remaining Difference**, plus created/updated/removed/unchanged/warning/error detail.
- Successful synchronous and queued applies run a fresh post-import Synchronize diff. `remaining_difference_known=true` is set only when that verification succeeds; a reduced import therefore cannot be presented as fully in sync while explicitly excluded differences remain.
- Reduced-plan exclusion metadata is frozen into the new immutable plan before its plan ID is issued. It is therefore available later to API4/CLI/queue apply results instead of existing only in browser session state.
- Queue create/update or delete-missing failure results include the accumulated work-unit state and canonical accounting, so a partial failure does not hide earlier applied work.
- Unsupported removal no longer renders as **Remove from Current CiviCRM** or a generic **Not Ready** card. It renders **Export to Saved Config** and explains that Current CiviCRM remains unchanged.
- The Last Import panel now leads with Applied / Blocked / Excluded / Remaining Difference and keeps create/update/remove/unchanged details behind progressive disclosure.

## Test additions

- Unit tests cover canonical accounting, verified remaining-difference counts, blocked dry-run accounting, exclusion de-duplication, persisted reduced-plan exclusion accounting, and unsupported-removal wording.
- A mutation gate erases exclusion accounting and requires the focused regression test to turn red before restoring the source.
- The targeted Playwright smoke now opens Import and verifies that no `Continue anyway` bypass exists; when blocker/reduced-plan UI is present it verifies the safe repair/exclusion language.

## Verification boundary

Alpha68.4 is the last maintainer-runtime-validated baseline: DDEV `composer qa:fast` passed with 315 tests / 2,278 assertions. Alpha68.5 passes the dependency-free PHP syntax, Alpha62/63/64 contracts, Alpha68 UI/lifecycle contracts, browser source contract, provider browser checks, Drupal login resolver, source hygiene, JS syntax, and shell syntax in the authoring environment. Full PHPUnit/PHPStan/PHPCompatibility and the new mutation proof require the maintainer DDEV/vendor environment. Real Playwright execution also still requires the missing Linux browser libraries in that DDEV image.

## Still pending before Beta2

- A68-09 stateful browser scenarios for safe/unsafe exclusion, stale-plan rejection, and partial-result wording.
- ML-001 language-independent canonicalization for any configured language/locale; EN/FR is only the original reproducer.
- Provider CRUD/delete runtime certification, CLI/operational polish, supported-runtime matrix, upgrade/package validation, and final Beta2 hardening.
