<?php
namespace Civi\ConfigManager\Handler;

use Civi\ConfigManager\Service\Canonicalizer;
use Civi\ConfigManager\Service\ConfigIdentity;
use Civi\ConfigManager\Util\SimpleYaml;

abstract class AbstractHandler implements HandlerInterface {

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

      if ($this->fingerprint($dbCompare) !== $this->fingerprint($fileCompare)) {
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
            $this->fingerprint($fileCompare),
            $this->fingerprint($dbCompare)
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

    foreach ($missingKeys as $oldKey) {
      $old = $fileItems[$oldKey];
      foreach ($newKeys as $newKey) {
        $new = $dbItems[$newKey];
        if (($old['identity']['provider_key'] ?? '') !== ($new['identity']['provider_key'] ?? '')) {
          continue;
        }
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
          'provider_key' => (string) ($old['identity']['provider_key'] ?? ''),
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
      foreach ($rows as $row) {
        $duplicateKey = $configKey . '|duplicate=' . rawurlencode((string) $row['filename']);
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
      'identity_field', 'identity_confidence', 'capabilities',
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

  protected function api4Get(string $entity, array $where = [], array $select = ['*'], array $orderBy = []): array {
    $class = 'Civi\\Api4\\' . $entity;
    if (!class_exists($class)) {
      return [];
    }
    $action = $class::get(FALSE)->addSelect(...$select);
    foreach ($where as $condition) {
      $action->addWhere(...$condition);
    }
    foreach ($orderBy as $field => $direction) {
      $action->addOrderBy($field, $direction);
    }
    return (array) $action->execute();
  }

  protected function api4GetFirst(string $entity, array $where, array $select = ['*']): ?array {
    $rows = $this->api4Get($entity, $where, $select);
    return $rows[0] ?? NULL;
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
