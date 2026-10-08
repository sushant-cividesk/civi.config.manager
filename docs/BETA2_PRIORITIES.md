# Beta2 priorities and evidence gates

**Planning checkpoint:** 2026-10-08. **Development baseline:** `0.1.0-alpha68.6.1-core`.
**Protected release:** `v1.0.0-beta1`, commit `5055d6edc58fa3d17c7fd28ab8bc0f74a2e21e2e`.
**Target:** `v1.0.0-beta2` after all mandatory evidence and explicit human approval.

This is the **recommended** work order, not a user-approved reprioritization.
Use the in-chat interactive priority planner to choose ordering, then update this
file and the linked documents with the submitted choices. Priority **never**
waives a mandatory Beta2 release gate.

## How to read priorities

- **P0 / Now:** next critical work; do not start optional features while blocked.
- **P1 / Before RC:** required release proof after immediate blockers.
- **P2 / Capacity:** useful improvements only if they do not delay mandatory gates.
- **After Beta2:** optional future scope; do not include in Beta2 readiness claims.

`Implemented` means code exists; `Verified` requires exact observed evidence.
A green default PHPUnit suite alone does not imply a release gate passed.

## Mandatory Beta2 work

| ID | Recommended priority | Work | Current state | Acceptance evidence |
|---|---|---|---|---|
| QA-003 | P0 | Restore default fast QA while keeping the intentionally red ML-001 requirement executable | Source adjustment in this checkpoint; DDEV/CI rerun pending | `composer qa:fast` green on supported PHP matrix; `composer test:ml001:pending` separately reproduces ML-001 until fixed |
| ML-001 | P0 | Language-independent Afform/FormBuilder canonicalization | Bug reproduced; implementation pending | Locale switch causes zero false drift/rewrites; genuine translation edit detected; more than one locale pair; peer-site import/re-export; independent oracle |
| A68-09 | P0 | Real browser import-safety and reliable DDEV harness | Fixture scripts exist; Drupal login and `candidate` failures remain; partial-write scenario unproven | Successful targeted + stateful Playwright; safe exclusion, stale-plan, partial outcomes, deliberately induced write failure with zero later deletes |
| A69-05 | P0 | Prove selected provider CRUD boundaries | Provider support exists; runtime certification incomplete | Disposable real-CiviCRM round trips, different numeric IDs, independent final state, business-data preservation, delete independently certified |
| B2-02/B2-03 | P1 | Cross-site, Drupal/WordPress/Standalone, CLI/API4/queue parity | Final matrix pending | Same YAML DEV to STAGE; canonical re-export; correct outcome counts on each supported surface |
| B2-04/B2-05 | P1 | Adversarial safety matrix | Core safety implemented; runtime edge evidence incomplete | Blockers, stale/race, secrets, ignored/unselected config, partial failure, rollback/recovery and mutation proof |
| B2-06/B2-07 | P1 | Upgrade, compatibility and installable release ZIP | Final lifecycle/package gate pending | Beta1 upgrade preserves settings/YAML; supported PHP/CiviCRM matrix; install/enable/disable/uninstall; runtime ZIP contains locked production dependencies |
| B2-08/B2-09 | P1 | Final QA evidence, docs and release approval | Pending | Complete evidence ledger, manual demo, exact release docs, explicit human sign-off before tag/publish |

## Optional improvements (do not replace mandatory gates)

| ID | Recommended priority | Work | Current state | Decision |
|---|---|---|---|---|
| UX-002 | P2 | Consistent structured warning/error and remediation messages across UI/API4/CLI/logs | Partially improved; not closed | Include only if it reduces blocking demo/operator risk |
| A67-04/A67-06 | After Beta2 | Live item/selection counts, remaining accessibility/focus polish | Partially implemented | Defer unless a concrete usability regression blocks Beta2 |
| Phase 2 | After Beta2 | Broad new provider support and optional UI/tooling expansion | Not in the Beta2 release gate | Scope only after Beta2 evidence and approval |

## Fixed safety boundaries

- No unsafe Import override, speculative translated-value equivalence, or blanket `title`/`label` exclusion.
- An unproven provider remains read-only, review-only, or export/compare; delete is separately authorized by real evidence.
- Keep the approved Synchronize / Import / Export / Settings UI; no dashboard redesign.
- No commit, push, tag, deploy or release without explicit user instruction.

## QA-003 implementation and truth in CI

The pending ML-001 regression was added before a semantic fix existed. The
2026-10-08 DDEV/CI evidence showed 321 tests, 2,306 assertions, one expected
locale-equivalence failure. This was a real release-QA gate failure due to test
classification, **not** proof that ML-001 was fixed.

The unmodified assertion now lives in
`tests/pending/ml001/MultilingualAfformCanonicalizationTest.php`, outside
`phpunit.xml.dist`'s default `tests/phpunit/Unit` discovery tree. This allows
normal QA to assess *implemented* functionality while preserving the original
ML-001 failing reproduction for explicit execution:

```bash
composer qa:fast
composer test:ml001:pending    # Expected non-zero until ML-001 is fixed
```

Run and record both outcomes separately. **Do not treat green `qa:fast` as ML-001
completion**. The pending test is not sufficient by itself: real-language,
real-Afform import/export evidence remains mandatory. Once the verified fix
exists, move the regression into the normal suite and prove red/green plus
end-to-end behavior.

## Documentation maintenance for every following change

1. Update this plan with changed priority, status, evidence, and acceptance gate.
2. Update [`PROJECT_STATUS.md`](PROJECT_STATUS.md) when any checkpoint or blocker changes.
3. Update [`../CHANGELOG.md`](../CHANGELOG.md) with implemented work; never state a planned fix as shipped.
4. Update affected user-facing/current-behavior docs (`../README.md`,
   [`TESTING.md`](TESTING.md), [`QA_AUTOMATION.md`](QA_AUTOMATION.md),
   [`ARCHITECTURE.md`](ARCHITECTURE.md), [`CLI.md`](CLI.md), and
   [`IMPLEMENTATION_PLAN.md`](IMPLEMENTATION_PLAN.md)) when their facts change.
5. Update [`RELEASE_AND_UPGRADE_POLICY.md`](RELEASE_AND_UPGRADE_POLICY.md) and
   the version-specific release notes **at the time of an actual release**, not
   retroactively to claim unshipped work exists in historical versions.
6. Record commands actually run, pass/fail results, unverified environments,
   and any production-data risks; do not substitute source-string checks for
   real-runtime proof.
