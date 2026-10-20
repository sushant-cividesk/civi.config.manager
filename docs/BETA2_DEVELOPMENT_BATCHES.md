# Beta2 development bundles — implementation tracker

**2026-10-20 QA portability checkpoint:** The GitHub fast-QA jobs on PHP 7.4 and 8.1 failed before exercising Bundle 2 Profile comparison because the smoke aliased the internal `stdClass` as an API4 stand-in (allowed on PHP 8.3+ only). The fixture now aliases a user-defined no-op class, with a regression guard preventing the internal-class alias from recurring on newer PHP. Local PHP 8.4 smoke passed (7 checks); full GitHub PHP 7.4/8.1/8.3 matrix rerun is pending. Production handlers and the Beta2 priority order are unchanged.

**2026-10-20 Bundle 1 ML-001 (partial implementation, mandatory P0 open):** From real STAGE locale-output proof, the built-in FormBuilder Afform handler now pins API4 read language to the `en_US` gettext source to prevent operator-locale-only Export/Synchronize drift. Source edit detection and unchanged custom Generic handler behavior pass in a dependency-free regression; DDEV full suite and guarded real STAGE integration are pending. French-only TranslationSource/Translation edits and existing locale-specific YAML migration still require an explicit translation-aware design; do not mark all of Bundle 1 certified.



**2026-10-20 updated bundle execution status (current source):** Bundle 3 completed real guarded disposable Contact Layout/Report create/update/round-trip/idempotency and exact cleanup on DDEV STAGE (`cfgb3_6300c6ed82c8`). Bundle 4 targeted STAGE Playwright passed 3/3 after clearing CiviCRM and Drupal caches. These are **tested environment-level milestones**, not full cross-version/cross-site release certification.

**New Bundle 5 work in this patch:** Human-readable Import readiness reasons with concrete recovery steps, plus unambiguous CLI preview/apply flags. The reviewed Import plan, provider permissions, dependency exclusion, import accounting and no-delete policy are untouched. DDEV focused tests and PHPUnit passed on the new code; full `qa:fast` stopped at PHPCS on four missing method `@param` entries. These annotations have been added; rerun the full DDEV QA gate.

**New Bundle 6 work in this patch:** Instrument cold/warm scope metadata and provider inventory with a strictly read-only STAGE profiler (1–5 samples). **No caching or performance optimization was introduced**. The initial real STAGE measurements are now recorded above in the latest project checkpoint (three same-process samples, median inventory 27.176 ms/max 366.372 ms for 192 providers), but a demonstrated sustained bottleneck and extension/schema invalidation proof still do not exist. A66-05 remains open until justified by data. Release gates and ML-001 remain open.


**2026-10-20 development continuation — Bundle 3/4:** Based on the user's
confirmed local `dc3baf2` and its green DDEV QA results (321 PHPUnit tests,
2,326 assertions), prepared a new guarded DDEV STAGE disposable CRUD proof for
ContactLayout and ReportInstance; local opt-in and wrong-webroot tests pass.
This **does not yet prove STAGE CRUD**, and absence of ContactLayout samples in
the last read-only probe remains documented. Bundle 4 now has progressively
enhanced native mode radio cards, lazy item/selection counts, picker keyboard
focus restoration and stale async-response protection. Existing scope POST,
permissions, dependency warnings, bulk controls and selected/unmanaged modes
remain unchanged. DDEV full QA, read-only confirmation, guarded STAGE CRUD and
logged-in responsive/keyboard browser smoke are the next acceptance steps.
**Both bundles remain pending runtime sign-off until that evidence is supplied.**


**Current baseline:** `0.1.0-alpha68.6.1-core` (see `info.xml`).
**Last scope decision:** 2026-10-16. The developer requested a **development-first** sequence: fix real defects, finish existing provider/UI functionality, and use *basic targeted smoke checks* now. The formerly approved mandatory Beta2 release-verification gates remain mandatory **before release**, but their scheduling is deferred to feature freeze. Never mark them passed without evidence.

**2026-10-20 Bundle 3 follow-up (current source checkpoint):** The newly
uploaded `087c458` checkout is clean and includes the successful QA scope
change and ReportInstance warning fix. Subsequent user-run DDEV `qa:fast`
was green (321 tests / 2,326 assertions; PHPStan, PHPCS, compatibility and
Composer security audit passed). The STAGE read-only probe confirmed three
ReportInstances in sync without warnings; it remains **INCOMPLETE** because
no ContactLayout fixture exists. A dependency-free regression then confirmed
that ContactLayout validation incorrectly accepted a local numeric
relationship reference (`related_rel: 44_ab`) inside saved YAML. The narrow
provider-only guard rejects it before Import and retains semantic conversion
on Export. Twenty in-memory reference portability and idempotency checks
now pass, including target IDs that differ from source IDs. The new source
change still needs DDEV full QA and real-runtime provider certification.
**Bundle 3 is not certified; ML-001 and release gates remain open.**

**2026-10-20 latest provider evidence (supersedes the earlier status below):**
Guarded DDEV STAGE Bundle 2 disposable CRUD **PASS**, including Tag,
UFGroup/Profile and UFField create/update, round trips, repeat import,
no-delete preservation, reference checks and zero-residue cleanup. Local
`main` commit `9f735b34` contains the first Bundle 3 provider corrections.
User DDEV `qa:fast` reached PHPCS after 321 PHPUnit tests / 2,326 assertions
and failed on test-fixture formatting only. The user has now requested
production-only QA static/style scans, excluding `tests/` without disabling
tests. Bundle 3 STAGE read-only ReportInstance samples=3 PASS with an
undefined `name` warning; no ContactLayout sample exists, therefore Bundle 3
is **still incomplete**. The narrow report warning fix has a red/green
regression but requires rerun on STAGE. ML-001 remains P0/open and all Beta2
release gates remain mandatory.

**2026-10-20 prior provider checkpoint (historical):** The uploaded
`0fcf6d65` source has a clean archived Git tree; user-run DDEV `qa:fast`
passed 321 tests / 2,326 assertions on that pre-change baseline. STAGE
Tag CRUD passed; UFGroup/Profile repeat import failed `update=1, skip=0`,
with successful disposable cleanup. Bundle 2 now has a narrow comparison
regression for explicitly declared comma-serialized `group_type` values
(6 in-memory red/green checks), and its real STAGE repeat-preview is guarded
before a second write. This is **not** a successful STAGE rerun or permission
to skip Profile/UFField CRUD proof. Bundle 3 source work has begun: Contact
Layout and ReportInstance only count completed writes, and failed APIv3 report
reads block unsafe creates. 29 in-memory red/green checks pass. A read-only
STAGE Contact Layout/Report probe is prepared, not executed. The present
source changes still require full DDEV QA and real-runtime provider evidence.
**Do not label Bundle 2 or Bundle 3 certified yet.** ML-001 remains P0/open.

**Latest verified user checkpoint (2026-10-16, commit `a381b02`):** DDEV
`qa:fast` passed 319 PHPUnit tests / 2,304 assertions, all five mutation
proofs, PHPStan and applicable compatibility checks; `composer standards`
also passed. The final rerun exposed a missing Composer alias
`@compatibility8.0`, not a product regression. This QA maintenance patch
adds the missing alias, validates Composer `@script` references before costly
checks, enforces `@standards` in `qa:fast`, and scopes compatibility scans away
from PHPStan-generated caches. **A new DDEV rerun remains pending for this
patch.** A67-07 is implemented; **ML-001 remains P0/open and is the next
product work**. Do not repeat broad code formatting or QA refactoring.

**2026-10-19 continuation:** The latest user-uploaded DDEV source remains at
`a381b02` on `main`, with `origin/main` also at that commit. Its only tracked
working-tree change before this update was whitespace in `composer.json`.
The 2026-10-16 QA-prevention patch applies cleanly to that baseline; this
source checkpoint integrates it and includes a read-only ML-001 runtime probe.
The assistant's source-only checks are not equivalent to a completed new
DDEV `composer run qa:fast`; ML-001 has not been fixed. Keep Bundle 1 active.

**Latest development checkpoint (2026-10-19):** The user-supplied DDEV
`composer run qa:fast` now passes (319 PHPUnit tests / 2,304 assertions;
mutation, PHPCS, PHPStan and scoped compatibility checks). A synthetic
ML-001 fixture reproduces false locale-only drift while preserving real
edit detection; this is a source-level test, **not** a functional fix, so
ML-001 stays mandatory/open. The English-only STAGE and non-bootstrapping DEV
cannot certify multilingual behavior. Proceeding with Bundle 2: corrected
Tag/UFField attempted-write accounting; a dependency-free 26-check
red/green smoke verifies counts and errors with simulated API4 failures
and successes. DDEV QA and real CRUD validation for this new change are pending.
Do not reopen the structural refactor or treat ML-001 as waived.

Authoritative original release priorities: [BETA2_PRIORITIES.md](BETA2_PRIORITIES.md).
Detailed pending items and historical decisions: [PROJECT_STATUS.md](PROJECT_STATUS.md).

## Phase 0: structural groundwork (source-layout checkpoint complete)

**R0.1 (2026-10-16):** Extracted real collaborators `ConfigIgnoreRules` and
`ExtensionProviderIdentity` with a 26-check boundary smoke. This work was
committed at `b8463cafd011e52a10e8c0f1d5e20f0ae95a1a26`.

**R0.2 (2026-10-16, committed at `57869a9`):** Split the remaining
stateful orchestration and long provider/UI helper methods into 34 narrowly
named concern traits across the six largest production classes. This keeps
existing constructors, private state, public APIs and import/queue safeguards
on the same objects, with **440/440 method bodies byte-for-byte preserved**.

| Production source | R0.1 lines | R0.2 lines |
|---|---:|---:|
| `Service/ConfigManager.php` | 5,632 | 114 |
| `Handler/ExtensionHandler.php` | 3,248 | 72 |
| `Handler/AbstractHandler.php` | 979 | 418 |
| `UI/MainPage.php` | 992 | 269 |
| `UI/Presenter.php` | 934 | 280 |
| `Handler/EntityDefinitionHandler.php` | 760 | 572 |

The largest production PHP file is now 608 lines; no test suite or fixture was
split without a functional reason. The 1,247-line frontend controller
was also divided into a 729-line main UI script and a 531-line Settings/scope
module. Node basic initialization behavior and AssetLoader order were checked
before/after, without adding a JS build process. The 34 traits are **organizational units
sharing their owning object's state**, not independent/isolated business
services. This avoids changing override semantics, safe import phases,
transaction/lock ownership, cache behavior and API query boundaries during a
large structural migration. Follow [CODE_STRUCTURE.md](CODE_STRUCTURE.md) to
find the new owner of each method and to avoid future spaghetti growth.

**Verified locally:** Reflection signatures 440/440 unchanged, method body
SHA-256 text equality 440/440, 26-check prior smoke, new 72-check composition
smoke, PHP syntax, Alpha62/63/64/68 and available frontend contracts.
**Not verified:** Full DDEV/Composer PHPUnit and real CiviCRM browser/API4
flows. This is not a Beta2 feature, provider capability expansion, schema
migration, or ML-001 functional fix. Do not classify as release-ready.

**Development next:** start Feature Bundle 1 (ML-001 then A67-07) and edit only
the relevant, now smaller source concerns. Keep basic functional tests for
each change and defer the documented advanced release matrix until feature
freeze, without waiving its requirements.

## Feature bundle 1: Synchronization correctness (in progress — 2026-10-16)

**R0.4 regression gate:** The R0.3 DDEV follow-up passed 319 PHPUnit tests,
but `qa:fast` then stopped at a pre-refactor provider mutation target.
Both mutation scripts have been retargeted to their relevant source traits;
a target and restoration smoke was added. Remaining post-PHPCBF coding-standard
violations were addressed at their recorded sites, and 3 PHP 8-only parameter
trailing commas were removed for PHP 7.4. All source-only checks pass but full
PHPUnit mutation red/green and fresh PHPCS remain unverified. **Do not start
Bundle 2 until these are confirmed.** ML-001 remains separately open.

**Regressions must be cleared before resuming this bundle:** The R0.2 class
split inadvertently dropped the private ConfigManager `isIgnoredPath()` entry
point, although internal scope/export/import code still called it. R0.3 adds a
small compatibility bridge to the centralized ignore engine, repairs stale
PHPUnit source checks, and extends no-runtime smoke coverage. This hotfix is
**locally verified but DDEV PHPUnit confirmation is pending**; do not interpret
it as an ML-001 fix. Complete the functional regression checkpoint before
new provider work. Coding-standard cleanup is a separate bounded maintenance
change rather than an automatic whole-repo reformat.


**A67-07 implementation:** `DiffStateClassifier` distinguishes `NO_BASELINE`
from baseline-verified `IN_SYNC`. Both compact streaming and detailed diff
paths report the count of matching-but-unbaselined objects, even if no changed
file is displayed. Cached site-wide health now warns when a provider scan is
incomplete, baseline persistence fails, selectors remain unresolved, or a
comparison lacks an accepted baseline. A type-filtered diff cannot replace the
last site-wide health report. Watch-only, ignore-only, initial-export, actual
changes, and confirmed clean baselines retain their previous behavior.

**Evidence:** New dependency-free `tests/ci/sync-state-health-smoke.php` has a
red-before/green-after result (21 assertions), including both full and compact
state enrichment. Basic syntax/contract checks passed; full runtime QA is
not claimed. No database schema, provider capability, YAML or import change.

**ML-001 remains open:** The CiviCRM Afform API may return active-language
values, including programmatically generated forms. Genuine translated edits
must remain detectable. Do not drop labels/titles or make the pending ML-001
test green by hiding values. The next code change needs verified raw Afform
source or translation catalog data from a disposable multilingual runtime.
See `ML-001-RUNTIME-EVIDENCE.md` for the exact read-only comparison.

## Feature bundles (structural prerequisite completed; implement in order)

| Order | Bundle | Included backlog IDs / work | Delivery and basic verification |
|---:|---|---|---|
| 1 | Synchronization correctness | ML-001 / A68-ML01, A67-07, sync-status wording | Fix locale-only false drift *without hiding actual translation edits*; focused canonical comparison and one read-only multilingual CiviCRM check |
| 2 | Core provider stability | A69-01 Tags, A69-02 UF Groups/Fields, safe CRUD review, handler validation | Basic export/diff/import of disposable records; never enable delete without independent proof |
| 3 | Contrib providers and references | A69-03 Contact Layout, A69-04 Reports, reference and dependency resolution, readable diffs | Read/preview with installed contrib versions; safe create/update only when supported |
| 4 | Settings UX | A67-03 management modes, A67-04 counts, A67-06 keyboard/focus, remaining A67-05 | Basic Settings operation and role/capability smoke; avoid eager provider collection |
| 5 | Import/operator experience | UX-002, import-readiness errors, CLI UX, deployment guides, sanitized processor policy | Actionable messages; confirm fail-closed blockers and no unrelated record writes |
| 6 | Performance only when measured | A66-05 discovery cache, remaining pagination/lazy loading | Measure first; add invalidation only for demonstrated problems |

## Per-bundle change contract

1. Inspect only the relevant services, nearby callers, tests, and docs. Establish source baseline and distinguish a real defect from an unverified feature.
2. Make one coherent change with minimal scope. Refactor when a large file or fragile coupling makes the feature risky; do not rewrite unrelated modules.
3. Verify the original behavior and the changed path with focused unit/contract checks and basic functional smoke. For imports, verify disposable test data and unrelated-record preservation. Record unverified runtime scenarios explicitly.
4. Review source and diff for broken compatibility, security, accidental mode changes, secrets, generated files, and regression-prone branch growth.
5. Update this tracker plus affected README/PROJECT_STATUS/CHANGELOG and specific QA or API docs. Preserve historical release notes and the protected Beta1 release.
6. Deliver **one full source ZIP** that preserves Unix executable modes and excludes `.git`, `__MACOSX`, `.DS_Store`, caches, and dependencies. No commit, push, tag, deployment, or Beta2 publication without explicit approval.

## Deferred mandatory release gate (after feature freeze)

QA-003 final DDEV reconciliation; A65-07/08/09/10; A66-06; A68-09; A69-05; B2-01 to B2-10 as applicable; PHP/CiviCRM/CMS compatibility and installable runtime ZIP. Use the authoritative priority document for exact acceptance evidence. None is waived by the development-first scheduling decision. Optional Phase 2 features are excluded from Beta2 scope unless separately approved.

## Evidence for checkpoint R0.1

- Pre-change and modified source passed the same 26-check independent PHP regression fixture (FakeCivi settings and private/public entry-point checks).
- PHP 8.4 syntax valid for 145 files; Alpha62 (31), Alpha63 (53), Alpha64 (23), Alpha68 UI (64), Alpha68 lifecycle state (20), lifecycle behavior (16), browser workflow contract (43), and Drupal login resolver (2) passed in the authoring container.
- `composer`/`phpunit` and a full bootstrapped CiviCRM runtime are not installed in the authoring container; **full Composer QA, PHPUnit, real browser and CiviCRM functional paths were not executed here**.
- Source package must be inspected separately for files and executable modes after ZIP creation.
