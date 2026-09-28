# Configuration Manager 0.1.0-alpha68.6-core

Alpha68.6 turns the remaining deterministic A68-09 Import safety cases into real stateful DDEV browser flows without adding QA-only behavior to production services.

## What changed

- `tests/integration/UiFixture.php` now seeds disposable real CiviCRM state for one normal Option Group difference, one independent Relationship Type difference, and one blocked Custom Data document with a deliberately missing Option Group dependency.
- `tests/playwright/config-manager.spec.js` proves unsafe whole-import exclusion is unavailable, safe component exclusion creates a fresh immutable reduced plan, refresh reconnects to that exact plan, and a later Saved Config export invalidates the stale reviewed plan.
- The same stateful browser suite applies a safe reduced Import and checks the persistent Last Import outcome categories: Applied, Blocked, Excluded, and Remaining Difference.
- `tests/ci/run-ddev-stateful-ui.sh` seeds the disposable fixture, runs Playwright only against `*.ddev.site`, and always attempts fixture cleanup.
- Targeted browser authentication now uses repeatable password login when a password is supplied; otherwise DDEV generates a fresh single-use Drush login URL per browser test.
- Browser diagnostics tolerate an already-closed page so setup failures are not hidden by a secondary `page.title()` error.

## Safety boundary

- No production Configuration Manager service receives a test-only bypass, forced failure switch, or broader write/delete authority.
- Stateful browser mutation is restricted to disposable DDEV targets and fixture cleanup restores Configuration Manager settings and removes created CiviCRM records.
- The existing fail-closed reviewed-plan, dependency exclusion, and delete-missing rules are unchanged.

## Verification boundary

The preceding Alpha68.5 DDEV gate passed with 319 PHPUnit tests / 2,304 assertions plus all configured mutation/static gates. Alpha68.6 source-level browser contracts, PHP/JS syntax, and source hygiene must pass before packaging. The new stateful DDEV browser suite still needs real execution in the maintainer environment.

## Still pending for A68-09

A deliberately induced partial-write browser failure is not yet represented as a stateful fixture. The queue/service accounting path already has focused PHPUnit coverage; the browser fixture must be added without introducing a production test hook or weakening fail-closed behavior.
