# Configuration Manager 0.1.0-beta1

This is the first internal beta release of Configuration Manager for Cividesk development-project testing.

## Included in this beta

- Export/import/diff/validate workflows for supported CiviCRM configuration.
- UI review screens with clearer plain-English summaries.
- Config ignore, field-level ignore, revert, delete/stale YAML cleanup, and dependency metadata support.
- CLI wrappers and aliases for project/DDEV use.
- Metadata-driven public hook support via `hook_civicfg_entityDefinitions()` so other extensions can declare exportable/importable APIv4 config without writing full handlers.
- Advanced custom handler hook support via `hook_civicfg_configTypes()`.
- Fast and full GitHub QA workflows, PHPUnit/static analysis coverage, and metadata-hook tests.

## Intended use

Use this beta first on internal development and staging sites. Do not treat it as final production-ready software until real project feedback and full round-trip testing are complete.

## Upgrade policy

Future beta changes should be incremental and compatibility-aware. Existing beta installs, exported YAML, and public hook definitions must be considered before changing behavior.
