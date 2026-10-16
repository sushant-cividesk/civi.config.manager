<?php
namespace Civi\ConfigManager\Handler;

use Civi\ConfigManager\Service\DiskRowSpool;

/**
 * ExtensionProviderExport methods from ExtensionHandler.
 *
 * Methods run on the original owning object and retain the same state and
 * access modifiers. Do not instantiate this trait separately.
 */
trait ExtensionProviderExportTrait {

  /**
   * @return array<int,array{key:string,label:string,path_prefix?:string}> */
  public function getExportUnits(): array {
    $units = [[
      'key' => '__statuses__',
      'label' => 'Extension status and settings',
    ],
    ];
    foreach ($this->discoverEntityDefinitions() as $definition) {
      $extensionKey = (string) ($definition['extension'] ?? '');
      if ($this->isGenericConfigSkippedExtension($extensionKey)
        || $this->isNonImportableDefinition($definition)
        || !$this->definitionMatchesRuntimeFilter($definition)) {
        continue;
      }
      $units[] = [
        'key' => $this->exportUnitKey($definition),
        'label' => 'Extension provider: ' . $this->labelForDefinition($definition),
        'path_prefix' => $this->safeName($extensionKey) . '/' . $this->safeName((string) ($definition['api'] ?? '')) . '/' . $this->safeName((string) ($definition['entity'] ?? '')),
      ];
    }
    return $units;
  }

  public function export(): array {
    $files = iterator_to_array($this->iterateExport(), FALSE);
    usort($files, static function (array $a, array $b): int {
      return strcmp((string) ($a['filename'] ?? ''), (string) ($b['filename'] ?? ''));
    });
    return $files;
  }

  public function iterateExport(): iterable {
    $this->exportErrors = [];
    foreach ($this->getExportUnits() as $unit) {
      foreach ($this->iterateExportUnitInternal((string) $unit['key']) as $file) {
        yield $file;
      }
    }
  }

  public function iterateExportUnit(string $unitKey, ?callable $progress = NULL): iterable {
    $this->exportErrors = [];
    foreach ($this->iterateExportUnitInternal($unitKey, $progress) as $file) {
      yield $file;
    }
  }

  /**
   * @return \Generator<int,array<string,mixed>>
   */
  private function iterateExportUnitInternal(string $unitKey, ?callable $progress = NULL): \Generator {
    if ($unitKey === '__statuses__') {
      $manager = \CRM_Extension_System::singleton()->getManager();
      $settingsByExtension = [];
      try {
        $settingsByExtension = $this->discoverSettingsByExtension();
      }
      catch (\Throwable $e) {
        $this->addExportError('Extension settings could not be discovered: ' . $e->getMessage());
      }

      foreach ($manager->getStatuses() as $key => $status) {
        $key = (string) $key;
        if (!$this->extensionMatchesRuntimeFilter($key)) {
          continue;
        }
        $data = [
          'schema_version' => 1,
          'type' => 'extension.item',
          'key' => $key,
          'dependencies' => $this->dependenciesForExtension($key),
          'extension' => ['key' => $key, 'status' => (string) $status],
        ];
        if (!empty($settingsByExtension[$key])) {
          $data['settings'] = $settingsByExtension[$key];
        }
        // config_index is rebuilt from the provider documents which actually
        // survive scope/ignore filtering in the staged workspace. This avoids
        // coupling status emission to provider order and enables durable units.
        yield ['filename' => $this->safeName($key) . '.yml', 'data' => $data];
      }
      return;
    }

    $definition = $this->definitionForExportUnit($unitKey);
    if ($definition === NULL) {
      throw new \RuntimeException('Unknown extension-provider export unit. The provider list changed after the job was planned. Start a fresh export.');
    }

    $extensionKey = (string) ($definition['extension'] ?? '');
    $api = (string) ($definition['api'] ?? '');
    $entity = (string) ($definition['entity'] ?? '');
    $spool = new DiskRowSpool();
    try {
      $counts = [];
      $fingerprintCounts = [];
      $rowCount = 0;
      foreach ($this->iterateCleanProviderRows($definition) as $row) {
        $row = (array) $row;
        $identityField = $this->identityField($row, $definition);
        if ($identityField === NULL) {
          // Rows without a usable semantic field cannot be addressed safely.
          // Keep provider export fail-closed rather than silently omitting them.
          throw new \RuntimeException($api . ' ' . $entity . ' returned a configuration row without a usable semantic identity field.');
        }
        $identity = (string) $row[$identityField];
        $fingerprint = $this->providerMonitorFingerprint($row);
        $counts[$identityField][$identity] = ($counts[$identityField][$identity] ?? 0) + 1;
        $fingerprintKey = $identityField . "\0" . $identity . "\0" . $fingerprint;
        $fingerprintCounts[$fingerprintKey] = ($fingerprintCounts[$fingerprintKey] ?? 0) + 1;
        $spool->append([
          'identity_field' => $identityField,
          'identity' => $identity,
          'fingerprint' => $fingerprint,
          'row' => $row,
        ]);
        $rowCount++;
        if ($progress !== NULL && ($rowCount % 100) === 0) {
          $progress(['processed' => $rowCount, 'stage' => 'scan', 'message' => 'Scanning extension provider ' . $this->labelForDefinition($definition) . ': ' . $rowCount . ' record(s) checked for portable/ambiguous identity.']);
        }
      }
      if ($progress !== NULL) {
        $progress(['processed' => $rowCount, 'stage' => 'spool_complete', 'message' => 'Provider identity scan complete for ' . $this->labelForDefinition($definition) . '. Building deterministic temporary YAML from the disk spool.']);
      }

      $occurrences = [];
      foreach ($spool->iterate() as $entry) {
        $row = (array) ($entry['row'] ?? []);
        $identityField = (string) ($entry['identity_field'] ?? '');
        $identity = (string) ($entry['identity'] ?? '');
        $fingerprint = (string) ($entry['fingerprint'] ?? '');
        $identityConfidence = $this->identityConfidence($identityField, $definition);
        $groupCount = (int) ($counts[$identityField][$identity] ?? 0);
        if ($identityConfidence !== 'AMBIGUOUS' && $groupCount !== 1) {
          $identityConfidence = 'AMBIGUOUS';
        }
        $monitorOnly = $identityConfidence === 'AMBIGUOUS';
        $fingerprintKey = $identityField . "\0" . $identity . "\0" . $fingerprint;
        $occurrence = ($occurrences[$fingerprintKey] ?? 0) + 1;
        $occurrences[$fingerprintKey] = $occurrence;

        $directory = $this->safeName($extensionKey) . '/' . $this->safeName($api) . '/' . $this->safeName($entity);
        if ($monitorOnly) {
          $filename = $directory . '/' . $this->safeName($identity)
            . '--ambiguous-' . substr($fingerprint, 0, 12)
            . '-' . str_pad((string) $occurrence, 2, '0', STR_PAD_LEFT) . '.yml';
        }
        else {
          $filename = $directory . '/' . $this->safeName($identity) . '.yml';
        }

        $data = [
          'schema_version' => 1,
          'type' => 'extension_config.item',
          'extension' => $extensionKey,
          'api' => $api,
          'entity' => $entity,
          'name' => $identity,
          'identity_field' => $identityField,
          'identity_portable' => !$monitorOnly,
          'identity_confidence' => $identityConfidence,
          'monitor_only' => $monitorOnly,
          'capabilities' => [
            'create' => !empty($definition['can_create']) && !$monitorOnly,
            'update' => !empty($definition['can_update']) && !$monitorOnly,
            'delete' => !empty($definition['can_delete']) && !$monitorOnly,
          ],
          'dependencies' => $this->dependenciesForEntityRow($row, $definition),
          'item' => $row,
        ];
        if ($monitorOnly) {
          $data['ambiguity'] = [
            'reason' => 'duplicate_or_unproven_portable_identity',
            'group_count' => $groupCount,
            'content_count' => (int) ($fingerprintCounts[$fingerprintKey] ?? 0),
            'content_fingerprint' => $fingerprint,
            'occurrence' => $occurrence,
          ];
        }
        yield ['filename' => $filename, 'data' => $data];
      }
    }
    catch (\Throwable $e) {
      $provider = trim($extensionKey . ' ' . $api . ' ' . $entity);
      $this->addExportError('Extension configuration provider ' . ($provider !== '' ? $provider : '(unknown)') . ' could not be exported: ' . $e->getMessage());
    }
    finally {
      $spool->close();
      $this->invalidateIdentityRowsForDefinition($definition);
    }
  }

  private function exportUnitKey(array $definition): string {
    return 'provider:' . substr(hash('sha256', $this->definitionKey(
      (string) ($definition['extension'] ?? ''),
      (string) ($definition['api'] ?? ''),
      (string) ($definition['entity'] ?? '')
    )), 0, 24);
  }

  private function definitionForExportUnit(string $unitKey): ?array {
    foreach ($this->discoverEntityDefinitions() as $definition) {
      $extensionKey = (string) ($definition['extension'] ?? '');
      if ($this->isGenericConfigSkippedExtension($extensionKey)
        || $this->isNonImportableDefinition($definition)
        || !$this->definitionMatchesRuntimeFilter($definition)) {
        continue;
      }
      if ($this->exportUnitKey($definition) === $unitKey) {
        return $definition;
      }
    }
    return NULL;
  }

  private function providerMonitorFingerprint(array $row): string {
    $normalized = $this->normaliseDataForDiff($row);
    return $this->fingerprint($normalized);
  }

  /**
   * Iterate one contributed provider without materializing the complete API4 or
   * normal API3 get collection. Read-adapter/custom actions retain the existing
   * conservative adapter because their pagination semantics are provider-owned.
   *
   * @return \Generator<int,array<string,mixed>>
   */
  private function iterateCleanProviderRows(array $definition): \Generator {
    $api = (string) ($definition['api'] ?? '');
    $entity = (string) ($definition['entity'] ?? '');

    if ($api === 'api4') {
      foreach ($this->api4Iterate($entity, [], ['*']) as $row) {
        $row = $this->cleanEntityRowForExport((array) $row, $definition);
        if (!$this->isPackagedExtensionAssetRow($row, $definition)) {
          yield $row;
        }
      }
      return;
    }

    $readAdapter = trim((string) ($definition['read_adapter'] ?? ''));
    $action = (string) ($definition['list_action'] ?? 'get');
    if ($readAdapter === '' && $action === 'get') {
      $offset = 0;
      $batchSize = 200;
      while (TRUE) {
        $result = civicrm_api3($entity, 'get', [
          'sequential' => 1,
          'options' => ['limit' => $batchSize, 'offset' => $offset],
        ]);
        $page = $this->normalizeApi3Rows((array) $result);
        $count = count($page);
        foreach ($page as $row) {
          $row = $this->cleanEntityRowForExport((array) $row, $definition);
          if (!$this->isPackagedExtensionAssetRow($row, $definition)) {
            yield $row;
          }
        }
        unset($page, $result);
        if ($count < $batchSize) {
          break;
        }
        $offset += $count;
      }
      return;
    }

    foreach ($this->fetchEntityRows($definition) as $row) {
      $row = $this->cleanEntityRowForExport((array) $row, $definition);
      if (!$this->isPackagedExtensionAssetRow($row, $definition)) {
        yield $row;
      }
    }
  }

  /**
   * Return and clear non-fatal errors collected during the latest export.
   *
   * Base extension status YAML remains exportable when one contributed
   * provider cannot be inspected. ConfigManager consumes these errors so the
   * UI never reports an incomplete extension scan as In Sync.
   */
  public function consumeExportErrors(): array {
    $errors = $this->exportErrors;
    $this->exportErrors = [];
    return $errors;
  }

  private function addExportError(string $message): void {
    $message = trim($message);
    if ($message !== '' && !in_array($message, $this->exportErrors, TRUE)) {
      $this->exportErrors[] = $message;
    }
  }

}
