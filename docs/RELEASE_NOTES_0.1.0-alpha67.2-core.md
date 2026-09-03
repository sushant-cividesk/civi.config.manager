# Configuration Manager 0.1.0-alpha67.2-core

Development/QA hotfix checkpoint. Alpha68 import-plan work is not included.

## What changed

- Added `civicfg qa-browser --base-url URL` as the canonical manual Playwright-PHP entry point.
- Added preview-first `civicfg qa-browser-clean` and explicit `--yes` cleanup for generated legacy nested browser artifacts.
- Added explicit `--clean-legacy` clean-and-run behavior.
- Kept the one-project-`vendor/` rule and external Playwright-PHP tooling workspace.
- Rejects command-line browser passwords; use `CIVICRM_ADMIN_PASS`.
- Added CLI regression coverage and expanded the browser tooling architecture contract.

## Why

The previous manual workflow could leave `tests/browser-php/vendor`, and running `composer qa:browser-php` from the nested browser manifest necessarily failed because that Composer project does not define the root project's `qa` scripts. The CLI now owns this developer workflow and routes it consistently to the same canonical runner as CI.
