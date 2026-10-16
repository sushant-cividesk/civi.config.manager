<?php
namespace Civi\ConfigManager\Handler;

use Civi\ConfigManager\Service\Canonicalizer;
use Civi\ConfigManager\Service\ConfigIdentity;
use Civi\ConfigManager\Util\SimpleYaml;


// Include stateful concerns for direct CiviCRM/legacy bootstrap loaders too.
require_once __DIR__ . '/HandlerApi4TransportTrait.php';
require_once __DIR__ . '/HandlerStructuredDiffTrait.php';

abstract class AbstractHandler implements HandlerInterface {
  use HandlerApi4TransportTrait;
  use HandlerStructuredDiffTrait;



  /**
   * Guard for the legacy api4Get() override bridge used by api4Iterate().
   *
   * Third-party/test subclasses which override the historical materialized
   * api4Get() helper must keep working after alpha62 introduced generators.
   */
  private bool $api4GetOverrideBridgeActive = FALSE;

  /**
   * Report whether this handler can read its runtime provider on this site.
   *
   * Handlers backed by optional API4 entities override this method. The
   * default is intentionally available for handlers whose dependencies are
   * intrinsic to supported CiviCRM core versions.
   *
   * @return array{available:bool,reason:string}
   */
  public function getRuntimeAvailability(): array {
    return ['available' => TRUE, 'reason' => ''];
  }

  /**
   * Return read-only metadata for provider inventory.
   *
   * The default deliberately reports only what every handler contract exposes.
   * It must never call export(), import(), or any provider collection action.
   * Handlers with declarative field/identity metadata may override this method.
   */
  public function getProviderMetadata(): array {
    return [
      'owner' => 'civi.config.manager',
      'api_version' => 'handler',
      'entity' => '',
      'actions' => [
        'read' => TRUE,
        'create' => NULL,
        'update' => NULL,
        'delete' => NULL,
      ],
      'field_names' => [],
      'identity_fields' => [],
      'reference_fields' => [],
      'sensitive_fields' => [],
      'runtime_fields' => [],
      'identity_evidence' => 'handler_policy',
      'metadata_completeness' => 'basic',
    ];
  }

  public function import(array $items, bool $dryRun = TRUE): array {
    return [
      'type' => $this->getType(),
      'status' => 'not_implemented',
      'dry_run' => $dryRun,
      'message' => 'Import handler is planned but not implemented for this config type yet.',
      'count' => count($items),
      'create' => 0,
      'update' => 0,
      'skip' => count($items),
      'warnings' => [],
      'errors' => [],
    ];
  }

  public function diff(array $items): array {
    return $this->diffFromExports($this->export(), $items);
  }

  public function diffFromExports(array $exported, array $items): array {
    $dbItems = $this->indexDiffItems($exported, TRUE);
    $fileItems = $this->indexDiffItems($items, FALSE);

    $newKeys = array_values(array_diff(array_keys($dbItems), array_keys($fileItems)));
    $missingKeys = array_values(array_diff(array_keys($fileItems), array_keys($dbItems)));
    $newInDb = array_values(array_map(fn($key) => $dbItems[$key]['filename'], $newKeys));
    $missingInDb = array_values(array_map(fn($key) => $fileItems[$key]['filename'], $missingKeys));
    $changed = [];
    $renamed = [];
    $files = [];

    foreach (array_intersect(array_keys($dbItems), array_keys($fileItems)) as $configKey) {
      $dbRow = $dbItems[$configKey];
      $fileRow = $fileItems[$configKey];
      $fileCompare = $this->normaliseDataForDiff($fileRow['data']);
      $dbCompare = $this->normaliseDataForDiff($dbRow['data']);

      if ($dbRow['filename'] !== $fileRow['filename']) {
        $renamed[] = [
          'config_key' => $configKey,
          'from' => $fileRow['filename'],
          'to' => $dbRow['filename'],
        ];
      }

      $fileFingerprint = $this->fingerprint($fileCompare);
      $dbFingerprint = $this->fingerprint($dbCompare);
      if ($dbFingerprint !== $fileFingerprint) {
        $fieldChanges = $this->structuredChanges($fileCompare, $dbCompare);
        if ($fieldChanges) {
          $changed[] = $fileRow['filename'];
          $files[] = $this->buildDiffFile(
            $fileRow['filename'],
            'changed',
            $fileCompare,
            $dbCompare,
            $fieldChanges,
            $fileRow['identity'],
            $fileFingerprint,
            $dbFingerprint
          );
        }
      }
    }

    foreach ($newKeys as $configKey) {
      $row = $dbItems[$configKey];
      $dbCompare = $this->normaliseDataForDiff($row['data']);
      $files[] = $this->buildDiffFile(
        $row['filename'],
        'new_in_db',
        [],
        $dbCompare,
        $this->structuredChanges([], $dbCompare),
        $row['identity'],
        NULL,
        $this->fingerprint($dbCompare)
      );
    }

    foreach ($missingKeys as $configKey) {
      $row = $fileItems[$configKey];
      $fileCompare = $this->normaliseDataForDiff($row['data']);
      $files[] = $this->buildDiffFile(
        $row['filename'],
        'missing_in_db',
        $fileCompare,
        [],
        $this->structuredChanges($fileCompare, []),
        $row['identity'],
        $this->fingerprint($fileCompare),
        NULL
      );
    }

    $possibleRenames = $this->possibleRenameCandidates($newKeys, $missingKeys, $dbItems, $fileItems);
    $status = ($newInDb || $missingInDb || $changed) ? 'changed' : 'in_sync';

    return [
      'type' => $this->getType(),
      'label' => $this->getLabel(),
      'db_count' => count($dbItems),
      'file_count' => count($fileItems),
      'status' => $status,
      'changed' => $changed,
      'new_in_db' => $newInDb,
      'missing_in_db' => $missingInDb,
      'renamed' => $renamed,
      'possible_renames' => $possibleRenames,
      'files' => $files,
    ];
  }

  /**
   * Suggest only very conservative machine-identity renames.
   *
   * A suggestion never changes matching/import behavior. It is informational
   * until an operator explicitly confirms the alias.
   */
  private function possibleRenameCandidates(array $newKeys, array $missingKeys, array $dbItems, array $fileItems): array {
    $allowedIdentityPaths = [
      'key',
      'name',
      'item.key',
      'item.machine_name',
      'item.name',
      'item.name_a_b',
      'item.workflow_name',
    ];
    $candidates = [];

    // A valid rename candidate may differ only in the small identity-path set
    // above. Bucket new records by provider + the remaining document content
    // first, then run the exact structured-change check only inside matching
    // buckets. This preserves the conservative rename semantics while avoiding
    // an O(m*n) comparison when a large site has many added/missing objects.
    $newBuckets = [];
    foreach ($newKeys as $newKey) {
      $new = $dbItems[$newKey];
      $provider = (string) ($new['identity']['provider_key'] ?? '');
      $signature = $this->renameStructuralSignature((array) $new['data']);
      $newBuckets[$provider][$signature][] = $newKey;
    }

    foreach ($missingKeys as $oldKey) {
      $old = $fileItems[$oldKey];
      $provider = (string) ($old['identity']['provider_key'] ?? '');
      $signature = $this->renameStructuralSignature((array) $old['data']);
      foreach ((array) ($newBuckets[$provider][$signature] ?? []) as $newKey) {
        $new = $dbItems[$newKey];
        $oldData = $this->normaliseDataForDiff((array) $old['data']);
        $newData = $this->normaliseDataForDiff((array) $new['data']);
        $changes = $this->structuredChanges($oldData, $newData);
        if (!$changes || count($changes) > 3) {
          continue;
        }
        foreach ($changes as $change) {
          if (!in_array((string) ($change['path'] ?? ''), $allowedIdentityPaths, TRUE)) {
            continue 2;
          }
        }
        $candidates[] = [
          'provider_key' => $provider,
          'old_config_key' => (string) ($old['identity']['config_key'] ?? ''),
          'new_config_key' => (string) ($new['identity']['config_key'] ?? ''),
          'old_identity_hash' => (string) ($old['identity']['identity_hash'] ?? ''),
          'new_identity_hash' => (string) ($new['identity']['identity_hash'] ?? ''),
          'from' => (string) $old['filename'],
          'to' => (string) $new['filename'],
          'changes' => $changes,
          'requires_confirmation' => TRUE,
        ];
      }
    }

    return $candidates;
  }

  /**
   * Fingerprint non-identity content for near-linear rename candidate lookup.
   */
  private function renameStructuralSignature(array $data): string {
    $data = $this->normaliseDataForDiff($data);
    unset($data['key'], $data['name']);
    if (isset($data['item']) && is_array($data['item'])) {
      unset(
        $data['item']['key'],
        $data['item']['machine_name'],
        $data['item']['name'],
        $data['item']['name_a_b'],
        $data['item']['workflow_name']
      );
    }
    return $this->fingerprint($data);
  }

  /**
   * @param array $items Export rows or filename => YAML rows.
   * @return array<string, array{filename:string,data:array,identity:array}>
   */
  private function indexDiffItems(array $items, bool $exportRows): array {
    $identityService = new ConfigIdentity();
    $groups = [];

    foreach ($items as $key => $value) {
      if ($exportRows) {
        $filename = (string) ($value['filename'] ?? '');
        $data = (array) ($value['data'] ?? []);
      }
      else {
        $filename = (string) $key;
        $data = (array) $value;
      }
      if ($filename === '') {
        continue;
      }

      $identity = $identityService->identify($this->getType(), $data, $filename);
      $configKey = (string) $identity['config_key'];
      $groups[$configKey][] = [
        'filename' => $filename,
        'data' => $data,
        'identity' => $identity,
      ];
    }

    $indexed = [];
    foreach ($groups as $configKey => $rows) {
      if (count($rows) === 1) {
        $indexed[$configKey] = $rows[0];
        continue;
      }

      // If a semantic key occurs more than once, none of those records is safe
      // to match automatically. Keep every duplicate visible under a stable
      // synthetic key and mark every copy ambiguous, including the first one.
      $occurrences = [];
      foreach ($rows as $row) {
        $fingerprint = $this->fingerprint($this->normaliseDataForDiff((array) $row['data']));
        $baseDuplicateKey = $configKey
          . '|duplicate=' . rawurlencode((string) $row['filename'])
          . '|fingerprint=' . $fingerprint;
        $occurrence = ($occurrences[$baseDuplicateKey] ?? 0) + 1;
        $occurrences[$baseDuplicateKey] = $occurrence;
        $duplicateKey = $baseDuplicateKey . '|occurrence=' . $occurrence;
        $row['identity']['config_key'] = $duplicateKey;
        $row['identity']['identity_hash'] = hash('sha256', $duplicateKey);
        $row['identity']['identity_method'] = 'duplicate_identity_fallback';
        $row['identity']['identity_confidence'] = ConfigIdentity::AMBIGUOUS;
        $row['identity']['write_safe'] = FALSE;
        $indexed[$duplicateKey] = $row;
      }
    }

    ksort($indexed, SORT_STRING);
    return $indexed;
  }

  /**
   * Normalize data only for diff display/comparison.
   *
   * Export/import handlers may intentionally ignore runtime fields such as
   * numeric database IDs. Diff must ignore the same fields so a YAML file from
   * another database does not show false changes or imply an unsafe update.
   */
  protected function normaliseDataForDiff(array $data): array {
    foreach ([
      'schema_version', 'type', 'entity', 'key', 'key_fields',
      'identity_field', 'identity_key', 'identity_confidence', 'capabilities',
      'dependencies', 'required_by', 'config_index',
    ] as $field) {
      unset($data[$field]);
    }
    if (isset($data['item']) && is_array($data['item'])) {
      unset($data['item']['required_by']);
    }
    return $data;
  }

  public function validate(array $items): array {
    return [
      'type' => $this->getType(),
      'valid' => TRUE,
      'warnings' => [],
      'errors' => [],
      'count' => count($items),
    ];
  }

  protected function fingerprint(array $data): string {
    return (new Canonicalizer())->hash($data, $this->getCanonicalOptions());
  }

  protected function normaliseData($data) {
    return (new Canonicalizer())->canonicalize($data, $this->getCanonicalOptions());
  }

  /**
   * Handler-specific canonicalization metadata.
   */
  protected function getCanonicalOptions(): array {
    return [];
  }

  public function getCanonicalizationOptions(): array {
    return $this->getCanonicalOptions();
  }

  protected function cleanValues(array $values, array $remove = ['id']): array {
    foreach ($remove as $field) {
      unset($values[$field]);
    }
    return $values;
  }

  protected function baseImportSummary(bool $dryRun): array {
    return [
      'type' => $this->getType(),
      'status' => $dryRun ? 'dry_run' : 'applied',
      'dry_run' => $dryRun,
      'create' => 0,
      'update' => 0,
      'delete' => 0,
      'skip' => 0,
      'warnings' => [],
      'errors' => [],
    ];
  }

  protected function desiredDiffers(array $existing, array $desired): bool {
    foreach ($desired as $key => $value) {
      if (!array_key_exists($key, $existing)) {
        continue;
      }
      if ($this->normaliseComparableValue($existing[$key]) !== $this->normaliseComparableValue($value)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  protected function normaliseComparableValue($value) {
    if ($value === NULL || $value === '') {
      return '';
    }
    if (is_bool($value)) {
      return $value ? '1' : '0';
    }
    if (is_array($value)) {
      ksort($value);
      return json_encode($value);
    }
    return (string) $value;
  }

}
