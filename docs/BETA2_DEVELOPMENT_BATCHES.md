# Beta2 development bundles — implementation tracker

**Current baseline:** `0.1.0-alpha68.6.1-core` (see `info.xml`).
**Last scope decision:** 2026-10-16. The developer requested a **development-first** sequence: fix real defects, finish existing provider/UI functionality, and use *basic targeted smoke checks* now. The formerly approved mandatory Beta2 release-verification gates remain mandatory **before release**, but their scheduling is deferred to feature freeze. Never mark them passed without evidence.

Authoritative original release priorities: [BETA2_PRIORITIES.md](BETA2_PRIORITIES.md).
Detailed pending items and historical decisions: [PROJECT_STATUS.md](PROJECT_STATUS.md).

## Phase 0: structural groundwork (source-layout checkpoint complete)

**R0.1 (2026-10-16):** Extracted real collaborators `ConfigIgnoreRules` and
`ExtensionProviderIdentity` with a 26-check boundary smoke. This work was
committed at `b8463cafd011e52a10e8c0f1d5e20f0ae95a1a26`.

**R0.2 (2026-10-16, awaiting maintainer commit):** Split the remaining
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
