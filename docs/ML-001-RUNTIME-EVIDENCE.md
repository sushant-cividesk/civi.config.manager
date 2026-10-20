# ML-001 multilingual Afform: evidence required before a safe fix

## 2026-10-20 — Real STAGE reproduction and source-locale candidate fix

**Measured user-executed STAGE result:** CiviCRM `6.17.2`, database `dcivistagecivi_jwy32`. API4 honored both `en_US` and `fr_FR`; the caller's locale was restored. `CRM_Core_I18n::isMultiLingual()` returned `false`, and the legacy `enableMultipleLanguages`/`enabledLanguages` settings were `null`. This site has working gettext/UI locale switching but **not legacy multilingual database mode**. Among 139 shared existing Afforms, 77 varied by locale (including 1 layout change). The synthetic process-only Afform title switched `Save` to `Enregistrer`, with identical layout. The first ten varying examples had no discoverable Afform source file, so their programmatic source/translation provenance remains unresolved.

**Root cause at Configuration Manager boundary:** The built-in `formbuilder-afforms` GenericApi4CollectionHandler reads locale-dependent `Afform.get` values (`title`, `layout`) and the canonical fingerprint compares strings literally. API4's `setLanguage()` is an explicit per-action locale selector. CiviCRM's own `civi.afform.get` hook permits generated translated titles. Thus two user sessions can produce different YAML bytes from the same source. The fix makes built-in Afform API4 read actions use the fixed gettext source locale `en_US` for both streaming export and existing-record Import lookup. It preserves all original source strings and the strict generic hash, so a source-title edit still changes the fingerprint. The API fails closed if it cannot set the requested locale; other Generic API4 types are untouched. No automatic rewrite of existing Saved Config is performed.

**Standalone regression:** `php tests/ci/ml001-afform-stable-locale-smoke.php` was RED before the fix (`User locale changed exported Saved Config`) and GREEN after. It exercises actual handler Export, Diff, Import preview and a genuine source-title edit, using an API4 action stub. Source read and stage safety guards are in `qa:fast`.

**Required real DDEV STAGE verification (no persisted test data):**

```bash
cd /var/www/html/build/dcivi-stage/web
ML001_SOURCE_LOCALE_TEST=YES_STAGE_ONLY   drush php:script /var/www/html/ext/civi.config.manager/tests/manual/ml001-stage-source-locale-smoke.php
```

This script checks the exact STAGE path and database before using a process-local `civi.afform.get` fixture. It compares only hashed Export results under two caller locales, then makes an **in-memory source-title change** and requires Diff to detect it. The event listener and original language are restored in `finally`; existing Afforms, files, YAML, translations and settings are never written. Expect `ML-001 STAGE SOURCE-LOCALE EXPORT/DIFF: PASS`, `locale_only_export_differences: 0`, and `genuine_source_edit_detected: true`. A failure stops acceptance without masking a potential provider bug.

**Outstanding release obligations:** `en_US` is a source-locale *read policy*, **not** full translation synchronization. Changes made only to CiviCRM `Translation`/`TranslationSource` records (including French-only translated string edits) are not represented by the present Afform YAML schema and may not affect the `en_US` projection. Those changes must be exported/synchronized through a separately proven translation-aware mechanism; do **not** claim detection, auto-import them, remove fields from hashes, or enable dangerous write fallback. Confirm real translated-edit detection, cross-site no-write round-trip with independent IDs, and a controlled migration of any existing French-localized YAML before closing ML-001 or approving Beta2. The old `tests/pending/ml001/MultilingualAfformCanonicalizationTest.php` intentionally compares raw translated strings without provenance and may remain red: it is historical evidence, not a legitimate shortcut for the handler to ignore text.

## 2026-10-20 — ML-001 multilingual STAGE test harness (NOT a product fix)

The developer reports that the disposable DDEV STAGE site has now been configured
for multiple languages. That setting has not yet been independently verified.
The prior ML-001 evidence was from La Cause, not from the newly configured STAGE.
Before modifying canonical fingerprints or Afform Import, establish the exact
locale-dependent fields and their authoritative source; do not erase or ignore
translated titles/labels to force the intentionally red test to pass.

Run once from the DDEV STAGE **Drupal webroot** (no `ddev ssh` required if you
are already in the container):

```bash
cd /var/www/html/build/dcivi-stage/web
ML001_STAGE_PROBE=YES_STAGE_ONLY ML001_LANGUAGES=en_US,fr_FR \
  drush php:script /var/www/html/ext/civi.config.manager/tests/manual/ml001-stage-locale-probe.php
```

This read-only probe requires the exact STAGE root and a `dcivistage*` CiviCRM
database. It attaches a randomly named `civi.afform.get` hook-supplied Afform
**only in the running PHP process**. Its title is resolved by CiviCRM's own
`ts('Save')`, allowing actual API4 `setLanguage()` translation behavior to be
observed without fake hand-coded EN/FR equivalents. It also compares at most
251 existing Afform API rows by stable machine name, prints only hashed
existing titles/layouts and up to 10 source-file SHA256 sets, and immediately
removes the temporary event listener. **No DB records, local Afform files,
translations, scope, YAML, settings or locale configurations are written.**
It cannot by itself test a real translated edit or a safe Import.

The report must show `requested_locales_honored: true`, distinct negotiated
locale names, and `active_locale_restored: true` before any locale difference
is meaningful. `temporary_fixture.title_differs` may be false if the installed
gettext catalog returns the same string in both locales; in that case examine
`existing_afforms.locale_varying_count` and confirm the STAGE language packs.
Source hashes can demonstrate that **bytes stayed unchanged while API values
changed**, but they do not establish semantic equivalence or cover hook-only
forms. Do not run the pending red canonicalizer test as if it were a release
validation or report Bundle 1/ML-001 completed before real translated-edit
and safe no-write round-trip evidence.

The negative guard test (`node tests/ci/ml001-stage-locale-guards.js`) runs in
`composer qa:fast`. Actual DDEV STAGE diagnostics remain an explicit manual
step. Based on those results, implement a targeted, provenance-backed
Afform normalization that preserves genuine translations; prove both
locale-only no-drift and real-edit detection before removing the P0 block.


Status: NOT FIXED. This document and the red regression test are investigation
artifacts only; they do not authorize a Beta2 release or a production import.

Priority: **P0**, mandatory before Beta2. See [Beta2 priorities](BETA2_PRIORITIES.md).

## Observed reproducer

On La Cause multilingual WordPress + CiviCRM, 181 Afform Saved Config files
were exported and 104 FormBuilder changes were reported when active language
context differed. Examples: `Nom du foyer` / `Household Name` and
`Mettre à jour « Information Contact »` / `Update Information Contact`.

The current Afform GenericApi4CollectionHandler selects `name`, `title`,
`layout`, and other fields from API4. Canonicalizer v2 hashes ordinary strings
verbatim. Neither layer reads a language-independent translation map.

## Requirement and rejection criteria

- Locale-only changes must produce zero changed Afforms and zero rewritten files.
- Any real edit to a translated value must remain detectable and portable.
- No hard-coded EN/FR translations, no unconditional removal of title/label,
  and no fuzzy text or translation dictionary matching.
- If the runtime supplies only one localized string, do not claim semantic
  equivalence or enable automatic writes based on that assumption.

## Additional source evidence (2026-10-16; not runtime proof)

CiviCRM's Afform API4 `Get` action merges scanner metadata with forms supplied
through the `civi.afform.get` hook. Hook-supplied forms can call `ts()` for
titles, so identical underlying definitions can emit locale-dependent API
strings. The Afform save path writes the source layout (`.aff.html`) and
metadata, while layout translations use `TranslationSource` and locale-specific
`Translation` records; entity-field translation uses a separate mechanism.
Neither a pair of localized API4 strings nor a source-text match is enough to
prove two translated edits equivalent. **Do not normalize this away by ignoring
`title`, `label` or layout text.**

Sources (upstream implementation and developer guide):
- https://github.com/civicrm/civicrm-core/blob/master/ext/afform/core/Civi/Api4/Action/Afform/Get.php
- https://github.com/civicrm/civicrm-core/blob/master/ext/afform/core/Civi/Api4/Utils/AfformSaveTrait.php
- https://docs.civicrm.org/dev/en/latest/translation/entity/
- https://docs.civicrm.org/dev/en/latest/hooks/civi.afform.get/

A minimal **read-only** DEV evidence capture for a *known existing* form
`af_household` is: use API4 `Afform.get` twice, in two configured languages,
selecting `name`, `title`, `layout`; then inspect the authoritative scanner
source file/metadata and corresponding active translation-source rows. Do not
edit the Afform, call create/update, or copy client data into test fixtures.
Only if provenance proves equivalence should the handler compare a stable
source/translation representation. A dynamically generated Afform without
source identity must remain unverified for semantic locale matching.

## Read-only DDEV evidence collector (2026-10-19)

From an existing CiviCRM-buildkit DDEV extension shell on a **disposable**
multilingual runtime, choose an existing machine name and two enabled languages:

```bash
ML001_AFFORM_NAME=af_household ML001_LANGUAGES=en_US,fr_FR \
  cv scr tests/manual/ml001-afform-evidence.php
```

The script only calls read-only API4 `get`/`getFields` and the Afform scanner,
requests locale-specific data via API4 `setLanguage()`, and prints title,
layout/API4 hashes, known enabled languages, scanner-source availability and
translation-entity field availability. It does not fetch full translation rows,
change site/user locale settings, edit forms, or run an Import. Exit code 3
means a second locale was not supplied; exit code 4 means a requested form
could not be read or its API failed. The probe can succeed without proving
that locale negotiation worked, so verify enabled locales and repeat with
an affected form and a separate non-EN/FR pair. A hook-only form may have no
file-source provenance. The script is diagnostic evidence, **not a fix**.

Do not run this against PROD or attach raw client layouts/translation catalog
records to tickets. After examining results, inspect the true untranslated
source and translation provenance with the installed CiviCRM implementation
before changing the canonicalizer, YAML or fingerprint format.

## Synthetic fixture verdict (2026-10-19; no database writes)

A source-level test called the actual `Canonicalizer` with synthetic
`af_test_multilingual` values, keeping the form name and structure the
same but varying the title and nested label across EN/FR:

| Comparison | Observed | Required |
|---|---|---|
| `Household Name` / `Household` versus `Nom du foyer` / `Foyer` | Different hashes | Same only **when authoritative provenance proves locale equivalence** |
| `Household Name` versus a genuine edit to `Household Details` | Different hashes | Different |

This reproduces the current literal-text drift and preservation of genuine
changes. It is **not proof that unrelated strings are translations**; fixture
assumptions cannot replace translation-source evidence. No source code or
canonical fingerprint semantics were changed. The latest real DDEV findings
were: `dcivi-stage` initializes CiviCRM 6.17.2 in `en_US` with no
multilingual languages configured; `dcivi-dev` fails during bootstrap with a
`civicrm_case_type.title` query mismatch. These infrastructure issues are
parked. **ML-001 remains open P0**, mandatory before Beta2 publication.

## Runtime evidence still needed

On a disposable copy of a representative multilingual CiviCRM environment:

1. Select one identified Afform (e.g. `af_household`) without modifying it.
2. Capture raw API4 `Afform.get` (`name`, `title`, `layout`) in two or more
   configured locales. Capture the active locale and enabled language settings.
3. Identify the authoritative *untranslated* Afform definition and/or complete
   translation catalog, using documented CiviCRM APIs/file storage, including
   how the real translator resolves fields nested under `layout`.
4. Capture whether a genuine edit to one translated title/label changes that
   authoritative representation, and whether import can preserve/edit it.
5. Prove export -> locale switch -> Synchronize -> import (no write if equal)
   -> re-export, then a genuine translated edit -> detection -> safe import ->
   canonical re-export in a second disposable site.
6. Repeat with another locale pair, not just English/French. Test missing
   metadata, extension-owned Afforms, custom Afforms and nested labels.

The deliberately red reproducer lives at
`tests/pending/ml001/MultilingualAfformCanonicalizationTest.php`.
Run it explicitly with `composer test:ml001:pending` (expected non-zero).
The ordinary PHPUnit suite does not discover `tests/pending/`. On older
checkouts the original `tests/phpunit/Unit/MultilingualAfformCanonicalizationTest.php`
may still exist after extracting a new ZIP over the old tree. The new PHPUnit
config excludes **only that exact obsolete path**; remove/move the obsolete
file after checking the working tree. These two payload-only assertions
expose the problem but cannot prove translation identity. Their expectation
is not an acceptable implementation shortcut without translation metadata.
Do not make the test green by dropping titles or labels. An ordinary green
`composer qa:fast` does not clear the mandatory Beta2 ML-001 gate.

After runtime discovery, add a focused Afform-specific normalization boundary
based only on trustworthy source translation metadata. Test it through the
actual export/diff/import APIs and make the canonical version migration
explicit if fingerprint semantics change.
