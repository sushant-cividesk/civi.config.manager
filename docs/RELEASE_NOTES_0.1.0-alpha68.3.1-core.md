# Configuration Manager 0.1.0-alpha68.3.1-core

Alpha68.3.1 is a focused QA hotfix for the immutable reviewed Import-plan checkpoint.

## Fixes

- Reviewed-plan validation now compares any submitted type selection directly with the type set frozen in the plan. Unknown or extra submitted types can no longer disappear during managed-type normalization and bypass the tamper check.
- The queue regression now expects the existing `import_preflight` worker action and separately verifies that its phase remains `preflight`.

## Safety boundary

- The reviewed plan remains the authority for Import apply.
- No provider capability, write scope, delete authority, Saved Config schema, or queue workflow is broadened by this hotfix.
- Empty submitted type input still means the caller is relying entirely on the reviewed plan, which is the intended UI apply behavior.

## Verification required

Run the focused Import-plan tests first, then `composer qa:fast`. The existing mutation proof must remain green. Browser QA is unchanged by this patch; if Chromium is missing in DDEV, install it before rerunning `npm run test:ui`.
