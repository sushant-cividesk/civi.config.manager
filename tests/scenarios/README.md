# Developer test scenarios

Every functional change should include or update a scenario in this directory. The scenario is the developer-owned contract that describes the disposable data, action, expected result, negative cases, and isolation requirements needed by automated QA.

A scenario should not reference existing client records or production identifiers. Test records must use a unique run prefix and be created through API4 or a fixture factory. Any generated files must stay below the run-specific sync directory.

Required sections:

- `id` and `feature`
- `level`: unit, headless, integration, or browser
- `fixtures`
- `steps`
- `expected`
- `negative_cases`
- `isolation`
- `cleanup`

The initial executable scenarios are implemented in:

- `tests/phpunit/Unit`
- `tests/integration/StandaloneRoundTrip.php`
- `tests/integration/UiFixture.php`
- `tests/playwright/config-manager.spec.js`

Use `scenario.template.yml.dist` as the starting point. Run:

```bash
composer test:scenarios
```

The validation gate rejects missing sections, duplicate IDs, unsupported levels, empty expected or negative cases, and any scenario that does not explicitly block outbound email and network access.
