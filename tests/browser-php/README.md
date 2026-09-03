# PHP black-box browser QA

This harness is intentionally isolated from the extension's production Composer graph.
`playwright-php/playwright` requires PHP 8.2+, while Configuration Manager continues to
support PHP 7.4. The harness therefore runs only as a QA client against a real disposable
CiviCRM HTTP site; it is never loaded by the extension runtime.

The JavaScript Playwright suite remains authoritative for broad browser/accessibility
coverage. This PHP suite adds an independently maintained black-box path so UI success is
not inferred from one test stack alone.

Run after the disposable CiviCRM site is available:

```bash
cd tests/browser-php
composer install
vendor/bin/playwright-install --browsers
CIVICFG_BASE_URL=http://127.0.0.1:8760 \
CIVICRM_ADMIN_USER=admin \
CIVICRM_ADMIN_PASS=qa-admin-password \
vendor/bin/phpunit -c phpunit.xml.dist
```
