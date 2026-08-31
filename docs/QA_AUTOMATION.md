# Automated QA

Configuration Manager uses a fast GitHub Actions workflow plus a manually triggered full isolated CiviCRM workflow. The scripts are kept portable so the same checks can be called from another CI runner.

## Fast workflow

`.github/workflows/qa-fast.yml` runs on pushes and pull requests.

Blocking checks include:

- Composer metadata validation
- PHP syntax
- developer scenario contract validation
- isolated PHPUnit unit tests on PHP 7.4, 8.1, and 8.3
- PHPStan at the current legacy-safe baseline
- PHPCompatibility enforcement against PHP 7.4
- dedicated public metadata-hook coverage
- Composer security audit

CiviCRM coding standards remain available as a manual/advisory check while the existing historical style baseline is being cleaned up. PHP 7.4 compatibility is blocking in `qa:fast`; functional work should also follow the surrounding CiviCRM/PHP conventions and pass `git diff --check`.

## Full workflow

`.github/workflows/qa-full.yml` is manually triggered against a selected branch or commit and a pinned CiviCRM Standalone image.

The workflow:

1. Runs the fast checks.
2. Starts disposable CiviCRM/MariaDB services.
3. Installs, disables, and re-enables Configuration Manager.
4. Creates fixture data through supported APIs.
5. Runs API4, CLI, YAML, round-trip, dry-run, idempotency, Config Ignore, site-identifier, secret-redaction, and Message Template checks.
6. Runs the real-extension fixture suite when enabled.
7. Optionally runs Playwright UI/UX/accessibility checks.
8. Blocks outbound application networking and real email delivery during application tests.
9. Verifies tracked extension source files did not change.
10. Uploads sanitized evidence and removes containers/volumes/fixtures after the run.

## Isolation guarantees

Full QA must never use an existing client DEV, STAGE, or PROD database.

- MariaDB uses a disposable Docker volume.
- YAML uses a run-specific temporary sync directory.
- Extension source is mounted read-only where the runner supports it.
- Application services are isolated from the public network during the test phase.
- PHP mail is blocked and the local sendmail blocker must remain empty unless a test explicitly changes that contract.
- Fixture records use generated QA prefixes and are removed after the run.
- Changed settings are backed up and restored.
- Browser screenshots/traces/logs/YAML snapshots stay under `tests/ci/artifacts`.

## Local commands

Fast checks:

```bash
composer install
composer qa:fast
```

Full isolated run without browser tests:

```bash
RUN_UI_TESTS=false tests/ci/run-standalone.sh
```

Full isolated run with browser tests:

```bash
RUN_UI_TESTS=true tests/ci/run-standalone.sh
```

## Developer-owned scenarios

Every functional change should add or update a file in `tests/scenarios`. `composer test:scenarios` requires disposable fixtures, concrete expected results, negative cases, cleanup, and explicit blocked outbound network/email boundaries.

Development scenarios must not depend on existing client records, production identifiers, real outbound services, or another test running first.


## Alpha62 large-site architecture coverage

Alpha63 keeps the alpha62 dependency-free streaming gate and adds release-specific ambiguity/queue/recovery contracts:

```bash
composer test:alpha62-contract
composer test:alpha63-contract
composer qa:stress
```

`qa:stress` runs both low-memory programs at `memory_limit=256M`: alpha62 traverses 5,000 API4 rows + 5,000 real YAML files and proves duplicate-path/exception rollback safety; alpha63 adds 10,000 disk-spooled provider rows, a persistent 5,000-document staging index, and durable recovery of a simulated hard-interrupted publication. The alpha63 scenario additionally covers deterministic monitor-only duplicate snapshots, target-ambiguity preflight blocking, per-identity delete safety, multi-unit Queue reconnect/retry rules, WordPress progress responsiveness, and repeated Export/Synchronize zero-diff behavior.

These source/stress gates complement rather than replace PHPUnit, PHPStan, PHPCompatibility, Drupal 7, WordPress, Standalone, and real contributed-extension lifecycle QA.

## Alpha59 scope UI coverage

Alpha59 adds regression coverage for lazy per-handler scope discovery, semantic picker selectors, full relative `path:` selectors, missing selected identities, mode-dependent browser controls, generated `civicrm.settings.php` examples, cheap handler capability labels, and contributed-provider compatibility notes that do not inflate YAML validation warnings. Settings page load must continue to avoid handler export/discovery work until the administrator explicitly opens a picker.

The alpha59 hotfix additionally covers picker filtering/clear behavior, the test-runtime `ts()` fallback, scope and cross-site API4/CLI commands, same-site/foreign-site manifest policy, zero/one/duplicate target identity semantics for contributed provider writes, nested provider-value preservation, backup-only import UX, and SQLTasks provider discovery/create capability in the full real fixture.

The alpha60 hotfix adds regression coverage for API3 create-spec writable-field filtering and virtual contributed-provider import isolation, including SQLTasks runtime-field cleanup and preservation of the selected subtype through UI preview/apply and service validation/apply behavior.

The alpha61 QA coverage follows the actual pinned SQLTasks 3.0.0-alpha3 runtime: native API4 `SqlTask` is preferred and verified by full real-fixture QA. The reviewed API3 `Sqltask` BAO generator/export adapter remains covered as a compatibility fallback because its `Sqltask.get` action is single-record only.

## Alpha58 scope and performance coverage

Alpha58 adds focused coverage for universal scope policy, portable selected-item mappings, explicit watch-only state, Message Template identities, operational identity metadata, API4 watch permissions, and the requirement that cached system health must not discover configuration handlers. The full isolated workflow should also exercise first-export UX, selected-scope safety, cross-environment IDs, watch scans, and repeat export/import idempotency.

## Alpha56 unit coverage

The semantic configuration engine has focused unit coverage for:

- stable/composite semantic identities and ambiguous identity safety
- deterministic type-preserving SHA-256 fingerprints
- associative-key normalization and line-ending normalization
- ordered versus explicitly unordered lists
- exact/path-aware runtime/sensitive field exclusion
- operational metadata exclusion from content hashes
- semantic filename-renames versus machine-identity changes
- conservative possible-rename detection
- baseline-aware drift states and three-way conflict analysis
- confirmed-identity baseline continuity
- metadata-provider create/update/delete capability gating
- semantic API4 reference export/import and dependency metadata
- generic contributed-extension strong versus weak identity handling
- one-extension/one-vendor/one-global CLI lifecycle
- Composer and non-Composer CLI layouts
- global launcher ownership, multi-project registry behavior, safe uninstall, and legacy managed-alias cleanup

## Real contributed-extension compatibility matrix

The regular Buildkit DEV/STAGE fixture set is deliberately much broader than the small default Standalone CI fixture subset.

For each installed extension/provider, the compatibility report uses one of these outcomes:

Each discovered provider also reports `identity_safety` as `SAFE`, `UNSAFE`, `UNVERIFIED`, or `ERROR`. Empty providers stay `UNVERIFIED` until a representative record proves identity uniqueness; provider read/hydration failures are `ERROR` and fail release-quality QA rather than being treated as an empty provider.

- `FULL` - portable configuration is discovered with safe import capability.
- `PARTIAL` - some portable configuration is supported while some provider surfaces are not.
- `NO_PORTABLE_CONFIG` - the extension is installed but exposes no meaningful portable configuration; this is valid and is not a failure.
- `UNSUPPORTED` - portable configuration appears to exist but Configuration Manager cannot manage it safely yet.
- `ERROR` - discovery/export/import/test execution itself failed.

The full Buildkit matrix currently exercises the regular test environment containing the requested extension set plus required fixture dependencies. Drupal-incompatible fixtures such as Standalone-only `switchuser` are reported as N/A by the environment installer rather than forced into an invalid CMS.

The compatibility test flow is:

```text
installed/enabled
  -> discover settings/API4/API3/hooks
  -> classify provider coverage
  -> create safe representative fixture where possible
  -> export
  -> validate
  -> assert no local IDs/runtime/secrets leak
  -> change active configuration
  -> verify structured diff
  -> import preview
  -> import apply
  -> re-export
  -> canonical/idempotency comparison
```

The production engine must remain generic. A fixture such as SQL Tasks may expose a missing generic behavior, but production code should not gain an extension-name branch merely to satisfy the fixture. Provider-specific metadata/hooks are appropriate only when the provider's semantics genuinely cannot be inferred safely.

## Standalone CI fixture subset versus Buildkit full matrix

`tests/ci/run-standalone.sh` keeps a small default real-extension subset so the isolated GitHub workflow remains practical. Its default `CIVICFG_QA_FIXTURE_EXTENSION_KEYS` is:

```text
uk.co.vedaconsulting.mosaico
de.systopia.sqltasks
org.civicrm.contactlayout
org.civicoop.civirules
```

`FullRealFixtures.php` can accept a broader `CIVICFG_QA_FIXTURE_EXTENSION_KEYS` value. In the dedicated Buildkit DEV/STAGE environment, run it against the full installed matrix rather than treating the four-extension CI default as the compatibility target.

Keep missing required fixtures fatal for release-quality runs. Allow missing fixtures only when diagnosing package/network setup outside the application test itself.

## Cross-environment acceptance

The strongest Point #2 acceptance test is DEV -> STAGE:

```text
DEV representative config
  -> export YAML
  -> move the same YAML to STAGE
  -> validate/import on STAGE
  -> re-export on STAGE
  -> canonical compare
```

The purpose is to prove that semantic keys/references survive different local database IDs. Infrastructure scheduling/promotion automation is intentionally outside this extension-level QA contract.

## Release-quality acceptance

Before promoting a development alpha to a public beta, require:

- fast QA green
- full isolated CiviCRM QA green
- state tables install/upgrade/uninstall cleanly
- rebuildable object state can be cleared and regenerated
- stable identities match across environments with different database IDs
- canonical fingerprints are deterministic and versioned
- secrets/runtime fields do not create false drift
- semantic references resolve to target-local IDs
- weak identities cannot perform unsafe writes
- possible renames block real import until reviewed/aligned
- three-way conflict cases distinguish overlapping from non-overlapping changes
- CLI works in Composer and non-Composer layouts without wrapper sprawl
- multi-project uninstall never removes another project's shared global CLI
- contrib compatibility results are explicit rather than silently skipped

## Alpha61 local and UI QA

Run the isolated stack locally without CiviCRM Buildkit:

```bash
composer qa:full
```

Run the same isolated stack with Playwright and review screenshots:

```bash
composer qa:full-ui
```

The browser suite writes screenshots and failure artifacts under `tests/ci/artifacts`, including `sync-setup-required.png` to verify that a fresh all-Ignore scope is not mislabeled **In Sync**. Real contributed-extension fixtures are pinned to tested refs by default; override the corresponding `*_REF` environment variable only for deliberate compatibility testing.
Run these commands from the host repository checkout where Docker Compose is available. The scripts intentionally stop before fetching fixtures when Docker is unavailable (for example inside a normal `ddev ssh` shell), and `tests/ci/artifacts/` is disposable/ignored by Git.
Standalone QA no longer runs Mailpit. The application network is internal-only, direct public TCP egress is verified as blocked, and PHP mail attempts are intercepted by the local sendmail blocker. A separate DDEV/Mailpit service on `127.0.0.1:8025` therefore cannot affect the suite. When `composer qa:full-ui` is run on a host without Node/npm, the UI stage uses the pinned `mcr.microsoft.com/playwright:v1.61.1-noble` image instead; GitHub Actions continues to use the workflow's Node/Playwright installation.

The alpha61 SQLTasks follow-up regression also verifies that directory-style API3 action files (`Entity/Create.php`, provider delete aliases such as `Entity/Deletetask.php`) are sufficient for capability discovery when runtime `getactions` introspection is unreliable. SQLTasks now has a reviewed declarative provider definition that does not load the BAO during discovery; the BAO adapter is loaded from the provider base path only when rows are read.
