# Configuration Manager 0.1.0-alpha68.2.1-core

Alpha68.2.1 is a narrow test-only hotfix for the Alpha68.2 QA run.

## Fixed

`ConfigManagerScopeUiTest::testValidationStillBlocksDependencyMissingFromYamlAndActiveCivi` still expected the retired wording `managed YAML set or active CiviCRM`, while production validation already correctly reports `managed Saved Config set or Current CiviCRM`. The regression expectation and test name now use the current product terminology.

No production import, validation, provider, dependency, scope, or Saved Config behavior changed.

## Evidence

The supplied DDEV `composer qa:fast` run is the red proof: 286 tests passed and this single assertion failed only because of the stale wording expectation. The source hotfix updates that independent expected message without changing `ConfigManager::formatMissingDependencyMessage()`.

The supplied targeted Playwright smoke subsequently passed after the required browser libraries were installed. The standalone real-runtime suite was not run because it was invoked inside DDEV first, where it correctly refuses container-inside-container execution, and Composer was not available on the host checkout afterward.
