<?php
namespace Civi\ConfigManager\Service;

/**
 * Classifies two-way and baseline-aware three-way configuration state.
 */
class DiffStateClassifier {
  public function classify(?string $baselineHash, ?string $yamlHash, ?string $activeHash): string {
    if ($yamlHash === NULL && $activeHash === NULL) {
      return 'UNKNOWN';
    }

    if ($baselineHash === NULL || $baselineHash === '') {
      if ($yamlHash === NULL) {
        return 'ONLY_IN_CIVICRM';
      }
      if ($activeHash === NULL) {
        return 'ONLY_IN_YAML';
      }
      return $yamlHash === $activeHash ? 'IN_SYNC' : 'DIFFERENT';
    }

    // Once a baseline exists, absence is itself a meaningful state change.
    // This allows deletions on either side to be attributed instead of being
    // reported only as a neutral two-way presence difference.
    if ($yamlHash === $activeHash) {
      return $yamlHash === $baselineHash ? 'IN_SYNC' : 'SYNCED_CHANGE';
    }
    if ($yamlHash === $baselineHash && $activeHash !== $baselineHash) {
      return 'ACTIVE_DRIFT';
    }
    if ($activeHash === $baselineHash && $yamlHash !== $baselineHash) {
      return 'YAML_CHANGE';
    }
    return 'BOTH_CHANGED';
  }

  public function analyzeThreeWay(array $baseline, array $yaml, array $active): array {
    $base = $this->flatten($baseline);
    $left = $this->flatten($yaml);
    $right = $this->flatten($active);
    $paths = array_values(array_unique(array_merge(array_keys($base), array_keys($left), array_keys($right))));
    sort($paths, SORT_NATURAL | SORT_FLAG_CASE);

    $yamlChanged = [];
    $activeChanged = [];
    $conflicts = [];

    foreach ($paths as $path) {
      $baseValue = $base[$path] ?? ['exists' => FALSE, 'value' => NULL];
      $yamlValue = $left[$path] ?? ['exists' => FALSE, 'value' => NULL];
      $activeValue = $right[$path] ?? ['exists' => FALSE, 'value' => NULL];

      $yamlIsChanged = !$this->sameLeaf($baseValue, $yamlValue);
      $activeIsChanged = !$this->sameLeaf($baseValue, $activeValue);

      if ($yamlIsChanged) {
        $yamlChanged[] = $path;
      }
      if ($activeIsChanged) {
        $activeChanged[] = $path;
      }
      if ($yamlIsChanged && $activeIsChanged && !$this->sameLeaf($yamlValue, $activeValue)) {
        $conflicts[] = [
          'path' => $path,
          'baseline' => $baseValue['value'],
          'yaml' => $yamlValue['value'],
          'active' => $activeValue['value'],
        ];
      }
    }

    return [
      'status' => $conflicts ? 'CONFLICT' : 'NON_CONFLICTING_DIVERGENCE',
      'yaml_changed_paths' => $yamlChanged,
      'active_changed_paths' => $activeChanged,
      'conflicts' => $conflicts,
    ];
  }

  private function flatten($value, string $path = ''): array {
    if (!is_array($value)) {
      return [$path === '' ? 'value' : $path => ['exists' => TRUE, 'value' => $value]];
    }

    if ($value === []) {
      return [$path === '' ? 'value' : $path => ['exists' => TRUE, 'value' => []]];
    }

    $flat = [];
    foreach ($value as $key => $child) {
      $childPath = $path === '' ? (string) $key : $path . '.' . (string) $key;
      $flat += $this->flatten($child, $childPath);
    }
    return $flat;
  }

  private function sameLeaf(array $a, array $b): bool {
    if (!empty($a['exists']) !== !empty($b['exists'])) {
      return FALSE;
    }
    return $a['value'] === $b['value'];
  }
}
