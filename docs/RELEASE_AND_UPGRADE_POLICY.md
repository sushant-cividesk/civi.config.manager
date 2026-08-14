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
5. Run `composer install --no-dev --prefer-dist --no-progress --optimize-autoloader` so runtime dependencies are present.
6. Package the ZIP without `.git`, `__MACOSX`, `node_modules`, or QA artifacts. Include the production `vendor/` directory because Standalone/WordPress-style hosts may not provide the YAML runtime dependency.
