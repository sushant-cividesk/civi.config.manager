# Configuration Manager 0.1.0-alpha67.4.2-core

QA workflow hotfix on the Alpha67 line.

## What changed

- Removed three dead `CliCommandTest` helpers that caused PHPStan to fail after browser-specific production CLI commands were removed.
- `npm run test:ui` now selects the full disposable fixture suite when `ui-fixture-state.json` exists.
- When no fixture exists and `CIVICFG_BASE_URL` is explicitly supplied, the same command runs a read-only targeted DEV smoke instead of failing during test discovery.
- Targeted mode does not run fixture-dependent import/watch/cross-site mutations.
