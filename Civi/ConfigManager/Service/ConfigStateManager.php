<?php
namespace Civi\ConfigManager\Service;

/**
 * Connects semantic identities/fingerprints with local baseline state.
 */
class ConfigStateManager {
  private ConfigIdentity $identity;
  private Canonicalizer $canonicalizer;
  private DiffStateClassifier $classifier;
  private StateStore $store;

  public function __construct(?StateStore $store = NULL) {
    $this->identity = new ConfigIdentity();
    $this->canonicalizer = new Canonicalizer();
    $this->classifier = new DiffStateClassifier();
    $this->store = $store ?: new StateStore();
  }

  public function enrichDiff($handler, array $exported, array $yaml, array $diff): array {
    $handlerType = (string) $handler->getType();
    $options = method_exists($handler, 'getCanonicalizationOptions') ? (array) $handler->getCanonicalizationOptions() : [];
    if (!$this->store->isAvailable()) {
      return $diff;
    }

    $active = $this->indexExported($handlerType, $exported, $options);
    $desired = $this->indexYaml($handlerType, $yaml, $options);
    $allKeys = array_values(array_unique(array_merge(array_keys($active), array_keys($desired))));
    $states = [];

    foreach ($allKeys as $key) {
      $activeRow = $active[$key] ?? NULL;
      $yamlRow = $desired[$key] ?? NULL;
      $identity = $activeRow['identity'] ?? $yamlRow['identity'];
      $providerKey = (string) $identity['provider_key'];
      $identityHash = (string) $identity['identity_hash'];
      $baseline = $this->baselineForIdentity($providerKey, $identityHash);
      if ($baseline && (int) ($baseline['canonical_version'] ?? 0) !== Canonicalizer::VERSION) {
        $baseline = NULL;
      }
      $yamlHash = $yamlRow['hash'] ?? NULL;
      $activeHash = $activeRow['hash'] ?? NULL;
      $state = $this->classifier->classify($baseline['baseline_hash'] ?? NULL, $yamlHash, $activeHash);
      $details = [
        'config_key' => (string) $identity['config_key'],
        'identity_hash' => (string) $identity['identity_hash'],
        'identity_method' => (string) $identity['identity_method'],
        'identity_confidence' => (string) $identity['identity_confidence'],
        'write_safe' => !empty($identity['write_safe']),
        'yaml_hash' => $yamlHash,
        'active_hash' => $activeHash,
        'baseline_hash' => $baseline['baseline_hash'] ?? NULL,
        'sync_state' => $state,
        'canonical_version' => Canonicalizer::VERSION,
      ];

      if ($state === 'BOTH_CHANGED' && $baseline) {
        if ($yamlRow && $activeRow) {
          $analysis = $this->classifier->analyzeThreeWay(
            (array) ($baseline['baseline_data'] ?? []),
            (array) $yamlRow['canonical'],
            (array) $activeRow['canonical']
          );
          $details['merge_state'] = $analysis['status'];
          $details['conflicts'] = $analysis['conflicts'];
          $details['yaml_changed_paths'] = $analysis['yaml_changed_paths'];
          $details['active_changed_paths'] = $analysis['active_changed_paths'];
        }
        else {
          // Deleting the object on one side while the other side also changed
          // is inherently conflicting: applying either state would discard the
          // other side's intentional object-level change.
          $details['merge_state'] = 'CONFLICT';
          $details['conflicts'] = [[
            'path' => '__object__',
            'baseline' => (array) ($baseline['baseline_data'] ?? []),
            'yaml' => $yamlRow ? (array) $yamlRow['canonical'] : NULL,
            'active' => $activeRow ? (array) $activeRow['canonical'] : NULL,
          ]];
          $details['yaml_changed_paths'] = $yamlRow ? [] : ['__object__'];
          $details['active_changed_paths'] = $activeRow ? [] : ['__object__'];
        }
      }

      $states[$key] = $details;
      $this->store->upsertObjectState($identity, $yamlHash, $activeHash, $state);
    }

    foreach ((array) ($diff['files'] ?? []) as $index => $file) {
      $key = (string) ($file['config_key'] ?? '');
      if ($key !== '' && isset($states[$key])) {
        $diff['files'][$index] = array_merge($file, $states[$key]);
      }
    }

    $counts = [];
    foreach ($states as $state) {
      $name = (string) $state['sync_state'];
      $counts[$name] = ($counts[$name] ?? 0) + 1;
    }
    ksort($counts, SORT_STRING);
    $diff['state_summary'] = $counts;
    return $diff;
  }

  public function acceptExportedBaseline($handler, array $exported, string $source): void {
    $handlerType = (string) $handler->getType();
    $options = method_exists($handler, 'getCanonicalizationOptions') ? (array) $handler->getCanonicalizationOptions() : [];
    if (!$this->store->isAvailable()) {
      return;
    }
    foreach ($this->indexExported($handlerType, $exported, $options) as $row) {
      $this->store->acceptBaseline($row['identity'], $row['canonical'], $row['hash'], $source);
    }
  }

  public function acceptYamlBaseline($handler, array $yaml, string $source): void {
    $handlerType = (string) $handler->getType();
    $options = method_exists($handler, 'getCanonicalizationOptions') ? (array) $handler->getCanonicalizationOptions() : [];
    if (!$this->store->isAvailable()) {
      return;
    }
    foreach ($this->indexYaml($handlerType, $yaml, $options) as $row) {
      $this->store->acceptBaseline($row['identity'], $row['canonical'], $row['hash'], $source);
    }
  }

  public function rebuildObjectState(): void {
    $this->store->clearObjectState();
  }

  private function indexExported(string $handlerType, array $exported, array $options = []): array {
    $rows = [];
    foreach ($exported as $file) {
      if (empty($file['filename'])) {
        continue;
      }
      $rows[] = $this->buildRow($handlerType, (string) $file['filename'], (array) ($file['data'] ?? []), $options);
    }
    return $this->indexRows($rows);
  }

  private function indexYaml(string $handlerType, array $yaml, array $options = []): array {
    $rows = [];
    foreach ($yaml as $filename => $data) {
      $rows[] = $this->buildRow($handlerType, (string) $filename, (array) $data, $options);
    }
    return $this->indexRows($rows);
  }

  /**
   * Index rows by semantic identity without hiding duplicate identities.
   *
   * This mirrors AbstractHandler's diff indexing: when more than one document
   * has the same semantic key, every copy is kept visible under a deterministic
   * synthetic key and marked ambiguous/read-only. State tracking must never
   * turn a duplicate identity back into an apparently safe single object.
   */
  private function indexRows(array $rows): array {
    $groups = [];
    foreach ($rows as $row) {
      $groups[(string) $row['identity']['config_key']][] = $row;
    }

    $index = [];
    foreach ($groups as $configKey => $group) {
      if (count($group) === 1) {
        $index[$configKey] = $group[0];
        continue;
      }

      foreach ($group as $row) {
        $duplicateKey = $configKey . '|duplicate=' . rawurlencode((string) $row['filename']);
        $row['identity']['config_key'] = $duplicateKey;
        $row['identity']['identity_hash'] = hash('sha256', $duplicateKey);
        $row['identity']['identity_method'] = 'duplicate_identity_fallback';
        $row['identity']['identity_confidence'] = ConfigIdentity::AMBIGUOUS;
        $row['identity']['write_safe'] = FALSE;
        $index[$duplicateKey] = $row;
      }
    }

    ksort($index, SORT_STRING);
    return $index;
  }

  /**
   * Find a baseline through an explicitly confirmed rename chain.
   *
   * Aliases are intentionally one-directional. Follow at most 50 predecessors
   * and stop on cycles so repeated confirmed renames retain baseline continuity
   * without risking an unbounded lookup.
   */
  private function baselineForIdentity(string $providerKey, string $identityHash): ?array {
    $seen = [];
    $current = $identityHash;

    for ($depth = 0; $depth < 50 && $current !== ''; $depth++) {
      if (isset($seen[$current])) {
        return NULL;
      }
      $seen[$current] = TRUE;

      $baseline = $this->store->getBaseline($providerKey, $current);
      if ($baseline) {
        return $baseline;
      }

      $previous = $this->store->previousIdentityHash($providerKey, $current);
      if ($previous === NULL || $previous === '') {
        return NULL;
      }
      $current = $previous;
    }

    return NULL;
  }

  private function buildRow(string $handlerType, string $filename, array $data, array $options = []): array {
    $identity = $this->identity->identify($handlerType, $data, $filename);
    $canonical = $this->canonicalizer->canonicalize($data, $options);
    return [
      'filename' => $filename,
      'identity' => $identity,
      'canonical' => $canonical,
      'hash' => $this->canonicalizer->hash($data, $options),
    ];
  }
}
