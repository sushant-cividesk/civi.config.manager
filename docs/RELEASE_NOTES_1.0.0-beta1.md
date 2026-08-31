# Configuration Manager 1.0.0-beta1

This is the first clean beta release of the real-world hardened Configuration Manager line. It promotes the alpha64 code that passed the supported PHP QA matrix and production packaging gates without broadening destructive behavior.

## Included in this beta

- Durable queued Export/Import orchestration with staged publication, rollback intent, reconnectable progress, and fail-closed mutation retry behavior.
- Ambiguous or non-portable configuration identities remain monitor-only and never gain automatic CRUD/delete authority.
- Contributed provider discovery does not treat arbitrary CRUD/business APIs as configuration and does not execute generic collection actions merely during discovery.
- WordPress web, `cv`, and `civicfg` resolve relative sync paths from the active CiviCRM CMS root.
- Runtime YAML uses bundled Symfony YAML (official release ZIP) or complete ext-yaml only; there is no hand-written production YAML serializer.
- JSON/AJAX endpoints are protected from buffered CMS/plugin output corrupting protocol responses.
- Official release ZIPs contain runtime extension code/assets and locked production `vendor/` dependencies only.

## Deployment

Install the attached `civi.config.manager-1.0.0-beta1.zip`. Do not use GitHub's automatically generated source ZIP for CiviCRM installation, and do not run Composer on the target site.

## Validation boundary

Use this beta first on controlled DEV/STAGE projects. Continue the real-project gates: WordPress and Drupal Export -> immediate Synchronize -> repeat Export, the known BMT CiviRules ambiguity case, and a DEV-only Import round trip before broader production promotion.
