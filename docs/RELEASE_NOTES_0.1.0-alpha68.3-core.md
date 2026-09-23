# Configuration Manager 0.1.0-alpha68.3-core

Alpha68.3 implements the first half of the Alpha68 import-safety architecture: immutable reviewed import plans and stale-plan protection.

## User-visible behavior

- A successful Import Preview now creates a private reviewed plan and shows that the preview is protected.
- Refresh/reconnect reuses the same reviewed plan while the relevant state is unchanged.
- Import apply requires the reviewed plan ID; posted type filters are no longer trusted as the authority for the write scope.
- If Saved Config, Current CiviCRM, Configuration Scope, Config Ignore, provider/runtime capability, site identity, sync root, or Configuration Manager implementation state changes after preview, apply stops and requires a fresh preview.
- API4 dry-run returns `plan_id`; API4 write mode requires `planId`.
- CLI write mode now requires `civicfg import --yes --plan <plan_id>`.

## Safety boundaries

- Plans contain compact hashes/fingerprints rather than full business data and are stored outside the portable Saved Config tree.
- Plan files are private, atomically written, integrity checked, short-lived, and lifecycle-cleaned.
- Direct write-mode `ConfigManager::import()` calls without a reviewed plan are blocked.
- Existing complete preflight, create/update-before-delete ordering, operation locking, and per-handler conflict checks remain in force.
- No provider capability or delete authority is broadened.

## Verification scope

Source-level and dependency-free checks in this build cover PHP syntax, Alpha62/63/64 architecture contracts, Alpha68 UI/lifecycle contracts, browser workflow contract, provider-browser behavior, and source hygiene. The full Composer/PHPUnit/PHPStan/PHPCompatibility suite and real CiviCRM browser/runtime workflow still need to be run in the normal DDEV/CI environment because this packaging environment does not contain `vendor/` or Composer.

## Still pending

- Dependency-component blocker grouping and safe reduced-plan/exclusion workflow (A68-02 through A68-05).
- Full blocker/result count normalization and remaining action-label/error UX (A68-07 through A68-09).
- Multilingual canonicalization ML-001 before Beta2.
- Provider CRUD/delete runtime evidence and the remaining Beta2 compatibility/runtime matrix.
