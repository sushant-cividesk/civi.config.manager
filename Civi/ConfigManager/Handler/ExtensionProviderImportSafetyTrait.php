<?php
namespace Civi\ConfigManager\Handler;

/**
 * ExtensionProviderImportSafety methods from ExtensionHandler.
 *
 * Methods run on the original owning object and retain the same state and
 * access modifiers. Do not instantiate this trait separately.
 */
trait ExtensionProviderImportSafetyTrait {

  private function processExtensionConfigEntry(string $filename, string $extensionKey, array $configEntry, array $definitions, array &$desiredConfigKeys, bool $dryRun, array &$summary): void {
    $api = (string) ($configEntry['api'] ?? '');
    $entity = (string) ($configEntry['entity'] ?? '');
    if (!$this->extensionConfigMatchesRuntimeFilter($extensionKey, $api, $entity)) {
      $summary['config']['skip']++;
      return;
    }
    $resolved = $this->resolveConfigDefinition($definitions, $extensionKey, $api, $entity);
    if ($resolved === NULL) {
      if ($this->isNonImportableLegacyExtensionConfig($extensionKey, $api, $entity)) {
        $summary['config']['skip']++;
        $summary['warnings'][] = [
          'file' => $filename,
          'message' => sprintf('Skipped read-only/generated extension config %s %s. Re-export to remove this obsolete YAML file.', $api, $entity),
        ];
        return;
      }
      $summary['errors'][] = [
        'file' => $filename,
        'message' => sprintf('Extension config provider is not available: extension %s, %s entity %s.', $extensionKey, $api, $entity),
      ];
      return;
    }
    $definitionKey = (string) $resolved['key'];
    $definition = $resolved['definition'];
    if ($this->isNonImportableDefinition($definition)) {
      $summary['config']['skip']++;
      $summary['warnings'][] = [
        'file' => $filename,
        'message' => sprintf('Skipped read-only/generated extension config %s %s. Re-export to remove this obsolete YAML file.', $api, $entity),
      ];
      return;
    }
    $configItem = (array) ($configEntry['item'] ?? []);
    $row = (array) ($configItem['item'] ?? $configItem);
    $sourceIdentityConfidence = strtoupper(trim((string) ($configItem['identity_confidence'] ?? ($configEntry['identity_confidence'] ?? ''))));
    $sourceCapabilities = (array) ($configItem['capabilities'] ?? ($configEntry['capabilities'] ?? []));
    $identityField = (string) ($configItem['identity_field'] ?? ($configEntry['identity_field'] ?? ''));
    if ($identityField === '' || !array_key_exists($identityField, $row) || !is_scalar($row[$identityField]) || trim((string) $row[$identityField]) === '') {
      $identityField = (string) ($this->identityField($row, $definition) ?? '');
    }
    if ($identityField === '') {
      $summary['errors'][] = ['file' => $filename, 'message' => sprintf('Extension config for %s %s is missing a stable identity field.', $api, $entity)];
      return;
    }
    $identity = (string) $row[$identityField];
    $identityKey = $this->identityKey($identityField, $identity);

    // Monitor-only source rows still protect their semantic key from
    // delete-missing. They cannot authorize writes, but they must never be
    // mistaken for objects which are absent from the source snapshot.
    $desiredConfigKeys[$definitionKey][$identityKey] = TRUE;

    if ($sourceIdentityConfidence === 'AMBIGUOUS' || !empty($configItem['monitor_only']) || !empty($configEntry['monitor_only'])) {
      $summary['config']['skip']++;
      $summary['compatibility'][] = [
        'file' => $filename,
        'message' => sprintf('%s %s is an intentional monitor-only snapshot because the source identity is ambiguous. Automatic create/update/delete was not attempted; unrelated safe identities may continue.', $api, $entity),
      ];
      return;
    }
    if ($sourceCapabilities && (empty($sourceCapabilities['create']) || empty($sourceCapabilities['update']))) {
      $summary['config']['skip']++;
      $summary['compatibility'][] = [
        'file' => $filename,
        'message' => sprintf('%s %s is a monitor-only snapshot because the source export did not authorize safe create/update capability.', $api, $entity),
      ];
      return;
    }
    if ($this->runtimeIdentityConfidence($definition, $identityField, $identity) === 'AMBIGUOUS') {
      $summary['config']['skip']++;
      $summary['compatibility'][] = [
        'file' => $filename,
        'message' => sprintf('%s %s target conflict: portable source identity %s=%s matches ambiguously on this site.', $api, $entity, $identityField, $identity),
      ];
      $summary['errors'][] = [
        'file' => $filename,
        'message' => sprintf('%s %s portable source identity %s=%s is ambiguous on the target. Resolve duplicate target records before import.', $api, $entity, $identityField, $identity),
      ];
      return;
    }

    if ($this->importWritesEnabled) {
      $this->applyBundledConfigItem($filename, $definition, $row, $identityField, $identity, $dryRun, $summary);
    }
  }

  /**
   * Record whether source YAML can authorize provider delete-missing.
   *
   * Destructive cleanup requires explicit portable identity confidence and
   * delete capability from the source export. Legacy/incomplete metadata fails
   * closed and remains export/compare-only until it is re-exported.
   */
  private function recordSourceProviderDeleteSafety(array &$providerDeleteSafe, array $definitions, string $extensionKey, array $configEntry): void {
    $resolved = $this->resolveConfigDefinition(
      $definitions,
      $extensionKey,
      (string) ($configEntry['api'] ?? ''),
      (string) ($configEntry['entity'] ?? '')
    );
    if ($resolved === NULL) {
      return;
    }
    $configItem = (array) ($configEntry['item'] ?? []);
    $identityConfidence = strtoupper(trim((string) ($configItem['identity_confidence'] ?? ($configEntry['identity_confidence'] ?? ''))));
    $capabilities = (array) ($configItem['capabilities'] ?? ($configEntry['capabilities'] ?? []));
    $safe = $identityConfidence !== ''
      && $identityConfidence !== 'AMBIGUOUS'
      && !empty($capabilities['delete']);
    $this->mergeProviderDeleteSafety($providerDeleteSafe, (string) $resolved['key'], $safe);
  }

  private function mergeProviderDeleteSafety(array &$providerDeleteSafe, string $definitionKey, bool $safe): void {
    if (!array_key_exists($definitionKey, $providerDeleteSafe)) {
      $providerDeleteSafe[$definitionKey] = $safe;
      return;
    }
    // Alpha63 applies delete safety per identity. One monitor-only source row no
    // longer disables safe cleanup for every other unique row in the provider.
    // Monitor-only desired identities are retained in desiredConfigKeys above.
    $providerDeleteSafe[$definitionKey] = !empty($providerDeleteSafe[$definitionKey]) || $safe;
  }

  private function providerIdentitySafetyForDelete(array $rows, array $definition): string {
    if ($rows) {
      return $this->identitySafetyForRows($rows, $definition);
    }
    $matchFields = array_values(array_filter(array_map('strval', (array) ($definition['match_fields'] ?? [])), static function ($field) {
      return $field !== '' && strtolower($field) !== 'id';
    }));
    // An explicitly declared provider match key can safely represent an empty
    // authoritative collection. Without one, an empty export cannot prove how
    // target rows would be matched and therefore cannot authorize deletion.
    return $matchFields ? 'SAFE' : 'UNVERIFIED';
  }

}
