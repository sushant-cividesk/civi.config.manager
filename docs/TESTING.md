# Testing

This document records the current test expectations for Configuration Manager. Release history is maintained in `../CHANGELOG.md`.

The frozen eight-rule test contract, milestone checklist, and evidence state live in [`PROJECT_STATUS.md`](PROJECT_STATUS.md). No important test is complete merely because the extension returned `ok`, a stub passed, a source string existed, or scenario YAML was structurally valid.

## Alpha65 real import-blocker requirement

`tests/integration/ImportBlockerSafety.php` reproduces an OptionValue whose YAML machine name changes while its stable value remains the same. It runs through `ConfigManager::import()` against disposable CiviCRM and requires both preview and confirmed apply to block with zero writes. Its independent oracle directly queries OptionGroup/OptionValue, counts contacts and message templates, fingerprints a secret sentinel, and hashes unrelated YAML before/after.

Run it as part of:

```bash
composer qa:real-runtime
composer qa:real-runtime-ui
```

The evidence file is `tests/ci/artifacts/import-blocker-safety.json`. Before accepting the test, create a disposable copy, disable the critical fail-closed behavior, prove this test fails, restore the behavior, and prove it passes. Record both run identifiers in [`PROJECT_STATUS.md`](PROJECT_STATUS.md). Never mutate a client or shared checkout.

## Alpha66 provider-inventory requirement

Opening provider inventory through the service/API4 boundary must not execute provider collection reads or inspect managed YAML. The unit test uses an independent API4 read trap. `tests/ci/mutation-provider-inventory.sh` deliberately inserts the forbidden read, requires the test to fail with that exact oracle, restores the source, and requires green. The disposable CiviCRM suite also invokes `cv api4 ConfigManager.providerInventory`; provider-specific real-runtime evidence is still required before new provider capabilities are advertised.

## Alpha67 provider-admission and dual-browser requirement

Automatically discovered API4 providers must pass these metadata-only gates in order before any generic management capability can be assigned:

`discover -> classify -> portable identity -> writable projection -> reference mapping -> capability`

`ProviderAdmissionPolicyTest` supplies independent fixtures for business-data rejection, ID-only identity rejection, sensitive writable fields, unresolved references, and an explicitly mapped semantic reference. `tests/ci/mutation-provider-admission.sh` deliberately changes the reference proof to trust every reference; the unmapped-reference regression test must go red, the source is restored, and the same test must return green.

Browser validation uses the existing JavaScript Playwright + axe suite against the disposable CiviCRM HTTP runtime. One browser stack is sufficient here because the independent evidence comes from the real-runtime integration/API/CLI/filesystem assertions, mutation proofs, and the browser-facing JavaScript flow—not from maintaining the same browser scenario twice in different languages.

## Browser QA entry point

Use `composer qa:browser` from a Docker-capable host checkout. It creates the disposable CiviCRM runtime, seeds the browser fixture, runs Playwright + axe, cleans the fixture, checks runtime logs/network isolation, and verifies source immutability. Browser QA is not exposed through the production `civicfg` CLI.

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
- CiviCRM Settings (one YAML file per allowlisted setting)
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


## Alpha59 scope UI and compatibility tests

In addition to the alpha58 scope safety checks, verify:

- Opening Settings does not enumerate active configuration records or extension-provider records.
- Changing a scope row to `Manage selected items` reveals the item controls; switching to `Monitor only` or `Ignore` hides them.
- Opening `Choose items` makes exactly one lazy request for that type and displays current human-readable CiviCRM items.
- Applying picker selections stores semantic `key:` selectors instead of local numeric IDs.
- A configured selected item that is temporarily missing stays visible/preserved rather than being silently dropped by the picker.
- The `civicrm.settings.php` example updates from current scope choices and a code-owned `civicfg_scope` override remains read-only.
- Full relative `path:` selectors match the same item as the UI picker.
- Healthy extension-provider YAML with an ambiguous provider identity shows compatibility information, not a validation warning; automatic writes remain blocked.
- A real extension status drift clearly shows the YAML state and active CiviCRM state.
- Typing a picker search hides non-matching rows, updates the visible count, and clearing the search restores the full list.
- The reviewed cross-site import switch is blocked by default for a foreign manifest, can be enabled deliberately, and blocks again after being disabled; the same policy is available through API4 and `civicfg cross-site-import`.
- `civicfg scope`, `scope-items`, and `scope-set` use the same effective policy as the Settings UI, including rejection of selectors outside selected mode and settings-file-owned scope.
- A contributed provider with a strong identity and zero matching target rows is treated as a create candidate; one match is update-safe and duplicate matches block automatic writes.
- Nested contributed-provider configuration survives export/import cleaning while only top-level runtime IDs/timestamps are removed.
- The pinned SQLTasks 3.0.0-alpha3 real fixture exposes native API4 `SqlTask`, which is the preferred canonical provider and must remain importable with create/delete capability. The reviewed API3 `Sqltask` + BAO `generator()`/`exportData()` adapter remains a fallback when API4 is unavailable; a write-safe task YAML missing on the target must not be downgraded to ambiguous merely because the target currently has zero matches.
- SQLTasks import strips read-only/computed API3 collection fields using the provider create specification while preserving writable task fields and nested `config` actions.
- A filtered SQLTasks provider import preserves the virtual subtype through UI preview/apply and CLI import, validates/applies only that provider, and is not blocked by unrelated extension-provider YAML errors.

## Alpha61 first-run scope and Settings UX tests

In addition to the existing scope and provider checks, verify:

- A genuinely fresh install sets the unconfigured scope default to `Ignore`; no configuration type is exported, imported, deleted, or watched until an administrator opts it in.
- On Synchronize, an all-Ignore or empty-selected scope must show **Setup Required**, never **In Sync**; managed Export/Import/Validate controls and the watch-scan action must be absent until their corresponding scope is configured.
- A watch-only scope must show **Monitoring Only** and keep **Scan Watched Config** available without implying that a managed YAML baseline exists.
- A managed scope with no YAML baseline must show **Initial Export Required**; **In Sync** is valid only after a managed baseline exists and the managed diff is empty.
- An upgraded installation with no `civicfg_scope_default_mode` marker keeps the historical `Manage everything` fallback.
- An explicit per-type scope policy always overrides the fresh-install default.
- The Settings page opens both **What should Configuration Manager manage?** and **Advanced settings** by default and allows either section to be collapsed and reopened.
- Every scope card has a bulk-selection checkbox; **Select all**, bulk mode selection, and **Apply** update only the visible form until **Save settings** is used.
- Any unsaved scope mode/selector/watch change shows an **Unsaved scope changes** warning explaining that Export/Import/Validate/Synchronize still use the last saved policy; the nearby **Save scope changes** button persists the same form as the existing bottom Save settings action.
- Switching `extensions` from `Ignore` to `Manage everything`, saving, and exporting must write `managed_scope.extensions.mode: all` plus extension status YAML. If one contributed provider cannot be read, safe extension files may still be written, but the export/diff must report an error, preserve stale YAML, and must never report **In Sync**.
- Bulk **Manage selected items** reveals the existing item-selection controls without implicitly selecting any configuration item.
- `civicrm.settings.php` scope ownership keeps individual and bulk scope controls read-only.
- Playwright writes review screenshots for the expanded Settings layout, bulk Ignore state, first-run/no-managed-config guidance, and the Synchronize **Setup Required** state (`sync-setup-required.png`) under `tests/ci/artifacts`.
- `composer qa:full` runs the isolated Docker suite without browser tests and `composer qa:full-ui` runs the same stack with Playwright; neither local command requires CiviCRM Buildkit.
- The GitHub full-QA workflow is exercised once with `run_ui_tests=false` and once with `run_ui_tests=true` before promotion.
- Saving a selected scope with **Monitor everything else in this type** captures an initial watch baseline for the unselected items; changing one of those items afterward must produce `changed > 0` on the next watch scan without adding that item to YAML.
- The first explicit watch scan for a watch scope that has no stored fingerprints reports a non-zero `baseline` count so initialization is visible rather than looking like an empty/no-op scan.
- Running standalone QA from inside `ddev ssh` must fail immediately with host-Docker guidance, before fixture repositories or QA artifacts are created; run `composer qa:full` / `composer qa:full-ui` from the host checkout instead.
- Detect one watched change, then change a different watched item and scan again: recent watch history must retain the first detection and append the second. A later no-op scan must keep both historical detections while reporting zero new/changed/missing for that latest scan.
- After **Scan Watched Config**, Synchronize must return to the Watched Configuration anchor with the panel expanded. The panel must distinguish latest-scan findings from retained recent history, and clearing history must not reset watch fingerprints/baselines.
- Local standalone QA must not bind host port `8025`; an existing local Mailpit/DDEV service must not conflict with the isolated suite. Local UI QA must be able to use the pinned Playwright Docker image when host Node/npm is unavailable.

## Alpha58 scope, watch, and performance tests

Before accepting alpha58 on a real development project, verify:

- Synchronize before the first export shows one setup prompt and does not scan/render every active record as a difference.
- `Manage selected` exports only selected objects and writes semantic `config_keys` plus selector mappings to `manifest.yml`.
- A selected object still matches on another environment when its numeric database ID differs.
- Unselected objects are never deleted by bulk import in selected mode.
- `Watch only` and `Watch unselected` objects stay out of YAML and are changed only in local watch-state fingerprints.
- `civicfg watch` and API4 `ConfigManager.watch` refresh watch state explicitly.
- `$civicrm_setting['domain']['civicfg_scope']` overrides UI scope settings and is shown as locked.
- Message Template system variants match by workflow/default identity, while duplicate user-template titles are blocked from automatic writes.
- `hook_civicrm_check()` reads cached health without discovering/exporting handlers or running a full diff.
- Export-page single-file choices reuse the current export preview instead of triggering a duplicate all-handler export.

The developer-owned scenario is `tests/scenarios/selective-scope-watch.yml`. Run the full isolated suite after fast QA so real CiviCRM behavior covers scope, manifest, watch state, Message Templates, split-file handlers, and idempotency together.

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

The alpha61 SQLTasks follow-up regression also verifies that directory-style API3 action files (`Entity/Create.php`, provider delete aliases such as `Entity/Deletetask.php`) are sufficient for capability discovery when runtime `getactions` introspection is unreliable. SQLTasks now has a reviewed declarative provider definition that does not load the BAO during discovery; the BAO adapter is loaded from the provider base path only when rows are read.

## CiviCRM 5.76 / PHP 7.4 compatibility coverage

- Fast GitHub QA must pass on PHP 7.4, 8.1, and 8.3.
- `composer compatibility` targets PHP 7.4 so source syntax/runtime calls cannot silently require PHP 8.
- Composer resolution uses a PHP 7.4.33 platform; an installable production package must bundle `vendor/`. Source checkouts may use complete ext-yaml (`yaml_parse_file` + `yaml_emit`) as a runtime fallback.
- A missing optional API4 provider must be reported as **Unavailable on this site** and a managed export/diff must fail closed; it must never be treated as an authoritative empty set that could delete stale YAML or target configuration.
- On a CiviCRM 5.76.3/Drupal 7 smoke site, verify `civicfg status --json`, initial export, diff, validate, dry-run import, and a second idempotent diff before promotion.
### Standalone YAML runtime diagnostics

- `civicfg status` must report `symfony_yaml_available`, `extension_vendor_autoload`, `php_yaml_extension`, `php_yaml_emitter`, parser, and dumper separately.
- The runtime is healthy when Symfony YAML is available, or when PHP ext-yaml provides **both** `yaml_parse_file()` and `yaml_emit()`. Parser-only ext-yaml is not export-safe.
- When no complete read/write YAML runtime is available, `hook_civicrm_check()` must report a CiviCRM System Status error without discovering configuration handlers and Export must fail before YAML staging.
- Malformed ext-yaml input must produce a controlled Configuration Manager exception/JSON error; PHP warnings or CMS HTML must not be mixed into a JSON endpoint response.
- `composer package:release` must create an installable ZIP containing `vendor/autoload.php` and the PHP 7.4-compatible Symfony YAML runtime so target sites do not need a post-install Composer command. Unzip the produced artifact and verify `civi.config.manager/vendor/autoload.php` exists before promotion.
- On WordPress, set `civicfg_sync_dir` to a relative value such as `civicrm-config` and verify the UI and `civicfg status --json` resolve the same absolute path under the WordPress CMS root even though `civicrm.settings.php` is stored under `wp-content/uploads/civicrm`.

### Scope dependency guidance

- Managing Custom Data while Option Groups, Contact Types, or Site Tokens are Ignore/Monitor must show a live scope dependency warning without silently changing those modes.
- A related type in selected-item mode must show a review warning because the referenced item may not be part of that selected scope.
- **Manage recommended dependencies** may change only Ignore/Monitor related types to Manage everything after an explicit administrator click; unavailable or already-selected dependencies must not be overwritten.
- Fully managed related types must clear the warning. Actual YAML/import dependency validation remains authoritative and may accept dependencies already present in active CiviCRM.
- The implementation and tests must remain compatible with PHP 7.4 and the configured PHP 7.4/8.1/8.3 fast-QA matrix.


## Runtime CRUD/provider regression coverage

- PHP 7.4/8.1/8.3 fast QA remains blocking.
- API4-backed handlers advertised as **Full management** must expose every API action used by their import path; missing write actions must downgrade the type to **Export + compare** instead of failing mid-import.
- SQLTasks discovery must prefer native API4 `SqlTask` when its provider file exists, including early CLI/bootstrap contexts where the contributed extension classloader is not yet active.
- An unavailable provider item picker must not call `export()` and must disable applying selected items.
- Error, warning, and success messages must retain distinct semantic colors across supported CiviCRM/Drupal themes.

- SQLTasks 2.2.x (API3-only) must discover `Sqltask` with `list_action=getalltasks`, hydrate rows through `get`, retain create/update through `create`, and delete through `deletetask`; no BAO adapter may be required.
- An unavailable provider with a previously managed/selected/watch policy must preserve that saved policy when unrelated settings are saved, while the UI permits only an explicit switch to Ignore and disables bulk/picker/watch/advanced-selection controls.
- Synchronize must show one compact error summary plus one detailed Synchronization Errors panel, not duplicate the same provider message twice.

## Alpha63 large-site, ambiguity, and operation-safety gate

Run the release-specific architecture and low-memory contracts before the heavier integration suites:

```bash
composer test:alpha62-contract
composer test:alpha63-contract
composer test:alpha64-contract
composer qa:stress
```

The alpha62 gate continues proving 5,000 API4 + 5,000 YAML bounded traversal and ordinary staged rollback. Alpha63 adds 10,000-row provider disk spooling, persistent 5,000-document staged metadata, durable hard-interruption publication recovery, deterministic monitor-only ambiguity, honest progress/session-unlock contracts, and multi-work-unit Export/Import queue structure.

The real-site alpha63 scenario must additionally prove: generic contributed provider discovery must not execute business APIs merely to inspect them (for example Civigrant `Grant` or CiviMobile `CiviMobileParticipant` must not become export units and discovery must not emit their provider warnings); generic provider discovery excludes business/transaction API entities such as Grant without aborting unrelated Export; legacy alpha61/62 portable CiviRules YAML remains importable; queued workspaces survive a normal PHP/container restart when CiviCRM ConfigAndLog is persistent; identical duplicate CiviRules rows export without path collision; monitor-only rows do not gain CRUD authority; portable YAML ambiguous on target blocks with zero writes; one ambiguous identity does not disable unrelated delete safety; WordPress 2,000+ YAML progress remains responsive and semantically labelled; refresh reconnects; create/update failure starts no delete work; interrupted live mutation blocks; interrupted publication restores the previous YAML snapshot; Export -> Synchronize is zero diff; and a repeat Export produces no unnecessary rewrite.

These gates do not replace the PHP 7.4 compatibility matrix or full Drupal 7/WordPress/Standalone CiviCRM lifecycle tests.
