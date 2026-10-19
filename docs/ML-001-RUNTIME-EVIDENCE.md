# ML-001 multilingual Afform: evidence required before a safe fix

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
