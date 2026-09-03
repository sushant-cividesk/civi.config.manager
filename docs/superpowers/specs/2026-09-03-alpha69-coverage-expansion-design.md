# Alpha69 Coverage Expansion Design

Date: 2026-09-03
Status: Approved design, implementation pending
Target series: `0.1.0-alpha69.x-core`

## Purpose

Alpha69 expands Configuration Manager from the current core configuration set to four client-required configuration families:

1. Tags
2. Profiles / UF Groups and Profile Fields
3. Contact Layouts
4. Traditional Reports / Report Instances

The expansion must preserve the project's existing safety model: configuration is portable YAML keyed by semantic identities, generic provider discovery remains deny-by-default, business/transactional records are never promoted to deployable configuration merely because CRUD exists, and every advertised write capability requires independent real-CiviCRM evidence.

Alpha69 is not a generic "manage every API4 entity" milestone. Each family must either be admitted through proven generic metadata or implemented through a narrow reviewed adapter that exists only for semantics the generic path cannot safely express.

## Prerequisite: targeted browser authentication diagnostics

The product Settings page is independently confirmed to render `.crm-configmanager-block` on the intended DEV site. The current targeted Playwright smoke can nevertheless reach the Settings assertion without proving that its login helper established an authenticated session.

Before Alpha69 capability work is called browser-validated:

- `login()` must fail explicitly when the expected authenticated state cannot be established.
- Drupal-integrated login must be supported without assuming that `/civicrm/login` always presents the same form.
- A failure must report the final URL and visible page title/heading before the Configuration Manager selector assertion.
- The `.crm-configmanager-block` assertion must remain unchanged; the harness must be fixed rather than weakening the product assertion.
- Targeted-site browser tests remain read-only.

This prerequisite changes test diagnostics/session establishment only. It does not change product permissions, access control, or Configuration Manager UI behavior.

## Architectural rules

### Generic-first, adapter-only-when-needed

For each Alpha69 family:

1. Inspect the real runtime API/provider metadata.
2. Run it through the existing admission policy.
3. Reuse `GenericApi4CollectionHandler` only when portable identity, writable projection, reference mapping, and write/delete capabilities are actually proven.
4. If the generic handler cannot represent a required semantic identity, dependency, or reference safely, add a focused reviewed handler/adapter for that family.
5. Do not add entity-specific special cases to `ConfigManager.php` or scatter them through unrelated shared paths.

The preferred code structure is one focused handler per family only when a handler is necessary. Shared portable-reference mechanics should be generalized only when two or more families need the same behavior and the abstraction reduces, rather than redistributes, complexity.

### Stable identities

Numeric CiviCRM IDs are never canonical deployment identities.

- Export may retain source IDs only as non-authoritative bootstrap/debug metadata where current file conventions already allow it.
- Import matching and dependency resolution must use stable semantic keys.
- Re-export on a target environment with different local IDs must be canonically equivalent to the source YAML after ignoring explicitly documented runtime fields.

### Preserve business data

Alpha69 may manage configuration definitions only.

It must not create/delete/rewrite business records that consume those definitions, including contacts, contributions, memberships, activities, participants, grants, group memberships, entity-tag assignments, profile submissions, report output, or other transactional rows.

### Delete-missing remains separately authorized

Create/update support does not imply delete-missing support.

Each new family must declare delete authority independently. If deletion cannot be proven safe for the configuration family, the handler must expose the narrower management capability and must not delete merely because generic CRUD metadata includes a delete action.

## Alpha69.1 — Tags

### Scope

Manage Tag definitions as configuration. Do not manage assignments of tags to contacts or other entities.

### Identity

Use a stable semantic Tag identity supported by the installed CiviCRM runtime. The implementation must prove uniqueness from runtime metadata/behavior rather than assuming numeric `id` or another local identifier.

### Parent references

Hierarchical tags require portable parent resolution.

YAML must represent a parent Tag by the parent's stable semantic identity. Local `parent_id` values may not be canonical YAML references.

Import order must ensure parent definitions are available before children. A missing/unresolvable parent is a preflight/import blocker, not a silent root-tag fallback.

### Preservation

Import/delete operations must not alter entity-tag assignments for Tags that remain present. If delete-missing for a Tag definition would cascade or destroy business associations, delete must remain disabled until real-runtime evidence proves an acceptable safe policy.

### Evidence

- independent export fixture for at least one root and one child Tag;
- source and target use different local IDs;
- export -> import -> re-export canonical equivalence;
- parent relationship independently queried after import;
- unrelated Tags preserved when outside managed selection;
- entity-tag/business assignments preserved;
- mutation/red-green proof for parent portable-reference resolution.

## Alpha69.2 — Profiles / UF Groups and Fields

### Scope

Treat a Profile as a dependency-aware configuration family comprising:

- Profile / UF Group definition;
- Profile / UF Field definitions belonging to that Profile.

Profile submissions or contact/business data exposed through a Profile are not configuration.

### Profile identity

Profiles must be matched by a proven stable semantic identity. Local UF Group IDs are not portable identities.

### Field identity

A Profile Field's identity is scoped to its owning Profile and must use a stable field semantic identity supported by the runtime. A local `uf_group_id`, `uf_field_id`, custom-field ID, or other environment-specific numeric identifier cannot be the canonical reference.

### Portable field references

Profile fields may reference core contact fields, custom fields, or component-provided fields. Export must encode the field reference semantically. Import must resolve the semantic reference against the target runtime before writing.

If a field cannot be resolved portably, the Profile family is blocked rather than creating an incorrect field binding.

### Dependencies

Preflight must detect dependencies relevant to the actual Profile definition, including required custom-data configuration and enabled CiviCRM components/extensions when a field/provider depends on them.

Dependencies must use the existing managed dependency system; Alpha69 must not invent an alternate dependency engine.

### Ordering

- ensure the Profile definition exists before its Profile Fields;
- remove/update fields before deleting a Profile definition when delete is authorized;
- failure in a field create/update prevents destructive cleanup for that Profile family.

### Evidence

- Profile containing a normal contact field and a custom field;
- target custom-field/local IDs differ from source;
- import resolves both fields correctly by semantic identity;
- independent final-state API query validates group and field membership/order/settings required by the fixture;
- unrelated Profiles and fields preserved;
- contact data/submissions unchanged;
- mutation proof for custom-field/reference resolution and owning-profile identity.

## Alpha69.3 — Contact Layouts

### Scope

Manage Contact Layout configuration supplied by its owning extension/provider when installed and proven compatible.

### Admission strategy

Contact Layouts are generic-first:

1. inspect the owning extension's API4/provider metadata;
2. use existing extension/provider admission when metadata proves portable identity, safe writable projection, and all references;
3. add a reviewed Contact Layout adapter only if the generic path lacks a semantic operation that cannot safely be expressed otherwise.

The existing dependency discovery that recognizes `afsearch...` references from Contact Layout data must remain canonical and must not be duplicated in a new handler.

### Provider dependency

Contact Layout import requires the owning extension/provider to exist and be compatible on the target. Missing provider support is a blocker; Configuration Manager must not create opaque fallback records.

### References

Known FormBuilder/SearchKit references must remain semantic names, not target-local IDs. Additional runtime-discovered writable references must either be explicitly mapped or cause generic admission to fail closed.

### Compatibility

Because Contact Layout is contributed configuration, Alpha69 must include representative extension-version fixtures/metadata evidence rather than assuming one installed version's field shape applies universally.

### Evidence

- metadata/admission fixture proving the intended version is admitted or explicitly unsupported;
- no provider collection read during inventory/admission;
- export -> import -> re-export equivalence on a disposable real runtime;
- referenced Afform/SearchKit dependency independently resolved on target;
- missing owning extension/provider blocks writes;
- mutation proof that removing a required semantic reference mapping makes admission/import fail.

## Alpha69.4 — Traditional Reports / Report Instances

### Scope

Manage saved Report Instance definitions/configuration. Do not manage generated report output, cached results, or business rows returned by reports.

### Identity

Use a stable report-instance identity proven by runtime metadata/behavior. Numeric report-instance IDs are not canonical deployment identities.

Where uniqueness requires more than one semantic field, use an explicit composite identity rather than fallback matching.

### Provider/template reference

A Report Instance's report/template/provider reference must be portable. Local implementation IDs must be converted to a semantic provider/template identity where the runtime allows it.

A target missing the required report provider/template is a blocker.

### Settings and permissions

Export/import must preserve deployable report configuration such as saved criteria, options, and explicit permission/access settings that are part of the Report Instance definition, while filtering runtime/cache/transient values.

Sensitive or environment-specific values must remain excluded according to the existing admission/sensitive-field rules.

### Evidence

- at least one saved Report Instance with non-default criteria/settings;
- source/target local IDs differ;
- provider/template reference resolves semantically;
- final target instance independently queried and compared against a hand-authored expected fixture;
- unrelated report instances preserved;
- report results/business data unchanged;
- mutation proof for report provider/template reference mapping.

## Alpha69.5 — capability and real-runtime gate

A family is not advertised as fully managed merely because unit tests pass.

For every newly advertised capability, record evidence satisfying the project's eight-rule test contract:

1. requirement/failure mode written before implementation;
2. independent oracle;
3. red/green or mutation proof;
4. public boundary where appropriate;
5. independent final-state assertion after import;
6. negative/preservation assertions;
7. disposable real CiviCRM confirmation;
8. adversarial review describing how the implementation could remain broken while weaker tests pass.

### Required round-trip shape

For each family:

1. create a source fixture with semantic dependencies;
2. export source YAML;
3. create/prepare a clean target whose numeric IDs intentionally differ;
4. import the exact same YAML;
5. query target state independently of Config Manager's `ok` result;
6. re-export target YAML;
7. compare canonical source/target YAML;
8. verify unrelated configuration and business data remain unchanged.

### Capability truthfulness

If any required runtime proof is missing or fails:

- keep the family monitor-only/export-only/unavailable as appropriate;
- expose the reason in provider inventory/Settings;
- do not promote it to full management merely to complete Alpha69.

## Handler ordering

The existing dependency order remains authoritative. Alpha69 families should be inserted only where dependencies require it.

Expected logical order, subject to runtime metadata confirmation:

1. Extensions/providers
2. Option Groups / Contact Types / other existing prerequisite config
3. Custom Groups and Fields
4. Tags
5. Profiles and Profile Fields
6. SearchKit / FormBuilder prerequisites
7. Contact Layouts
8. Report Instances after their owning report providers/extensions are available

The final implementation must update `docs/IMPLEMENTATION_PLAN.md` with the concrete proven order rather than relying solely on this design-level expectation.

## UI behavior

Alpha69 uses the Alpha67 provider browser. New families automatically appear in the correct group through handler/provider registration and capability metadata.

Do not add family-specific Settings branches. Each new family must provide enough canonical metadata for the existing UI to show:

- label/type;
- valid management modes;
- capability/reason;
- item/selection information where supported;
- dependencies;
- provider ownership when contributed.

## Error handling

- Missing semantic dependency: block before write where preflight can know it.
- Ambiguous identity match: block; never choose an arbitrary row.
- Provider unavailable/incompatible: block or downgrade capability; never bypass.
- Create/update failure: prevent delete-missing for the affected operation/family.
- Malformed YAML/reference: validation error with file/type/reference context.
- Unknown writable reference: fail closed.

## Non-goals

Alpha69 does not:

- manage Contacts, Contributions, Membership records, Activities, Participants, Grants, or other business/transactional records;
- add a universal API4 CRUD handler that auto-admits entities;
- redesign Alpha68 immutable import plans;
- add persistent provider-discovery caching;
- modify CiviCRM core/contrib source;
- change release/tagging policy.

## Delivery sequence

Implement and verify in independent checkpoints:

1. targeted browser authentication diagnostics prerequisite;
2. Alpha69.1 Tags;
3. Alpha69.2 Profiles/UF Groups/Fields;
4. Alpha69.3 Contact Layouts;
5. Alpha69.4 Report Instances;
6. Alpha69.5 cross-family real-runtime evidence and documentation closure.

Each checkpoint must remain reviewable and must not be called complete until its capability evidence matches its advertised status.
