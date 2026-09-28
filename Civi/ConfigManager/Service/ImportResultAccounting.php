<?php
namespace Civi\ConfigManager\Service;

/**
 * Canonical accounting for Import previews and apply results.
 *
 * UI, API4, CLI and queue consumers should read the same compact accounting
 * payload instead of independently interpreting handler result shapes.
 */
final class ImportResultAccounting {

  /** @return array<string,int> */
  public function activityTotals(array $items): array {
    $totals = [
      'created' => 0,
      'updated' => 0,
      'removed' => 0,
      'unchanged' => 0,
      'warnings' => 0,
      'errors' => 0,
    ];
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }
      $totals['created'] += (int) ($item['create'] ?? 0);
      $totals['updated'] += (int) ($item['update'] ?? 0);
      $totals['removed'] += (int) ($item['delete'] ?? 0);
      $totals['unchanged'] += (int) ($item['skip'] ?? 0);
      $totals['updated'] += (int) ($item['install'] ?? 0) + (int) ($item['enable'] ?? 0) + (int) ($item['disable'] ?? 0);
      foreach (['groups', 'values', 'settings', 'config'] as $group) {
        $groupResult = (array) ($item[$group] ?? []);
        $totals['created'] += (int) ($groupResult['create'] ?? 0);
        $totals['updated'] += (int) ($groupResult['update'] ?? 0);
        $totals['removed'] += (int) ($groupResult['delete'] ?? 0);
        $totals['unchanged'] += (int) ($groupResult['skip'] ?? 0);
      }
      $totals['warnings'] += count((array) ($item['warnings'] ?? []));
      $totals['errors'] += count((array) ($item['errors'] ?? []));
    }
    return $totals;
  }

  public function countActivity(array $item): int {
    $totals = $this->activityTotals([$item]);
    return $totals['created'] + $totals['updated'] + $totals['removed'] + $totals['unchanged'];
  }

  /** @param array<int,array<string,mixed>> $components */
  public function countExcluded(array $components): int {
    $files = [];
    $types = [];
    $planned = 0;
    foreach ($components as $component) {
      $planned += max(0, (int) ($component['excluded_action_count'] ?? 0));
      foreach ((array) ($component['files'] ?? []) as $file) {
        $file = trim((string) $file);
        if ($file !== '') {
          $files[$file] = TRUE;
        }
      }
      foreach ((array) ($component['types'] ?? []) as $type) {
        $type = trim((string) $type);
        if ($type !== '') {
          $types[$type] = TRUE;
        }
      }
    }
    return $planned > 0 ? $planned : ($files ? count($files) : count($types));
  }

  public function countBlocked(array $result): int {
    $components = (array) ($result['dependency_components'] ?? []);
    $count = 0;
    foreach ($components as $component) {
      $actionCount = max(0, (int) (($component['action_count'] ?? 0)));
      $count += $actionCount > 0 ? $actionCount : max(1, (int) ($component['blocker_count'] ?? 1));
    }
    $count += count((array) ($result['possible_renames'] ?? []));

    $problems = [];
    foreach ((array) ($result['errors'] ?? []) as $error) {
      $message = $this->errorMessage($error);
      if ($message !== '') {
        $problems[$message] = TRUE;
      }
    }
    foreach ((array) ($result['items'] ?? []) as $item) {
      foreach ((array) (($item['errors'] ?? [])) as $error) {
        $message = $this->errorMessage($error);
        if ($message !== '') {
          $problems[$message] = TRUE;
        }
      }
    }

    // Dependency/rename blockers already have their own semantic count. Count
    // only additional failures, avoiding duplicate messages where possible.
    if ($count === 0) {
      $count = count($problems);
    }
    return $count;
  }

  public function countRemainingDifferences(array $diff): int {
    $count = 0;
    foreach ((array) ($diff['items'] ?? []) as $item) {
      $count += count((array) ($item['changed'] ?? []));
      $count += count((array) ($item['new_in_db'] ?? []));
      $count += count((array) ($item['missing_in_db'] ?? []));
    }
    return $count;
  }

  /**
   * Attach the canonical accounting payload to a result.
   *
   * @param array<int,array<string,mixed>> $excludedComponents
   * @param array<string,mixed>|null $verifiedDiff
   * @return array<string,mixed>
   */
  public function attach(array $result, array $excludedComponents = [], ?array $verifiedDiff = NULL): array {
    if (!$excludedComponents) {
      $excludedComponents = (array) ($result['excluded_components'] ?? []);
      if (!$excludedComponents && !empty($result['excluded_component']) && is_array($result['excluded_component'])) {
        $excludedComponents = [(array) $result['excluded_component']];
      }
    }

    $totals = $this->activityTotals((array) ($result['items'] ?? []));
    $totals['warnings'] += count((array) ($result['warnings'] ?? []));
    $totals['errors'] += count((array) ($result['errors'] ?? []));

    $dryRun = !empty($result['dry_run']);
    $planned = $totals['created'] + $totals['updated'] + $totals['removed'];
    $applied = $dryRun ? 0 : $planned;
    $blocked = empty($result['ok']) ? $this->countBlocked($result) : 0;
    $excluded = $this->countExcluded($excludedComponents);

    $remainingKnown = $verifiedDiff !== NULL && !empty($verifiedDiff['ok']);
    $remaining = $remainingKnown
      ? $this->countRemainingDifferences((array) $verifiedDiff)
      : ($dryRun ? $planned + $excluded : NULL);

    $result['excluded_components'] = array_values($excludedComponents);
    $result['result_accounting'] = [
      'applied' => $applied,
      'blocked' => $blocked,
      'excluded' => $excluded,
      'remaining_difference' => $remaining,
      'remaining_difference_known' => $remainingKnown,
      'created' => $totals['created'],
      'updated' => $totals['updated'],
      'removed' => $totals['removed'],
      'unchanged' => $totals['unchanged'],
      'warnings' => $totals['warnings'],
      'errors' => $totals['errors'],
    ];
    $result['summary_message'] = $this->summaryMessage($result['result_accounting']);
    return $result;
  }

  /** @param array<string,mixed> $accounting */
  public function summaryMessage(array $accounting): string {
    $remaining = !empty($accounting['remaining_difference_known'])
      ? (string) (int) ($accounting['remaining_difference'] ?? 0)
      : 'not yet verified';
    return sprintf(
      'Import result: %d applied, %d blocked, %d excluded, %s remaining difference(s). %d created, %d updated, %d removed, %d unchanged, %d warning(s), %d error(s).',
      (int) ($accounting['applied'] ?? 0),
      (int) ($accounting['blocked'] ?? 0),
      (int) ($accounting['excluded'] ?? 0),
      $remaining,
      (int) ($accounting['created'] ?? 0),
      (int) ($accounting['updated'] ?? 0),
      (int) ($accounting['removed'] ?? 0),
      (int) ($accounting['unchanged'] ?? 0),
      (int) ($accounting['warnings'] ?? 0),
      (int) ($accounting['errors'] ?? 0)
    );
  }

  /** @param mixed $error */
  private function errorMessage($error): string {
    if (is_array($error)) {
      return trim((string) ($error['message'] ?? ''));
    }
    return is_string($error) ? trim($error) : '';
  }
}
