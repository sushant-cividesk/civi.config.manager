# Beta2 priorities and evidence gates

**Approved task priority order:** 2026-10-08 (user-confirmed). **Development baseline:**
`0.1.0-alpha68.6.1-core`. **Protected release:** `v1.0.0-beta1` at
`5055d6edc58fa3d17c7fd28ab8bc0f74a2e21e2e`.

**Release target:** `v1.0.0-beta2`, only after every mandatory gate below is
actually verified and the user explicitly authorizes tagging and publication.
The approved **priority order is not a release approval**.

## Approved order (authoritative)

| Order | ID | Priority | Scope and current state | Gate / evidence required |
|---:|---|---|---|---|
| 1 | **QA-003** | **P0, required** | Restore normal QA; legacy ML-001 file still causes failures in old DDEV/CI checkouts. Source fix ready; updated DDEV/CI run **not yet verified**. | `composer qa:fast` passes in the supported PHP matrix; separately run `composer test:ml001:pending` to preserve the known red ML-001 reproducer. |
| 2 | **ML-001** | **P0, required** | Multilingual Afform/FormBuilder false drift. Reproduced; semantic fix **not implemented**. | Locale-only switch gives zero false differences/rewrites, genuine translated edits are detected, multiple locales, real API4 round trip, independent oracle. |
| 3 | **A69-05** | **P0, required** | Certify a selected, limited provider set. Runtime create/update/delete permission is **not yet established**. | Disposable real-CiviCRM different-ID round trips, independent final-state checks, preservation of business data; deletion certified separately. |
| 4 | **B2-02/03** | **P0, required** | Cross-environment and API/CLI/queue parity; final real-runtime matrix **pending**. | Same Saved Config DEV to STAGE, canonical re-export, and consistent UI/API4/CLI/queue accounting on Drupal/WordPress/Standalone as supported. |
| 5 | **A67-04/06** | **P0, optional** | Remaining interface counts, selection/keyboard/focus/accessibility polish; **partially implemented**. | UX tests and observed accessible interaction; optional work must not block the P0 required tasks. |
| 6 | **A68-09** | **P1, required** | Playwright and stateful Import-safety proof; Drupal login + settings-discovery harness issues, partial-write scenario **open**. | Targeted/stateful browser tests, safe/unsafe exclusions, stale review plans, partial accounting, deliberately induced failure without later deletion. |
| 7 | **B2-04/05** | **P1, required** | Adversarial safety cases; final evidence **pending**. | Race/stale-plan, blocker, rollback/recovery, secrets, ignored/unselected scope, preservation and mutation-red evidence. |
| 8 | **B2-06/07** | **P1, required** | Upgrade, compatibility and installable runtime ZIP; release packaging gate **pending**. | Beta1 upgrade, documented PHP/CiviCRM matrix, install/enable/disable/uninstall, production ZIP with locked dependencies and SHA256. |
| 9 | **B2-08/09** | **P1, required** | Final documentation and release-candidate review; **pending**. | Full evidence ledger, manual demo, consistent docs/version/release notes, user authorization before tag/publish. |
| 10 | **UX-002** | **P1, optional** | Consistent warnings, structured errors and operator remediation across UI/API4/CLI/logs; **partial**. | Actionable language and preserved fail-closed behavior, without blocking required release work. |
| 11 | **Phase 2** | **P1, optional** | Additional provider/feature expansion; **not part of committed Beta2 scope**. | P1 permits *planning/scoping* before Beta2; implementing new Phase 2 capabilities still requires separate scope approval and must not weaken/delay mandatory gates. |

The numbers in this table represent the user's selected order, **not** completion
percentages. P0 is first for scheduling; P1 is the next group. Optional P0/P1
entries must never consume the time needed for mandatory Beta2 gates.
Every mandatory task remains a release blocker independent of priority.

## QA-003 diagnosis: why the old test still failed

On 2026-10-08 the user's DDEV run again reported **321 tests, 2,306 assertions,
one failure** at `tests/phpunit/Unit/MultilingualAfformCanonicalizationTest.php:25`.
Earlier GitHub Actions on PHP 8.1 showed the same red test. The previous
source ZIP moved that intentionally failing regression into
`tests/pending/ml001/` but extraction *over* an older extension does not delete
old files. The old PHPUnit-discoverable file was therefore left in place.

**Corrective source change:** `phpunit.xml.dist` now explicitly excludes only the
obsolete path `tests/phpunit/Unit/MultilingualAfformCanonicalizationTest.php`.
The current `tests/pending/ml001/MultilingualAfformCanonicalizationTest.php`
remains an explicit requirement-first reproduction via:

```bash
composer qa:fast
composer test:ml001:pending     # Expected to fail until the real ML-001 fix
```

For clean migration, inspect current `git status --short`, then move the
**exact obsolete file** out of the PHPUnit-discovery directory (keep a backup
if it is locally modified). Do not delete other tests or unrelated files. The
exact-path XML exclusion also protects **overlay installs** where the file
remains on disk. Verify `phpunit --configuration phpunit.xml.dist --list-tests`
no longer discovers this class. The failing reproducer remains a **known open
product bug**, not something to be disabled or declared fixed.

A source-level overlay simulation confirmed the old file survives ZIP overlay;
**post-change DDEV PHPUnit, complete Composer QA, and GitHub CI remain unverified**
until run against the corrected files in those environments.

## Non-negotiable safety and release boundaries

- No broad ignore of `title`/`label`, hard-coded language dictionary, or inferred
  translation equivalence. A real translated edit must remain observable.
- No `Continue anyway` Import bypass. Safe exclusion requires a fresh reduced
  plan, complete preflight, and independent post-apply verification.
- An unproven provider stays review/export/compare only. Delete authorization
  requires separate runtime and business-data preservation evidence.
- Keep Synchronize / Import / Export / Settings, the approved client-friendly
  terminology, and incremental UX (not a dashboard redesign).
- Do not commit, push, switch Git branches, tag, publish, deploy, or mutate PROD
  without explicit user authorization.
- Do not publish a source-only ZIP as an installable release. No Beta2 claim
  from unit/static tests without complete runtime/browser/upgrade evidence.

## Documentation maintenance on every subsequent implementation batch

1. Keep this file as the **authoritative, user-approved** order and evidence
   ledger. Change this order only with a later explicit user decision.
2. Reconcile [`PROJECT_STATUS.md`](PROJECT_STATUS.md), [`ROADMAP.md`](ROADMAP.md),
   [`IMPLEMENTATION_PLAN.md`](IMPLEMENTATION_PLAN.md), and the affected active
   engineering/QA documents in the same implementation batch.
3. Update [`../CHANGELOG.md`](../CHANGELOG.md) for completed implementation,
   evidence and important corrections; never describe plans as shipped code.
4. Update affected current behavior guides: [`../README.md`](../README.md),
   [`TESTING.md`](TESTING.md), [`QA_AUTOMATION.md`](QA_AUTOMATION.md),
   [`ARCHITECTURE.md`](ARCHITECTURE.md), [`CLI.md`](CLI.md),
   [`DETAILED_REFERENCE.md`](DETAILED_REFERENCE.md), and any others whose
   instructions or facts actually change.
5. Keep [`RELEASE_AND_UPGRADE_POLICY.md`](RELEASE_AND_UPGRADE_POLICY.md) and
   future release notes aligned with gates and evidence, **but preserve
   historical version-specific release notes unchanged**.
6. Record commands actually run, pass/fail counts, and remaining unverified
   runtime boundaries. Verify that all relative doc links resolve and that
   version-specific history is not accidentally rewritten.
