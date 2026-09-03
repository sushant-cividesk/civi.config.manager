# Configuration Manager 0.1.0-alpha67.1-core

Development/QA maintenance checkpoint. This does not represent completion of the roadmap's Alpha68 import-plan work.

## What changed

- Kept exactly one normal project `vendor/` directory in the extension tree.
- Playwright-PHP remains PHP 8.2+ QA-only tooling and is provisioned into an external workspace from the pinned `tests/browser-php/composer.json` + `composer.lock`.
- Added `tests/ci/run-browser-php.sh` as the single local/CI/disposable-runtime browser-PHP entry point.
- Browser QA now fails if `CIVICFG_BASE_URL` is missing. A skipped browser test can no longer be mistaken for real browser evidence.
- Removed CI/release workflow steps that preinstalled `tests/browser-php/vendor`.

## Evidence carried forward

The 2026-09-03 DEV/buildkit run supplied by the maintainer observed `composer qa:fast` green with 250 tests / 1,976 assertions, the provider-inventory mutation proof green after deliberate red, and the provider-admission mutation proof green after deliberate red. Playwright-PHP 1.4.0 and PHPUnit 11.5.56 installed successfully on PHP 8.3.31 and Playwright browsers installed, but the test was skipped because `CIVICFG_BASE_URL` was not set. That skipped run is explicitly not counted as browser validation.
