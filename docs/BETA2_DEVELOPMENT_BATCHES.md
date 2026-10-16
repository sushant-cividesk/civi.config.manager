# Beta2 development bundles — implementation tracker

**Current baseline:** `0.1.0-alpha68.6.1-core` (see `info.xml`).
**Last scope decision:** 2026-10-16. The developer requested a **development-first** sequence: fix real defects, finish existing provider/UI functionality, and use *basic targeted smoke checks* now. The formerly approved mandatory Beta2 release-verification gates remain mandatory **before release**, but their scheduling is deferred to feature freeze. Never mark them passed without evidence.

Authoritative original release priorities: [BETA2_PRIORITIES.md](BETA2_PRIORITIES.md).
Detailed pending items and historical decisions: [PROJECT_STATUS.md](PROJECT_STATUS.md).

## Phase 0: structural groundwork (active; not a feature release)

The user-supplied ZIP on 2026-10-16 has two oversized source files:

| Source file | Original size | Refactor-first concern |
|---|---:|---|
| `Civi/ConfigManager/Service/ConfigManager.php` | 5,887 lines | Mixes scope/ignore policy, export, diff, review plans, import, queues, and status |
| `Civi/ConfigManager/Handler/ExtensionHandler.php` | 3,311 lines | Mixes discovery, API3/API4 transport, provider identity, and import/delete handling |

**Checkpoint R0.1 — extracted pure/cohesive rules, implemented 2026-10-16:**

- `Service/ConfigIgnoreRules.php` owns built-in and configured ignore rules, legacy setting migration, whole-file matching, field-value filtering, and ignored-only diff filtering.
- `Handler/ExtensionProviderIdentity.php` owns *pure* portable identity selection, confidence, uniqueness, and multiplicity analysis. It performs **no API queries, writes, or caching**.
- `ConfigManager` and `ExtensionHandler` retain their existing public and reflection-tested method entry points. The collaborators are lazily initialized, so existing subclasses that intentionally skip parent constructors keep their existing behavior.
- Runtime provider duplicate queries, row caches, create/update/delete decisions, synchronization behavior, and CiviCRM settings contracts are **unchanged**. There is no ML-001 functional fix in this batch.
- Added `tests/ci/refactor-boundaries-smoke.php`, runnable via `composer test:refactor-boundaries` and early in `composer qa:fast`. It requires only PHP and in-memory FakeCivi settings. The same checks were run against pre-change Git HEAD to establish a baseline.
- No database mutation, changes to generated YAML, new permission, provider-capability expansion, or version bump.

**Not complete:** The two original coordinator files are **still oversized**. R0.1 reduces coupling in focused rule layers, but does not certify their remaining 5k+/3k+ lines as maintainable. **Continue structural decomposition before layering new logic into these classes.** Prioritize the following seams, one behavior-preserving extraction at a time, with existing callers/tests preserved:

1. `ConfigManager`: separate scope/picker presentation from stateful export/import orchestration; evaluate project/path resolution as its own service without breaking subclass overrides or CMS-root fallback behavior.
2. `ConfigManager`: separate queued export staging and compact snapshot mechanics from import/review-plan orchestration; keep atomic publish/rollback and current public API stable.
3. `ExtensionHandler`: isolate metadata-only API3/API4 provider discovery from collection reads and mutation operations; preserve fail-closed admission and cache invalidation. Do not move data-write operations together with diagnostic discovery.
4. Decompose only where dependency analysis shows a cohesive boundary. **No broad traits, rename churn, auto-generated thin wrappers, or speculative caching just to reduce line counts.**

## Feature bundles (on hold until structural boundaries are suitable)

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
