# Configuration Manager extension hooks

Configuration Manager exposes one preferred public hook for other extensions that want their configuration to be exportable/importable without patching Configuration Manager.

## Preferred hook: `hook_civicfg_entityDefinitions()`

Use this hook when your extension has config records exposed through APIv4. You only describe the config metadata; Configuration Manager handles export, import, diff, validation, field ignoring, dependency metadata, stale YAML cleanup, and safe cross-site matching by stable keys. This is the preferred beta integration path for contributed/custom extensions with API4-backed config.

```php
/**
 * Declare extension config that Configuration Manager can export/import.
 */
function myext_civicfg_entityDefinitions(array &$definitions): void {
  $definitions['myext_report_templates'] = [
    'label' => 'My Extension Report Templates',
    'description' => 'Report templates managed by My Extension.',
    'api_version' => 4,
    'entity' => 'MyExtReportTemplate',
    'path' => 'extensions/myext/report-templates',
    'key_fields' => ['name'],
    'export_fields' => [
      'name',
      'label',
      'description',
      'is_active',
      'template_file',
      'settings',
    ],
    'ignore_fields' => [
      'id',
      'created_date',
      'modified_date',
    ],
    'sensitive_fields' => [
      'api_key',
      'access_token',
      'secret',
    ],
    'dependencies' => [
      'extension' => ['myext'],
    ],
    'order_by' => ['name' => 'ASC'],
    'split_files' => TRUE,
    'delete_missing' => FALSE,
    'weight' => 500,
  ];
}
```

This produces YAML like:

```yaml
schema_version: 1
type: myext_report_templates.item
entity: MyExtReportTemplate
key_fields:
  - name
key: name=member_summary
dependencies:
  - type: extension
    name: myext
item:
  name: member_summary
  label: Member Summary
  description: Member summary report template
  is_active: true
  template_file: member-summary.odt
  settings:
    format: pdf
```

## Supported definition keys

| Key | Required | Purpose |
| --- | --- | --- |
| `label` | Yes | Human label shown in UI and CLI. |
| `description` | No | Human description for docs/UI. |
| `api_version` | Yes | Must be `4` for this metadata hook. |
| `entity` | Yes | APIv4 entity name, without `Civi\\Api4\\`. |
| `path` | Yes | YAML directory under the configured sync root. |
| `key_fields` | Yes | Stable fields used to match records across sites. Do not use numeric IDs. |
| `export_fields` | Yes | Fields to export/import. Use explicit fields where possible. |
| `ignore_fields` | No | Runtime/local fields removed from export, import, and diff. `id` is always ignored. Dot paths are supported. |
| `sensitive_fields` | No | Fields that must never be exported and must not appear in YAML. Dot paths are supported. |
| `dependencies` | No | Dependency metadata written into YAML, e.g. required extension names. |
| `where` | No | APIv4 where conditions limiting records owned by the extension. |
| `order_by` | No | APIv4 sort order for stable export. Defaults to first key field. |
| `split_files` | No | Defaults to `TRUE`; one YAML file per record. |
| `delete_missing` | No | Defaults to `FALSE`; set `TRUE` only when YAML should be authoritative. |
| `import` | No | Defaults to `TRUE`; set `FALSE` for export-only config. |
| `weight` | No | UI/CLI ordering. |

## Recommended defaults

Use `split_files: TRUE` for readable diffs. Keep `delete_missing: FALSE` unless the extension config is fully owned by YAML. Always use stable `key_fields` such as `name`, `machine_name`, or a composite key. Never use `id` as a key.

## Advanced hook: `hook_civicfg_configTypes()`

Use the older `civicfg_configTypes()` hook only when metadata is not enough, for example private database tables, config that is not exposed through API4, complex ID remapping, generated files, or multi-entity import workflows. This keeps the public metadata hook simple while still allowing any contributed/custom extension to become exportable through either metadata or a custom handler.

No test-specific public hook is provided. Tests should exercise the same export/import hooks used by real extensions.

## QA coverage in this extension

The public metadata hook is covered by `tests/phpunit/Unit/EntityDefinitionHandlerTest.php` and the scenario contract `tests/scenarios/entity-definition-hook.yml`. The required GitHub fast and full workflows both run a dedicated `composer test:hook` / `EntityDefinitionHandlerTest` step, so regressions in this hook are visible before any release ZIP is used on a real site.
