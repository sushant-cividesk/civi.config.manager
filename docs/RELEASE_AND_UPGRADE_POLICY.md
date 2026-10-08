# Release and upgrade policy

The protected release baseline is `v1.0.0-beta1`. Development continues on numbered alpha builds; `1.0.0-beta2` requires the full checklist in [`PROJECT_STATUS.md`](PROJECT_STATUS.md) plus explicit approval to tag/publish.

[Beta2 priorities](BETA2_PRIORITIES.md) contains the **2026-10-08 confirmed
P0/P1 order** and proof for every mandatory gate. The user prioritised optional
interface work at P0 and optional UX/Phase 2 at P1; that does **not** broaden
the Beta2 release scope or allow required gates to be skipped. ML-001 must be
resolved, its regression moved into the normal suite, and its real-runtime
behavior verified before final release testing. Never tag or publish without
explicit separate release approval.

A source-only ZIP must preserve Unix executable permissions for its CLI,
script and QA entrypoints. `composer qa:fast` validates them before other
checks, and the runtime package builder must fail if `bin/civicfg` is not
executable. This source-package validation does **not** replace the later
installable runtime ZIP and upgrade/lifecycle release gate.

## Compatibility rules after beta

- Treat exported YAML as user-owned configuration. Avoid changing YAML structure unless there is a documented migration path.
- Prefer incremental additions over behavior changes.
- Keep existing public hooks stable, especially `hook_civicfg_entityDefinitions()` and `hook_civicfg_configTypes()`.
- If a hook definition key must change, keep the old key working with a deprecation note before removal.
- Document any change that can affect export/import/diff output, CLI behavior, UI review text, ignore/revert/delete behavior, or extension-owned config discovery.
- Include upgrade notes in `CHANGELOG.md` for every beta and release candidate.
- Do not make broad destructive import/delete behavior the default for existing users.

Source-hygiene concessions for ignored, untracked `.DS_Store` files apply
**only to local QA in a Git checkout**. They are not permission to ship such
files: the production release builder must continue rejecting `.DS_Store` in
its staged archive. Source-only archives must preserve executable modes and
exclude Finder-generated files. A green local source-hygiene check is not a
substitute for examining the actual installable release ZIP.

## Release checklist

Before tagging a beta/release candidate:

1. Update `info.xml`, `README.md`, and `CHANGELOG.md`.
2. Run fast QA: `composer validate --strict`, `composer install`, `composer qa:fast`, `composer test:hook`, and `composer audit`.
3. Run required full QA: GitHub Actions `QA - Full CiviCRM Extension`, including real CiviCRM, contributed fixtures, the import-blocker preservation test, and Playwright.
4. Test at least one DDEV development-to-stage round trip before broader production use.
5. Run `composer package:release`. The release builder creates a clean package, installs locked production dependencies with `--no-dev`, verifies bundled Symfony YAML, and emits a SHA-256 checksum.
6. Inspect the produced ZIP and confirm `civi.config.manager/vendor/autoload.php` exists. Never publish a source-only ZIP as the installable release artifact; target administrators must not need a post-install Composer command.

## Production release artifact policy

The Git repository is the development/source tree. It intentionally contains tests, QA configuration, documentation, and release tooling. Sites must not be deployed from a raw GitHub source archive when a tagged production artifact is available.

A tagged release is built with `composer package:release`. The resulting `dist/civi.config.manager-<version>.zip` is an explicit runtime allowlist containing only the extension runtime directories/files plus locked `--no-dev` Composer dependencies under `vendor/`. Tests, CI workflows, documentation, release scripts, development Composer metadata, Node files, analysis configuration, caches, logs, and other build material are rejected by the packager if they leak into the archive.

Release tags must be `v<info.xml version>` (for example `v1.0.0-beta1`). The tag release workflow re-runs the supported PHP fast-QA matrix, security audit, stress gate, verifies the tag/version match, builds the production runtime ZIP, validates its contents, and attaches only the runtime ZIP and SHA-256 file to the GitHub Release.

Development and CI checkouts still require `composer install` before Composer QA commands. That requirement is deliberately separate from the installable release ZIP, which bundles its production runtime dependencies and must not require Composer on the target CiviCRM site.
