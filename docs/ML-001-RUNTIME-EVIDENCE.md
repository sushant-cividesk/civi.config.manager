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
