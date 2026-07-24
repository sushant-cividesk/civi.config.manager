# Automated QA

Configuration Manager uses two GitHub Actions workflows. The design is intentionally portable so the same scripts can later be called from GitLab CI.

## Fast workflow

`.github/workflows/qa-fast.yml` runs on pushes and pull requests.

Blocking checks:

- Composer metadata validation
- PHP syntax
- developer scenario contract validation
- isolated PHPUnit unit tests on PHP 8.1 and PHP 8.3
- PHPStan for the independently testable service/storage code at the current legacy-safe baseline
- Composer security audit advisory

CiviCRM coding standards and PHP 8.1 compatibility are included as visible advisory checks during the initial legacy-baseline phase. They should become blocking after the existing findings are resolved or baselined.

## Full workflow

`.github/workflows/qa-full.yml` is manually triggered from GitHub Actions against the selected branch or commit. It accepts a CiviCRM Standalone image and an option to include browser tests.

The workflow:

1. Runs the fast checks.
2. Starts a disposable CiviCRM Standalone and MariaDB stack.
3. Installs, disables, and re-enables this extension from a read-only bind mount.
4. Creates all fixture records through API4.
5. Runs service, API4, CLI, YAML, round-trip, dry-run, idempotency, Config Ignore, site-identifier, secret-redaction, and Message Template tests.
6. Optionally runs Playwright UI/UX and accessibility checks.
7. Fails on any PHP mail attempt and verifies that Mailpit received zero messages.
8. Verifies that tracked extension source files did not change.
9. Uploads sanitized evidence and a `READY` marker only when all required checks pass.
10. Deletes the containers, database volume, public/private volumes, fixtures, and temporary configuration.

The default image is `civicrm/civicrm:6.16-php8.3`. A different pinned image can be supplied when testing an older client environment, an upgrade target, or a future CiviCRM release.

## Isolation guarantees

The full workflow is not allowed to use a client DEV, STAGE, or PROD database.

The sync directory root may be a symbolic link to support local dev/stage shared-config testing. The resolved real directory is treated as the safe root; symbolic links inside that root and path traversal attempts are still rejected.

- MariaDB uses a disposable Docker volume.
- The YAML sync directory is generated under `/tmp` with a unique run identifier.
- The extension source is mounted read-only.
- Docker services use an internal-only network.
- Playwright aborts and fails on requests outside the configured local test host.
- PHP `sendmail_path` points to a local blocker that discards message content, records the attempt, and fails the run.
- Mailpit is available only as a local capture service, and the run fails if it contains any message.
- Message Template tests export, diff, and import template records; they do not invoke a mail-send API.
- Generated records use a `qa_` prefix and are deleted after each run.
- Settings changed by a fixture are backed up and restored.
- Browser screenshots, traces, logs, and YAML snapshots are written only to `tests/ci/artifacts`.

## Local commands

Fast checks:

```bash
composer install
composer qa:fast
```

Browser dependencies:

```bash
npm install
npx playwright install chromium
```

Full isolated run without browser tests:

```bash
RUN_UI_TESTS=false tests/ci/run-standalone.sh
```

Full isolated run with browser tests:

```bash
RUN_UI_TESTS=true tests/ci/run-standalone.sh
```

Docker, Docker Compose, PHP, Composer, Node.js, and Chromium are required for the full local run.

## Developer-owned test data

Every feature or bug fix should add or update a file in `tests/scenarios`. `composer test:scenarios` enforces the required sections, unique IDs, supported test levels, non-empty expected/negative cases, and blocked email/network boundaries. The scenario must specify disposable fixtures, expected results, at least one relevant negative case, cleanup, and whether UI coverage is required.

A change is not fully covered when its test depends on existing site data, manual record creation, real email delivery, an external service, or another test running first.

## Current automated coverage

Unit coverage currently includes:

- YAML dump/parse behavior
- filesystem traversal and symlink protection
- atomic YAML writes and cleanup
- upload path, prefix-collision, and symlink protection
- focused field-level diff behavior
- API4 `getFields()` regression protection
- handler registry uniqueness and ordering
- sensitive-setting export/import protection
- version metadata consistency

Standalone integration coverage currently includes:

- isolated sync-directory enforcement
- extension install/disable/re-enable lifecycle
- API4 facade and permission map
- generated site identifier and manifest
- Option Group and Option Value export/diff/dry-run/import/idempotency
- non-reserved Option Value deletion with reserved-record protection
- malformed handler and manifest YAML validation without database changes
- Message Template export/diff/import without sending email
- sensitive setting redaction
- Config Ignore behavior
- API4 and CLI smoke checks

Playwright coverage currently includes:

- Configuration Manager page and primary actions
- isolated changed-file display
- exact `IMPORT` confirmation guard
- browser console and uncaught JavaScript errors
- serious/critical WCAG 2 A/AA findings inside the extension UI

## Next coverage increments

The next additions should be data-driven round-trip tests for every remaining handler, destructive import ordering across dependency chains, full ZIP upload/download integration cases, extension uninstall/reinstall and previous-release upgrade tests, permission-role browser tests, and pinned CiviCRM compatibility anchors.

## Full real-fixture CiviCRM QA

The full workflow now runs two integration suites inside an isolated CiviCRM Docker site:

1. `StandaloneRoundTrip.php` covers the fast disposable round-trip and security checks.
2. `FullRealFixtures.php` covers broader real fixtures:
   - scheduled jobs export/import/revert/stale YAML cleanup
   - dedupe rule groups export/import
   - contact types export/import
   - settings field-level ignore and revert
   - real extension discovery/idempotency for Mosaico, SQLTasks, Contact Layout, and CiviRules when installed
   - full export/import idempotency after all fixture changes

The runner fetches fixture extensions on the host first via `tests/ci/fetch-fixture-extensions.sh`, then mounts them read-only into the CiviCRM container. The CiviCRM container remains on an internal Docker network during the application test, so extension code cannot use the public internet during QA.

Useful environment switches:

```bash
RUN_REAL_EXTENSION_FIXTURES=true
RUN_FULL_REAL_FIXTURE_SUITE=true
CIVICFG_QA_FIXTURE_EXTENSION_KEYS="uk.co.vedaconsulting.mosaico de.systopia.sqltasks org.civicrm.contactlayout org.civicoop.civirules"
CIVICFG_ALLOW_MISSING_FIXTURE_EXTENSIONS=false
```

Keep `CIVICFG_ALLOW_MISSING_FIXTURE_EXTENSIONS=false` for release QA. Set it to `true` only while debugging network/download problems in a fork.
