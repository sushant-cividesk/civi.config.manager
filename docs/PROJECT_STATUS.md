# Configuration Manager project status

This is the durable implementation checklist and decision log. Update it in the same change as code, tests, or a changed decision. `info.xml` is authoritative for the development version; `CHANGELOG.md` records completed work.

The **user-confirmed P0/P1 task order (2026-10-08)** and mandatory evidence
are maintained in [Beta2 priorities](BETA2_PRIORITIES.md). The exact order is
QA-003, ML-001, A69-05, B2-02/03, A67-04/06, A68-09, B2-04/05,
B2-06/07, B2-08/09, UX-002, then Phase 2. A67-04/06, UX-002, and
Phase 2 remain optional; Phase 2 P1 is planning priority only, **not a
commitment to add new features before Beta2**. No order change waives a release gate.

## Authoritative state

| Item | Current value |
|---|---|
| Protected release baseline | `v1.0.0-beta1` at `5055d6edc58fa3d17c7fd28ab8bc0f74a2e21e2e` |
| Active development line | `0.1.0-alpha68.6.1-core` |
| Next public candidate | `1.0.0-beta2`, only after the gates below pass and release is explicitly approved |
| Product purpose | Portable, Git-reviewable CiviCRM configuration synchronization across DEV, STAGE, PROD, and peer environments |
| Source of truth | Managed YAML for supported configuration; local tables contain rebuildable operational state only |
| Core boundaries | Export, diff, validation, preflight, safe import, and independent final-state verification |

Beta1 must remain reproducible and unchanged. Development continues as numbered alphas. Do not tag, push, publish, or call an alpha Beta2 without explicit approval.

## Frozen safety decisions

- Import always builds a complete non-writing preflight before any write.
- The original full import remains blocked while any blocker is unresolved.
- The implemented **safe reduced plan** lets an administrator explicitly exclude a complete blocked dependency component only when proven safe. It discards the original plan, builds a new scope, and runs a complete fresh preflight. It is never “continue anyway.” Real browser evidence is still pending.
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
- [x] A66-04 Support contrib/custom extensions generically through metadata and hooks; duplicate/invalid hook registrations now fail closed without shadowing unrelated/core providers, and rejected registrations remain visible as unavailable provider inventory.
- [ ] A66-05 Cache discovery by extension/core version and invalidate it safely after extension or schema changes.
- [ ] A66-06 Add compatibility fixtures for supported CiviCRM 5.x/6.x targets and representative contrib/custom providers.

### Alpha67 — Settings and inventory UX

- [x] A67-00 Keep one trustworthy browser stack: JavaScript Playwright + axe against the disposable real-CiviCRM runtime. The experimental Playwright-PHP duplicate stack was removed in Alpha67.4 after proving to add host/dependency complexity without independent product evidence.
- [x] A67-01 Replace the long undifferentiated list with searchable groups: Core, Contributed, Custom, Unavailable, and Backup/Monitor-only. Alpha67.5 loads provider metadata asynchronously so the initial Settings render does not wait for full provider inventory.
- [x] A67-02 Keep the heading **What should Configuration Manager manage?** and expose provider-safety evidence/reasons so write capability is not presented as implicit. Broader grouping/search UX remains in A67-01/A67-04-A67-06.
- [ ] A67-03 Show per-type mode cards only for valid choices: Manage all, Manage selected, Monitor only, Ignore.
- [ ] A67-04 Show item counts, Saved Config file counts, selected counts, provider/capability status, dependency summaries, and a clear reason when full management is unavailable. **Alpha68 adds the total Saved Config count plus lightweight per-type Saved Config counts without loading provider collections; live item/selected counts remain pending.**
- [ ] A67-05 Load expensive item inventories only when a card/picker is opened; cache and paginate large providers. **Item pickers were already lazy; Alpha67.5 also moves metadata-rich provider inventory off the initial Settings request. Persistent caching remains deferred until measurement justifies it.**
- [ ] A67-06 Add bulk actions, unsaved-change protection, accessible keyboard/focus behavior, concise help, and responsive layouts. **Bulk actions/unsaved protection already exist; Alpha67.5 adds responsive search/filter controls and live result counts. Remaining accessibility/focus polish is pending.**
- [ ] A67-07 Never label a monitor-only/all-ignore/no-baseline state as In Sync.

### Alpha68 — safe reduced import plans and blocker UX

- [x] A68-01 Represent import operations and dependencies as immutable versioned plans with content/scope/active-state fingerprints. **Alpha68.3 persists private reviewed plans with site/scope/provider/implementation/Saved Config/Current CiviCRM fingerprints and reuses the same valid plan across refresh/reconnect.**
- [x] A68-02 Group blockers into dependency components and explain the affected files/types/actions in plain language. **Alpha68.4 groups missing portable dependencies by connected type component and shows affected Saved Config files, configuration types, blocker reasons, and planned dry-run actions. Runtime QA pending.**
- [x] A68-03 Offer **Fix and preview again** for every blocker; offer **Exclude component and build a new preview** only when graph closure is proven safe. **Alpha68.4 keeps the fix path universal and enables exclusion only for a whole component that leaves a non-empty closed remaining scope. Runtime QA pending.**
- [x] A68-04 Require explicit component selection and confirmation; never silently remove dependencies or individual rows. **Alpha68.4 posts only an opaque component ID through the existing confirmation modal; the server recomputes the current component and refuses stale/unsafe IDs. Runtime QA pending.**
- [x] A68-05 Discard the old plan, rebuild from current YAML/active state, and run full validation/preflight again after exclusions. **Alpha68.4 never edits a reviewed plan in place; the UI clears the prior plan and the service rebuilds full validation/preflight from current Saved Config and Current CiviCRM before issuing a new plan ID. Runtime QA pending.**
- [x] A68-06 Bind apply to the exact new plan token/fingerprints; stale or altered plans fail closed. **Alpha68.3 requires the reviewed plan ID across UI/API4/CLI/queue apply paths, rejects tampered/stale plans before write, and keeps existing per-handler conflict checks as a second barrier.**
- [x] A68-07 Report Applied, Blocked, Excluded, and Remaining Difference counts consistently in UI/API4/CLI/queue results. **Alpha68.5 uses one canonical accounting service across synchronous and queued results; successful apply computes Remaining Difference from a fresh post-import Synchronize diff, while reduced-plan exclusions are persisted in the immutable plan. Maintainer DDEV `composer qa:fast` passed with 319 PHPUnit tests / 2,304 assertions and all configured mutation/static gates.**
- [x] A68-08 Fix misleading action labels such as an extension warning saying “not uninstalled” while a card says “Remove from CiviCRM.” **Alpha68.5 now shows `Export to Saved Config` and explicitly says the item remains in Current CiviCRM whenever removal is not proven safe; destructive wording remains only on delete-enabled types.**
- [ ] A68-09 Add browser tests for blocker explanation, unavailable unsafe exclusion, safe component exclusion, stale-plan rejection, and partial-status wording. **Alpha68.6.1 retains the deterministic disposable DDEV fixture and stateful Playwright scenarios for unsafe whole-import exclusion, safe reduced-plan construction, refresh/reconnect, stale-plan invalidation, and canonical reduced-Import outcome labels. The DDEV runners now self-prepare Playwright dependencies and explicitly bootstrap CiviCRM through the discovered `civicrm.settings.php`. Real stateful browser execution is still required; a deliberately induced browser-level partial-write failure remains the only A68-09 scenario not yet represented as a stateful fixture.**


### Alpha68 UI/UX slice — approved 2026-09-04

- [x] Preserve the existing page structure and use progressive disclosure; no dashboard-style redesign.
- [x] Replace primary client-facing YAML/active-database terminology with Saved Config / Current CiviCRM wording.
- [x] Replace awkward difference labels with Not Yet Saved / Not in Current CiviCRM.
- [x] Show total Saved Config count in the existing summary area.
- [x] Keep a persistent Last Export summary on Synchronize after the toast disappears.
- [x] Simplify queued progress wording to parts/items/done language while keeping unknown totals honest and avoiding fake ETA.
- [x] Add per-type Saved Config counts without forcing expensive provider collection during initial page render. Live item/selected counts remain part of A67-04.
- [x] Add persistent Import result summary using the same compact progressive-disclosure pattern; keep only the most recent Export/Import result to avoid UI clutter.
- [ ] Finish structured error/warning remediation UX across UI, CLI, API4, and logs.
- [x] Improve extension discovery visibility: the normal management cards stay focused on configurable types, while Settings now explains additional discovered provider entries in collapsed safety groups. Provider counts no longer imply that every detected API is separately manageable; technical identifiers/reasons stay behind progressive disclosure. **Alpha68.2 adds no provider authority.**
- [x] Correct capability wording so reviewed create/update-only handlers show **Create + update**, fully managed handlers show **Managed**, and no label implies delete authority that the handler has not enabled.
- [x] Replace duplicate **Not Yet Saved** prose with an Export next action and keep removal wording explicitly conditional on proven provider safety.
- [x] Keep Profile Field collision hashes internal: normal Sync/Import/Export cards use semantic Profile/field titles while the underlying Saved Config filename and identity remain unchanged.
- [ ] Complete the safe per-provider CRUD audit, starting with Tags; do not advertise delete until business-data preservation is proven.
- [ ] A68-ML01 Design and implement locale-independent Saved Config canonicalization for multilingual sites. Switching only the active UI/site language must not rewrite equivalent Saved Configs or create false Synchronize drift; genuine translations must remain portable. **Real EN/FR reproducer recorded; implementation deliberately deferred from alpha68.1.**

### Alpha69 — coverage expansion

> Source support for these families landed early in `0.1.0-alpha67.7-core`. This did **not** complete Alpha68 or Alpha69 out of sequence: Alpha68 is now the active milestone, and the Alpha69 items remain open until their runtime/compatibility evidence is satisfied.

- [ ] A69-01 Tags: **source implementation complete; awaiting runtime evidence.** Metadata-driven API4 management uses stable `name` identity and semantic parent-tag references. Create/update is enabled; delete-missing remains disabled. A disposable DEV → target round trip plus entity-tag/business-data preservation proof is still required.
- [ ] A69-02 Profiles/UF Groups and Profile Fields: **source implementation complete; awaiting runtime evidence.** Profiles use stable `name`; Profile Fields now use a reviewed repeated-field identity adapter with semantic Profile/field/location/type qualifiers and label only as an ambiguity tie-breaker; UF Group and Location Type references resolve semantically. Create/update is enabled and delete-missing remains disabled. Real component/version fixtures and independent final-state proof remain required.
- [ ] A69-03 Contact Layouts: **reviewed adapter implemented; awaiting real contrib-version evidence.** Known nested Group/Profile/Custom Group/Relationship Type IDs are converted to semantic references; unknown nested `*_id` values fail closed. Duplicate labels block portability and delete-missing is disabled.
- [ ] A69-04 traditional Reports/Report Instances: **reviewed APIv3 adapter implemented; awaiting runtime evidence.** Portable identity prefers `report_id + name` and uses guarded `report_id + title` only for legacy unnamed instances; saved criteria, permission, group-role, activation, and reservation settings are managed conservatively. Runtime/local IDs and delivery-recipient fields remain outside automatic management; delete-missing is disabled.
- [ ] A69-05 Add provider-specific real-runtime tests for every newly advertised capability, including different local IDs, independent post-import queries/re-export, business-data preservation, and deliberate mutation/red evidence. **Not yet satisfied.**

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
- [ ] B2-10 Language-independent multilingual regression proves that changing only the active language/locale to any configured language produces zero false drift/rewrites, while a genuine translated-value change is detected, exported, imported on a peer environment, and re-exported canonically. Representative real multilingual fixtures must include more than one locale pair; EN/FR remains only the original reproducer.

## Known blocker ledger

**2026-10-08 QA note:** The new ML-001 requirement-first test caused the reported
`composer qa:fast` / GitHub CI failure (321 tests, 2,306 assertions, 1 failure).
It has been moved to `tests/pending/ml001/` and exposed as
`composer test:ml001:pending`. On the old DDEV checkout the original path
remained discoverable after the ZIP was extracted over existing files.
`phpunit.xml.dist` now excludes that exact legacy path for overlay safety.
The latest corrected checkout still needs a
DDEV/CI rerun. This is QA classification, **not** a multilingual product fix.


| ID | Evidence | Current handling | Target improvement | Status |
|---|---|---|---|---|
| BLK-001 | OptionValue stable value `3` appears with a changed email-like machine name | Full preflight blocks rename and delete-missing | Real-runtime zero-write proof; later component-aware explanation/exclusion only if safe | Test added; runtime evidence pending |
| UX-001 | Extension warning says it is not automatically uninstalled while preview card says “Remove from CiviCRM” with zero fields | Unsupported removal now renders as `Export to Saved Config` and states that Current CiviCRM is unchanged | Keep destructive wording only for delete-enabled types | Fixed in alpha68.5 |
| BLK-002 | Runtime export produced duplicate path `profiles/fields/summary_overlay__phone.yml` because `profile + field_name` was not unique for repeated UFFields | Export stopped atomically; no live YAML changed | Dedicated semantic Profile Field identity adapter plus regression/runtime proof | Source fix in alpha67.7; DEV rerun pending |
| BLK-003 | Runtime export encountered an unnamed ReportInstance and the strict `report_id + name` rule aborted the whole export | Export stopped atomically; no live YAML changed | Guarded `report_id + title` fallback for legacy unnamed rows; still block missing template/provider or ambiguous fallback | Source fix in alpha67.7; DEV rerun pending |
| UX-002 | Export failures were safe but too opaque for operators (no offending object/source identity or clear remediation) | Raw exception text only | Standard structured severity/context/cause/remediation across UI, CLI, API, and logs without weakening fail-closed behavior | Partially improved in alpha67.7 duplicate-path diagnostics; broader UX work planned |
| ML-001 | EN/FR WordPress+CiviCRM reproducer showed FormBuilder Afform title values changing with language context; examples include `Nom du foyer` vs `Household Name` and `Mettre à jour « Information Contact »` vs `Update Information Contact` | Treat language-only FormBuilder drift as unreliable; do not bulk-ignore/import it as a permanent workaround | Locale-independent canonical export/diff plus explicit preservation of genuine translations; real multilingual round-trip and mutation proof before Beta2 | Reproducer red on DDEV/CI 2026-10-08; semantic fix and real-runtime proof pending, required before Beta2 |
| QA-001 | Source-string contracts can stay green while runtime behavior is broken | Kept as architecture lint | Independent behavioral, real-runtime, mutation, and browser gates | In progress |
| CI-001 | Supplied PHP 8.1 workflow failed only because Packagist advisory download returned HTTP 502 | Direct `composer audit` failed on transient service outage | Retry only transport/408/425/429/5xx errors; advisories/unknown errors fail closed | Wrapper implemented and shell-tested |
| CI-002 | `composer qa:fast` failed in `mutation-provider-inventory.sh` with `PHP Parse error: unexpected call_user_func` | Bash ANSI-C quoting stopped interpreting later `\n` escapes after embedded single-quote fragments, so the mutation itself contained literal `\n` text | Build the needle/replacement as literal heredoc strings; require exactly one replacement; syntax-check mutated and restored source before PHPUnit | Harness fixed 2026-09-03; mutation syntax proof passed; behavioral red/green awaits PHPUnit dependencies |

## Evidence ledger

| Evidence | Required command/boundary | Current state |
|---|---|---|
| Fast static/unit matrix | `composer qa:fast` on PHP 7.4/8.1/8.3 | An earlier Alpha68.5 DDEV run passed 319 PHPUnit tests / 2,304 assertions plus configured mutation/static gates. On 2026-10-08 DDEV and GitHub PHP 8.1 both reported 321 tests / 2,306 assertions with one deliberately red ML-001 test. The test has now been separated into an explicit pending suite; **fresh post-change DDEV/CI and the full supported PHP matrix remain required**. |
| Real import blocker | `composer qa:real-runtime` → `tests/ci/artifacts/import-blocker-safety.json` | Implemented; not run |
| Browser UX | `composer qa:browser` and DDEV targeted/stateful wrappers | JS Playwright + axe remains the single browser stack. Latest DDEV targeted browser evidence stops in shared Drupal login setup; stateful runner stops on `candidate: unbound variable`. Successful real browser behavior is **not yet proven**. |
| Mutation proof | Disposable source mutation + real blocker test red, restore + green | Alpha68.5 DDEV reportedly passed configured provider-inventory, provider-admission, reviewed-plan, reduced-Import and Import-accounting mutation proofs. A new full mutation/runtime gate is still required before Beta2; older 2026-09-03 local-container limitations are historical, not the current DDEV state. |
| Cross-environment | Identical DEV YAML imported/re-exported on STAGE with different IDs | Not run |
| Alpha66/67 provider inventory + admission | Unit collection-read trap + admission policy/mutation + `cv api4 ConfigManager.providerInventory` on disposable CiviCRM | Metadata-only admission smoke passes locally. PHPUnit/mutation behavioral proof and real-runtime inventory/admission remain pending because this container has no Composer/vendor or Docker. |
| Composer audit retry | `tests/ci/composer-audit-wrapper-test.sh` | Passed again 2026-09-03: transient recovery, advisory fail-closed, exhausted failure |
| Authoring checks | JSON parse, Bash syntax, `git diff --check` | 2026-09-03: archive SHA-256/integrity matched handoff; `info.xml` is `0.1.0-alpha66-core`; composer/package JSON parsed; all 10 test Bash scripts passed `bash -n`; all 108 project PHP files passed syntax under PHP 8.4. `validate-scenarios.php` could not start because `vendor/autoload.php` is absent. |

Historical Alpha67.5 checkpoint (superseded by the Alpha68.6.1 status above): A66-02c, A66-03, and A66-04 are implemented; Settings now renders scope/capability controls first and loads metadata-rich provider inventory asynchronously into searchable groups. Maintainer evidence from Alpha67.4.2 observed 255 PHPUnit tests / 1,993 assertions green before the final PHPStan hotfix; Alpha67.5 adds request-local scope-option reuse plus provider-browser tests. Full PHPUnit/static-analysis/real-runtime/browser gates must rerun before release promotion.

A66-04 is complete. Alpha67.5 intentionally prioritizes lazy/searchable Settings UX over persistent discovery caching: full provider inventory is no longer required for initial Settings render. Next: finish A67 counts/accessibility/mode-choice polish, add A66-06 supported-version/provider fixtures, then measure discovery cost before deciding whether persistent caching is justified.

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


### Alpha67.4 workflow simplification and A66-04 completion — 2026-09-03

- Removed the experimental Playwright-PHP harness, nested QA Composer manifest/lock, browser-PHP runners, and browser-specific production CLI commands.
- `composer qa:browser` is now the single browser entry point: disposable real CiviCRM + JavaScript Playwright/axe. `composer qa:fast` and `composer qa:real-runtime` remain the non-browser gates.
- Maintainer evidence carried forward before this change: 255 PHPUnit tests / 1,993 assertions, both provider mutation proofs, architecture/scenario contracts, source hygiene, and PHPStan all passed; Playwright-PHP failed at browser launch because the DDEV container lacked host browser libraries.
- Completed A66-04 registration safety: core/earlier provider types cannot be shadowed accidentally by later hook registrations; malformed advanced-hook values are rejected without breaking unrelated providers; rejected registrations are surfaced as unavailable provider inventory metadata.
- Next: A66-05 discovery caching, then A66-06 supported CiviCRM/provider fixtures, then the remaining Alpha67 Settings UX before Beta2 gates.

### Alpha67.4.1 QA hotfix — 2026-09-03

- CI exposed two Alpha67.4 test-suite defects, not provider-runtime defects: diagnostics-return tests compared a single expected diagnostic against the diagnostics list, and one branch/checkout still contained the removed `qa-browser-clean` CLI test.
- The diagnostics tests now assert exactly one rejected registration and compare the first list item to the independent expected fields.
- The browser workflow contract now fails if removed `qa-browser*` production CLI commands or `testQaBrowser*` unit tests reappear.
- Targeted DEV JavaScript browser QA remains deliberately simple: run `npm install` once in the checkout, then `npm run test:ui` with the target URL/admin environment.


### Alpha67.4.2 QA hotfix — 2026-09-03

- Maintainer DDEV evidence confirmed the Alpha67.4.1 functional/unit layer green at 255 tests / 1,993 assertions with both provider mutation proofs, browser workflow contract, and source hygiene passing; `qa:fast` then failed only because three obsolete private CLI-test helpers were left unused after browser CLI removal.
- Removed those dead helpers rather than suppressing PHPStan.
- Direct targeted `npm run test:ui` previously failed before test discovery because the full disposable suite unconditionally loaded `ui-fixture-state.json`. The npm entry point now dispatches to a read-only targeted DEV smoke when `CIVICFG_BASE_URL` is explicit and fixture state is absent, while fixture-present disposable QA continues to run the full seeded suite.
- The targeted smoke does not execute fixture-dependent import/watch/cross-site mutation scenarios.

### Alpha67.5 Settings provider browser — 2026-09-03

- Initial Settings rendering no longer calls the metadata-rich `getProviderInventory()` path. Scope cards render from the existing cheap scope/capability path, then provider ownership/safety evidence loads asynchronously through an admin-only JSON endpoint.
- Configuration types are grouped into Core, Contributed, Custom, Backup / monitor-only, and Unavailable, with search and group filtering. Rejected registrations remain visible under Unavailable.
- Existing saved scope modes, dependency warnings, selector/item picker semantics, and write-safety admission remain unchanged. AJAX metadata failure does not broaden capability or silently alter saved scope.
- Persistent discovery caching is deferred until profiling shows it is needed; this avoids making correctness depend on cache invalidation before there is measured value.
