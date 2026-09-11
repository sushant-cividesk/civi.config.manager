# Configuration Manager 0.1.0-alpha68.2-core

Alpha68.2 is a bounded provider-visibility checkpoint on the active Alpha68 line. It fixes the confusing gap between the provider-inventory total and the much smaller set of Settings management cards without changing provider admission or CRUD behavior.

## Provider visibility

- Normal **What should Configuration Manager manage?** cards remain limited to configuration types which already belong to the saved scope model.
- Settings now adds a collapsed **Other detected providers** section for inventory entries which do not have their own management card.
- The provider-inventory status explicitly distinguishes total detected provider entries from configurable Settings types and reports how many additional entries are explained below.
- Additional entries are grouped by what Configuration Manager can safely say about them:
  - **Managed through Extensions**
  - **Create + update through Extensions**
  - **Export + compare**
  - **Review only**
  - **Not offered separately**
  - **Cannot be managed automatically**
  - **Unavailable**
- Common safety/admission reasons are translated into concise operator-facing explanations. Extension keys, API/entity identifiers, action metadata, identity fields, reason codes, and raw provider diagnostics remain under **Technical details**.
- Rejected handler registrations keep their existing warning path and are not duplicated in the additional-provider list.

This explains cases where CiviCRM reports a large provider inventory but only a small number of extension-owned configuration providers are actually admitted for management. Detection is inventory evidence, not write authority.

## Safety boundary

- Provider discovery remains metadata-only; no provider collection is read merely to render this explanation.
- No new provider is admitted by this UI change.
- No saved scope is widened or changed automatically.
- No create, update, restore/import, or delete capability is enabled by this checkpoint.
- Business/transactional API candidates remain blocked automatically when the existing admission policy classifies them as unsafe.
- The protected `v1.0.0-beta1` baseline is not modified.
- ML-001 multilingual canonicalization is intentionally untouched and remains a required later-Alpha/Beta2 fix.

## Regression evidence

The provider-browser behavior test uses independent fixture expectations to prove that represented Settings types and rejected registrations are excluded from the supplemental list while discovered providers retain conservative safety classifications. It also proves that unknown internal reason text is not exposed in the normal explanation and that the inventory status distinguishes provider entries from configurable types.

A mutation harness deliberately changes the supplemental-provider selector to hide every additional provider. The focused test must fail on the expected visibility assertion, then the harness restores the source and requires the same test to pass.

The Playwright workflow adds a denied business-provider fixture at the real provider-inventory HTTP boundary and checks that it appears only under collapsed **Other detected providers**, is labelled **Cannot be managed automatically**, exposes a client-facing reason, keeps raw provider detail collapsed, and never becomes a management card.

## Validation still required

The source package can prove local classification, selection, mutation, syntax, and static UI contracts. It cannot prove the actual extension/provider mix or rendered browser behavior of a specific CiviCRM installation without running against that installation.

Before promoting this checkpoint, run the normal DDEV/DEV browser workflow and inspect Settings with a representative site containing contributed/custom extensions. In particular, confirm that the large provider count is understandable, that **Other detected providers** remains usable with the real provider volume, and that admitted extension configuration still appears in the normal workflow as before.

## Local source-package verification — 2026-09-11

Executed successfully in the available source-only environment:

- PHP syntax: 129 project PHP files valid.
- Alpha62 / Alpha63 / Alpha64 architecture contracts: 31 / 53 / 23 checks passed.
- Alpha68 UI behavior: 46 checks passed.
- Alpha68 lifecycle / lifecycle-state behavior: 17 / 16 checks passed.
- Drupal login URL resolver / browser workflow contract: 2 / 29 checks passed.
- Provider browser behavior: existing 9 checks plus 13 provider-discovery visibility checks passed.
- Provider-browser mutation proof: hiding every additional detected provider failed on the expected visibility assertion; restored source returned green.
- JavaScript syntax: provider browser and both affected Playwright specs parsed successfully.
- Mutation shell syntax, source hygiene, Composer audit retry-wrapper behavior, JSON metadata, `info.xml` version/date, and added-line whitespace checks passed.
- Alpha62 stress fixture passed with 5,000 API4 rows plus 5,000 YAML files under the existing 256 MB test ceiling; observed peak delta was 4 MiB.

Composer/vendor dependencies and `node_modules` are not present in this build environment, and Composer itself is unavailable. Therefore full `composer qa:fast`, PHPUnit, PHPStan, PHPCompatibility/PHPCS, the actual Playwright browser run, and disposable real-CiviCRM provider inventory validation were not executed here. Those remain required environment-level evidence rather than being inferred from the source checks above.
