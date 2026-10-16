<?php
namespace Civi\ConfigManager\Handler;

/**
 * Pure portable-identity selection and multiplicity rules for discovered
 * extension configuration providers. No API calls or stateful row caching.
 */
final class ExtensionProviderIdentity {
  public function identityField(array $row, array $definition = []): ?string {
    foreach ((array) ($definition['match_fields'] ?? []) as $field) {
      $field = (string) $field;
      if ($field !== 'id' && array_key_exists($field, $row) && is_scalar($row[$field]) && (string) $row[$field] !== '') {
        return $field;
      }
    }
    foreach (['key', 'machine_name', 'name', 'workflow_name', 'name_a_b'] as $field) {
      if (!empty($row[$field]) && is_scalar($row[$field])) {
        return $field;
      }
    }
    // Weak identities are still useful for export/diff visibility, but imports
    // are blocked unless the provider exposes a stronger stable key.
    foreach (['title', 'label'] as $field) {
      if (!empty($row[$field]) && is_scalar($row[$field])) {
        return $field;
      }
    }
    return NULL;
  }

  public function identityConfidence(string $field, array $definition = []): string {
    if (in_array($field, array_map('strval', (array) ($definition['match_fields'] ?? [])), TRUE)) {
      return 'API_VERIFIED';
    }
    return in_array(strtolower($field), ['key', 'machine_name', 'name', 'workflow_name', 'name_a_b'], TRUE)
      ? 'DISCOVERED_UNIQUE'
      : 'AMBIGUOUS';
  }

  public function identityValueIsUnique(array $rows, string $field, string $identity): bool {
    $matches = 0;
    foreach ($rows as $row) {
      $row = (array) $row;
      if (array_key_exists($field, $row) && is_scalar($row[$field]) && (string) $row[$field] === $identity) {
        $matches++;
        if ($matches > 1) {
          return FALSE;
        }
      }
    }
    return $matches === 1;
  }

  public function identityValueHasDuplicateConflict(array $rows, string $field, string $identity): bool {
    $matches = 0;
    foreach ($rows as $row) {
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

  public function identitySafetyForRows(array $rows, array $definition): string {
    if (!$rows) {
      return 'UNVERIFIED';
    }

    $counts = $this->identityMultiplicityForRows($rows, $definition);
    foreach ($rows as $row) {
      $row = (array) $row;
      $field = $this->identityField($row, $definition);
      if ($field === NULL || $this->identityConfidence($field, $definition) === 'AMBIGUOUS') {
        return 'UNSAFE';
      }
      $identity = (string) $row[$field];
      if (($counts[$field][$identity] ?? 0) !== 1) {
        return 'UNSAFE';
      }
    }

    return 'SAFE';
  }

  public function identityMultiplicityForRows(array $rows, array $definition): array {
    $counts = [];
    foreach ($rows as $row) {
      $row = (array) $row;
      $field = $this->identityField($row, $definition);
      if ($field === NULL || !array_key_exists($field, $row) || !is_scalar($row[$field])) {
        continue;
      }
      $identity = (string) $row[$field];
      $counts[$field][$identity] = ($counts[$field][$identity] ?? 0) + 1;
    }
    return $counts;
  }
}
