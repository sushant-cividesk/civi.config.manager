# Configuration Manager 0.1.0-alpha67.7-core

Alpha67.7 is the follow-up repair checkpoint for the early source coverage expansion for the client-required configuration coverage expansion without skipping the roadmap: Alpha68 remains the next planned milestone, while Alpha69 remains the later runtime-evidence/coverage-completion milestone. It adds conservative create/update support for Tags, Profiles/UF Groups, Profile Fields, Contact Layouts, and traditional CiviReport instances without broadening generic CRUD authority.

## Added coverage

- Tags use stable `name` identity and semantic parent-tag references.
- Profiles use UFGroup `name`; Profile Fields use a reviewed repeated-field semantic identity adapter so multiple fields such as Home/Work phone can coexist without filename or identity collisions; UFGroup/LocationType IDs resolve only at the API boundary.
- Contact Layouts translate known nested Group, Profile, Custom Group, and Relationship Type IDs to semantic references and fail closed on unknown scalar `*_id` shapes.
- Report Instances prefer APIv3 `report_id + name` identity and use a guarded `report_id + title` fallback for legacy unnamed rows when unambiguous, while preserving saved criteria plus explicit access/activation settings.

## Safety boundary

- Delete-missing is disabled for every newly added family.
- Entity-tag assignments, profile submissions, report output, contacts, contributions, memberships, activities, participants, grants, and other business/transactional rows are outside these handlers.
- Contact Layout duplicate labels and unknown nested local-ID shapes fail closed.
- Report instances missing `report_id`, blank name+title, or ambiguous fallback identity fail export instead of being guessed or silently omitted.
- Report navigation IDs and delivery-recipient fields are not automatically managed in this checkpoint.

## Browser QA

Targeted-site smoke now requires the repository-local Playwright dependency installed by `npm install`. It probes the Settings page first, exercises supported Drupal/CiviCRM login surfaces only when necessary, and reports the final URL/title/heading when authentication or access does not reach Configuration Manager. The Settings wrapper assertion remains strict.

## Evidence boundary

Vendor-free syntax/architecture checks can be run from the source package, but this early coverage work is not runtime-complete until the future A69-05 gate passes on disposable real CiviCRM: export/import/re-export with different local IDs, independent final-state queries, business-data preservation, and mutation/red proof for each advertised capability.
