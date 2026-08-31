# Release and upgrade policy

This project is now entering internal beta testing with `0.1.0-beta1`. The beta is intended for Cividesk/internal development-project sites so real project feedback can guide the next improvements.

## Compatibility rules after beta

- Treat exported YAML as user-owned configuration. Avoid changing YAML structure unless there is a documented migration path.
- Prefer incremental additions over behavior changes.
- Keep existing public hooks stable, especially `hook_civicfg_entityDefinitions()` and `hook_civicfg_configTypes()`.
- If a hook definition key must change, keep the old key working with a deprecation note before removal.
- Document any change that can affect export/import/diff output, CLI behavior, UI review text, ignore/revert/delete behavior, or extension-owned config discovery.
- Include upgrade notes in `CHANGELOG.md` for every beta and release candidate.
- Do not make broad destructive import/delete behavior the default for existing users.

## Release checklist

Before tagging a beta/release candidate:

1. Update `info.xml`, `README.md`, and `CHANGELOG.md`.
2. Run fast QA: `composer validate --strict`, `composer install`, `composer qa:fast`, `composer test:hook`, and `composer audit`.
3. Run full QA where possible: GitHub Actions `QA - Full CiviCRM Extension`.
4. Test at least one DDEV development-to-stage round trip before broader production use.
5. Run `composer package:release`. The release builder creates a clean package, installs locked production dependencies with `--no-dev`, verifies bundled Symfony YAML, and emits a SHA-256 checksum.
6. Inspect the produced ZIP and confirm `civi.config.manager/vendor/autoload.php` exists. Never publish a source-only ZIP as the installable release artifact; target administrators must not need a post-install Composer command.

## Production release artifact policy

The Git repository is the development/source tree. It intentionally contains tests, QA configuration, documentation, and release tooling. Sites must not be deployed from a raw GitHub source archive when a tagged production artifact is available.

A tagged release is built with `composer package:release`. The resulting `dist/civi.config.manager-<version>.zip` is an explicit runtime allowlist containing only the extension runtime directories/files plus locked `--no-dev` Composer dependencies under `vendor/`. Tests, CI workflows, documentation, release scripts, development Composer metadata, Node files, analysis configuration, caches, logs, and other build material are rejected by the packager if they leak into the archive.

Release tags must be `v<info.xml version>` (for example `v1.0.0-beta1`). The tag release workflow re-runs the supported PHP fast-QA matrix, security audit, stress gate, verifies the tag/version match, builds the production runtime ZIP, validates its contents, and attaches only the runtime ZIP and SHA-256 file to the GitHub Release.

Development and CI checkouts still require `composer install` before Composer QA commands. That requirement is deliberately separate from the installable release ZIP, which bundles its production runtime dependencies and must not require Composer on the target CiviCRM site.
