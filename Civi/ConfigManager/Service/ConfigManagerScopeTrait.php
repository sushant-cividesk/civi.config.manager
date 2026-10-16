<?php
namespace Civi\ConfigManager\Service;

use Civi\ConfigManager\Handler\ScopePickerHintProviderInterface;
use Civi\ConfigManager\Storage\YamlFileStorage;

/**
 * ConfigManagerScope methods from ConfigManager.
 *
 * Methods run on the original owning object and retain the same state and
 * access modifiers. Do not instantiate this trait separately.
 */
trait ConfigManagerScopeTrait {

  public function isScopePolicyOverridden(): bool {
    return $this->scope->isPolicyOverridden();
  }

  public function getScopeSelectorHelp(): string {
    return $this->scope->selectorHelp();
  }

  public function allowsDeleteMissingForType(string $type): bool {
    return $this->scope->allowsDeleteMissing($type);
  }

  public function getScopeTypeOptions(): array {
    if ($this->scopeTypeOptionsCache !== NULL) {
      return $this->scopeTypeOptionsCache;
    }

    $rows = [];
    foreach ($this->getAllHandlers() as $handler) {
      $capability = $this->scopeCapabilityForHandler($handler);
      $rows[] = [
        'type' => (string) $handler->getType(),
        'base_type' => (string) $handler->getType(),
        'label' => (string) $handler->getLabel(),
        'directory' => (string) $handler->getDirectory(),
        'weight' => (int) $handler->getWeight(),
        'virtual' => FALSE,
        'provider' => '',
        'capability' => $capability['key'],
        'capability_label' => $capability['label'],
        'capability_help' => $capability['help'],
      ];
    }
    usort($rows, static function($a, $b) {
      $cmp = ((int) $a['weight']) <=> ((int) $b['weight']);
      return $cmp !== 0 ? $cmp : strcmp((string) $a['label'], (string) $b['label']);
    });

    // Scope dependencies are guidance for building a portable deployable set.
    // They deliberately do not force a mode: an administrator may knowingly
    // rely on configuration that already exists on the target environment.
    $rules = $this->getScopeDependencyRules();
    $labels = [];
    foreach ($rows as $row) {
      $labels[(string) $row['type']] = (string) $row['label'];
    }
    $dependents = [];
    foreach ($rules as $sourceType => $dependencies) {
      foreach ($dependencies as $dependency) {
        $dependencyType = (string) ($dependency['type'] ?? '');
        if ($dependencyType !== '' && isset($labels[$sourceType], $labels[$dependencyType])) {
          $dependents[$dependencyType][] = [
            'type' => (string) $sourceType,
            'label' => (string) $labels[$sourceType],
          ];
        }
      }
    }

    foreach ($rows as &$row) {
      $type = (string) $row['type'];
      $dependencies = [];
      foreach ((array) ($rules[$type] ?? []) as $dependency) {
        $dependencyType = (string) ($dependency['type'] ?? '');
        if ($dependencyType === '' || !isset($labels[$dependencyType])) {
          continue;
        }
        $dependencies[] = [
          'type' => $dependencyType,
          'label' => (string) $labels[$dependencyType],
          'reason' => (string) ($dependency['reason'] ?? ''),
        ];
      }
      $row['scope_dependencies'] = $dependencies;
      $row['scope_dependency_types'] = implode(',', array_map(static function($dependency) {
        return (string) $dependency['type'];
      }, $dependencies));
      $row['scope_dependents'] = array_values((array) ($dependents[$type] ?? []));
    }
    unset($row);

    $this->scopeTypeOptionsCache = $rows;
    return $this->scopeTypeOptionsCache;
  }

  /**
   * Describe configuration types that are commonly referenced by another type.
   *
   * These are deployment-safety relationships, not hard database constraints.
   * Import validation still checks the actual YAML dependencies and accepts a
   * dependency that is already present in active CiviCRM. Keeping this map as
   * guidance avoids silently widening scope while still making risky scope
   * combinations visible before an export or import is attempted.
   */
  public function getScopeDependencyRules(): array {
    return [
      'custom-data' => [
        ['type' => 'option-groups', 'reason' => 'Custom fields can use option groups for choice values.'],
        ['type' => 'contact-types', 'reason' => 'Custom groups can be limited to contact types or subtypes.'],
        ['type' => 'site-tokens', 'reason' => 'Portable custom configuration can reference site-provided tokens.'],
      ],
      'relationship-types' => [
        ['type' => 'contact-types', 'reason' => 'Relationship types can be limited to contact types or subtypes.'],
      ],
      'profile-fields' => [
        ['type' => 'profiles', 'reason' => 'Profile fields belong to Profiles/UF Groups.'],
        ['type' => 'location-types', 'reason' => 'Profile fields can reference portable location types.'],
      ],
      'contact-layouts' => [
        ['type' => 'profiles', 'reason' => 'Contact Layout blocks can reference Profiles.'],
        ['type' => 'custom-data', 'reason' => 'Contact Layout blocks can reference custom groups.'],
        ['type' => 'relationship-types', 'reason' => 'Related-contact blocks can reference relationship types.'],
      ],
      'searchkit-displays' => [
        ['type' => 'searchkit-saved-searches', 'reason' => 'SearchKit displays belong to saved searches.'],
      ],
      'formbuilder-afforms' => [
        ['type' => 'searchkit-saved-searches', 'reason' => 'Afforms can embed or depend on SearchKit saved searches.'],
        ['type' => 'searchkit-displays', 'reason' => 'Afforms can embed SearchKit displays.'],
      ],
      'civirules' => [
        ['type' => 'extensions', 'reason' => 'CiviRules configuration requires the CiviRules extension/provider to exist on the target.'],
      ],
      'site-tokens' => [
        ['type' => 'extensions', 'reason' => 'Site token providers can be supplied by extensions that must exist on the target.'],
      ],
    ];
  }

  /**
   * Return warnings for the currently saved scope dependency combination.
   *
   * @return array<int,array<string,string>>
   */
  public function getScopeDependencyWarnings(): array {
    $rows = $this->getScopeTypeOptions();
    $byType = [];
    foreach ($rows as $row) {
      $byType[(string) $row['type']] = $row;
    }

    $warnings = [];
    foreach ($rows as $row) {
      $sourceType = (string) $row['type'];
      $sourcePolicy = $this->scope->getPolicy($sourceType);
      $sourceMode = (string) ($sourcePolicy['mode'] ?? ConfigScope::MODE_IGNORE);
      if (!in_array($sourceMode, [ConfigScope::MODE_ALL, ConfigScope::MODE_SELECTED], TRUE)) {
        continue;
      }
      if ($sourceMode === ConfigScope::MODE_SELECTED && empty($sourcePolicy['selectors'])) {
        continue;
      }

      foreach ((array) ($row['scope_dependencies'] ?? []) as $dependency) {
        $dependencyType = (string) ($dependency['type'] ?? '');
        if ($dependencyType === '' || !isset($byType[$dependencyType])) {
          continue;
        }
        $dependencyRow = $byType[$dependencyType];
        $dependencyPolicy = $this->scope->getPolicy($dependencyType);
        $dependencyMode = (string) ($dependencyPolicy['mode'] ?? ConfigScope::MODE_IGNORE);
        $sourceLabel = (string) $row['label'];
        $dependencyLabel = (string) $dependencyRow['label'];
        $reason = trim((string) ($dependency['reason'] ?? ''));

        if ((string) ($dependencyRow['capability'] ?? '') === 'unavailable') {
          $warnings[] = [
            'level' => 'error',
            'source_type' => $sourceType,
            'dependency_type' => $dependencyType,
            'message' => $sourceLabel . ' can reference ' . $dependencyLabel . ', but ' . $dependencyLabel . ' is unavailable on this site.' . ($reason !== '' ? ' ' . $reason : ''),
          ];
          continue;
        }
        if ($dependencyMode === ConfigScope::MODE_IGNORE) {
          $warnings[] = [
            'level' => 'warning',
            'source_type' => $sourceType,
            'dependency_type' => $dependencyType,
            'message' => $sourceLabel . ' can reference ' . $dependencyLabel . ', but ' . $dependencyLabel . ' is ignored and will not be included in managed Saved Configs.' . ($reason !== '' ? ' ' . $reason : ''),
          ];
        }
        elseif ($dependencyMode === ConfigScope::MODE_WATCH) {
          $warnings[] = [
            'level' => 'warning',
            'source_type' => $sourceType,
            'dependency_type' => $dependencyType,
            'message' => $sourceLabel . ' can reference ' . $dependencyLabel . ', but ' . $dependencyLabel . ' is monitor-only and will not be restored/imported.' . ($reason !== '' ? ' ' . $reason : ''),
          ];
        }
        elseif ($dependencyMode === ConfigScope::MODE_SELECTED) {
          $dependencySelectors = (array) ($dependencyPolicy['selectors'] ?? []);
          $emptySelected = !$dependencySelectors;
          $warnings[] = [
            'level' => $emptySelected ? 'warning' : 'review',
            'source_type' => $sourceType,
            'dependency_type' => $dependencyType,
            'message' => $emptySelected
              ? $dependencyLabel . ' uses selected-item scope but no items are selected, so referenced dependencies will not be deployed.' . ($reason !== '' ? ' ' . $reason : '')
              : $dependencyLabel . ' uses selected-item scope. Verify that every item referenced by ' . $sourceLabel . ' is included before promotion.' . ($reason !== '' ? ' ' . $reason : ''),
          ];
        }
      }
    }

    return $warnings;
  }

  /**
   * Lazily enumerate active items for one scope type. Settings never calls this
   * until an administrator opens that type's item picker.
   */
  public function getScopePickerItems(string $type): array {
    if (!preg_match('/^[A-Za-z0-9_.-]+$/', $type)) {
      throw new \RuntimeException('Invalid Configuration Scope type.');
    }

    $handler = NULL;
    foreach ($this->getAllHandlers() as $candidate) {
      if ((string) $candidate->getType() === $type) {
        $handler = $candidate;
        break;
      }
    }
    if ($handler === NULL) {
      throw new \RuntimeException('Unknown Configuration Scope type: ' . $type);
    }

    if (method_exists($handler, 'getRuntimeAvailability')) {
      $availability = (array) $handler->getRuntimeAvailability();
      if (array_key_exists('available', $availability) && empty($availability['available'])) {
        return [
          'type' => $type,
          'label' => (string) $handler->getLabel(),
          'policy' => $this->scope->getPolicy($type),
          'available' => FALSE,
          'unavailable_reason' => trim((string) ($availability['reason'] ?? 'Required runtime provider is not available on this site.')),
          'items' => [],
        ];
      }
    }

    $storage = new YamlFileStorage($this->getSyncDir());
    $exported = $this->attachScopeRelativePaths($handler, $handler->export());
    $pickerHints = [];
    if ($handler instanceof ScopePickerHintProviderInterface) {
      try {
        $pickerHints = (array) $handler->getScopePickerHints($exported);
      }
      catch (\Throwable $e) {
        // Picker recommendations are optional UX metadata. Never make scope
        // selection unavailable merely because a recommendation cannot be
        // calculated on a particular CiviCRM/provider version.
        $pickerHints = [];
      }
    }
    $partition = $this->scopePartition($handler, $exported, $storage, FALSE);
    $selectedKeys = array_fill_keys(array_map('strval', (array) ($partition['managed_config_keys'] ?? [])), TRUE);
    $identityService = new ConfigIdentity();
    $items = [];

    foreach ($exported as $file) {
      $file = (array) $file;
      $filename = (string) ($file['filename'] ?? '');
      $data = (array) ($file['data'] ?? []);
      if ($filename === '') {
        continue;
      }
      $identity = $identityService->identify($type, $data, $filename);
      $configKey = (string) ($identity['config_key'] ?? '');
      $relative = (string) ($file['relative_path'] ?? (trim((string) $handler->getDirectory(), '/') . '/' . $filename));
      if ($this->isIgnoredPath($relative)) {
        continue;
      }
      $pickerHint = (array) ($pickerHints[$filename] ?? []);
      $items[] = [
        'selector' => 'key:' . $configKey,
        'config_key' => $configKey,
        'label' => $this->displayLabelForConfigFile($type, $file),
        'path' => $relative,
        'source_id' => isset($file['source_id']) && is_scalar($file['source_id']) ? (string) $file['source_id'] : '',
        'selected' => isset($selectedKeys[$configKey]),
        'write_safe' => !empty($identity['write_safe']),
        'identity_confidence' => (string) ($identity['identity_confidence'] ?? ''),
        'recommended' => !empty($pickerHint['recommended']),
        'reference' => !empty($pickerHint['reference']),
        'recommendation' => trim((string) ($pickerHint['recommendation'] ?? '')),
      ];
    }

    // Keep configured selectors visible even when the active record is missing.
    // This prevents opening/saving the picker from silently losing a managed
    // backup that is intentionally waiting to be restored.
    $presentSelectors = array_fill_keys(array_map(static fn($item) => (string) ($item['selector'] ?? ''), $items), TRUE);
    foreach ((array) ($partition['selector_config_keys'] ?? []) as $selector => $configKey) {
      $stableSelector = 'key:' . trim((string) $configKey);
      if ($stableSelector === 'key:' || isset($presentSelectors[$stableSelector])) {
        continue;
      }
      $items[] = [
        'selector' => $stableSelector,
        'config_key' => (string) $configKey,
        'label' => 'Managed item currently missing from CiviCRM',
        'path' => '',
        'source_id' => '',
        'selected' => TRUE,
        'write_safe' => FALSE,
        'identity_confidence' => '',
        'missing' => TRUE,
        'source_selector' => (string) $selector,
      ];
    }
    foreach ((array) ($partition['unresolved_selectors'] ?? []) as $selector) {
      $selector = trim((string) $selector);
      if ($selector === '') {
        continue;
      }
      $items[] = [
        'selector' => $selector,
        'config_key' => '',
        'label' => 'Configured selector not found yet',
        'path' => '',
        'source_id' => '',
        'selected' => TRUE,
        'write_safe' => FALSE,
        'identity_confidence' => '',
        'missing' => TRUE,
        'source_selector' => $selector,
      ];
    }

    usort($items, static function($a, $b) {
      $recommendedCmp = ((int) !empty($b['recommended'])) <=> ((int) !empty($a['recommended']));
      if ($recommendedCmp !== 0) {
        return $recommendedCmp;
      }
      $missingCmp = ((int) !empty($a['missing'])) <=> ((int) !empty($b['missing']));
      if ($missingCmp !== 0) {
        return $missingCmp;
      }
      // Reserved workflow reference templates are useful for advanced review,
      // but CiviCRM itself hides them from the normal Message Templates list.
      // Keep them selectable while placing them after live/user templates.
      $referenceCmp = ((int) !empty($a['reference'])) <=> ((int) !empty($b['reference']));
      if ($referenceCmp !== 0) {
        return $referenceCmp;
      }
      return strnatcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
    });

    return [
      'type' => $type,
      'label' => (string) $handler->getLabel(),
      'policy' => $this->scope->getPolicy($type),
      'available' => TRUE,
      'unavailable_reason' => '',
      'items' => $items,
    ];
  }

  /**
   * Generate a deployable civicrm.settings.php example from effective scope.
   */
  public function getScopeSettingsExample(): string {
    $lines = [
      'global $civicrm_setting;',
      '',
      '$civicrm_setting[\'domain\'][\'civicfg_scope\'] = [',
    ];
    foreach ($this->getScopeTypeOptions() as $row) {
      $type = (string) ($row['type'] ?? '');
      if ($type === '') {
        continue;
      }
      $policy = $this->scope->getPolicy($type);
      $mode = (string) ($policy['mode'] ?? ConfigScope::MODE_ALL);
      if ($mode === ConfigScope::MODE_ALL && empty($policy['selectors']) && empty($policy['watch_unmanaged'])) {
        continue;
      }
      $lines[] = '  ' . var_export($type, TRUE) . ' => [';
      $lines[] = '    \'mode\' => ' . var_export($mode, TRUE) . ',';
      if ($mode === ConfigScope::MODE_SELECTED) {
        $lines[] = '    \'selectors\' => [';
        foreach ((array) ($policy['selectors'] ?? []) as $selector) {
          $lines[] = '      ' . var_export((string) $selector, TRUE) . ',';
        }
        $lines[] = '    ],';
        if (!empty($policy['watch_unmanaged'])) {
          $lines[] = '    \'watch_unmanaged\' => TRUE,';
        }
      }
      $lines[] = '  ],';
    }
    $lines[] = '];';
    return implode("\n", $lines);
  }

  /**
   * Return effective scope configuration without enumerating active records.
   */
  public function getScopeConfiguration(): array {
    $types = [];
    foreach ($this->getScopeTypeOptions() as $row) {
      $type = (string) ($row['type'] ?? '');
      if ($type === '') {
        continue;
      }
      $types[] = $row + ['policy' => $this->scope->getPolicy($type)];
    }
    return [
      'ok' => TRUE,
      'overridden' => $this->scope->isPolicyOverridden(),
      'types' => $types,
    ];
  }

  /**
   * Change one scope policy while preserving all other type policies.
   */
  public function setScopePolicy(string $type, string $mode, array $selectors = [], bool $watchUnmanaged = FALSE): array {
    $type = trim($type);
    $mode = strtolower(trim($mode));
    $validTypes = [];
    foreach ($this->getScopeTypeOptions() as $row) {
      $validTypes[(string) $row['type']] = TRUE;
    }
    if ($type === '' || !isset($validTypes[$type])) {
      throw new \RuntimeException('Unknown Configuration Scope type: ' . $type);
    }
    if (!in_array($mode, [ConfigScope::MODE_ALL, ConfigScope::MODE_SELECTED, ConfigScope::MODE_WATCH, ConfigScope::MODE_IGNORE], TRUE)) {
      throw new \RuntimeException('Invalid Configuration Scope mode: ' . $mode);
    }

    $selectors = array_values(array_unique(array_filter(array_map(static function($selector) {
      return trim((string) $selector);
    }, $selectors), 'strlen')));
    if ($mode !== ConfigScope::MODE_SELECTED) {
      $selectors = [];
      $watchUnmanaged = FALSE;
    }

    $policies = $this->scope->getPolicies();
    $policies[$type] = [
      'mode' => $mode,
      'selectors' => $selectors,
      'watch_unmanaged' => $watchUnmanaged,
    ];
    $this->saveScopePolicies($policies);

    return [
      'ok' => TRUE,
      'type' => $type,
      'policy' => $this->scope->getPolicy($type),
      'overridden' => $this->scope->isPolicyOverridden(),
    ];
  }

  public function getCrossSiteImportPolicy(): array {
    return [
      'ok' => TRUE,
      'allowed' => (bool) \Civi::settings()->get('civicfg_allow_cross_site_import'),
      'site_id' => $this->getSiteIdentifier(),
    ];
  }

  public function setCrossSiteImportAllowed(bool $allowed): array {
    \Civi::settings()->set('civicfg_allow_cross_site_import', $allowed ? 1 : 0);
    return $this->getCrossSiteImportPolicy();
  }

  private function scopeCapabilityForHandler($handler): array {
    $metadata = method_exists($handler, 'getProviderMetadata')
      ? (array) $handler->getProviderMetadata()
      : [];
    $declaredCapability = (string) ($metadata['management_capability'] ?? '');
    $runtimeCapability = '';
    $runtimeReason = '';

    if (method_exists($handler, 'getRuntimeAvailability')) {
      try {
        $availability = (array) $handler->getRuntimeAvailability();
        $runtimeReason = trim((string) ($availability['reason'] ?? ''));
        if (array_key_exists('available', $availability) && empty($availability['available'])) {
          return [
            'key' => 'unavailable',
            'label' => 'Unavailable on this site',
            'help' => $runtimeReason !== '' ? $runtimeReason : 'Required runtime provider is not available on this site.',
          ];
        }
        $runtimeCapability = (string) ($availability['management_capability'] ?? '');
        if ($runtimeCapability === 'export_only') {
          return [
            'key' => 'export_only',
            'label' => 'Export + compare',
            'help' => $runtimeReason !== '' ? $runtimeReason : 'The provider can be read, but safe automatic restore/import is not available on this site.',
          ];
        }
      }
      catch (\Throwable $e) {
        return [
          'key' => 'unavailable',
          'label' => 'Unavailable on this site',
          'help' => 'Runtime provider availability could not be confirmed: ' . $e->getMessage(),
        ];
      }
    }

    if ($handler instanceof \Civi\ConfigManager\Handler\ExtensionHandler) {
      return [
        'key' => 'mixed',
        'label' => 'Mixed provider capabilities',
        'help' => 'Extension status and safe provider config can be managed. Providers without a safe portable identity stay export/monitor-only.',
      ];
    }

    if ($declaredCapability === 'export_only') {
      return [
        'key' => 'export_only',
        'label' => 'Export + compare',
        'help' => $runtimeReason !== '' ? $runtimeReason : 'This handler can be exported and compared, but automatic restore/import is not enabled.',
      ];
    }

    try {
      $method = new \ReflectionMethod($handler, 'import');
      if ($method->getDeclaringClass()->getName() === \Civi\ConfigManager\Handler\AbstractHandler::class) {
        return [
          'key' => 'export_only',
          'label' => 'Export + compare',
          'help' => 'This handler can be exported, compared, watched, and backed up, but automatic restore/import is not implemented.',
        ];
      }
    }
    catch (\ReflectionException $e) {
      // Registered handlers implement HandlerInterface; use the conservative
      // export/compare label if a custom implementation is unusual.
      return [
        'key' => 'export_only',
        'label' => 'Export + compare',
        'help' => 'Automatic restore/import capability could not be confirmed for this handler.',
      ];
    }

    if ($runtimeCapability === 'managed_no_delete' || $declaredCapability === 'managed_no_delete') {
      return [
        'key' => 'managed_no_delete',
        'label' => 'Create + update',
        'help' => $runtimeReason !== ''
          ? $runtimeReason
          : 'Supports Saved Config export/compare plus safe create/update restore/import. Automatic removal is not enabled for this type.',
      ];
    }

    return [
      'key' => 'full',
      'label' => 'Managed',
      'help' => "Supports Saved Config export/compare plus the handler's reviewed restore/import behavior.",
    ];
  }
}
