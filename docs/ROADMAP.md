# Roadmap

This roadmap describes planned work. Completed release history is maintained in `../CHANGELOG.md`.

## Current alpha scope

The current alpha focuses on a safe, reviewable configuration workflow:

- API4 automation surface.
- YAML export, diff, and validation.
- UI synchronize/import/export/settings tabs.
- Create/update/delete import for supported handlers, with destructive changes shown in preview.
- Single YAML and ZIP staging.
- Single-file export preview and download.
- Granular permissions.
- CiviCRM status report integration.
- Dependency-free UI assets.

## Phase 1 completion

Before treating phase 1 as complete, finish:

- Round-trip tests for all phase 1 handlers on real CiviCRM builds.
- Drupal, WordPress, and Standalone smoke tests.
- More handler-specific import readiness messages based on real-world failures.
- Decide whether sanitized Payment Processors should ever be importable by default.
- Final decision on sanitized Payment Processors import support.

## Phase 1.1 hardening

- Improve diff summaries for large files.
- Add more handler-specific validation.
- Expand dependency detection for SearchKit, Afform, custom fields, option values, and future CiviRules.
- Improve status report wording after real-world testing.
- Add documentation for deployment workflows between dev/stage/prod.

## Phase 2 candidates

- SQL query definitions.
- Mosaico/contact-layout/base-template asset deployment review through the generic bundled bundled extension config support.
- More complete CiviRules rule-component dependency ordering.
- Safer generic bundled extension config classification for extension APIs that expose operational data instead of deployable config.
- More environment override support beyond field-level Config Ignore Values.
- Further harden destructive import dependency checks and per-record dependency ordering.
- Expand real-world CLI smoke coverage across Drupal 7/non-Composer, modern Composer CMS builds, WordPress, and Standalone.

## CLI roadmap

The alpha56 CLI architecture is intentionally narrow: the extension owns `bin/civicfg`, Composer projects may expose one `vendor/bin/civicfg`, and a single ownership-aware global dispatcher may be shared by multiple local projects. Legacy project-bin aliases and PATH helper files are no longer part of the target architecture.

Remaining CLI work is compatibility testing and UX/documentation refinement rather than adding more wrapper locations or aliases.

## Asset tooling

No Node/npm build step is required today.

Optional Stylelint/ESLint can be considered later, but runtime CSS and JavaScript should remain simple and dependency-free for CiviCRM 5.x/6.x compatibility.
