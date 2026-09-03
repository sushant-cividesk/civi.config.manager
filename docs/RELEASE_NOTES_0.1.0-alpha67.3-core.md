# Configuration Manager 0.1.0-alpha67.3-core

Browser/CLI reliability hotfix on the Alpha67 development line. This does not consume the roadmap's Alpha68 import-plan work.

## What changed

- `composer qa:browser-php` now creates a disposable CiviCRM stack itself and runs Playwright-PHP end-to-end.
- `composer qa:browser` runs the same real-runtime suite plus both JavaScript Playwright/axe and Playwright-PHP; GitHub Actions uses this same entry point.
- Targeted existing-site QA remains explicit via `./bin/civicfg qa-browser --base-url URL` and requires `CIVICRM_ADMIN_PASS`.
- PHP browser tests fail on skips, risky/zero-assertion tests, warnings, unreachable/login-broken UI, or assertion failures.
- CLI installation prefers a writable PATH directory that already contains `cv`; `./bin/civicfg cli-install` and `./bin/civicfg cli-doctor` provide repair/status when the bare `civicfg` launcher is unavailable.
- Legacy nested browser vendor cleanup remains preview-first and explicit.
- Source ZIPs exclude `.git`, `__MACOSX`, dependency trees, caches, and generated QA artifacts.

## Evidence carried forward

The maintainer's Alpha67.1/67.2 buildkit run observed the core fast QA green at 250 tests / 1,976 assertions with both provider mutation proofs and static analysis passing. The previous browser attempt did not count as browser validation because it first skipped without a URL, then was blocked by the legacy nested vendor. Alpha67.3 changes the orchestration so the normal browser command no longer depends on either condition.
