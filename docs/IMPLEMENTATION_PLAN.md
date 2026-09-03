# Implementation Plan

This document records current implementation decisions and remaining work. Version history is maintained in `../CHANGELOG.md`.

The authoritative cross-release checklist and evidence ledger is [`PROJECT_STATUS.md`](PROJECT_STATUS.md). Update it with every implementation/evidence change.

## Locked decisions

- Extension key: `civi.config.manager`
- UI name: `Configuration Manager`
- File format: YAML
- Default sync directory: `civicrm-config`
- Sync directory setting: `civicfg_sync_dir`
- Target compatibility: CiviCRM 5.x and 6.x
- Current command strategy: API4 through `cv api4 ConfigManager.*`
- Current import delete behavior: supported handlers can delete CiviCRM records that are absent from YAML, after preview and explicit confirmation
- Payment processors: export sanitized data only; never export secrets
- `org.civicoop.configitems`: reference only; not a dependency
- Release policy: preserve `v1.0.0-beta1`, develop through numbered alphas, and promote to `1.0.0-beta2` only after all recorded gates and explicit approval
- Blocker policy: full import remains strict; a future safe reduced plan may exclude only a complete proven-safe dependency component and must rebuild/re-preflight from scratch
- Generic provider policy: runtime discovery and metadata/hooks are preferred; CRUD availability or an extension-specific heuristic cannot by itself grant write/delete authority
- Test policy: every important test follows the eight-rule contract in `PROJECT_STATUS.md`

## Approved implementation sequence

1. Alpha65: durable tracking, honest QA classification, real blocker regression, and mandatory real-runtime release gates.
2. Alpha66: read-only generic provider inventory and safe admission pipeline.
3. Alpha67: Settings/inventory counts, grouping, lazy loading, accessibility, and provider explanations.
4. Alpha68: immutable plans, dependency components, safe reduced-plan workflow, and blocker UI/API4/CLI consistency.
5. Alpha69: Tags, Profiles, Contact Layouts, and traditional Reports are implemented in source; independent real-runtime evidence remains the completion gate.
6. Beta2: compatibility, upgrade, cross-environment, browser, mutation, packaging, and explicit release approval gates.

## Current command strategy

Use API4 for command-line and automation flows:

```bash
cv api4 ConfigManager.status
cv api4 ConfigManager.listTypes
cv api4 ConfigManager.diff
cv api4 ConfigManager.validate
cv api4 ConfigManager.export dryRun=1
cv api4 ConfigManager.export dryRun=0
cv api4 ConfigManager.import dryRun=1 type=option-groups
cv api4 ConfigManager.import dryRun=0 yes=1 type=option-groups
```

The custom `cv civicfg:*` wrapper is paused. Do not document or build release processes around that wrapper until the API4/UI behavior is stable.

## Dependency order

Handlers should run in this order unless a new dependency requires adjustment:

1. Extensions
2. Option Groups and Values
3. Contact Types
4. Relationship Types
5. Location Types
6. Financial Types
7. Payment Processors, sanitized
8. Custom Groups and Fields
9. CiviCRM Settings (local safety allowlist)
10. Message Templates
11. Dedupe Rules
12. Scheduled Jobs
13. SearchKit Saved Searches
14. SearchKit Displays
15. FormBuilder Afforms
16. Bundled Extension Config, for extension-provided API4/APIv3 config entities
17. CiviRules, alpha support

## File layout decisions

Use split item files for config types that are large, commonly edited, or dependency-sensitive:

- Scheduled Jobs
- SearchKit Saved Searches
- SearchKit Displays
- FormBuilder Afforms
- Bundled Extension Config

Keep collection files for smaller stable types unless diffs become difficult to review. When adding a new split-file handler, include dependency metadata in each item file and document the dependency order here instead of duplicating release notes.

Temporary filtered exports are expanded by type for known dependency-sensitive groups:

- SearchKit Saved Searches, SearchKit Displays, and FormBuilder Afforms are exported together.
- Custom Groups and Fields can bring Option Groups, Contact Types, and Site Tokens.
- Bundled Extension Config can bring Extensions, extension settings, and detectable related managed config.
- Relationship Types can bring Contact Types.

Type-level expansion is still used for temporary filtered exports, and split SearchKit/FormBuilder YAML files now also carry per-record dependency metadata where it can be detected. Missing managed dependency YAML files are import-blocking validation errors.

## Version maintenance

Update the release version in `info.xml`. Runtime export metadata reads that version through `Civi\ConfigManager\Version`, so service code should not hard-code the alpha version. Update `CHANGELOG.md` and current-behavior docs when behavior changes.

## Current supported import areas

Create/update import is currently implemented for:

- Extensions, conservative install/enable/disable only
- Option Groups and Values
- Contact Types
- Relationship Types
- Location Types
- Financial Types
- Custom Groups and Fields
- CiviCRM Settings (local safety allowlist)
- Message Templates
- Dedupe Rules
- Scheduled Jobs
- SearchKit Saved Searches
- SearchKit Displays
- FormBuilder Afforms
- Bundled Extension Config

Import can create, update, and delete supported records according to YAML. Payment Processors remain export/diff only because sanitized exports may omit required environment-specific values.

## Remaining phase 1 work

- Add round-trip tests for each handler on real CiviCRM builds.
- Add compatibility smoke tests for Drupal, WordPress, and Standalone.
- Continue expanding per-record dependency detection beyond the current SearchKit/FormBuilder/Scheduled Job metadata and add more handler-specific dependency ordering where needed.
- Add clearer per-handler import readiness reporting based on real-world failures.
- Re-check whether sanitized Payment Processors should ever be importable by default.

## UI maintenance rules

- Keep page logic out of Smarty templates.
- Keep CSS in `css/configmanager.css` unless it must be critical preload CSS.
- Keep `css/configmanager-preload.css` small.
- Keep JavaScript dependency-free in `js/configmanager.js`.
- Do not reintroduce large inline `<style>` or `<script>` blocks.
- Do not add a required frontend build step for the current alpha.

## Sync directory rules

- Use `civicrm-config` as the default relative directory.
- Resolve relative paths from the CMS/project root where possible.
- Reject URL-style values.
- Lock the UI field when `civicfg_sync_dir` is defined in `civicrm.settings.php`.

## Status reporting rules

The CiviCRM status report should warn when:

- The sync directory is missing.
- The sync directory exists but no YAML files exist.
- CiviCRM and YAML have pending differences.

When there are no differences, the status check may show an informational in-sync notice.


## Alpha33 validation note

Option group value validation allows CiviCRM core data where option value names may be reused with different stored values. Custom field option group references should be exported by `option_group_name` where possible so YAML is portable between environments; legacy numeric `option_group_id` YAML remains accepted for compatibility.

## Alpha37 decisions

- Add Config Ignore before broader extension-specific configuration support. This lets teams intentionally exclude environment-specific or self-referential YAML.
- SearchDisplay identity is treated as `saved_search_id.name + name` because display names such as `Table` are not globally unique.
- Existing-record conflicts that can be matched safely should produce warnings rather than hard errors, so imports can finish without creating duplicates.
