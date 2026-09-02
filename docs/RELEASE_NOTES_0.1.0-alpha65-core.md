# Configuration Manager 0.1.0-alpha65-core

Alpha65 starts the post-Beta1 development line without changing the protected `v1.0.0-beta1` tag. It establishes the durable product/safety checklist and strengthens the evidence required before broader export/import functionality is added.

## Included

- Requirement-first test policy and persistent milestone/blocker/evidence tracking.
- Real-CiviCRM regression coverage for the observed OptionValue identity-rename blocker, including independent zero-write and preservation assertions.
- Honest classification of source contracts and scenario schemas as lint/document checks.
- Pull-request real-CiviCRM/Playwright QA and a mandatory real-runtime tagged-release gate.

## Validation boundary

This authoring workspace has no PHP, Composer, or Docker runtime. Syntax/diff checks available here were run, but the PHP matrix, disposable CiviCRM, browser, and mutation evidence remain pending in `PROJECT_STATUS.md`. Do not promote this alpha to Beta2 or a production deployment until those gates pass.

The accompanying `-source.zip` is a review/development archive, not the runtime-complete CiviCRM installer. Build an installable ZIP only with `composer package:release` in a verified environment; that process adds locked production dependencies and validates the package.
