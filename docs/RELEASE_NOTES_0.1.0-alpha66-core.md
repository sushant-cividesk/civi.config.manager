# Configuration Manager 0.1.0-alpha66-core

Alpha66 is the first implementation slice of generic core/contributed/custom provider discovery. It preserves the protected `v1.0.0-beta1` baseline and does not grant any newly discovered provider export or import authority.

## Included

- Admin-only `ConfigManager.providerInventory` API4 action.
- Deterministic metadata inventory for core handlers, both public registration hooks, and installed extension API4/API3 candidates.
- Explicit admission/capability reasons, identity evidence, and metadata completeness for future Settings UI grouping.
- A collection-read trap regression and real-CiviCRM CLI smoke hook.
- Fail-closed Composer advisory retry for transient network/service failures only.

## Safety boundary

Provider inventory loads declarative provider metadata where available but does not execute provider collection actions, inspect business rows, read managed YAML, or change existing export/import admission. A discovered provider marked unsupported remains unsupported.

The new PHP tests, real CiviCRM provider inventory, browser behavior, and deliberate mutation proof have not run in this authoring workspace because PHP, Composer, and Docker are unavailable. Do not promote this alpha to Beta2 until the evidence ledger in `PROJECT_STATUS.md` is complete.
