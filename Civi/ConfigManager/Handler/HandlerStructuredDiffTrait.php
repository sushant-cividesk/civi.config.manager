<?php
namespace Civi\ConfigManager\Handler;

use Civi\ConfigManager\Service\Canonicalizer;
use Civi\ConfigManager\Util\SimpleYaml;

/**
 * HandlerStructuredDiff methods from AbstractHandler.
 *
 * Methods run on the original owning object and retain the same state and
 * access modifiers. Do not instantiate this trait separately.
 */
trait HandlerStructuredDiffTrait {

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

}
