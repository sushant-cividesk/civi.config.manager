# Testing

This document records the current test expectations for Configuration Manager. Release history is maintained in `../CHANGELOG.md`.

## Standard Round-Trip Test

Use this flow for every handler that supports import:

1. Export YAML from a clean CiviCRM state.
2. Confirm Synchronize reports no pending changes.
3. Change one safe field in the CiviCRM UI or database.
4. Confirm Synchronize shows a focused field-level diff.
5. Use Export to write the CiviCRM change into YAML, then confirm the site is back in sync.
6. Revert the YAML field manually or from Git.
7. Use Import preview to confirm YAML will update CiviCRM.
8. Apply Import, complete the confirmation modal by acknowledging the warning and typing `IMPORT`, and confirm the CiviCRM UI/database value is reverted to YAML.
9. Confirm Synchronize reports no pending changes.

## Phase 1 Handler Matrix

Run the standard test for:

- Option Groups and Values
- Contact Types
- Relationship Types
- Location Types
- Financial Types
- Custom Groups and Fields
- CiviCRM Settings Allowlist
- Message Templates
- Dedupe Rules
- Scheduled Jobs
- SearchKit Saved Searches
- SearchKit Displays
- FormBuilder Afforms

Extensions need a separate test because extension enable/disable affects runtime code. Never test disabling Configuration Manager itself; the handler intentionally skips self-disable.

Payment Processors remain export/diff only and should not be tested as importable unless a future explicit import policy is added.

## SearchKit/FormBuilder Dependency Test

1. Create or select a Saved Search.
2. Create or select a Search Display for that Saved Search.
3. Create or select a FormBuilder Afform that references the Search Display.
4. Apply a temporary filter for only SearchKit Saved Searches and run Export.
5. Confirm related SearchKit Displays and FormBuilder Afforms are included in the export when available, and that the filter is cleared after export.
6. Confirm the Search Display YAML declares the Saved Search dependency.
7. Confirm the Afform YAML declares the Search Display dependency where detectable.
8. Remove or move one dependency YAML file in a test copy of the sync directory.
9. Run Validate and confirm a clear dependency error names the affected YAML file and missing dependency.
10. Restore the dependency file before import, or remove the dependent YAML files together if testing destructive deletion.
11. If deleting a Saved Search and its Display from YAML, confirm import deletes the SearchDisplay before the SavedSearch and finishes without a false error.

## Large Text Diff Test

For Message Templates, edit only a short marker inside `msg_html` or `msg_text`.

Expected behavior:

- Synchronize should report only the changed field.
- The modal should not flood the page with the entire template body.
- The changed text should be visible near the center of the preview and highlighted.
- Long preview values may be focused/truncated in the UI, while the YAML file keeps the complete content.

## CMS Smoke Tests

Before wider release, run the standard flow on:

- Drupal
- WordPress
- CiviCRM Standalone

For each CMS, verify UI access, API4 commands, sync-directory resolution, export, import dry-run, import apply, validation, ZIP download/upload, and CiviCRM status report notices.

## Recreate From YAML Test

For handlers that support create/update import, delete a non-critical test record from CiviCRM after export and then import it from YAML. Confirm the record is recreated with the YAML values. Note that CiviCRM may assign a new numeric database ID; dependency checks should rely on stable names/keys where available.


## Alpha33 validation note

Option group value validation allows CiviCRM core data where option value names may be reused with different stored values. Custom field option group references should be exported by `option_group_name` where possible so YAML is portable between environments; legacy numeric `option_group_id` YAML remains accepted for compatibility.

## Alpha37 tests

- Export a SearchKit display named `Table` under a saved search, reinstall the site, then import. Confirm it matches by saved search name plus display name and does not fail with an already-exists error.
- Confirm `extensions/civi.config.manager.yml` is ignored by default in Synchronize, Validate, Export, and Import.
- Add a path to Config Ignore and confirm it is excluded from changed-file and import previews.

## Alpha40 generic bundled extension config tests

Run these tests on sites where contributed/custom extensions are installed and enabled:

- Generic extension entities: create or edit a non-critical extension-provided API4/APIv3 config record, export, diff, import dry-run, import apply, and confirm it is restored without relying on local numeric IDs. Extension status/settings should appear in `extensions/<extension-key>.yml`; larger extension-owned API records should appear as split files under `extensions/<extension-key>/<api>/<entity>/<item>.yml` and be referenced from the extension file by `config_index`.
- Extension settings: confirm non-secret settings that can be attributed to an installed extension export inside `extensions/<extension-key>.yml`; confirm sensitive names such as passwords, secrets, tokens, and API keys are blocked.
- Dependency clarity: remove a required YAML dependency and run Validate/Import. The message should identify the owning file, missing dependency type/name, and whether Config Ignore or an older numeric-ID export is likely involved.
- Missing provider: disable/remove the provider extension on a disposable test build and confirm validation/import reports a clear missing-provider error instead of fataling.

## Alpha43 lifecycle and dependency metadata tests

- Site Identifier: install/enable the extension and confirm Settings shows a generated read-only Site Identifier. Export and confirm `manifest.yml` includes the same value. Clone the database to another environment and confirm the value remains the same.
- Cross-site guard: change the manifest site_id in a disposable copy and confirm Validate blocks import unless Experimental Cross-site Import is enabled.
- Reverse dependencies: export SearchKit/FormBuilder or custom-data dependencies and confirm dependency target files receive `required_by` metadata. Remove a dependent file in a disposable copy and confirm Validate reports stale reverse metadata as a warning.
- Project CLI wrappers: after install/enable, confirm `<project-root>/bin/civicfg`, `ce`, `ci`, `cdf`, and `cval` exist when the project bin directory is writable, do not overwrite non-managed files, and warn if the extension is disabled.
- UI buttons: smoke-test Synchronize, Import, Export, and Settings action buttons in RiverLea, Shoreditch, and the base CiviCRM theme.

## Automated GitHub QA

Automated tests now complement the manual scenarios in this document:

- `QA - Fast` runs syntax, unit, static-analysis, dependency-audit, coding-standard, and PHP-compatibility checks on pushes and pull requests.
- `QA - Full CiviCRM Extension` is manually triggered against a selected commit and pinned CiviCRM Standalone image.

The full run creates its own database, records, settings, YAML directory, Docker network, and browser fixture. It blocks outbound networking from the application stack, disables PHP mail delivery, verifies that Mailpit received no messages, restores changed settings, deletes fixture records, and removes all Docker volumes after the run.

See `docs/QA_AUTOMATION.md` for commands, current coverage, isolation rules, and the developer test-scenario contract.


## Alpha56 semantic identity, state, and CLI tests

Before accepting alpha56 on a real development project, verify:

- Two environments with different numeric database IDs export the same semantic configuration keys and canonical portable values.
- Renaming only a YAML filename does not appear as delete/create when the semantic key is unchanged.
- Changing a machine identity produces a possible-rename warning, does not auto-match the records, and blocks a real import while the create/delete pair remains unresolved.
- `ConfigManager.confirmIdentityAlias` records only a reviewed same-provider identity relationship; re-export/alignment is still required before import.
- `null`, empty string, integers, numeric strings, booleans, and floats remain distinct in canonical fingerprints.
- Runtime/sensitive ignore paths remove only the declared path and do not recursively remove same-named nested configuration fields.
- Ordered lists remain order-sensitive; only declared unordered paths become order-insensitive.
- Active-only drift, YAML-only change, synchronized change, non-conflicting divergence, and same-field conflict are classified correctly against an accepted baseline.
- `civicrm_civicfg_object_state` can be cleared and regenerated without losing portable YAML.
- Successful real export/import advances the baseline; diff/status alone does not.
- The extension-local `bin/civicfg` is authoritative; Composer sites get at most one managed `vendor/bin/civicfg`; non-Composer sites still work through the global dispatcher.
- The global dispatcher resolves the current site's extension at runtime, contains no project-specific extension path, never overwrites unrelated files, and remains installed until the last registered project is uninstalled.
- The contrib compatibility matrix explicitly reports FULL, PARTIAL, NO_PORTABLE_CONFIG, UNSUPPORTED, or ERROR rather than silently treating missing portable config as failure.

The historical Alpha43 project-wrapper expectations above document that alpha's behavior only. Alpha56 intentionally replaces those development-only wrappers before public release.
