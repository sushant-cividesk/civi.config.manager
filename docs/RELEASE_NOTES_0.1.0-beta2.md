# Configuration Manager 0.1.0-beta2

Internal beta update for development-project testing.

## Highlights

- Safer terminal CLI access after extension install/enable.
- Managed wrappers continue to be installed into project/shared `bin` directories.
- New `civicfg-env` and `civicfg-path` helpers make project-level PATH setup explicit and repeatable.
- Optional `CIVICFG_GLOBAL_BIN_DIR` supports a user/system-level bin directory without editing shell profiles automatically.
- Added focused CLI wrapper unit coverage in GitHub fast/full QA.
- Clarified contributed/custom extension integration for API4-backed metadata hooks and advanced custom handlers.

## Upgrade note

This is an incremental beta update from `0.1.0-beta1`. Existing exported YAML formats are preserved. Re-enable the extension or run the lifecycle check to regenerate managed CLI wrappers/helpers. Existing non-managed terminal commands are never overwritten.
