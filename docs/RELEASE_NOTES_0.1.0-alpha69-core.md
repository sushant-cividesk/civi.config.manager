# Configuration Manager 0.1.0-alpha69-core

Alpha69 is the source checkpoint for the client-required configuration coverage expansion. It adds conservative create/update support for Tags, Profiles/UF Groups, Profile Fields, Contact Layouts, and traditional CiviReport instances without broadening generic CRUD authority.

## Added coverage

- Tags use stable `name` identity and semantic parent-tag references.
- Profiles use UFGroup `name`; Profile Fields use the composite `uf_group_id.name + field_name` identity and semantic UFGroup/LocationType references.
- Contact Layouts translate known nested Group, Profile, Custom Group, and Relationship Type IDs to semantic references and fail closed on unknown scalar `*_id` shapes.
- Report Instances use APIv3 with composite `report_id + name` identity and preserve saved criteria plus explicit access/activation settings included in the reviewed projection.

## Safety boundary

- Delete-missing is disabled for every newly added family.
- Entity-tag assignments, profile submissions, report output, contacts, contributions, memberships, activities, participants, grants, and other business/transactional rows are outside these handlers.
- Contact Layout duplicate labels and unknown nested local-ID shapes fail closed.
- Report instances without complete `report_id + name` identity fail export instead of disappearing silently.
- Report navigation IDs and delivery-recipient fields are not automatically managed in this checkpoint.

## Browser QA

Targeted-site smoke now requires the repository-local Playwright dependency installed by `npm install`. It probes the Settings page first, exercises supported Drupal/CiviCRM login surfaces only when necessary, and reports the final URL/title/heading when authentication or access does not reach Configuration Manager. The Settings wrapper assertion remains strict.

## Evidence boundary

Vendor-free syntax/architecture checks can be run from the source package, but Alpha69 is not runtime-complete until A69-05 passes on disposable real CiviCRM: export/import/re-export with different local IDs, independent final-state queries, business-data preservation, and mutation/red proof for each advertised capability.
