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
- Drupal-targeted Playwright authentication now prefers an explicitly supplied password and otherwise generates a fresh single-use DDEV ULI per browser context; diagnostics no longer mask the original failure when a timed-out page has already closed.
- The disposable browser fixture now seeds one deliberate Custom Data dependency blocker plus one independent Relationship Type drift. Stateful Playwright scenarios prove unsafe exclusion is unavailable, safe exclusion builds a fresh reduced plan, refresh reconnects to the same plan, an unrelated Saved Config export invalidates the stale plan, and reduced Import results expose the canonical outcome labels.
- `tests/ci/run-ddev-stateful-ui.sh` provides a DDEV-only seed/run/cleanup path and refuses non-`.ddev.site` targets or an already-active fixture state.

## Verification boundary

Maintainer DDEV `composer qa:fast` now passes Alpha68.5 with 319 PHPUnit tests / 2,304 assertions, all configured import/provider mutation proofs, PHPStan/compatibility/contracts, and source hygiene. Playwright Chromium plus its Linux dependencies install successfully in the DDEV image. The first real targeted run exposed a shared-authentication harness defect before either product test executed; the harness source is now corrected. Browser workflow contract (39 checks), Alpha68 UI contract (64 checks), provider browser checks, source hygiene, JS syntax, PHP fixture syntax, and shell syntax pass in the authoring environment. The corrected targeted browser run and the new disposable stateful A68-09 suite still require maintainer DDEV execution.

## Still pending before Beta2

- Execute the new A68-09 stateful browser suite on DDEV and add one deterministic browser-level partial-write failure fixture if feasible without adding production-only test hooks.
- ML-001 language-independent canonicalization for any configured language/locale; EN/FR is only the original reproducer.
- Provider CRUD/delete runtime certification, CLI/operational polish, supported-runtime matrix, upgrade/package validation, and final Beta2 hardening.
