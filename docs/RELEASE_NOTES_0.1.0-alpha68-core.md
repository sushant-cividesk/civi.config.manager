# Configuration Manager 0.1.0-alpha68-core

Alpha68 starts the approved safe-plan and operator-UX milestone without redesigning the existing Configuration Manager workflow. This checkpoint focuses on clearer language, persistent export feedback, truthful progress, and Saved Config visibility while the deeper immutable-plan, blocker-component, provider CRUD, and runtime-evidence work remains open.

## UI/UX

- Keeps the existing Synchronize / Import / Export / Settings structure.
- Uses Saved Config and Current CiviCRM client-facing terminology.
- Uses Not Yet Saved and Not in Current CiviCRM for the two one-sided difference states.
- Shows the total Saved Config file count in the existing Changes summary card and lightweight per-type saved counts in the existing filter.
- Keeps a compact Last Export or Last Import summary on Synchronize after the transient toast disappears; only the most recent operation summary is kept to avoid clutter.
- Simplifies queue progress to parts/items checked/done and does not invent an ETA when duration cannot be predicted reliably.
- Explains that extension-owned config appears in the filter only after safe automatic management is proven, and links operators to Settings for detected-but-limited providers.

## Safety

- Export publication remains staged and atomic.
- Unknown progress totals remain explicitly unknown.
- No provider receives broader create/update/delete authority from this UI change.
- Full structured error remediation across every boundary, per-provider CRUD evidence, immutable plans, and safe reduced-plan work remain tracked in PROJECT_STATUS.md.
