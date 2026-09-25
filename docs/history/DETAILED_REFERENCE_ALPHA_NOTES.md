# Historical detailed-reference alpha notes

These sections were moved from `docs/DETAILED_REFERENCE.md` without intentionally changing their historical content.

## Alpha37 Notes

- Added Config Ignore to skip selected YAML files from diff, validate, export, and import. This is useful for environment-specific configuration and for avoiding self-management of this extension.
- `extensions/civi.config.manager.yml` is ignored by default. Exporting this extension's own status can create a circular dependency because the extension must stay enabled to finish imports.
- SearchDisplay import now uses `saved_search_id.name + name` as the stable identity. This avoids duplicate `Table` display failures when a target site already has extension-provided SearchKit displays.
- New SearchDisplay exports include the SavedSearch name in the filename to avoid collisions. Older display filenames are still read.
- Already-existing records are treated as warnings when they can be matched safely instead of as hard errors.

## Alpha36 Notes

- Extension status is now exported as one YAML file per extension key under `extensions/`, instead of one large collection file. This makes future extension-specific config files easier to review and group.
- Import/export/validate/settings forms show a full-page progress overlay and disable controls while the request is running, which helps prevent double submits.
- Import failures are saved across the post/redirect/get flow so the next page can show the exact handler/file error instead of only a generic toast.
- Site Tokens now have an optional handler. It exports/imports `SiteToken` API4 records when that API4 entity is available and clearly blocks import when the target site lacks the provider.
- Custom Groups and Fields now support YAML-source deletes for missing custom fields and non-reserved missing custom groups. Option group references are resolved by `option_group_name` where possible.
- CiviRules has an alpha handler for common CiviRules API4 entities when the CiviRules extension exposes them. This still needs real-world testing with rule triggers, conditions, actions, and extension-provided rule components.

### CLI launcher lifecycle

`bin/civicfg` is the only implementation. Managed Composer/global launchers delegate to it dynamically; legacy generated aliases are removed only when they carry the Configuration Manager managed marker. CLI status checks are read-only. See `docs/CLI.md`.

### Config Ignore

Config Ignore accepts one relative YAML path or wildcard per line. Ignored files are skipped during diff, validate, export, import, single-file preview, and ZIP download. Do not ignore a YAML file that is a dependency of a non-ignored YAML file. Validation will show a dependency warning or error when it can detect this situation.

`extensions/civi.config.manager.yml` is ignored by default to avoid self-management loops while the extension is running an import.

### Environment workflow

The safest target workflow is one site codebase moving configuration between its own environments: dev, stage, and production. Cross-site imports are possible but require extra review because extensions, sample data, IDs, and contributed-extension defaults can differ between sites.

## Alpha 41 Notes

- Removed the separate `extension-config` and `extension-settings` managed types from the registry to prevent hundreds of duplicate YAML files.
- Bundled safely discoverable contributed/custom extension settings and extension API config under each `extensions/<extension-key>.yml` file.
- Generic extension config discovery now skips CiviCRM core component extensions and already-managed core handlers so operational data such as line items, events, financial accounts, SearchKit, and FormBuilder is not duplicated.
- Added option-value delete/revert support for non-reserved option values that exist in CiviCRM but are missing from YAML. Reserved option values are still skipped with a warning.
- Import summary counts now include nested option value, bundled extension setting, and bundled extension config create/update/delete results.

## Alpha 40 Notes

- Added generic contributed/custom extension support instead of hard-coded handlers for individual extensions.
- Generic Extension Entity Config discovers installed extension API4/APIv3 entities and exports records with stable identities under `extension-config/<extension>/<api>/<entity>/<item>.yml`.
- Generic provider admission is intentionally conservative: CRUD capability is not enough. API4 auto-discovery requires non-ID `match_fields` metadata and rejects obvious business/transaction or sensitive writable fields; generic API3 providers require a reviewed adapter. Providers that are real configuration but cannot prove this generically should declare `hook_civicfg_entityDefinitions()` metadata instead.
- Generic Extension-specific Settings discovers non-secret settings from Setting metadata and installed-extension namespaces; password/secret/token/API-key style names are blocked.
- Dependency validation now gives clearer messages when required YAML is missing or when old YAML still contains local numeric IDs.
- If a provider extension/API entity is unavailable on the target site, validation/import reports the missing provider instead of fataling.

## Alpha 39 Notes

- Config Ignore is applied consistently to diff, validate, import, export, single-file preview, and ZIP download. Ignored DB-only records are hidden from Synchronize when their generated YAML path matches an ignore rule.
- Saving Config Ignore now checks for detectable non-ignored YAML files that depend on ignored YAML files and warns the administrator.
- CLI uses the single `civicfg` command with `export`, `import`, `diff`, `validate`, and `status` subcommands.
- UI compatibility styles were adjusted so buttons and panels render more consistently across CiviCRM core themes.

## Alpha 42 Notes

- Extension status/settings remain in `extensions/<extension-key>.yml`. Generic extension-owned API config is split by item under the same extension directory.
- Field-level ignore rules use `path.yml:dot.path` and are intended for environment-specific values, not dependencies or required identities.
- The Site Identifier is generated automatically for one site family across dev/stage/prod; Experimental Cross-site Import remains a reviewed migration tool, not a general cross-site synchronization guarantee.

## Alpha 43 Notes

- Site Identifier is now automatic and read-only in the UI. It is stored in CiviCRM settings and exported to `manifest.yml`.
- Cross-site Import is labelled experimental and should stay disabled for normal dev/stage/prod workflows.
- Export adds reverse `required_by` metadata in addition to forward `dependencies`, so dependency review works both directions.
- Project-level CLI wrappers are installed when possible without overwriting non-managed files, and they warn if the extension is disabled.
- Button styling is normalized inside the Configuration Manager page for CiviCRM core/custom theme compatibility.

## Alpha 45 Notes

- The machine key is now `civi.config.manager`. The visible UI name remains `Configuration Manager`.
- The Synchronize screen includes per-file Revert and Ignore actions. Revert makes the selected YAML match active CiviCRM. Ignore can save either a whole-file ignore rule or selected field-level ignore rules.
- Extension-owned config filters are discovered dynamically from supported contributed/custom extension APIs. If an enabled extension exposes safe importable config entities, those entities can appear as separate filter/managed-type options.
- Generic extension config export skips read-only/generated API entities that cannot be recreated or updated through API. This avoids broken cross-environment imports for provider-generated records.

## Alpha 46 Notes

- Revert on the Synchronize screen now applies YAML back into active CiviCRM for the selected file and its dependency closure. It no longer rewrites YAML from the current database value.
- Managed Types and Filter Config Types now render extension-owned config more cleanly, with the provider extension shown as secondary text.
- Sync status language now distinguishes changed fields, added-in-CiviCRM files, and added-in-YAML files instead of calling every difference a change.
- `menubar_color` and `menubar_position` are included in the recommended settings allowlist so Riverlea menu-bar environment differences can be detected or ignored field-by-field.

## Alpha 47 Notes

- Synchronize now keeps the technical YAML/file view but adds plain-language explanations so non-developers can see whether a record was changed, added in CiviCRM, added in YAML, or removed.
- Managed type filters are grouped into standard CiviCRM config and extension-owned config discovered from enabled contrib/custom extensions.
- Whole-file ignore now avoids leaving stale extension config index references when the ignored file belongs to split extension-owned config.
- Field-level ignore UI now automatically selects the field-level option when fields are checked and clears fields when whole-file ignore is chosen.

## Alpha 48 Notes

- Sync, import, and export review screens now show shorter plain-language descriptions for common changed fields such as contact type labels, option value weights, extension settings, and extension-owned config records.
- Review cards were restyled to make changed/added/removed records easier to scan across CiviCRM themes.
- Config Ignore field selection is more robust: checking a field switches to field-level ignore, while switching back to whole-file ignore clears field selections.
- Generic extension settings discovery now also reads runtime settings stored in `civicrm_setting`, so extensions such as SQLTasks can export additional `sqltasks_*` values even when they are not fully described by setting metadata.
- Generic API3 discovery was broadened for contributed/custom extensions that expose importable API records but do not publish `getactions` consistently. Read-only/generated entities are still skipped.

## Alpha 49 Notes

- Added push-ready GitHub Actions workflows: `QA - Fast` for every push/pull request and `QA - Full CiviCRM Extension` for manual disposable CiviCRM Standalone runs.
- The fast workflow runs Composer validation, PHP syntax, scenario contracts, unit tests, PHPStan, a dedicated metadata-hook unit check, and Composer audit. PHPCS/PHP-compatibility cleanup is intentionally outside the required fast path until the existing style baseline is fixed.
- The full workflow runs the extension in an isolated CiviCRM container with disposable MariaDB, Mailpit, blocked PHP mail, sanitized artifacts, API/CLI smoke tests, integration fixtures, and optional Playwright checks.
- The YAML sync root may now be a symlink to support dev/stage shared-config test setups; files and subdirectories inside the sync root are still protected against traversal and symlink escapes.

## Alpha 54 Notes

- Added stronger automated coverage for the preferred `hook_civicfg_entityDefinitions()` integration path.
- The metadata-hook tests now cover stable-key export, collection YAML, where/order metadata, composite keys, update/create/dry-run/delete-missing imports, import-disabled definitions, invalid YAML validation, sensitive-field blocking, and ignored-field diff behavior.
- GitHub fast and full workflows now include a dedicated required `composer test:hook` / `EntityDefinitionHandlerTest` step so hook regressions are easy to spot in Actions.

## Alpha 59 Notes

Alpha59 refines the alpha58 universal scope foundation for day-to-day administrator use:

- Settings now uses plain-language, mode-aware scope cards instead of showing an always-visible raw selector textarea for every type.
- `Manage selected items` provides a lazy searchable picker. Opening Settings does not enumerate configuration records; only the requested type is exported/discovered when its picker opens.
- Picker selections are stored as semantic `key:` selectors automatically. Local numeric IDs remain available only as advanced bootstrap selectors.
- The Settings page generates a copyable `civicrm.settings.php` example from the current scope choices, and code-owned scope remains read-only in the UI.
- Each registered handler shows a cheap capability label: full management, export/compare only, or mixed provider capabilities for contributed-extension configuration.
- Expected contributed-provider identity limitations are reported as compatibility information rather than YAML validation warnings; genuinely invalid or unsafe YAML still produces warnings/errors.
- Extension status changes now explain both sides in plain language, e.g. `YAML Installed but disabled → CiviCRM Enabled`.
- Large change lists stay collapsed by default after the summary when many individual items need review.
- Full relative `path:` selectors are now consistent between Settings help and runtime matching.
- The alpha59 hotfix makes picker search deterministic, adds API4/CLI scope and cross-site policy controls, and verifies the reviewed cross-site switch from UI/service/CLI tests.
- Contributed-provider target matching now correctly allows a strong identity with zero existing target matches to proceed to CREATE; only duplicate target identities are ambiguous. Created provider records are read back immediately so a provider cannot silently report success without producing restorable configuration.
- Expected backup/monitor-only provider limitations no longer inflate import warning counts, and non-write-safe contributed provider files are shown as backup-only rather than offering an impossible Restore action.
- Normal extension install/enable/disable plans are treated as expected import actions rather than warnings, and Message Template pickers prefer administrator-facing titles while retaining stable workflow identities underneath.
- SQLTasks remains handled inside the contributed-provider engine. The pinned 3.0.0-alpha3 provider exposes native API4 `SqlTask`, which Configuration Manager prefers for normal discovery/read/write. The reviewed API3 `Sqltask` + BAO `generator()`/`exportData()` path remains a compatibility fallback for environments where API4 `SqlTask` is unavailable; nested task configuration remains preserved.
- Added service, static-analysis, unit, CLI, integration-fixture, and browser coverage for lazy scope discovery, picker search, mode-dependent controls, portable selectors, cross-site policy, target create identity safety, missing selected records, settings-file examples, and compatibility reporting.

## Alpha 58 Notes

Alpha58 introduces universal configuration scope and performance-safe monitoring on top of alpha57:

- Every registered configuration handler can be `Manage all`, `Manage selected`, `Watch only`, or `Ignore`; selected mode can optionally watch the remaining active objects.
- Numeric IDs are accepted only as source selectors. `manifest.yml` persists semantic config keys and selector mappings so selected objects remain portable across environments with different database IDs.
- Message Templates now use explicit portable identities and support safe selective management of customized system templates and unique user templates.
- Contact Types, Relationship Types, Location Types, Financial Types, Payment Processors, and Dedupe Rules now export as split item files so they can participate in item-level scope where practical.
- Watch-only state is stored in a local disposable table and refreshed only by an explicit watch scan. Watched objects never enter YAML and never become import/delete candidates.
- `hook_civicrm_check()` now reads cached health instead of running a full diff. Settings avoids virtual-provider discovery, and the Export page reuses its existing export preview instead of exporting every handler a second time just to populate the single-file selector.
- Synchronize suppresses the initial all-CiviCRM difference flood and shows concise human change descriptions after a baseline exists; generated API/capability/dependency/identity metadata stays in Details.
- Selected scope centrally disables bulk delete-missing, preserving the rule that absence from selective YAML never means an unselected active object should be removed.
- Managed ZIP and single-file downloads enforce the same effective scope: stale deselected YAML backups may remain safely on disk, but they are not packaged as managed configuration and crafted single-file requests cannot bypass selection.

## Alpha 56 Notes

Alpha56 replaces development-only identity/fingerprint internals with a stable semantic configuration model while preserving the existing export/import/diff/validate product workflows. YAML remains the portable source of truth. Database IDs and filenames are not trusted as cross-environment identities.

Key changes:

- semantic `config_key` identities with explicit confidence (`EXPLICIT`, `API_VERIFIED`, `DISCOVERED_UNIQUE`, `AMBIGUOUS`); ambiguous identities remain visible for export/diff but are not considered safe for automatic generic writes;
- deterministic, type-preserving SHA-256 fingerprints with a versioned canonicalization format;
- exact/path-aware runtime, ignored, and sensitive fields plus ordered/unordered collection metadata;
- semantic API4 reference export/import support for metadata-hook providers;
- local `civicrm_civicfg_object_state`, `civicrm_civicfg_baseline`, and `civicrm_civicfg_identity_alias` tables for rebuildable scan state, accepted three-way baselines, and confirmed identity renames;
- baseline-aware states such as active drift, YAML change, synchronized change, both-changed, and field-level conflict/non-conflicting divergence;
- conservative possible-rename suggestions which never auto-match or auto-write until explicitly confirmed;
- contributed-extension compatibility reporting (`FULL`, `PARTIAL`, `NO_PORTABLE_CONFIG`, `UNSUPPORTED`, `ERROR`) and broader real-fixture QA;
- the finalized single-implementation CLI lifecycle described above.

Because this extension has not yet been published for client use, alpha56 intentionally does not preserve obsolete development-only CLI aliases or weak fingerprint/identity formats.

## Alpha 55 Notes

- Continues directly from the full `0.1.0-beta2` codebase; beta1/beta2 history and beta-only functionality are retained.
- Synchronize status text now describes the current comparison state instead of assuming a change direction.
- The developer integration hook path is explicitly documented and regression-tested for custom/contributed extensions using normal CiviCRM hook dispatch.
- Generic API3 extension discovery now understands nested `Entity/Action.php` layouts and safe custom `get-all...` collection actions, and can hydrate listed records through `get` for fuller export data.
- Generic contrib exports remove common non-portable runtime timestamps such as `last_modified`, preventing source-site modification times from creating false drift or being sent back during updates on another environment.
- Extension setting matching accepts conservative singular namespaces such as `sqltask_*` for plural extension keys such as `sqltasks`, while retaining the existing secret/sensitive-setting safeguards.
- SQL Tasks remains a real-world QA fixture only; the production implementation stays generic and does not hard-code `de.systopia.sqltasks`.
