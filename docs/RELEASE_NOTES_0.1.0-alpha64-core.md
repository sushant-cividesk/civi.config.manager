# Configuration Manager 0.1.0-alpha64-core

Alpha64 is the real-world hardening release built on alpha63's durable queue, staged publication, ambiguity handling, and low-memory export model.

## What changed

- Contributed API entities are no longer assumed to be configuration simply because CRUD actions exist. Generic API4 providers require proven portable configuration identity; generic API3 candidates require an explicit/reviewed adapter.
- Provider discovery is metadata/file driven and does not execute arbitrary business API collection actions.
- WordPress web, `cv`, and `civicfg` resolve relative sync paths from the active CiviCRM CMS root.
- Production YAML uses bundled Symfony YAML or complete ext-yaml only; the unsafe hand-written serializer is removed.
- JSON/AJAX endpoints isolate buffered CMS/plugin output and report controlled protocol failures.
- Official release ZIPs are runtime-complete and contain production code/assets plus locked `vendor/` dependencies only. Tests, docs, build scripts, Composer metadata, repository files, logs, and generated QA artifacts are excluded from the installable ZIP.
- Git tags are gated by supported-PHP QA, dependency audit, stress tests, tag/version matching, and archive-policy checks before a GitHub pre-release is created.

## Deployment rule

Use the attached `civi.config.manager-0.1.0-alpha64-core.zip` artifact for CiviCRM installation. Do not deploy GitHub's automatically generated source ZIP as the installable extension. The runtime ZIP must work without running Composer on the target site.

## Validation still required

Before broader production promotion, validate at least one WordPress and one Drupal real project with Export -> immediate Synchronize -> repeat Export, plus a DEV-only Import round trip and the known BMT CiviRules ambiguity case.
