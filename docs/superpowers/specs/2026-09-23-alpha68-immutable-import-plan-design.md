# Alpha68 immutable import plan design

## Purpose

Bind every destructive Import apply to the exact preview an administrator reviewed. A preview must remain usable across refresh/reconnect, but any relevant change to Saved Config, Configuration Scope, provider capability/runtime contract, Current CiviCRM, site identity, or implementation version must make the preview stale and block writes.

## Safety invariant

> What was reviewed is what may be applied. If the reviewed inputs no longer match, Configuration Manager performs zero new writes and requires a fresh preview.

There is no continue-anyway path for a stale plan.

## Plan lifecycle

1. Import page computes the normal complete non-writing preflight.
2. Only a green preflight creates a server-side review plan.
3. The browser receives an opaque `plan_id`; the plan body never needs to round-trip through HTML.
4. Refreshing the Import page may create a new equivalent plan; an existing plan remains valid until it expires or becomes stale.
5. Apply submits only the `plan_id`. The server loads the plan, checks owner/site/sync-root/version, and derives the exact requested type scope from the stored plan.
6. The queued preflight re-verifies every plan fingerprint before the first mutating work unit.
7. Each create/update work unit rechecks that handler's Saved Config snapshot and Current CiviCRM preflight snapshot before writing.
8. Each delete-missing work unit rechecks its Saved Config snapshot and the post-write Current CiviCRM snapshot before removal.
9. Any mismatch blocks the operation and instructs the operator to preview again.

## Immutable plan contents

The private server-side JSON plan contains:

- schema version and opaque random plan ID;
- created/expiry timestamps;
- initiating CiviCRM contact ID when available;
- site identifier and sync-directory hash;
- exact requested/effective/validation/apply type sets;
- effective Configuration Scope + Config Ignore + cross-site policy fingerprint;
- manifest fingerprint;
- selected handler/provider/runtime capability fingerprint;
- extension/CiviCRM implementation fingerprint;
- per-handler managed Saved Config identity/content snapshots;
- per-handler Current CiviCRM identity/content snapshots captured after a successful dry-run;
- compact fingerprint of the reviewed preflight result.

The plan store is private operational state under CiviCRM's ConfigAndLog directory when available, with a private system-temp fallback matching existing queued-operation workspace behavior. Plan files are atomic, mode 0600, integrity checked, and expire automatically.

## UI / API / CLI behavior

- The Import UI shows the existing preview and confirmation flow, with a hidden opaque plan ID only when preflight is green.
- The queued Import start endpoint requires that plan ID and never trusts posted type values as the authority for apply scope.
- The compatibility synchronous/stream paths also require a valid plan ID.
- API4 dry-run creates and returns a review plan. API4 apply requires `planId`.
- CLI dry-run exposes the plan through API4; CLI apply requires `--plan PLAN_ID` so automation cannot bypass the reviewed-plan boundary.

## Error handling

Stale-plan failures use one operator-facing message family: the reviewed Import preview is no longer current, no new writes were started for the stale work unit, and the operator must build/review a fresh preview. Technical mismatch details may be present in diagnostics but are not a continue-anyway mechanism.

## Compatibility and non-goals

- No provider gains create/update/delete authority.
- No Saved Config schema changes.
- No dependency-component exclusion behavior is added here; A68-02 through A68-05 remain separate work.
- Existing in-operation active-state conflict checks remain and become a second barrier after preview-to-apply verification.
- Existing direct internal `ConfigManager::import()` remains available for current unit/runtime internals; user-facing UI/API/CLI apply paths are moved onto reviewed plans.

## Verification obligations

Automated evidence must prove at minimum:

- plan store rejects tampered/expired IDs/files;
- owner/site/sync-root/type mismatch blocks apply;
- changing a managed Saved Config after preview blocks before write;
- changing managed Current CiviCRM after preview blocks before write;
- changing scope/ignore/capability/implementation contract blocks before write;
- unchanged preview can start and complete the existing queue sequence;
- subtype filters are derived from the stored plan, not mutable form values;
- refresh/reconnect does not invalidate an otherwise unchanged plan;
- a mutation that bypasses stale-plan verification turns regression coverage red;
- existing import preflight, provider admission, lifecycle, export, source-hygiene, and browser tests remain green.
