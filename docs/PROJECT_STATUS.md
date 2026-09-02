# Configuration Manager project status

This is the durable implementation checklist and decision log. Update it in the same change as code, tests, or a changed decision. `info.xml` is authoritative for the development version; `CHANGELOG.md` records completed work.

## Authoritative state

| Item | Current value |
|---|---|
| Protected release baseline | `v1.0.0-beta1` at `5055d6edc58fa3d17c7fd28ab8bc0f74a2e21e2e` |
| Active development line | `0.1.0-alpha65-core` |
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

- [ ] A66-01 Inventory every registered core, contributed, and custom API4/API3/config-hook provider without reading business collections during discovery.
- [ ] A66-02 Record provider owner, API version, actions, fields, declared/derived identity, references, sensitive/runtime fields, and capability reason codes.
- [ ] A66-03 Add a deny-by-default admission pipeline: discover → classify → prove portable identity → prove writable projection → prove reference mapping → assign capability.
- [ ] A66-04 Support contrib/custom extensions generically through metadata and hooks; keep extension-name branches out of the core engine unless a reviewed semantic adapter is unavoidable.
- [ ] A66-05 Cache discovery by extension/core version and invalidate it safely after extension or schema changes.
- [ ] A66-06 Add compatibility fixtures for supported CiviCRM 5.x/6.x targets and representative contrib/custom providers.

### Alpha67 — Settings and inventory UX

- [ ] A67-01 Replace the long undifferentiated list with searchable groups: Core, Contributed, Custom, Unavailable, and Backup/Monitor-only.
- [ ] A67-02 Keep the heading **What should Configuration Manager manage?** and explain that only proven portable providers can write.
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

## Evidence ledger

| Evidence | Required command/boundary | Current state |
|---|---|---|
| Fast static/unit matrix | `composer qa:fast` on PHP 7.4/8.1/8.3 | Not run for alpha65 |
| Real import blocker | `composer qa:real-runtime` → `tests/ci/artifacts/import-blocker-safety.json` | Implemented; not run |
| Browser UX | `composer qa:real-runtime-ui` | Required on PR/release; not run |
| Mutation proof | Disposable source mutation + real blocker test red, restore + green | Not run |
| Cross-environment | Identical DEV YAML imported/re-exported on STAGE with different IDs | Not run |

The next implementation action after alpha65 evidence is A66-01: build a read-only provider inventory and classification report before granting any new provider management capability.
