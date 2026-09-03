# Configuration Manager 0.1.0-alpha67.4-core

This checkpoint deliberately reduces QA complexity and completes the generic contributed/custom provider registration safety slice. It remains on the Alpha67 line; Alpha68 is still reserved for reduced import-plan safety work.

## What changed

- One browser stack: JavaScript Playwright + axe against the disposable real-CiviCRM runtime.
- Removed Playwright-PHP, its separate Composer graph, external tool workspace, and browser-specific `civicfg` commands.
- Stable QA entry points are now `composer qa:fast`, `composer qa:real-runtime`, and `composer qa:browser`.
- Generic hook providers cannot accidentally shadow an existing configuration type. The earlier registration wins; the collision is rejected and exposed as unavailable provider inventory.
- Invalid values injected through the advanced handler hook are rejected without breaking unrelated providers.

## Evidence boundary

The maintainer's Alpha67.3 run observed the fast gate green at 255 PHPUnit tests / 1,993 assertions, both provider mutation proofs passing, architecture/scenario contracts green, source hygiene green, and PHPStan with no errors. The Playwright-PHP experiment failed at browser launch because the DDEV container lacked native browser libraries; this release removes that duplicate browser stack rather than adding more host setup. Alpha67.4's new provider-registration tests still require the normal PHPUnit gate in a Composer-enabled checkout before release promotion.
