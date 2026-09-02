# Configuration Manager extension hooks

Configuration Manager exposes a preferred metadata hook for API4-backed extension configuration and an advanced custom-handler hook for cases where metadata is not enough.

## Preferred hook: `hook_civicfg_entityDefinitions()`

Use this hook when your extension owns portable configuration records exposed through API4. The provider declares stable identity and portability metadata; Configuration Manager handles YAML export/import, semantic matching, canonical fingerprints, diff/validation, dependency metadata, stale YAML cleanup, and target-environment reference resolution.

For a traditional CiviCRM extension hook, place the function in the extension's main PHP file (for example `myext.php`) or a PHP file that the main file always includes. Use the normal hook prefix for the providing extension. Configuration Manager dispatches the hook through `CRM_Utils_Hook`.

```php
/**
 * Declare extension config that Configuration Manager can export/import.
 */
function myext_civicfg_entityDefinitions(array &$definitions): void {
  $definitions['myext_report_templates'] = [
    'provider' => 'myext',
    'label' => 'My Extension Report Templates',
    'description' => 'Report templates managed by My Extension.',
    'api_version' => 4,
    'entity' => 'MyExtReportTemplate',
    'path' => 'extensions/myext/report-templates',

    // Cross-environment semantic identity. Never use a local numeric ID.
    'key_fields' => ['name'],

    'export_fields' => [
      'name',
      'label',
      'description',
      'is_active',
      'template_file',
      'custom_group_id',
      'settings',
    ],

    // Permanently non-portable fields. `id` is ignored automatically.
    'ignore_fields' => [
      'created_date',
    ],

    // Runtime values which should not participate in portable state.
    'runtime_fields' => [
      'modified_date',
      'settings.generated_at',
    ],

    // Never exported, fingerprinted, baselined, or accepted from YAML.
    'sensitive_fields' => [
      'settings.api_key',
      'settings.access_token',
      'settings.secret',
    ],

    // Convert local IDs to semantic references on export and resolve the
    // target site's local ID during import.
    'reference_fields' => [
      'custom_group_id' => [
        'entity' => 'CustomGroup',
        'id_field' => 'id',
        'key_fields' => ['name'],
        'dependency_type' => 'custom-data',
      ],
    ],

    // List order is meaningful by default. Declare only truly set-like paths
    // unordered so canonical fingerprints can sort them safely.
    'unordered_paths' => [
      'settings.allowed_roles',
    ],
    'ordered_paths' => [
      'settings.workflow_steps',
    ],

    'dependencies' => [
      'extension' => ['myext'],
    ],
    'order_by' => ['name' => 'ASC'],
    'split_files' => TRUE,

    // Provider capabilities. Keep deletion opt-in unless YAML really owns the
    // full record set.
    'import' => TRUE,
    'can_create' => TRUE,
    'can_update' => TRUE,
    'can_delete' => FALSE,
    'delete_missing' => FALSE,
    'weight' => 500,
  ];
}
```

A split export contains semantic keys instead of using the local database ID as its identity. A declared reference is exported in a portable form such as:

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
  - type: custom-data
    name: member_fields
capabilities:
  create: true
  update: true
  delete: false
item:
  name: member_summary
  label: Member Summary
  custom_group_id:
    provider: api4:CustomGroup
    entity: CustomGroup
    key:
      name: member_fields
  settings:
    allowed_roles:
      - member
      - administrator
```

The `capabilities` block is operational metadata and does not affect the portable content fingerprint.

## Supported definition keys

| Key | Required | Purpose |
| --- | --- | --- |
| `provider` | No | Owning extension key shown in the admin-only provider inventory. Defaults to an explicit unknown hook-provider label when omitted. |
| `label` | Yes | Human label shown in UI/CLI. |
| `description` | No | Human description for docs/UI. |
| `api_version` | Yes | Currently must be `4`. |
| `entity` | Yes | API4 entity name, without `Civi\\Api4\\`. |
| `path` | Yes | YAML directory under the configured sync root. |
| `key_fields` | Yes | Stable semantic fields used to match records across environments. Composite keys are supported. Never use numeric IDs. |
| `export_fields` | Yes | Explicit fields to export/import; key/reference fields are included as needed. |
| `ignore_fields` | No | Exact runtime/local fields removed from export/import/diff. `id` is always ignored. Dot paths are supported. |
| `runtime_fields` | No | Exact fields excluded from portable state/fingerprints because they are generated/runtime values. Dot paths are supported. |
| `sensitive_fields` | No | Exact fields that must never be exported and are rejected when present in YAML. Dot paths are supported. |
| `reference_fields` | No | Map of local reference paths to API4 target entity, target ID field, stable target key fields, and optional dependency type. |
| `ordered_paths` | No | Documentation/metadata for semantically ordered lists. List order is preserved by default. |
| `unordered_paths` | No | List paths that are semantically set-like and may be sorted during canonicalization. |
| `dependencies` | No | Additional dependency metadata written into YAML, for example required extension names. |
| `where` | No | API4 conditions limiting records owned by the extension. |
| `order_by` | No | API4 sort order for stable export. Defaults to the first key field. |
| `split_files` | No | Defaults to `TRUE`; one YAML file per record. |
| `import` | No | Defaults to `TRUE`; set `FALSE` for export-only config. |
| `can_create` | No | Defaults to `TRUE` when import is enabled. Controls create capability. |
| `can_update` | No | Defaults to `TRUE` when import is enabled. Controls update capability. |
| `can_delete` | No | Defaults to `delete_missing`; controls delete capability. |
| `delete_missing` | No | Defaults to `FALSE`; set only when YAML should own the full provider record set. |
| `weight` | No | Handler/import ordering. |

## Identity and write safety

A metadata definition with explicit `key_fields` has the strongest identity contract (`EXPLICIT`). Use immutable machine fields where possible. Composite keys are appropriate when a child name is only unique within a parent.

A display label or title is not a good write identity unless the provider guarantees it is immutable and unique. Generic auto-discovery may expose weak records for export/diff, but Configuration Manager deliberately prevents automatic writes when it cannot establish a safe semantic identity.

Do not use local numeric IDs in `key_fields`. Use `reference_fields` to convert local foreign keys into semantic references instead. Declared references are strict: export fails rather than leaking an unresolved local ID, and validation/import reject raw IDs, wrong target providers/entities, extra key fields, or missing stable key values.

## Runtime, sensitive, and collection metadata

Runtime/sensitive/ignored fields are path-aware. Declaring `modified_date` removes that exact field; it does not recursively remove every nested field with the same name.

List order is preserved by default because order can be meaningful. Add a path to `unordered_paths` only when element order is semantically irrelevant. This keeps SHA-256 fingerprints stable without hiding meaningful workflow/action ordering changes.

## Rename behavior

Changing a machine identity can look like one object removed and another object created. Configuration Manager may suggest a conservative possible rename when the only differences are identity fields, but it never applies the rename automatically. A real import is blocked while that possible rename remains unresolved.

After reviewing an intentional rename, an operator can confirm the identity relationship through `ConfigManager.confirmIdentityAlias`; then align/re-export YAML under the accepted identity. The local alias preserves baseline continuity without making an unsafe guess about how the provider itself performs a rename.

## Advanced hook: `hook_civicfg_configTypes()`

Use `civicfg_configTypes()` when metadata is not enough, for example private database tables, non-API4 configuration, complex multi-entity transforms, generated assets, or provider-specific workflows that cannot be represented safely by the metadata contract.

An advanced handler should still follow the same architectural rules: semantic cross-environment identity, portable YAML, type-preserving canonical comparison, explicit write safety, stable references instead of local IDs, and deterministic dependency behavior.

No test-specific public hook is provided. Tests exercise the same hooks used by real extensions.

## QA coverage

`tests/phpunit/Unit/HandlerRegistryTest.php` verifies both public hook dispatch paths. `tests/phpunit/Unit/EntityDefinitionHandlerTest.php` covers stable/composite keys, create/update/delete capabilities, path-aware ignored/runtime/sensitive fields, semantic reference export/import, and dependency metadata. Identity/canonicalization/state behavior is covered independently in the corresponding service unit tests.

The full real-fixture suite also evaluates regular contributed extensions so gaps improve the generic engine rather than adding extension-name special cases to production code.
