# Configuration Manager 0.1.0-alpha67-core

Development checkpoint only. Do not treat this source ZIP as the protected Beta1 release or as a production runtime package.

## What changed

- Added the A66-03 deny-by-default provider admission policy as explicit metadata-only proof stages: discover, classify, portable identity, writable projection, reference mapping, then capability assignment.
- Generic API4 candidates with writable local/foreign references are denied unless an explicit semantic reference mapping is declared. CRUD availability cannot bypass this gate.
- Moved generic admission policy out of the already-large `ExtensionHandler` so provider discovery and provider safety policy can be tested independently.
- Added explicit provider metadata for the fixed core handlers that previously inherited only `basic` inventory metadata.
- Added a second, isolated PHP 8.2+ Playwright black-box harness without changing the extension's PHP 7.4 runtime compatibility or production Composer graph. Existing JavaScript Playwright + axe coverage remains in place.
- Settings scope cards now expose a compact **Provider safety** disclosure with owner, registration source, capability reason, identity evidence, and metadata completeness. This uses metadata inventory only and must not read provider business collections.
- Added focused unit tests and a deliberate A66-03 mutation harness that must detect an unsafe "trust every reference" regression.

## Safety boundaries retained

- No generic provider receives write/delete authority merely because API CRUD exists.
- Provider inventory remains collection-read free.
- Export remains staged/atomic with `manifest.yml` as final publication marker.
- Import still performs complete non-writing preflight before any write and has no generic **Continue anyway** bypass.
- Create/update failure must prevent delete-missing.
- Beta1 is unchanged. This checkpoint is not committed, tagged, pushed, published, released, or deployed by this package creation.

## Evidence status

Local authoring checks for this checkpoint include PHP syntax, architecture contracts, JSON/workflow syntax, Composer audit-wrapper behavior, and direct admission-policy smoke checks. The uploaded source did not include `vendor/`, Composer is unavailable in the current execution container, and disposable Docker/CiviCRM is unavailable here; therefore PHPUnit red/green execution, the supported PHP matrix, the PHP Playwright install/runtime, real-CiviCRM, and browser evidence must be run in CI/disposable runtime and recorded in `docs/PROJECT_STATUS.md` before capability claims are promoted.
