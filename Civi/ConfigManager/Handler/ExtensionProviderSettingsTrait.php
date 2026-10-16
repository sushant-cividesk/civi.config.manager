<?php
namespace Civi\ConfigManager\Handler;

use Civi\ConfigManager\Version;
use Civi\ConfigManager\Service\ProviderAdmissionPolicy;

/**
 * ExtensionProviderSettings methods from ExtensionHandler.
 *
 * Methods run on the original owning object and retain the same state and
 * access modifiers. Do not instantiate this trait separately.
 */
trait ExtensionProviderSettingsTrait {

  private function dependenciesForExtension(string $key): array {
    return [];
  }

  private function discoverSettingsByExtension(): array {
    $groups = [];
    $metadata = $this->discoverSettingMetadata();
    foreach ($this->discoverRuntimeSettingNames() as $name) {
      $metadata[$name] = $metadata[$name] ?? ['name' => $name];
    }
    ksort($metadata, SORT_NATURAL | SORT_FLAG_CASE);

    foreach ($metadata as $name => $meta) {
      $name = (string) $name;
      if (!$this->isSafeSettingName($name) || $this->isSensitiveSettingName($name)) {
        continue;
      }
      $extensionKey = $this->extensionKeyForSetting($name, (array) $meta);
      if ($extensionKey === '' || $this->isGenericConfigSkippedExtension($extensionKey)) {
        continue;
      }
      $groups[$extensionKey][$name] = \Civi::settings()->get($name);
    }
    foreach ($groups as &$settings) {
      ksort($settings, SORT_NATURAL | SORT_FLAG_CASE);
    }
    ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
    return $groups;
  }

  private function discoverRuntimeSettingNames(): array {
    $names = [];
    try {
      $dao = \CRM_Core_DAO::executeQuery("SELECT DISTINCT name FROM civicrm_setting WHERE name IS NOT NULL AND name <> '' ORDER BY name");
      while ($dao->fetch()) {
        $name = (string) ($dao->name ?? '');
        if ($name !== '') {
          $names[$name] = TRUE;
        }
      }
      $dao->free();
    }
    catch (\Throwable $e) {
      // Some tests or install states may not have the settings table yet.
    }
    return array_keys($names);
  }

  private function discoverSplitConfigByExtension(): array {
    $files = [];
    $index = [];
    foreach ($this->discoverEntityDefinitions() as $definition) {
      $extensionKey = (string) ($definition['extension'] ?? '');
      $api = (string) ($definition['api'] ?? '');
      $entity = (string) ($definition['entity'] ?? '');

      try {
        if ($this->isGenericConfigSkippedExtension($extensionKey) || $this->isNonImportableDefinition($definition)) {
          continue;
        }
        if (!$this->definitionMatchesRuntimeFilter($definition)) {
          continue;
        }

        $providerRows = $this->identityRowsForDefinition($definition);
        $identityCounts = $this->identityMultiplicityForRows($providerRows, $definition);
        $usedNames = [];
        foreach ($providerRows as $row) {
          $identityField = $this->identityField($row, $definition);
          if ($identityField === NULL) {
            continue;
          }
          $identity = (string) $row[$identityField];
          $identityConfidence = $this->identityConfidence($identityField, $definition);
          if ($identityConfidence !== 'AMBIGUOUS' && (($identityCounts[$identityField][$identity] ?? 0) !== 1)) {
            $identityConfidence = 'AMBIGUOUS';
          }
          $safeExtension = $this->safeName($extensionKey);
          $filename = $safeExtension . '/' . $this->safeName($api) . '/' . $this->safeName($entity) . '/' . $this->uniqueConfigFileName($identity, $usedNames) . '.yml';
          $dependencies = $this->dependenciesForEntityRow($row, $definition);
          $files[] = [
            'filename' => $filename,
            'data' => [
              'schema_version' => 1,
              'type' => 'extension_config.item',
              'extension' => $extensionKey,
              'api' => $api,
              'entity' => $entity,
              'name' => $identity,
              'identity_field' => $identityField,
              'identity_confidence' => $identityConfidence,
              'capabilities' => [
                'create' => !empty($definition['can_create']) && $identityConfidence !== 'AMBIGUOUS',
                'update' => !empty($definition['can_update']) && $identityConfidence !== 'AMBIGUOUS',
                'delete' => !empty($definition['can_delete']) && $identityConfidence !== 'AMBIGUOUS',
              ],
              'dependencies' => $dependencies,
              'item' => $row,
            ],
          ];
        }

        // Keep a zero-count provider index only after the provider was read
        // successfully. A failed provider must never look like an authoritative
        // empty desired set because that could authorize destructive cleanup on
        // import.
        $identitySafety = $this->providerIdentitySafetyForDelete($providerRows, $definition);
        $index[$extensionKey][] = [
          'api' => $api,
          'entity' => $entity,
          'directory' => $this->safeName($extensionKey) . '/' . $this->safeName($api) . '/' . $this->safeName($entity),
          'count' => count($usedNames),
          'identity_safety' => $identitySafety,
          'delete_safe' => $identitySafety === 'SAFE' && !empty($definition['can_delete']),
        ];
      }
      catch (\Throwable $e) {
        $provider = trim($extensionKey . ' ' . $api . ' ' . $entity);
        $this->addExportError('Extension configuration provider ' . ($provider !== '' ? $provider : '(unknown)') . ' could not be exported: ' . $e->getMessage());
      }

      finally {
        // Export only needs one provider collection at a time. Release the
        // identity cache before moving to the next extension provider so a
        // large site does not retain every provider row for the whole request.
        $this->invalidateIdentityRowsForDefinition($definition);
      }
    }

    foreach ($index as &$rows) {
      usort($rows, fn($a, $b) => strcmp($a['api'] . ':' . $a['entity'], $b['api'] . ':' . $b['entity']));
    }
    unset($rows);
    ksort($index, SORT_NATURAL | SORT_FLAG_CASE);
    usort($files, fn($a, $b) => strcmp((string) $a['filename'], (string) $b['filename']));
    return ['files' => $files, 'index' => $index];
  }

  private function uniqueConfigFileName(string $identity, array &$used): string {
    $base = $this->safeName($identity);
    $candidate = $base;
    $i = 2;
    while (isset($used[$candidate])) {
      $candidate = $base . '-' . $i;
      $i++;
    }
    $used[$candidate] = TRUE;
    return $candidate;
  }

  private function isPackagedExtensionAssetRow(array $row, array $definition): bool {
    $extensionKey = (string) ($definition['extension'] ?? '');
    $json = json_encode($row, JSON_UNESCAPED_SLASHES);
    if (!$json || $extensionKey === '') {
      return FALSE;
    }
    $hasPackagedPath = stripos($json, '/ext/' . $extensionKey . '/') !== FALSE
      || stripos($json, '/ext/' . str_replace('.', '/', $extensionKey) . '/') !== FALSE
      || (stripos($json, $extensionKey) !== FALSE && preg_match('/\/(packages|templates|resources|assets)\//i', $json));
    if (!$hasPackagedPath) {
      return FALSE;
    }
    foreach (['content', 'html', 'body', 'template', 'msg_html', 'msg_text'] as $field) {
      if (!empty($row[$field]) && is_string($row[$field]) && strlen($row[$field]) > 200) {
        return FALSE;
      }
    }
    return TRUE;
  }

  private function discoverConfigByExtension(): array {
    $groups = [];
    foreach ($this->discoverEntityDefinitions() as $definition) {
      $extensionKey = (string) $definition['extension'];
      if ($this->isGenericConfigSkippedExtension($extensionKey) || $this->isNonImportableDefinition($definition)) {
        continue;
      }
      foreach ($this->fetchEntityRows($definition) as $row) {
        $row = $this->cleanEntityRowForExport((array) $row, $definition);
        $identityField = $this->identityField($row);
        if ($identityField === NULL) {
          continue;
        }
        $identity = (string) $row[$identityField];
        $groups[$extensionKey][(string) $definition['api']][(string) $definition['entity']][] = [
          'name' => $identity,
          'identity_field' => $identityField,
          'dependencies' => $this->dependenciesForEntityRow($row, $definition),
          'item' => $row,
        ];
      }
    }
    foreach ($groups as &$apiGroups) {
      foreach ($apiGroups as &$entityGroups) {
        foreach ($entityGroups as &$rows) {
          usort($rows, fn($a, $b) => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
        }
        ksort($entityGroups, SORT_NATURAL | SORT_FLAG_CASE);
      }
      ksort($apiGroups, SORT_NATURAL | SORT_FLAG_CASE);
    }
    ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
    return $groups;
  }

  private function flattenBundledConfig($config): array {
    $entries = [];
    foreach ((array) $config as $api => $entities) {
      foreach ((array) $entities as $entity => $items) {
        foreach ((array) $items as $item) {
          if (is_array($item)) {
            $entries[] = ['api' => (string) $api, 'entity' => (string) $entity, 'item' => $item];
          }
        }
      }
    }
    return $entries;
  }

  private function discoverSettingMetadata(): array {
    $settings = [];
    if (!function_exists('civicrm_api3')) {
      return $settings;
    }
    try {
      $result = civicrm_api3('Setting', 'getfields', ['sequential' => 1]);
      foreach ((array) ($result['values'] ?? []) as $key => $meta) {
        $meta = (array) $meta;
        $name = (string) ($meta['name'] ?? (is_string($key) ? $key : ''));
        if ($name !== '') {
          $settings[$name] = $meta;
        }
      }
    }
    catch (\Throwable $e) {
      // Optional setting discovery.
    }
    ksort($settings, SORT_NATURAL | SORT_FLAG_CASE);
    return $settings;
  }

  private function extensionKeyForSetting(string $name, array $meta): string {
    $installed = $this->installedExtensionKeys();
    foreach (['extension', 'extension_key', 'component', 'module', 'group', 'group_name'] as $field) {
      $candidate = (string) ($meta[$field] ?? '');
      if ($candidate !== '' && isset($installed[$candidate])) {
        return $candidate;
      }
    }
    foreach (array_keys($installed) as $extensionKey) {
      if ($this->settingNameLooksRelatedToExtension($name, $extensionKey)) {
        return $extensionKey;
      }
    }
    return '';
  }

  private function settingNameLooksRelatedToExtension(string $settingName, string $extensionKey): bool {
    $setting = strtolower($settingName);
    foreach ($this->extensionTokens($extensionKey) as $token) {
      if (preg_match('/^' . preg_quote($token, '/') . '([_.:-]|$)/', $setting)) {
        return TRUE;
      }
      if (preg_match('/(^|[_.:-])' . preg_quote($token, '/') . '([_.:-]|$)/', $setting)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  private function extensionTokens(string $extensionKey): array {
    $parts = preg_split('/[^A-Za-z0-9]+/', strtolower($extensionKey));
    $tokens = [];
    foreach ((array) $parts as $part) {
      if (strlen($part) >= 4 && !in_array($part, ['civi', 'civicrm', 'org', 'com', 'net', 'co', 'uk', 'info', 'extension'], TRUE)) {
        $tokens[] = $part;
      }
    }
    $last = end($parts);
    if (is_string($last) && strlen($last) >= 3) {
      $tokens[] = $last;
    }
    // Contrib extensions sometimes use a singular setting namespace while the
    // extension key is plural (for example `sqltask_*` vs `sqltasks`). Apply
    // this only to namespace parts, not the fully compacted extension key.
    foreach (array_values(array_unique($tokens)) as $token) {
      if (strlen($token) >= 5 && substr($token, -1) === 's' && substr($token, -2) !== 'ss') {
        $tokens[] = substr($token, 0, -1);
      }
    }

    $compact = preg_replace('/[^A-Za-z0-9]+/', '', strtolower($extensionKey));
    if ($compact !== '') {
      $tokens[] = $compact;
    }
    return array_values(array_unique($tokens));
  }

  private function installedExtensionKeys(): array {
    $keys = [];
    try {
      $statuses = (array) \CRM_Extension_System::singleton()->getManager()->getStatuses();
      foreach ($statuses as $key => $status) {
        if (in_array(strtolower((string) $status), ['installed', 'enabled', 'disabled'], TRUE)) {
          $keys[(string) $key] = TRUE;
        }
      }
    }
    catch (\Throwable $e) {
      // Leave empty.
    }
    return $keys;
  }

  /**
   * Decide whether an automatically discovered API4 entity is safe to treat as
   * deployable configuration. CRUD capability alone is not evidence: many
   * contributed extensions expose contacts, grants, payments, activities, or
   * other business records through API4. Accidentally exporting those records
   * would be both a configuration-integrity and data-exposure bug.
   *
   * Explicit civicfg_entityDefinitions() handlers are outside this generic
   * discovery path. Reviewed adapters may opt in here after fixture review.
   *
   * @param string[] $matchFields
   * @param array<string,array<string,mixed>> $fields
   * @return array{admitted:bool,reason:string,reason_code:string,stages:array,writable_fields:array,reference_fields:array}
   */
  private function genericApi4ConfigAdmission(array $matchFields, array $fields, bool $reviewedProvider = FALSE): array {
    return (new ProviderAdmissionPolicy())->assess($matchFields, $fields, $reviewedProvider);
  }

  private function isNonImportableDefinition(array $definition): bool {
    if (array_key_exists('generic_config_admitted', $definition) && empty($definition['generic_config_admitted'])) {
      return TRUE;
    }
    if ($this->isNonImportableLegacyExtensionConfig(
      (string) ($definition['extension'] ?? ''),
      (string) ($definition['api'] ?? ''),
      (string) ($definition['entity'] ?? '')
    )) {
      return TRUE;
    }
    foreach (['can_create', 'can_update'] as $flag) {
      if (array_key_exists($flag, $definition) && empty($definition[$flag])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  private function isNonImportableLegacyExtensionConfig(string $extensionKey, string $api, string $entity): bool {
    $entityLower = strtolower($entity);
    $extensionLower = strtolower($extensionKey);

    // Some extension API entities are generated/read-only views over packaged
    // files or runtime state. They are useful to read, but not safe deployable
    // YAML unless the provider explicitly supports create/update.
    if ($api === 'api3' && $this->api3EntityUsable($entity) && !$this->api3EntityHasAction($entity, 'create')) {
      return TRUE;
    }
    if ($api === 'api4') {
      $class = 'Civi\\Api4\\' . $entity;
      if (class_exists($class) && (!is_callable([$class, 'create']) || !is_callable([$class, 'update']))) {
        return TRUE;
      }
    }

    // Known generated-provider fallback. This stays as a safety belt for older
    // Mosaico builds where API3 action discovery may be incomplete.
    if ($extensionLower === 'uk.co.vedaconsulting.mosaico' && $entityLower === 'mosaicobasetemplate') {
      return TRUE;
    }

    return FALSE;
  }

  private function isGenericConfigSkippedExtension(string $extensionKey): bool {
    if ($extensionKey === Version::EXTENSION_KEY) {
      return TRUE;
    }
    if (preg_match('/^civi_/i', $extensionKey)) {
      return TRUE;
    }
    return in_array($extensionKey, [
      'org.civicrm.afform',
      'org.civicrm.api4',
      'org.civicrm.search_kit',
      'org.civicrm.flexmailer',
      // Dedicated CiviRulesHandler is the single owner of CiviRules config.
      'org.civicoop.civirules',
    ], TRUE);
  }

}
