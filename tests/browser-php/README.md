# PHP black-box browser QA

This harness is intentionally isolated from the extension's production Composer graph. `playwright-php/playwright` requires PHP 8.2+, while Configuration Manager continues to support PHP 7.4.

The repository keeps one project-level `vendor/` directory only. `composer qa:browser-php` copies this harness's pinned Composer manifest/lock into an external QA workspace (by default under `/tmp`) and installs the PHP 8.2+ browser toolchain there. It must not create or use `tests/browser-php/vendor/`.

The browser test is a real HTTP-boundary test. If `CIVICFG_BASE_URL` is missing, the command fails; it does not skip and report a misleading green result.

Run against an authorized DEV/disposable CiviCRM site:

```bash
CIVICFG_BASE_URL=http://127.0.0.1:8760 \
CIVICRM_ADMIN_USER=admin \
CIVICRM_ADMIN_PASS=qa-admin-password \
composer qa:browser-php
```

For the normal disposable runtime, `RUN_PHP_UI_TESTS=true tests/ci/run-standalone.sh` supplies the site URL and credentials automatically.
