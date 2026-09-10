# Configuration Manager 0.1.0-alpha68.1-core

Alpha68.1 is a bounded maintenance checkpoint on the active Alpha68 line. It improves operator-facing capability and difference presentation without changing the underlying provider CRUD policy, portable identity, Saved Config filenames, or import/delete authority.

## UI and wording

- Providers whose reviewed policy supports create/update but deliberately disables delete-missing now show **Create + update** instead of **Full management**.
- Providers on the normal full capability path now use the concise **Managed** label.
- Runtime safety downgrades are now authoritative in provider inventory: if runtime evidence limits a provider to export/compare, declared metadata cannot re-label it as managed.
- **Not Yet Saved** cards use the action-oriented explanation: export the item to add it to Saved Configs, instead of repeating the state in prose.
- Profile Field cards use a semantic title such as `Profile Field "Home Phone" in "Summary Overlay"` instead of exposing the collision-resistant `--<hash>` filename suffix. The real file path and semantic identity are unchanged; Synchronize details retain the technical path.
- Client-facing messages use **Saved Config** and **Current CiviCRM** where those product concepts are intended. Literal YAML parsing, upload validation, and `.yml`/`.yaml` file-format messages continue to say YAML.

## Safety boundary

- No delete-missing capability was enabled.
- No handler import/export semantics were broadened.
- No Saved Config filename, semantic identity, canonical payload, or manifest format was changed.
- The protected `v1.0.0-beta1` baseline is not modified.

## Known multilingual issue: ML-001

A real English/French WordPress + CiviCRM site demonstrated language-dependent FormBuilder Afform drift: at least some Saved Config titles exported in French compare against English values returned by Current CiviCRM. Merely changing the active language can therefore make equivalent configuration appear changed or cause repeated export churn.

Alpha68.1 records this as a correctness blocker but intentionally does not attempt locale canonicalization. A later Alpha must make export/sync deterministic across active UI languages while preserving genuine translated configuration. Beta2 must not ship while changing EN/FR context alone can rewrite equivalent Saved Configs or create false Synchronize drift.

## Local verification

The source-only package was checked locally with the dependency-free gates available in the build environment:

- PHP syntax: 129 files valid.
- Alpha62 / Alpha63 / Alpha64 contracts: 31 / 53 / 23 checks passed.
- Alpha68 UI behavior: 42 checks passed, including capability-label, runtime-downgrade, Profile Field display, and Not Yet Saved regressions.
- Alpha68 lifecycle / lifecycle behavior: 17 / 16 checks passed.
- Browser workflow contract / provider browser / Drupal login resolver: 29 / 9 / 2 checks passed.
- Composer audit-wrapper behavior, source hygiene, and the Alpha62 5,000-row/5,000-file stress fixture passed.
- Mutation checks proved the focused Alpha68 behavior gate fails when the four corrected behaviors are deliberately regressed.

Full Composer/PHPUnit/static-analysis gates could not be run in this source-only environment because Composer/vendor dependencies are unavailable and outbound dependency download is blocked. The Alpha63 YAML stress/scenario validator likewise requires the missing YAML/vendor runtime. Real CiviCRM browser validation and the multilingual ML-001 reproducer remain follow-up evidence; they are not claimed as validated by this package.
