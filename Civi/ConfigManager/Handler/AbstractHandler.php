<?php
namespace Civi\ConfigManager\Handler;

use Civi\ConfigManager\Service\Canonicalizer;
use Civi\ConfigManager\Service\ConfigIdentity;
use Civi\ConfigManager\Util\SimpleYaml;

abstract class AbstractHandler implements HandlerInterface {

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

  /**
   * Build focused, field-level changes. Lists of records with a 'name' key are
   * compared by that machine name so the UI shows only meaningful changed fields.
   */
  protected function structuredChanges($old, $new, string $path = ''): array {
    $changes = [];
    $old = $this->normaliseStructuredValue($old);
    $new = $this->normaliseStructuredValue($new);

    if (is_array($old) && is_array($new)) {
      if ($this->isNamedList($old) || $this->isNamedList($new)) {
        $oldMap = $this->namedListToMap($old);
        $newMap = $this->namedListToMap($new);
        $keys = array_values(array_unique(array_merge(array_keys($oldMap), array_keys($newMap))));
        sort($keys, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($keys as $key) {
          $childPath = $path . '[' . $key . ']';
          if (!array_key_exists($key, $oldMap)) {
            $changes[] = [
              'path' => $childPath,
              'type' => 'added',
              'old' => NULL,
              'new' => $newMap[$key],
            ];
          }
          elseif (!array_key_exists($key, $newMap)) {
            $changes[] = [
              'path' => $childPath,
              'type' => 'removed',
              'old' => $oldMap[$key],
              'new' => NULL,
            ];
          }
          else {
            $changes = array_merge($changes, $this->structuredChanges($oldMap[$key], $newMap[$key], $childPath));
          }
        }
        return $changes;
      }

      $keys = array_values(array_unique(array_merge(array_keys($old), array_keys($new))));
      sort($keys, SORT_NATURAL | SORT_FLAG_CASE);
      foreach ($keys as $key) {
        $childPath = $path === '' ? (string) $key : $path . '.' . $key;
        if (!array_key_exists($key, $old)) {
          $changes[] = [
            'path' => $childPath,
            'type' => 'added',
            'old' => NULL,
            'new' => $new[$key],
          ];
        }
        elseif (!array_key_exists($key, $new)) {
          $changes[] = [
            'path' => $childPath,
            'type' => 'removed',
            'old' => $old[$key],
            'new' => NULL,
          ];
        }
        else {
          $changes = array_merge($changes, $this->structuredChanges($old[$key], $new[$key], $childPath));
        }
      }
      return $changes;
    }

    if ($this->normaliseScalar($old) !== $this->normaliseScalar($new)) {
      $changes[] = [
        'path' => $path === '' ? 'value' : $path,
        'type' => 'changed',
        'old' => $old,
        'new' => $new,
      ];
    }
    return $changes;
  }

  protected function normaliseStructuredValue($value) {
    if (is_array($value)) {
      $result = [];
      foreach ($value as $key => $child) {
        $result[$key] = $this->normaliseStructuredValue($child);
      }
      return $result;
    }
    if ($value === NULL || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
      return $value;
    }
    return (string) $value;
  }

  private function isNamedList($value): bool {
    if (!is_array($value) || array_keys($value) !== range(0, count($value) - 1)) {
      return FALSE;
    }
    if (!$value) {
      return FALSE;
    }
    foreach ($value as $item) {
      if (!is_array($item) || $this->listItemIdentity($item) === '') {
        return FALSE;
      }
    }
    return TRUE;
  }

  private function namedListToMap(array $list): array {
    $baseCounts = [];
    foreach ($list as $item) {
      if (is_array($item)) {
        $base = $this->listItemIdentity($item);
        if ($base !== '') {
          $baseCounts[$base] = ($baseCounts[$base] ?? 0) + 1;
        }
      }
    }

    $map = [];
    foreach ($list as $index => $item) {
      if (is_array($item)) {
        $base = $this->listItemIdentity($item);
        if ($base !== '') {
          $key = $base;
          if (($baseCounts[$base] ?? 0) > 1) {
            if (array_key_exists('value', $item) && $item['value'] !== NULL && $item['value'] !== '') {
              $key .= '::' . (string) $item['value'];
            }
            elseif (array_key_exists('id', $item) && $item['id'] !== NULL && $item['id'] !== '') {
              $key .= '::id:' . (string) $item['id'];
            }
            else {
              $key .= '::index:' . $index;
            }
          }
          $map[$key] = $item;
          continue;
        }
      }
      $map['index:' . $index] = $item;
    }
    return $map;
  }

  private function listItemIdentity(array $item): string {
    foreach (['key', 'name', 'name_a_b', 'title', 'label'] as $field) {
      if (array_key_exists($field, $item) && $item[$field] !== NULL && $item[$field] !== '') {
        return (string) $item[$field];
      }
    }
    return '';
  }

  private function normaliseScalar($value): string {
    $json = json_encode($this->normaliseData($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    return $json === FALSE ? serialize($value) : $json;
  }

  protected function buildDiffFile(string $filename, string $status, array $fileData, array $dbData, array $fieldChanges = [], array $identity = [], ?string $yamlHash = NULL, ?string $activeHash = NULL): array {
    $relative = trim($this->getDirectory(), '/') . '/' . $filename;
    return [
      'file' => $filename,
      'path' => $relative,
      'status' => $status,
      'config_key' => (string) ($identity['config_key'] ?? ''),
      'identity_hash' => (string) ($identity['identity_hash'] ?? ''),
      'identity_method' => (string) ($identity['identity_method'] ?? ''),
      'identity_confidence' => (string) ($identity['identity_confidence'] ?? ''),
      'write_safe' => !empty($identity['write_safe']),
      'yaml_hash' => $yamlHash,
      'active_hash' => $activeHash,
      'canonical_version' => Canonicalizer::VERSION,
      'change_count' => count($fieldChanges),
      'changes' => $fieldChanges,
      'diff' => $this->fieldDiff($relative, $fieldChanges),
    ];
  }

  protected function fieldDiff(string $relative, array $changes): string {
    $diff = [];
    $diff[] = 'diff --git a/' . $relative . ' b/' . $relative;
    $diff[] = '--- a/' . $relative;
    $diff[] = '+++ b/' . $relative;
    if (!$changes) {
      $diff[] = '@@ no field-level differences @@';
      return implode("\n", $diff);
    }
    $maxChanges = 120;
    $shown = 0;
    foreach ($changes as $change) {
      if ($shown >= $maxChanges) {
        $diff[] = '... diff truncated for UI preview ...';
        break;
      }
      $path = (string) ($change['path'] ?? 'value');
      $diff[] = '@@ ' . $path . ' @@';
      if (($change['type'] ?? '') === 'added') {
        $diff[] = '+ ' . $path . ': ' . $this->formatDiffValue($change['new'] ?? NULL);
      }
      elseif (($change['type'] ?? '') === 'removed') {
        $diff[] = '- ' . $path . ': ' . $this->formatDiffValue($change['old'] ?? NULL);
      }
      else {
        $old = $change['old'] ?? NULL;
        $new = $change['new'] ?? NULL;
        if (is_string($old) && is_string($new) && ($this->isLargeText($old) || $this->isLargeText($new))) {
          foreach ($this->formatFocusedTextDiff($path, $old, $new) as $line) {
            $diff[] = $line;
          }
        }
        else {
          $diff[] = '- ' . $path . ': ' . $this->formatDiffValue($old);
          $diff[] = '+ ' . $path . ': ' . $this->formatDiffValue($new);
        }
      }
      $shown++;
    }
    return implode("\n", $diff);
  }

  protected function isLargeText(string $value): bool {
    return strlen($value) > 800 || substr_count($value, "\n") > 12;
  }

  protected function formatFocusedTextDiff(string $path, string $old, string $new): array {
    [$oldStart, $oldEnd, $newStart, $newEnd] = $this->changedRanges($old, $new);
    $oldExcerpt = $this->excerptForDiff($old, $oldStart, $oldEnd);
    $newExcerpt = $this->excerptForDiff($new, $newStart, $newEnd);
    return [
      '- ' . $path . ': ' . $oldExcerpt,
      '+ ' . $path . ': ' . $newExcerpt,
    ];
  }

  protected function excerptForDiff(string $value, int $start, int $end, int $context = 240): string {
    $from = max(0, $start - $context);
    $to = min(strlen($value), $end + $context);
    $excerpt = substr($value, $from, max(0, $to - $from));
    $prefix = $from > 0 ? "...\n" : '';
    $suffix = $to < strlen($value) ? "\n..." : '';
    if ($excerpt === '') {
      return '[empty at changed position]';
    }
    return $prefix . $excerpt . $suffix;
  }

  protected function changedRanges(string $old, string $new): array {
    $oldLen = strlen($old);
    $newLen = strlen($new);
    $start = 0;
    $maxStart = min($oldLen, $newLen);
    while ($start < $maxStart && $old[$start] === $new[$start]) {
      $start++;
    }
    $oldEnd = $oldLen;
    $newEnd = $newLen;
    while ($oldEnd > $start && $newEnd > $start && $old[$oldEnd - 1] === $new[$newEnd - 1]) {
      $oldEnd--;
      $newEnd--;
    }
    return [$start, $oldEnd, $start, $newEnd];
  }

  protected function formatDiffValue($value): string {
    if (is_array($value)) {
      $yaml = trim(SimpleYaml::dump($value));
      $lines = explode("\n", $yaml);
      if (count($lines) > 12) {
        $lines = array_slice($lines, 0, 12);
        $lines[] = '...';
      }
      return implode("\n  ", $lines);
    }
    if ($value === NULL || $value === '') {
      return "''";
    }
    if (is_bool($value)) {
      return $value ? 'true' : 'false';
    }
    $value = (string) $value;
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $lines = explode("\n", $value);
    if (count($lines) > 20) {
      $value = implode("\n", array_slice($lines, 0, 20)) . "\n... (diff value truncated for preview)";
    }
    if (strlen($value) > 2400) {
      $value = substr($value, 0, 2400) . "\n... (diff value truncated for preview)";
    }
    return $value;
  }

  /**
   * Describe the API4 action surface needed for safe managed CRUD.
   *
   * This is intentionally based on callable API actions rather than CiviCRM
   * version numbers. Contributed/core entities vary by installation, so a
   * handler is only advertised as full-management when the actions it uses are
   * actually present at runtime.
   *
   * @return array{available:bool,management_capability:string,reason:string,missing_actions:array<int,string>}
   */
  protected function api4ManagementAvailability(string $entity, array $requiredActions = ['get', 'create', 'update', 'delete']): array {
    $class = 'Civi\\Api4\\' . $entity;
    if (!class_exists($class)) {
      return [
        'available' => FALSE,
        'management_capability' => 'unavailable',
        'reason' => 'API4 entity ' . $entity . ' is not available on this site.',
        'missing_actions' => array_values($requiredActions),
      ];
    }

    $missing = [];
    foreach ($requiredActions as $action) {
      if (!is_callable([$class, (string) $action])) {
        $missing[] = (string) $action;
      }
    }

    if (in_array('get', $missing, TRUE)) {
      return [
        'available' => FALSE,
        'management_capability' => 'unavailable',
        'reason' => 'API4 entity ' . $entity . ' cannot be read on this site. Missing action(s): ' . implode(', ', $missing) . '.',
        'missing_actions' => $missing,
      ];
    }

    if ($missing) {
      return [
        'available' => TRUE,
        'management_capability' => 'export_only',
        'reason' => 'API4 entity ' . $entity . ' is readable but does not expose every action required for managed restore/import. Missing action(s): ' . implode(', ', $missing) . '.',
        'missing_actions' => $missing,
      ];
    }

    return [
      'available' => TRUE,
      'management_capability' => 'full',
      'reason' => '',
      'missing_actions' => [],
    ];
  }

  /**
   * Combine multiple API4 capability checks for one composite handler.
   */
  protected function combineApi4ManagementAvailability(array $checks, string $label): array {
    $reasons = [];
    $capability = 'full';
    foreach ($checks as $check) {
      $check = (array) $check;
      if (empty($check['available'])) {
        $capability = 'unavailable';
      }
      elseif (($check['management_capability'] ?? 'full') !== 'full' && $capability !== 'unavailable') {
        $capability = 'export_only';
      }
      $reason = trim((string) ($check['reason'] ?? ''));
      if ($reason !== '') {
        $reasons[] = $reason;
      }
    }

    return [
      'available' => $capability !== 'unavailable',
      'management_capability' => $capability,
      'reason' => $reasons ? ($label . ': ' . implode(' ', $reasons)) : '',
    ];
  }

  /**
   * Iterate an API4 collection in bounded pages without retaining prior pages.
   *
   * This is the primitive large-site handlers should use. api4Get() remains a
   * compatibility helper for legacy/small code paths and intentionally builds
   * an array from this iterator.
   *
   * @return \Generator<int,array<string,mixed>>
   */
  protected function api4Iterate(string $entity, array $where = [], array $select = ['*'], array $orderBy = []): \Generator {
    // Preserve the pre-alpha62 protected extension seam: a subclass may
    // override api4Get() to provide custom/test provider semantics. Route that
    // explicit override through the generator once, while guarding parent-call
    // recursion. Built-in handlers continue on the true streaming path.
    $api4GetMethod = new \ReflectionMethod($this, 'api4Get');
    if (!$this->api4GetOverrideBridgeActive && $api4GetMethod->getDeclaringClass()->getName() !== self::class) {
      $this->api4GetOverrideBridgeActive = TRUE;
      try {
        foreach ($this->api4Get($entity, $where, $select, $orderBy) as $row) {
          yield (array) $row;
        }
      }
      finally {
        $this->api4GetOverrideBridgeActive = FALSE;
      }
      return;
    }

    $class = 'Civi\\Api4\\' . $entity;
    if (!class_exists($class)) {
      throw new \RuntimeException('API4 entity not available: ' . $entity . '. Install/enable the provider before managing this configuration type.');
    }

    $offset = 0;
    $batchSize = 200;
    $seenPages = [];
    $orderFields = array_keys($orderBy);
    $firstOrderField = isset($orderFields[0]) ? (string) $orderFields[0] : '';
    $idSelected = in_array('*', $select, TRUE) || in_array('id', $select, TRUE);
    $useIdCursor = $firstOrderField === 'id'
      && strtoupper((string) ($orderBy['id'] ?? '')) === 'ASC'
      && $idSelected;
    $lastId = NULL;

    while (TRUE) {
      $action = $class::get(FALSE)->addSelect(...$select);
      foreach ($where as $condition) {
        $action->addWhere(...$condition);
      }
      if ($useIdCursor && $lastId !== NULL) {
        // Database-local IDs are execution cursors only. They never become
        // portable Configuration Manager identity. Keyset paging avoids
        // offset skips/repeats when a numeric-ID collection changes earlier
        // in the result set while it is being scanned.
        $action->addWhere('id', '>', $lastId);
      }
      foreach ($orderBy as $field => $direction) {
        $action->addOrderBy($field, $direction);
      }

      $hasLimit = method_exists($action, 'setLimit');
      $hasOffset = method_exists($action, 'setOffset');
      $paged = $hasLimit && ($useIdCursor || $hasOffset);
      if ($paged) {
        $action->setLimit($batchSize);
        if (!$useIdCursor) {
          $action->setOffset($offset);
        }
      }

      $page = array_values((array) $action->execute());
      if ($paged && $page) {
        $fingerprint = $this->collectionPageFingerprint($page);
        if (isset($seenPages[$fingerprint])) {
          throw new \RuntimeException('API4 provider pagination repeated a previous page for ' . $entity . '; refusing an incomplete or unbounded collection read.');
        }
        $seenPages[$fingerprint] = TRUE;
      }

      $pageCount = count($page);
      $nextLastId = $lastId;
      foreach ($page as $row) {
        $row = (array) $row;
        if ($useIdCursor) {
          if (!isset($row['id']) || !is_numeric($row['id'])) {
            throw new \RuntimeException('API4 provider ' . $entity . ' was configured for numeric-ID cursor paging but returned a row without a numeric id.');
          }
          $rowId = (int) $row['id'];
          if ($nextLastId !== NULL && $rowId <= $nextLastId) {
            throw new \RuntimeException('API4 provider ' . $entity . ' did not make forward progress for numeric-ID cursor paging.');
          }
          $nextLastId = $rowId;
        }
        yield $row;
      }
      unset($page, $action);

      if (!$paged || $pageCount < $batchSize) {
        break;
      }
      if ($useIdCursor) {
        $lastId = $nextLastId;
      }
      else {
        $offset += $pageCount;
      }
    }
  }

  /**
   * Backward-compatible materialized API4 collection read.
   */
  protected function api4Get(string $entity, array $where = [], array $select = ['*'], array $orderBy = []): array {
    return iterator_to_array($this->api4Iterate($entity, $where, $select, $orderBy), FALSE);
  }

  /**
   * Fingerprint a small page sample for pagination-loop protection.
   */
  private function collectionPageFingerprint(array $rows): string {
    $sample = [];
    foreach (array_slice($rows, 0, 3) as $row) {
      $row = (array) $row;
      foreach (['id', 'key', 'name', 'machine_name', 'title'] as $field) {
        if (array_key_exists($field, $row) && is_scalar($row[$field])) {
          $sample[] = $field . '=' . (string) $row[$field];
          continue 2;
        }
      }
      $sample[] = sha1(serialize($row));
    }
    return sha1(implode('|', $sample));
  }

  /**
   * Fetch one API4 row without materializing the complete matching collection.
   */
  protected function api4GetFirst(string $entity, array $where, array $select = ['*']): ?array {
    $class = 'Civi\\Api4\\' . $entity;
    if (!class_exists($class)) {
      throw new \RuntimeException('API4 entity not available: ' . $entity . '. Install/enable the provider before managing this configuration type.');
    }
    $action = $class::get(FALSE)->addSelect(...$select);
    foreach ($where as $condition) {
      $action->addWhere(...$condition);
    }
    if (method_exists($action, 'setLimit')) {
      $action->setLimit(1);
    }
    $rows = array_values((array) $action->execute());
    return isset($rows[0]) ? (array) $rows[0] : NULL;
  }

  protected function api4Create(string $entity, array $values): array {
    $class = 'Civi\\Api4\\' . $entity;
    if (!class_exists($class)) {
      throw new \RuntimeException("API4 entity not available: {$entity}");
    }
    $action = $class::create(FALSE);
    foreach ($values as $field => $value) {
      $action->addValue($field, $value);
    }
    $result = (array) $action->execute();
    return $result[0] ?? [];
  }

  protected function api4Update(string $entity, array $where, array $values): array {
    $class = 'Civi\\Api4\\' . $entity;
    if (!class_exists($class)) {
      throw new \RuntimeException("API4 entity not available: {$entity}");
    }
    $action = $class::update(FALSE);
    foreach ($where as $condition) {
      $action->addWhere(...$condition);
    }
    foreach ($values as $field => $value) {
      $action->addValue($field, $value);
    }
    return (array) $action->execute();
  }

  protected function api4Delete(string $entity, array $where): array {
    $class = 'Civi\\Api4\\' . $entity;
    if (!class_exists($class)) {
      throw new \RuntimeException("API4 entity not available: {$entity}");
    }
    $action = $class::delete(FALSE);
    foreach ($where as $condition) {
      $action->addWhere(...$condition);
    }
    return (array) $action->execute();
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
