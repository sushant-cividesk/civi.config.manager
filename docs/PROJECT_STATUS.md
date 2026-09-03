# Configuration Manager project status

This is the durable implementation checklist and decision log. Update it in the same change as code, tests, or a changed decision. `info.xml` is authoritative for the development version; `CHANGELOG.md` records completed work.

## Authoritative state

| Item | Current value |
|---|---|
| Protected release baseline | `v1.0.0-beta1` at `5055d6edc58fa3d17c7fd28ab8bc0f74a2e21e2e` |
| Active development line | `0.1.0-alpha67.3-core` |
| Next public candidate | `1.0.0-beta2`, only after the gates below pass and release is explicitly approved |
| Product purpose | Portable, Git-reviewable CiviCRM configuration synchronization across DEV, STAGE, PROD, and peer environments |
| Source of truth | Managed YAML for supported configuration; local tables contain rebuildable operational state only |
| Core boundaries | Export, diff, validation, preflight, safe import, and independent final-state verification |

Beta1 must remain reproducible and unchanged. Development continues as numbered alphas. Do not tag, push, publish, or call an alpha Beta2 without explicit approval.

## Frozen safety decisions

- Import always builds a complete non-writing preflight before any write.
- The original full import remains blocked while any blocker is unresolved.
- A future **safe reduced plan** may let an administrator explicitly exclude the complete blocked dependency component. This discards the original plan, builds a new scope, and runs a completely new preflight. It is never “continue anyway.”
- Exclusion is allowed only when the dependency graph proves the remaining component is closed and safe. If safety cannot be proven, the only action is to fix the configuration and preview again.
- A reduced import never reports **In Sync** while excluded differences remain. It reports a scoped/partial result and lists exclusions.
- Create/update for every included type finishes before any delete-missing phase starts. Any write failure prevents deletion.
- Ambiguous, local-ID-only, sensitive, operational, or unproven provider data cannot gain generic write/delete authority.
- API4 is preferred, but the code must discover capabilities at runtime and fail closed when a CiviCRM/core/contrib version changes the usable contract.
- CRUD availability alone does not prove that an API entity is portable configuration.
- Export must publish atomically or leave the previous YAML snapshot intact. Import must preserve business data, secrets, ignored paths, unrelated YAML, and unselected configuration.

## Mandatory test contract

Every important test must satisfy all eight rules:

1. **Requirement-first** — Write the observable obligation and failure mode before implementation.
2. **Independent oracle** — Expected results cannot be calculated using the same canonicalizer, resolver, classifier, or provider code being tested.
3. **Red/green or mutation proof** — Either demonstrate the test fails against the known defect or deliberately mutate/revert the critical behavior and confirm the test fails.
4. **Public-boundary execution** — Test through the boundary the requirement concerns: service, API4, CLI, HTTP, browser, queue, or filesystem.
5. **Independent final-state assertion** — After import, query CiviCRM directly and inspect YAML/files independently. Do not trust only the extension's returned `ok` value.
6. **Negative and preservation checks** — Prove what must not change: business records, secrets, unrelated YAML, ignored types, and unselected configuration.
7. **Real-runtime confirmation** — Stubs prove local logic only. At least one disposable real CiviCRM test must prove every supported provider capability.
8. **Adversarial review** — Every review asks: “How could this implementation still be broken while these tests remain green?”

Source-string “contract” scripts and scenario-schema validation are architecture lint/document checks, not behavioral proof. They may support a gate but cannot satisfy these rules alone.

## Delivery checklist

Status meanings: **done** = implemented and locally inspectable; **awaiting runtime** = implemented but the required disposable environment evidence has not run; **planned** = approved but not implemented.

### Alpha65 — evidence foundation and continuity

- [x] A65-01 Preserve `v1.0.0-beta1`; move development metadata to `0.1.0-alpha65-core`.
- [x] A65-02 Add this durable decision/checklist document and link it from maintained docs.
- [x] A65-03 Rename source-inspection and scenario-schema scripts as lint/document checks in the primary QA command.
- [x] A65-04 Add a real-CiviCRM OptionValue identity-rename blocker test through `ConfigManager::import()`.
- [x] A65-05 Independently assert database rows, business-record counts, a secret fingerprint, and unrelated YAML remain unchanged.
- [x] A65-06 Make real-CiviCRM plus Playwright QA automatic on pull requests and mandatory before a tagged package job.
- [ ] A65-07 Run fast QA on PHP 7.4, 8.1, and 8.3. **Awaiting CI/runtime.**
- [ ] A65-08 Run the disposable real-CiviCRM suite and retain `import-blocker-safety.json`. **Awaiting Docker runtime.**
- [ ] A65-09 Produce red/mutation evidence for the blocker test by disabling the critical fail-closed branch in a disposable copy, proving failure, restoring it, then proving green. **Awaiting Docker runtime.**
- [ ] A65-10 Run Drupal and WordPress smoke/round-trip tests. **Awaiting project runtimes.**

### Alpha66 — generic provider discovery inventory

- [x] A66-01 Implement deterministic inventory for every registered core/config-hook handler plus installed contributed/custom API4/API3 candidates, without provider collection or YAML reads. **Real-runtime evidence pending.**
- [x] A66-02 Add the provider schema for owner, registration source, API/entity, actions, fields, identity, references, sensitive/runtime fields, admission/capability reasons, and evidence completeness. Unknown metadata remains explicitly empty/partial rather than inferred.
- [x] A66-02a Expose the inventory through admin-only `ConfigManager.providerInventory`; add service/unit contracts and the real-runtime CLI smoke call.
- [x] A66-02b Add an automated red/green mutation harness for the forbidden collection-read path. On 2026-09-03 the harness quoting defect that produced invalid injected PHP was fixed; the exact mutation now applies once and passes PHP syntax. **Behavioral red/green execution remains pending because PHPUnit/vendor dependencies are unavailable in the current container.**
- [x] A66-02c Fixed/core handlers now declare explicit action, identity, reference, sensitive/runtime, and management-capability metadata instead of inheriting `basic`; contributed dynamic-provider metadata remains explicit about its limits. **Supported real-runtime verification remains pending.**
- [x] A66-03 Implemented a deny-by-default metadata admission pipeline: discover → classify → prove portable identity → prove writable projection → prove reference mapping → assign capability. Writable reference fields without explicit semantic mapping fail closed; reviewed adapters remain explicit reviewed integration points. **PHPUnit mutation and disposable real-runtime proof remain pending.**
- [ ] A66-04 Support contrib/custom extensions generically through metadata and hooks; keep extension-name branches out of the core engine unless a reviewed semantic adapter is unavoidable.
- [ ] A66-05 Cache discovery by extension/core version and invalidate it safely after extension or schema changes.
- [ ] A66-06 Add compatibility fixtures for supported CiviCRM 5.x/6.x targets and representative contrib/custom providers.

### Alpha67 — Settings and inventory UX

- [x] A67-00 Add an isolated PHP 8.2+ Playwright black-box QA client alongside existing JavaScript Playwright + axe coverage. Keep it out of the PHP 7.4 runtime dependency graph and run it only against disposable/authorized HTTP environments. **Runtime execution remains pending.** The harness pins Playwright-PHP `1.4.0`; generate and review its nested `composer.lock` in a Composer-enabled environment before treating dependency resolution as release-reproducible.
- [ ] A67-01 Replace the long undifferentiated list with searchable groups: Core, Contributed, Custom, Unavailable, and Backup/Monitor-only.
- [x] A67-02 Keep the heading **What should Configuration Manager manage?** and expose provider-safety evidence/reasons so write capability is not presented as implicit. Broader grouping/search UX remains in A67-01/A67-04-A67-06.
- [ ] A67-03 Show per-type mode cards only for valid choices: Manage all, Manage selected, Monitor only, Ignore.
- [ ] A67-04 Show item counts, managed YAML file counts, selected counts, provider/capability status, dependency summaries, and a clear reason when full management is unavailable.
- [ ] A67-05 Load expensive item inventories only when a card/picker is opened; cache and paginate large providers.
- [ ] A67-06 Add bulk actions, unsaved-change protection, accessible keyboard/focus behavior, concise help, and responsive layouts.
- [ ] A67-07 Never label a monitor-only/all-ignore/no-baseline state as In Sync.

### Alpha68 — safe reduced import plans and blocker UX

- [ ] A68-01 Represent import operations and dependencies as immutable versioned plans with content/scope/active-state fingerprints.
- [ ] A68-02 Group blockers into dependency components and explain the affected files/types/actions in plain language.
- [ ] A68-03 Offer **Fix and preview again** for every blocker; offer **Exclude component and build a new preview** only when graph closure is proven safe.
- [ ] A68-04 Require explicit component selection and confirmation; never silently remove dependencies or individual rows.
- [ ] A68-05 Discard the old plan, rebuild from current YAML/active state, and run full validation/preflight again after exclusions.
- [ ] A68-06 Bind apply to the exact new plan token/fingerprints; stale or altered plans fail closed.
- [ ] A68-07 Report Applied, Blocked, Excluded, and Remaining Difference counts consistently in UI/API4/CLI/queue results.
- [ ] A68-08 Fix misleading action labels such as an extension warning saying “not uninstalled” while a card says “Remove from CiviCRM.”
- [ ] A68-09 Add browser tests for blocker explanation, unavailable unsafe exclusion, safe component exclusion, stale-plan rejection, and partial-status wording.

### Alpha69 — coverage expansion

- [ ] A69-01 Add Tags with portable parent/reference handling and round-trip proof.
- [ ] A69-02 Add Profiles/UF Groups and fields with component/module/dependency checks.
- [ ] A69-03 Add Contact Layouts through generic provider metadata or a reviewed adapter, with real contrib-version fixtures.
- [ ] A69-04 Add traditional Reports/Report Instances with portable references and preservation of permissions/settings.
- [ ] A69-05 Add provider-specific real-runtime tests for every newly advertised capability.

### Beta2 release gate

- [ ] B2-01 All supported provider capabilities pass disposable real-CiviCRM round trips with independent final-state checks.
- [ ] B2-02 DEV → STAGE test passes using the identical YAML on databases with different local IDs; STAGE re-export is canonically equivalent.
- [ ] B2-03 Drupal, WordPress, Standalone, API4, CLI, queue, filesystem, and browser boundaries pass on the supported matrix.
- [ ] B2-04 Blocker, reduced-plan, partial-failure, rollback/recovery, race/stale-plan, secret, ignored, unselected, and business-data preservation cases pass.
- [ ] B2-05 Every important test has recorded red/mutation evidence and adversarial review.
- [ ] B2-06 Upgrade from Beta1 preserves settings, YAML compatibility, hooks, CLI, and existing managed scope.
- [ ] B2-07 Production runtime ZIP includes locked runtime dependencies and passes install/enable/disable/uninstall/package inspection.
- [ ] B2-08 Documentation, changelog, version, release notes, evidence matrix, and known limitations match observed behavior.
- [ ] B2-09 Explicit human approval to tag and publish `v1.0.0-beta2`.

## Known blocker ledger

| ID | Evidence | Current handling | Target improvement | Status |
|---|---|---|---|---|
| BLK-001 | OptionValue stable value `3` appears with a changed email-like machine name | Full preflight blocks rename and delete-missing | Real-runtime zero-write proof; later component-aware explanation/exclusion only if safe | Test added; runtime evidence pending |
| UX-001 | Extension warning says it is not automatically uninstalled while preview card says “Remove from CiviCRM” with zero fields | Confusing but safety text is present | One consistent non-actionable/monitor-only label and reason | Planned A68-08 |
| QA-001 | Source-string contracts can stay green while runtime behavior is broken | Kept as architecture lint | Independent behavioral, real-runtime, mutation, and browser gates | In progress |
| CI-001 | Supplied PHP 8.1 workflow failed only because Packagist advisory download returned HTTP 502 | Direct `composer audit` failed on transient service outage | Retry only transport/408/425/429/5xx errors; advisories/unknown errors fail closed | Wrapper implemented and shell-tested |
| CI-002 | `composer qa:fast` failed in `mutation-provider-inventory.sh` with `PHP Parse error: unexpected call_user_func` | Bash ANSI-C quoting stopped interpreting later `\n` escapes after embedded single-quote fragments, so the mutation itself contained literal `\n` text | Build the needle/replacement as literal heredoc strings; require exactly one replacement; syntax-check mutated and restored source before PHPUnit | Harness fixed 2026-09-03; mutation syntax proof passed; behavioral red/green awaits PHPUnit dependencies |

## Evidence ledger

| Evidence | Required command/boundary | Current state |
|---|---|---|
| Fast static/unit matrix | `composer qa:fast` on PHP 7.4/8.1/8.3 | Still pending. 2026-09-03 container has PHP 8.4 only, no Composer/vendor dependencies, no Docker/Podman, and outbound DNS is disabled. PHP 8.4 syntax passed for all 108 project PHP files; Alpha62/63/64 architecture contracts passed (31/53/23 checks). |
| Real import blocker | `composer qa:real-runtime` → `tests/ci/artifacts/import-blocker-safety.json` | Implemented; not run |
| Browser UX | `composer qa:real-runtime-ui` | JS Playwright + axe remains required; alpha67 also wires isolated Playwright-PHP black-box QA. Both runtime executions remain pending in this authoring container. |
| Mutation proof | Disposable source mutation + real blocker test red, restore + green | 2026-09-03 harness parse defect fixed. Independent injection check proved the forbidden-read mutation applies exactly once and the mutated PHP lints; restored source also lints. PHPUnit red/green proof remains pending because `vendor/bin/phpunit` is unavailable. |
| Cross-environment | Identical DEV YAML imported/re-exported on STAGE with different IDs | Not run |
| Alpha66/67 provider inventory + admission | Unit collection-read trap + admission policy/mutation + `cv api4 ConfigManager.providerInventory` on disposable CiviCRM | Metadata-only admission smoke passes locally. PHPUnit/mutation behavioral proof and real-runtime inventory/admission remain pending because this container has no Composer/vendor or Docker. |
| Composer audit retry | `tests/ci/composer-audit-wrapper-test.sh` | Passed again 2026-09-03: transient recovery, advisory fail-closed, exhausted failure |
| Authoring checks | JSON parse, Bash syntax, `git diff --check` | 2026-09-03: archive SHA-256/integrity matched handoff; `info.xml` is `0.1.0-alpha66-core`; composer/package JSON parsed; all 10 test Bash scripts passed `bash -n`; all 108 project PHP files passed syntax under PHP 8.4. `validate-scenarios.php` could not start because `vendor/autoload.php` is absent. |

Current alpha67 implementation checkpoint: A66-02c and A66-03 are implemented in source; Settings exposes provider-safety metadata; an isolated Playwright-PHP harness and CI/disposable-runtime wiring are present. Local checks observed: all 112 project PHP files lint under PHP 8.4, Alpha62/63/64 architecture contracts pass (31/53/23), Composer audit-wrapper behavior passes, workflow YAML/Composer JSON parse, and direct admission-policy smoke proves unmapped references deny while a reference-free portable provider can admit. Full PHPUnit/mutation red-green, PHP 7.4/8.1/8.3, Docker/CiviCRM, JS Playwright, and Playwright-PHP execution remain runtime gates.

The next implementation action after these gates is A66-04 generic contrib/custom support completion, then A66-05 caching and A66-06 supported-version fixtures before the remaining Alpha67 inventory grouping/count/search/accessibility work.

### Alpha67.1 QA maintenance evidence — 2026-09-03

- Maintainer-run `composer qa:fast` on buildkit/PHP 8.3.31 passed: 250 PHPUnit tests, 1,976 assertions, provider-inventory mutation proof red/restored-green, provider-admission mutation proof red/restored-green, and static analysis reported no errors.
- Playwright-PHP 1.4.0 + PHPUnit 11.5.56 and browser binaries installed successfully in the original nested harness experiment, but the test was skipped because `CIVICFG_BASE_URL` was missing. This is dependency-install evidence only, not browser validation.
- Alpha67.1 removes the nested-vendor architecture and makes a missing real-site URL a hard failure whenever PHP browser QA is requested.

### Alpha67.2 CLI browser-QA hotfix — 2026-09-03

- Root cause: `qa:browser-php` is a root Composer script, while the nested `tests/browser-php` Composer project has no `qa` namespace; additionally, the pre-Alpha67.1 manual install left a generated nested `vendor/` that Alpha67.1 correctly refused.
- `civicfg qa-browser --base-url URL` now owns manual Playwright-PHP orchestration and delegates to the same external-tooling runner as CI.
- `civicfg qa-browser-clean` is read-only by default and requires `--yes` before deleting only known generated legacy browser-QA artifacts. `qa-browser --clean-legacy` provides an explicit clean-and-run path.
- Passwords remain environment-only through `CIVICRM_ADMIN_PASS`; the CLI rejects `--admin-pass`.
- This is an Alpha67 maintenance hotfix. After its real DEV browser run is green, continue A66-04 generic contributed/custom provider support, then A66-05/A66-06 and the remaining Alpha67 inventory UX.


### Alpha67.3 browser/CLI hotfix — 2026-09-03

- Root cause addressed: manual `tests/browser-php` Composer use created a second vendor tree, root `qa:*` commands were unavailable from that nested project, and browser QA depended on a pre-existing site URL.
- `composer qa:browser-php` now owns its disposable CiviCRM runtime; `composer qa:browser` runs both browser stacks through the same standalone runtime used by GitHub Actions.
- Targeted existing-site testing is explicit and requires `CIVICFG_BASE_URL` plus `CIVICRM_ADMIN_PASS`.
- Browser PHPUnit is fail-closed for skipped/risky/zero-assertion tests.
- CLI installation now prefers a writable directory containing `cv`; `./bin/civicfg cli-install` and `./bin/civicfg cli-doctor` provide deterministic repair/diagnostics when a global launcher is not present.
- Source packaging must exclude `.git`, `__MACOSX`, all vendor/node_modules trees, PHPUnit caches, and generated QA artifacts.
