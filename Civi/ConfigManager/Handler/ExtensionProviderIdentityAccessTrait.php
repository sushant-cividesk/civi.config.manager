<?php
namespace Civi\ConfigManager\Handler;

/**
 * ExtensionProviderIdentityAccess methods from ExtensionHandler.
 *
 * Methods run on the original owning object and retain the same state and
 * access modifiers. Do not instantiate this trait separately.
 */
trait ExtensionProviderIdentityAccessTrait {

  private function stripRuntime(array $row): array {
    // Remove only known top-level runtime metadata. Nested fields with the same
    // names may be genuine contributed-extension configuration and must remain.
    unset($row['id'], $row['created_date'], $row['modified_date'], $row['last_modified'], $row['created_id'], $row['modified_id']);
    return $row;
  }

  private function stripReadOnlyFields(array $row, array $fields): array {
    foreach ($fields as $name => $field) {
      $field = (array) $field;
      if (!empty($field['readonly']) || !empty($field['read_only']) || (($field['type'] ?? '') === 'Extra')) {
        unset($row[$name]);
      }
    }
    return $row;
  }

  private function identityField(array $row, array $definition = []): ?string {
    return $this->providerIdentityRules()->identityField($row, $definition);
  }

  private function identityConfidence(string $field, array $definition = []): string {
    return $this->providerIdentityRules()->identityConfidence($field, $definition);
  }

  private function identityValueIsUnique(array $rows, string $field, string $identity): bool {
    return $this->providerIdentityRules()->identityValueIsUnique($rows, $field, $identity);
  }

  private function runtimeIdentityConfidence(array $definition, string $field, string $identity): string {
    $confidence = $this->identityConfidence($field, $definition);
    if ($confidence === 'AMBIGUOUS') {
      return $confidence;
    }

    // Source/export identity validation requires exactly one matching row, but
    // target import is different: zero matches is the normal CREATE case. A
    // strong semantic identity is unsafe only when the target already contains
    // more than one matching record and Configuration Manager cannot choose.
    return $this->providerIdentityHasDuplicateConflict($definition, $field, $identity) ? 'AMBIGUOUS' : $confidence;
  }

  /**
   * Check one target identity without caching the provider's complete rows.
   */
  private function providerIdentityHasDuplicateConflict(array $definition, string $field, string $identity): bool {
    $definitionKey = $this->definitionKey(
      (string) ($definition['extension'] ?? ''),
      (string) ($definition['api'] ?? ''),
      (string) ($definition['entity'] ?? '')
    );
    if (array_key_exists($definitionKey, $this->identityRowsByDefinition)) {
      return $this->identityValueHasDuplicateConflict(
        (array) $this->identityRowsByDefinition[$definitionKey],
        $field,
        $identity
      );
    }

    $matches = 0;
    if (($definition['api'] ?? '') === 'api4') {
      foreach ($this->api4Iterate((string) $definition['entity'], [[$field, '=', $identity]], [$field, 'id']) as $_row) {
        $matches++;
        if ($matches > 1) {
          return TRUE;
        }
      }
      return FALSE;
    }

    $readAdapter = trim((string) ($definition['read_adapter'] ?? ''));
    $action = (string) ($definition['list_action'] ?? 'get');
    if ($readAdapter === '' && $action === 'get') {
      $result = civicrm_api3((string) $definition['entity'], 'get', [
        'sequential' => 1,
        $field => $identity,
        'options' => ['limit' => 2],
      ]);
      return count($this->normalizeApi3Rows((array) $result)) > 1;
    }

    foreach ($this->iterateCleanProviderRows($definition) as $row) {
      $row = (array) $row;
      if (!array_key_exists($field, $row) || !is_scalar($row[$field]) || (string) $row[$field] !== $identity) {
        continue;
      }
      $matches++;
      if ($matches > 1) {
        return TRUE;
      }
    }
    return FALSE;
  }

  private function identityValueHasDuplicateConflict(array $rows, string $field, string $identity): bool {
    return $this->providerIdentityRules()->identityValueHasDuplicateConflict($rows, $field, $identity);
  }

  private function invalidateIdentityRowsForDefinition(array $definition): void {
    $key = $this->definitionKey(
      (string) ($definition['extension'] ?? ''),
      (string) ($definition['api'] ?? ''),
      (string) ($definition['entity'] ?? '')
    );
    unset($this->identityRowsByDefinition[$key]);
  }

  /**
   * Return cleaned portable rows once per provider for identity checks.
   *
   * Validation/import can inspect many YAML items for the same provider. Cache
   * the provider collection for the lifetime of this handler so uniqueness
   * checks do not repeatedly call API3/API4.
   */
  private function identityRowsForDefinition(array $definition): array {
    $key = $this->definitionKey(
      (string) ($definition['extension'] ?? ''),
      (string) ($definition['api'] ?? ''),
      (string) ($definition['entity'] ?? '')
    );
    if (array_key_exists($key, $this->identityRowsByDefinition)) {
      return $this->identityRowsByDefinition[$key];
    }

    // Keep the cache bounded to the provider currently being inspected. YAML
    // for extension providers is naturally grouped, so this retains the useful
    // no-repeat behavior without pinning every provider collection in memory
    // for the lifetime of a full export/validate/import request.
    $this->identityRowsByDefinition = [];

    $rows = [];
    foreach ($this->fetchEntityRows($definition) as $row) {
      $row = $this->cleanEntityRowForExport((array) $row, $definition);
      if (!$this->isPackagedExtensionAssetRow($row, $definition)) {
        $rows[] = $row;
      }
    }
    $this->identityRowsByDefinition[$key] = array_values($rows);
    return $this->identityRowsByDefinition[$key];
  }

  /**
   * Summarize whether current provider rows have safe cross-environment keys.
   */
  private function identitySafetyForRows(array $rows, array $definition): string {
    return $this->providerIdentityRules()->identitySafetyForRows($rows, $definition);
  }

  /**
   * Build identity multiplicities in O(n) for provider-wide safety checks.
   *
   * Older code rescanned the complete provider collection for every row,
   * making large providers quadratic during export/delete safety analysis.
   * Keep one compact count map instead.
   *
   * @return array<string,array<string,int>>
   */
  private function identityMultiplicityForRows(array $rows, array $definition): array {
    return $this->providerIdentityRules()->identityMultiplicityForRows($rows, $definition);
  }

  private function findExistingEntityRow(array $definition, string $identityField, string $identity): ?array {
    if ($definition['api'] === 'api4') {
      return $this->api4GetFirst((string) $definition['entity'], [[$identityField, '=', $identity]], ['*']);
    }
    try {
      $readAdapter = trim((string) ($definition['read_adapter'] ?? ''));
      $action = (string) ($definition['list_action'] ?? 'get');
      if ($readAdapter === '' && $action === 'get') {
        $result = civicrm_api3((string) $definition['entity'], 'get', ['sequential' => 1, $identityField => $identity, 'options' => ['limit' => 1]]);
        $values = array_values((array) ($result['values'] ?? []));
        return $values[0] ?? NULL;
      }
      foreach ($this->fetchEntityRows($definition) as $row) {
        $row = (array) $row;
        if (isset($row[$identityField]) && (string) $row[$identityField] === $identity) {
          return $row;
        }
      }
      return NULL;
    }
    catch (\Throwable $e) {
      throw new \RuntimeException('Could not look up existing contributed configuration provider API3 ' . (string) $definition['entity'] . ': ' . $e->getMessage(), 0, $e);
    }
  }

  private function createEntityRow(array $definition, array $values): array {
    if ($definition['api'] === 'api4') {
      return $this->api4Create((string) $definition['entity'], $values);
    }
    $result = civicrm_api3((string) $definition['entity'], 'create', $values + ['sequential' => 1]);
    return $this->firstApi3ResultRow((array) $result);
  }

  private function updateEntityRow(array $definition, array $existing, array $values): array {
    if ($definition['api'] === 'api4') {
      return $this->api4Update((string) $definition['entity'], [['id', '=', (int) $existing['id']]], $values);
    }
    if (empty($existing['id'])) {
      throw new \RuntimeException('Existing APIv3 row has no id, so it cannot be updated safely.');
    }
    $values['id'] = (int) $existing['id'];
    $result = civicrm_api3((string) $definition['entity'], 'create', $values + ['sequential' => 1]);
    return $this->firstApi3ResultRow((array) $result);
  }

  /**
   * Normalize API3 write responses which may return either a row list or one
   * associative row directly under `values`.
   */
  private function firstApi3ResultRow(array $result): array {
    $rows = $this->normalizeApi3Rows($result);
    return !empty($rows[0]) && is_array($rows[0]) ? (array) $rows[0] : [];
  }

  private function deleteEntityRow(array $definition, int $id): void {
    if ($definition['api'] === 'api4') {
      $this->api4Delete((string) $definition['entity'], [['id', '=', $id]]);
      return;
    }
    $action = (string) ($definition['delete_action'] ?? 'delete');
    civicrm_api3((string) $definition['entity'], $action, ['id' => $id]);
  }

  private function dependenciesForEntityRow(array $row, array $definition): array {
    $dependencies = [[
      'type' => 'extensions',
      'entity' => 'Extension',
      'name' => (string) $definition['extension'],
      'reason' => 'This bundled configuration is provided by this extension API entity.',
    ],
    ];
    $json = json_encode($row);
    if ($json) {
      preg_match_all('/afsearch[A-Za-z0-9_:-]+/', $json, $matches);
      foreach (array_values(array_unique($matches[0] ?? [])) as $name) {
        $dependencies[] = [
          'type' => 'formbuilder-afforms',
          'entity' => 'Afform',
          'name' => $name,
          'reason' => 'Extension configuration references this FormBuilder Afform.',
        ];
      }
    }
    return $this->uniqueDependencies($dependencies);
  }

}
