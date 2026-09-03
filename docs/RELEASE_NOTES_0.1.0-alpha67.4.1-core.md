# Configuration Manager 0.1.0-alpha67.4.1-core

QA-only hotfix for Alpha67.4. Product/provider behavior is unchanged.

## What changed

- Corrected the new handler-registration tests to respect the public `getRegistrationDiagnostics()` list shape.
- Added a regression contract preventing removed browser-QA CLI commands/tests from returning in future merges.
- Documented the targeted DEV Playwright prerequisite: install root npm dependencies before running `npm run test:ui`.

## Evidence carried forward

The maintainer's GitHub run reached the full 255-test PHPUnit suite and failed only in the stale browser-clean test. A subsequent DDEV run after `composer install` reached the same suite and failed only in the two new registry diagnostics assertions. Both failures are test expectations/workflow residue; neither demonstrates a provider-runtime failure.
