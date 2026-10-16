<?php
namespace Civi\ConfigManager\Handler;

use Civi\ConfigManager\Version;
use Civi\ConfigManager\Service\DiskRowSpool;

/**
 * ExtensionProviderImport methods from ExtensionHandler.
 *
 * Methods run on the original owning object and retain the same state and
 * access modifiers. Do not instantiate this trait separately.
 */
trait ExtensionProviderImportTrait {

  public function validate(array $items): array {
    $errors = [];
    $warnings = [];
    $compatibility = [];
    $definitions = $this->entityDefinitionsByKey();

    foreach ($items as $filename => $item) {
      $type = (string) ($item['type'] ?? '');
      if ($type === 'extensions.collection') {
        foreach (($item['items'] ?? []) as $index => $extension) {
          if (empty($extension['key'])) {
            $errors[] = ['file' => $filename, 'message' => 'Extension row ' . $index . ' is missing key.'];
          }
        }
        continue;
      }
      if ($type === 'extension_config.item') {
        $this->validateExtensionConfigItem($filename, $item, $definitions, $errors, $warnings, $compatibility);
        continue;
      }
      if ($type !== 'extension.item') {
        $errors[] = ['file' => $filename, 'message' => 'Invalid type. Expected extension.item or extension_config.item.'];
        continue;
      }

      $extension = (array) ($item['extension'] ?? []);
      $key = (string) ($extension['key'] ?? ($item['key'] ?? ''));
      if ($key === '') {
        $errors[] = ['file' => $filename, 'message' => 'Extension item is missing extension.key.'];
        continue;
      }

      if (!empty($item['settings'])) {
        foreach ((array) $item['settings'] as $settingName => $settingValue) {
          $settingName = (string) $settingName;
          if (!$this->isSafeSettingName($settingName)) {
            $errors[] = ['file' => $filename, 'message' => 'Unsafe extension setting name: ' . $settingName];
          }
          if ($this->isSensitiveSettingName($settingName)) {
            $errors[] = ['file' => $filename, 'message' => 'Sensitive extension setting is blocked from import: ' . $settingName];
          }
          if (!$this->settingNameLooksRelatedToExtension($settingName, $key)) {
            $warnings[] = ['file' => $filename, 'message' => 'Setting name does not clearly match extension namespace; review before importing: ' . $settingName];
          }
        }
      }

      foreach ($this->flattenBundledConfig($item['config'] ?? []) as $entry) {
        $resolved = $this->resolveConfigDefinition($definitions, $key, (string) $entry['api'], (string) $entry['entity']);
        if ($resolved === NULL) {
          $errors[] = [
            'file' => $filename,
            'message' => sprintf('Bundled extension config provider is not available: extension %s, %s entity %s. Install/enable that extension before import.', $key, $entry['api'], $entry['entity']),
          ];
          continue;
        }
        $definition = $resolved['definition'];
        if ($this->isNonImportableDefinition($definition)) {
          $compatibility[] = [
            'file' => $filename,
            'message' => sprintf('Skipped non-admitted extension provider %s %s during validation. Re-export removes the obsolete generic-provider Saved Config; Current CiviCRM business/config data is untouched.', $entry['api'], $entry['entity']),
          ];
          continue;
        }
        $row = (array) ($entry['item']['item'] ?? []);
        $identityField = (string) ($entry['item']['identity_field'] ?? '');
        if ($identityField === '' || empty($row[$identityField])) {
          $identityField = (string) ($this->identityField($row, $definition) ?? '');
        }
        if ($identityField === '') {
          $errors[] = ['file' => $filename, 'message' => sprintf('Bundled extension config for %s %s is missing a stable identity field.', $entry['api'], $entry['entity'])];
        }
        elseif ($this->runtimeIdentityConfidence($definition, $identityField, (string) $row[$identityField]) === 'AMBIGUOUS') {
          $compatibility[] = ['file' => $filename, 'message' => sprintf('%s %s is monitor-only snapshot because the provider does not expose a unique portable identity. Automatic create/update/delete stays blocked.', $entry['api'], $entry['entity'])];
        }
      }
    }

    return [
      'type' => $this->getType(),
      'valid' => empty($errors),
      'errors' => $errors,
      'warnings' => $warnings,
      'compatibility' => $compatibility,
      'count' => count($items),
    ];
  }

  public function import(array $items, bool $dryRun = TRUE): array {
    return $this->importIterable($items, $dryRun);
  }

  public function importIterable(iterable $items, bool $dryRun = TRUE): array {
    $summary = [
      'type' => $this->getType(),
      'status' => $dryRun ? 'dry_run' : 'applied',
      'dry_run' => $dryRun,
      'install' => 0,
      'enable' => 0,
      'disable' => 0,
      'delete' => 0,
      'settings' => ['update' => 0, 'skip' => 0],
      'config' => ['create' => 0, 'update' => 0, 'delete' => 0, 'skip' => 0],
      'skip' => 0,
      'warnings' => [],
      'compatibility' => [],
      'errors' => [],
    ];

    $manager = \CRM_Extension_System::singleton()->getManager();
    $current = (array) $manager->getStatuses();
    $desiredKeys = [];
    $definitions = $this->entityDefinitionsByKey();
    $desiredConfigKeys = $this->desiredConfigKeysForRuntimeFilter($definitions);
    $providerDeleteSafe = [];

    // One pass is important here: a generator cannot be rewound for separate
    // config-index, extension-status, and provider-item passes. Process each
    // YAML document immediately and retain only compact desired identity sets.
    foreach ($items as $filename => $item) {
      $filename = (string) $filename;
      $item = (array) $item;
      $type = (string) ($item['type'] ?? '');

      if ($type === 'extensions.collection') {
        foreach ((array) ($item['items'] ?? []) as $extension) {
          $extension = (array) $extension;
          $key = (string) ($extension['key'] ?? '');
          if ($key === '') {
            $summary['errors'][] = ['file' => $filename, 'message' => 'Extension key missing.'];
            continue;
          }
          $desiredKeys[$key] = TRUE;
          if ($this->importWritesEnabled) {
            $this->applyExtensionStatus($manager, $current, $filename, $key, strtolower((string) ($extension['status'] ?? '')), $dryRun, $summary);
          }
        }
        continue;
      }

      if ($type === 'extension.item') {
        $extension = (array) ($item['extension'] ?? []);
        if (empty($extension['key']) && !empty($item['key'])) {
          $extension['key'] = $item['key'];
        }
        $key = (string) ($extension['key'] ?? '');
        if ($key === '') {
          $summary['errors'][] = ['file' => $filename, 'message' => 'Extension key missing.'];
          continue;
        }
        $desiredKeys[$key] = TRUE;

        foreach ((array) ($item['config_index'] ?? []) as $index) {
          $index = (array) $index;
          if (empty($index['api']) || empty($index['entity'])) {
            continue;
          }
          $resolved = $this->resolveConfigDefinition($definitions, $key, (string) $index['api'], (string) $index['entity']);
          if ($resolved !== NULL) {
            $definitionKey = (string) $resolved['key'];
            $desiredConfigKeys[$definitionKey] = $desiredConfigKeys[$definitionKey] ?? [];
            $this->mergeProviderDeleteSafety($providerDeleteSafe, $definitionKey, !empty($index['delete_safe']));
          }
        }

        if ($this->importWritesEnabled) {
          $this->applyExtensionStatus($manager, $current, $filename, $key, strtolower((string) ($extension['status'] ?? '')), $dryRun, $summary);
          $this->applyBundledSettings($filename, $key, (array) ($item['settings'] ?? []), $dryRun, $summary);
        }
        foreach ($this->flattenBundledConfig($item['config'] ?? []) as $configEntry) {
          $this->recordSourceProviderDeleteSafety($providerDeleteSafe, $definitions, $key, $configEntry);
          $this->processExtensionConfigEntry($filename, $key, $configEntry, $definitions, $desiredConfigKeys, $dryRun, $summary);
        }
        continue;
      }

      if ($type === 'extension_config.item') {
        $extensionKey = (string) ($item['extension'] ?? '');
        if ($extensionKey === '') {
          $summary['errors'][] = ['file' => $filename, 'message' => 'Extension config item is missing extension.'];
          continue;
        }
        $configEntry = [
          'filename' => $filename,
          'extension' => $extensionKey,
          'api' => (string) ($item['api'] ?? ''),
          'entity' => (string) ($item['entity'] ?? ''),
          'identity_field' => $item['identity_field'] ?? NULL,
          'identity_confidence' => $item['identity_confidence'] ?? NULL,
          'monitor_only' => !empty($item['monitor_only']),
          'ambiguity' => (array) ($item['ambiguity'] ?? []),
          'capabilities' => (array) ($item['capabilities'] ?? []),
          'item' => [
            'name' => $item['name'] ?? NULL,
            'identity_field' => $item['identity_field'] ?? NULL,
            'identity_confidence' => $item['identity_confidence'] ?? NULL,
            'monitor_only' => !empty($item['monitor_only']),
            'ambiguity' => (array) ($item['ambiguity'] ?? []),
            'capabilities' => (array) ($item['capabilities'] ?? []),
            'dependencies' => $item['dependencies'] ?? [],
            'item' => (array) ($item['item'] ?? []),
          ],
        ];
        $this->recordSourceProviderDeleteSafety($providerDeleteSafe, $definitions, $extensionKey, $configEntry);
        $this->processExtensionConfigEntry($filename, $extensionKey, $configEntry, $definitions, $desiredConfigKeys, $dryRun, $summary);
        continue;
      }

      $summary['errors'][] = ['file' => $filename, 'message' => 'Invalid extension YAML type. Expected extension.item or extension_config.item.'];
    }

    if ($this->deleteMissingEnabled) {
      foreach ($desiredConfigKeys as $definitionKey => $desiredForEntity) {
        if (!isset($definitions[$definitionKey])) {
          continue;
        }
        $this->deleteMissingBundledConfig($definitions[$definitionKey], $desiredForEntity, !empty($providerDeleteSafe[$definitionKey]), $dryRun, $summary);
      }

      foreach ($current as $key => $status) {
        if (isset($desiredKeys[(string) $key]) || (string) $key === Version::EXTENSION_KEY) {
          continue;
        }
        $summary['skip']++;
        $summary['warnings'][] = ['extension' => (string) $key, 'message' => 'Extension exists in CiviCRM but not YAML. It is not uninstalled automatically for safety: ' . (string) $key];
      }
    }

    $summary['ok'] = empty($summary['errors']);
    return $summary;
  }

  protected function normaliseDataForDiff(array $data): array {
    unset($data['required_by']);
    if (isset($data['item']) && is_array($data['item'])) {
      unset($data['item']['required_by']);
      if (($data['type'] ?? '') === 'extension_config.item') {
        $definitions = $this->entityDefinitionsByKey();
        $resolved = $this->resolveConfigDefinition(
          $definitions,
          (string) ($data['extension'] ?? ''),
          (string) ($data['api'] ?? ''),
          (string) ($data['entity'] ?? '')
        );
        if ($resolved !== NULL) {
          $data['item'] = $this->cleanEntityRowForImport($data['item'], $resolved['definition']);
        }
      }
    }
    return $data;
  }

  private function applyExtensionStatus($manager, array $current, string $filename, string $key, string $desired, bool $dryRun, array &$summary): void {
    $actual = strtolower((string) ($current[$key] ?? 'missing'));
    if ($actual === $desired) {
      $summary['skip']++;
      return;
    }

    try {
      if (in_array($desired, ['installed', 'enabled'], TRUE)) {
        if ($actual === 'missing') {
          $summary['errors'][] = ['file' => $filename, 'extension' => $key, 'message' => 'Extension code is not available on this site: ' . $key];
          return;
        }
        if (in_array($actual, ['uninstalled', 'not installed', 'not_installed'], TRUE)) {
          $summary['install']++;
          if (!$dryRun) {
            $this->callManager($manager, 'install', [$key]);
          }
        }
        else {
          $summary['enable']++;
          if (!$dryRun) {
            $this->callManager($manager, 'enable', [$key]);
          }
        }
      }
      elseif ($desired === 'disabled') {
        if ($key === Version::EXTENSION_KEY) {
          $summary['skip']++;
          $summary['warnings'][] = ['file' => $filename, 'extension' => $key, 'message' => 'Self-disable is skipped so Configuration Manager can finish the import safely.'];
          return;
        }
        $summary['disable']++;
        if (!$dryRun) {
          $this->callManager($manager, 'disable', [$key]);
        }
      }
      elseif (in_array($desired, ['uninstalled', 'not installed', 'not_installed'], TRUE)) {
        $summary['skip']++;
        $summary['warnings'][] = ['file' => $filename, 'extension' => $key, 'message' => 'Uninstall is skipped for safety: ' . $key];
      }
      else {
        $summary['skip']++;
        $summary['warnings'][] = ['file' => $filename, 'extension' => $key, 'message' => 'Unknown target status for ' . $key . ': ' . $desired];
      }
    }
    catch (\Throwable $e) {
      $summary['errors'][] = ['file' => $filename, 'extension' => $key, 'message' => $e->getMessage()];
    }
  }

  private function applyBundledSettings(string $filename, string $extensionKey, array $settings, bool $dryRun, array &$summary): void {
    foreach ($settings as $name => $value) {
      $name = (string) $name;
      if (!$this->isSafeSettingName($name) || $this->isSensitiveSettingName($name)) {
        $summary['errors'][] = ['file' => $filename, 'message' => 'Unsafe or sensitive extension setting skipped: ' . $name];
        continue;
      }
      if (!$this->settingNameLooksRelatedToExtension($name, $extensionKey)) {
        $summary['warnings'][] = ['file' => $filename, 'message' => 'Setting namespace is not clearly tied to ' . $extensionKey . '; skipped for safety: ' . $name];
        $summary['settings']['skip']++;
        continue;
      }
      $current = \Civi::settings()->get($name);
      if ($this->normaliseComparableValue($current) !== $this->normaliseComparableValue($value)) {
        $summary['settings']['update']++;
        if (!$dryRun) {
          \Civi::settings()->set($name, $value);
        }
      }
      else {
        $summary['settings']['skip']++;
      }
    }
  }

  private function applyBundledConfigItem(string $filename, array $definition, array $row, string $identityField, string $identity, bool $dryRun, array &$summary): void {
    try {
      $desired = $this->cleanEntityRowForImport($row, $definition);
      $existing = $this->findExistingEntityRow($definition, $identityField, $identity);
      if ($existing) {
        if ($this->desiredDiffers($existing, $desired)) {
          if (empty($definition['can_update']) && array_key_exists('can_update', $definition)) {
            $summary['config']['skip']++;
            $summary['compatibility'][] = ['file' => $filename, 'name' => $identity, 'message' => sprintf('%s %s is read-only on this site, so the YAML backup was not applied automatically.', $definition['api'], $definition['entity'])];
            return;
          }
          $summary['config']['update']++;
          if (!$dryRun) {
            $this->updateEntityRow($definition, (array) $existing, $desired);
            $this->invalidateIdentityRowsForDefinition($definition);
          }
        }
        else {
          $summary['config']['skip']++;
        }
      }
      else {
        if (empty($definition['can_create']) && array_key_exists('can_create', $definition)) {
          $summary['config']['skip']++;
          $summary['compatibility'][] = ['file' => $filename, 'name' => $identity, 'message' => sprintf('%s %s is read-only on this site, so the YAML backup was not created automatically.', $definition['api'], $definition['entity'])];
          return;
        }
        $summary['config']['create']++;
        if (!$dryRun) {
          $this->createEntityRow($definition, $desired);
          $this->invalidateIdentityRowsForDefinition($definition);
          if ($this->findExistingEntityRow($definition, $identityField, $identity) === NULL) {
            throw new \RuntimeException(sprintf(
              'Provider %s %s accepted create but the new record could not be read back by %s=%s.',
              $definition['api'],
              $definition['entity'],
              $identityField,
              $identity
            ));
          }
        }
      }
    }
    catch (\Throwable $e) {
      $message = $this->formatEntityImportException($e, $definition, $identity);
      if ($this->isEntityConflictException($e)) {
        $summary['config']['skip']++;
        $summary['warnings'][] = ['file' => $filename, 'name' => $identity, 'message' => $message];
      }
      else {
        $summary['errors'][] = ['file' => $filename, 'name' => $identity, 'message' => $message];
      }
    }
  }

  private function deleteMissingBundledConfig(array $definition, array $desiredKeys, bool $sourceDeleteSafe, bool $dryRun, array &$summary): void {
    if (!$sourceDeleteSafe) {
      $summary['compatibility'][] = [
        'message' => sprintf('%s %s delete-missing is disabled because the source YAML does not prove delete capability for any portable identity in this provider.', $definition['api'], $definition['entity']),
      ];
      return;
    }

    $spool = new DiskRowSpool();
    $identityCounts = [];
    try {
      foreach ($this->iterateCleanProviderRows($definition) as $row) {
        $row = (array) $row;
        $identityField = $this->identityField($row, $definition);
        if ($identityField === NULL) {
          continue;
        }
        $identity = (string) $row[$identityField];
        $identityCounts[$identityField][$identity] = ($identityCounts[$identityField][$identity] ?? 0) + 1;
        $spool->append(['identity_field' => $identityField, 'identity' => $identity, 'row' => $row]);
      }

      foreach ($spool->iterate() as $entry) {
        $identityField = (string) ($entry['identity_field'] ?? '');
        $identity = (string) ($entry['identity'] ?? '');
        if ($identityField === '' || $identity === '') {
          continue;
        }
        $confidence = $this->identityConfidence($identityField, $definition);
        if ($confidence === 'AMBIGUOUS' || (($identityCounts[$identityField][$identity] ?? 0) !== 1)) {
          $summary['config']['skip']++;
          $summary['compatibility'][] = [
            'name' => $identity,
            'message' => sprintf('%s %s delete-missing skipped ambiguous identity %s=%s; unrelated unique identities remain eligible for safe cleanup.', $definition['api'], $definition['entity'], $identityField, $identity),
          ];
          continue;
        }
        if (isset($desiredKeys[$this->identityKey($identityField, $identity)])) {
          continue;
        }
        if (empty($definition['can_delete']) && array_key_exists('can_delete', $definition)) {
          $summary['config']['skip']++;
          $summary['compatibility'][] = [
            'name' => $identity,
            'message' => sprintf('%s %s does not expose a delete action, so Configuration Manager leaves the active record in place.', $definition['api'], $definition['entity']),
          ];
          continue;
        }
        $summary['config']['delete']++;
        $summary['warnings'][] = [
          'name' => $identity,
          'message' => sprintf('Bundled extension config %s %s exists in CiviCRM but not YAML and will be deleted when import is applied: %s', $definition['api'], $definition['entity'], $identity),
        ];
        if (!$dryRun) {
          try {
            $live = $this->findExistingEntityRow($definition, $identityField, $identity);
            if ($live && !empty($live['id'])) {
              $this->deleteEntityRow($definition, (int) $live['id']);
            }
          }
          catch (\Throwable $e) {
            $summary['errors'][] = ['name' => $identity, 'message' => 'Delete failed: ' . $e->getMessage()];
          }
        }
      }
    }
    finally {
      $spool->close();
    }
  }

  private function expandItems(array $items, array &$summary): array {
    $rows = [];
    foreach ($items as $filename => $item) {
      $type = (string) ($item['type'] ?? '');
      if ($type === 'extensions.collection') {
        foreach (($item['items'] ?? []) as $extension) {
          $rows[] = ['filename' => $filename, 'extension' => (array) $extension, 'item' => ['extension' => (array) $extension]];
        }
      }
      elseif ($type === 'extension.item') {
        $extension = (array) ($item['extension'] ?? []);
        if (empty($extension['key']) && !empty($item['key'])) {
          $extension['key'] = $item['key'];
        }
        $rows[] = ['filename' => $filename, 'extension' => $extension, 'item' => (array) $item];
      }
      elseif ($type !== 'extension_config.item') {
        $summary['errors'][] = ['file' => $filename, 'message' => 'Invalid extension YAML type. Expected extension.item or extension_config.item.'];
      }
    }
    return $rows;
  }

  private function validateExtensionConfigItem(string $filename, array $item, array $definitions, array &$errors, array &$warnings, array &$compatibility): void {
    $extensionKey = (string) ($item['extension'] ?? '');
    $api = (string) ($item['api'] ?? '');
    $entity = (string) ($item['entity'] ?? '');
    if ($extensionKey === '' || $api === '' || $entity === '') {
      $errors[] = ['file' => $filename, 'message' => 'Extension config item is missing extension, api, or entity.'];
      return;
    }
    $resolved = $this->resolveConfigDefinition($definitions, $extensionKey, $api, $entity);
    if ($resolved === NULL) {
      if ($this->isNonImportableLegacyExtensionConfig($extensionKey, $api, $entity)) {
        $warnings[] = [
          'file' => $filename,
          'message' => sprintf('Read-only/generated extension config is no longer imported and should be removed by running Export: extension %s, %s entity %s.', $extensionKey, $api, $entity),
        ];
        return;
      }
      $errors[] = [
        'file' => $filename,
        'message' => sprintf('Extension config provider is not available: extension %s, %s entity %s. Install/enable that extension before import.', $extensionKey, $api, $entity),
      ];
      return;
    }
    $row = (array) ($item['item'] ?? []);
    $identityField = (string) ($item['identity_field'] ?? '');
    $definition = $resolved['definition'];
    if ($this->isNonImportableDefinition($definition)) {
      $compatibility[] = [
        'file' => $filename,
        'message' => sprintf('Extension provider %s %s is no longer admitted by generic configuration discovery. Import will skip this YAML; re-export removes it safely.', $api, $entity),
      ];
      return;
    }
    if ($identityField === '' || empty($row[$identityField])) {
      $identityField = (string) ($this->identityField($row, $definition) ?? '');
    }
    if ($identityField === '') {
      $errors[] = ['file' => $filename, 'message' => sprintf('Extension config item for %s %s is missing a stable identity field.', $api, $entity)];
    }
    elseif ($this->runtimeIdentityConfidence($definition, $identityField, (string) $row[$identityField]) === 'AMBIGUOUS') {
      $compatibility[] = [
        'file' => $filename,
        'message' => sprintf('%s %s is monitor-only snapshot because %s is not a unique portable identity. Export/diff remain available; automatic create/update/delete stays blocked.', $api, $entity, $identityField),
      ];
    }
  }
}
