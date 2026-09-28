# Configuration Manager 0.1.0-alpha68.6.1-core

Alpha68.6.1 is a QA harness hotfix for the first real Alpha68.6 DDEV browser execution. It does not change Configuration Manager Import/provider behavior.

## What changed

- Added `tests/ci/ensure-ddev-playwright.sh` to install Playwright Linux dependencies into the current DDEV web container and ensure the repository-pinned Chromium build exists for the normal DDEV web user.
- Added `tests/ci/run-ddev-targeted-ui.sh` as the host-side read-only targeted DDEV smoke entry point.
- Updated `tests/ci/run-ddev-stateful-ui.sh` to run the same Playwright prerequisite check before fixture setup.
- The stateful runner now resolves the active `civicrm.settings.php` from common Drupal/WordPress DDEV layouts, falls back to a constrained search under `/var/www/html`, and passes `CIVICRM_SETTINGS` explicitly to fixture seed and cleanup.

## Root causes addressed

1. Playwright Chromium was present but the current DDEV web container did not have the Linux shared-library dependencies required to launch it. These packages are container state and may disappear after the container is rebuilt/recreated.
2. The stateful fixture invoked `cv scr` from the extension directory, so `cv` could not discover `civicrm.settings.php` by walking parent directories. Explicitly passing the discovered settings file removes that working-directory assumption.

## Safety boundary

- No production service, provider capability, Import rule, delete behavior, or reviewed-plan logic changes.
- Browser mutation remains restricted to explicit `*.ddev.site` targets.
- Fixture cleanup uses the same explicit CiviCRM bootstrap path as fixture seed.

## Verification boundary

Alpha68.6 DDEV `composer qa:fast` passed with 319 PHPUnit tests / 2,304 assertions and all configured mutation/static gates. This hotfix is source/contract checked in the authoring environment; the decisive next evidence is the real targeted and stateful DDEV browser rerun.
