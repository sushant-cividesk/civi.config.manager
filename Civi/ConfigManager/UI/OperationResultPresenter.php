<?php
namespace Civi\ConfigManager\UI;

/**
 * Builds compact, user-facing operation summaries for the admin UI.
 *
 * Large path/item arrays stay in durable job results instead of PHP session.
 */
class OperationResultPresenter {

  /**
   * @param array<string,mixed> $result
   * @param array<string,mixed> $status
   * @param array<string,mixed> $job
   * @return array<string,mixed>
   */
  public function exportSummary(array $result, array $status, array $job = []): array {
    $jobStatus = trim((string) ($job['status'] ?? ''));
    $errors = count((array) ($result['errors'] ?? []));
    $problem = trim((string) ($job['error'] ?? ''));
    if ($problem === '') {
      $problem = $this->firstProblem($result);
    }
    $ok = ($jobStatus === '' || $jobStatus === 'complete')
      && empty($result['errors'])
      && (($result['ok'] ?? TRUE) !== FALSE);

    return [
      'ok' => $ok,
      'updated' => isset($result['updated_count']) ? (int) $result['updated_count'] : count((array) ($result['written'] ?? [])),
      'created' => (int) ($result['created_count'] ?? 0),
      'unchanged' => count((array) ($result['skipped'] ?? [])),
      'removed' => count((array) ($result['deleted'] ?? [])),
      'review_only' => (int) ($result['monitor_only'] ?? 0),
      'review_only_items' => $this->reviewOnlyItems($result),
      'warnings' => count((array) ($result['warnings'] ?? [])),
      'errors' => $errors > 0 ? $errors : ($ok ? 0 : 1),
      'problem' => $problem,
      'next_action' => $ok ? '' : ts('Fix the reported configuration problem, then run Export again. Existing Saved Configs were not replaced by an incomplete export.'),
      'processed_items' => (int) ($result['processed_items'] ?? 0),
      'saved_config_count' => (int) ($status['saved_config_count'] ?? 0),
      'completed_at' => (string) ($job['finished_at'] ?? date('Y-m-d H:i:s')),
      'dependency_types' => array_values(array_map('strval', (array) ($result['dependency_types'] ?? []))),
    ];
  }

  /** @param array<string,mixed> $summary */
  public function exportMessage(array $summary): string {
    if (empty($summary['ok'])) {
      $errors = max(1, (int) ($summary['errors'] ?? 0));
      $message = ts('Export stopped with %1 error(s). The previous Saved Config snapshot was left unchanged.', [1 => $errors]);
      $problem = trim((string) ($summary['problem'] ?? ''));
      return $problem !== '' ? $message . ' ' . $problem : $message;
    }

    $created = (int) ($summary['created'] ?? 0);
    $updated = (int) ($summary['updated'] ?? 0);
    $removed = (int) ($summary['removed'] ?? 0);
    $unchanged = (int) ($summary['unchanged'] ?? 0);
    $reviewOnly = (int) ($summary['review_only'] ?? 0);
    $message = ($created || $updated || $removed)
      ? ts('Export complete. %1 Saved Config file(s) created, %2 updated, %3 stale Saved Config file(s) deleted, %4 unchanged file(s) skipped.', [1 => $created, 2 => $updated, 3 => $removed, 4 => $unchanged])
      : ts('Export complete. Saved Config files already match Current CiviCRM configuration.');
    if ($reviewOnly > 0) {
      $message .= ' ' . ts('%1 configuration object(s) were saved for review but are not automatically managed because their portable identity is not proven safe.', [1 => $reviewOnly]);
    }
    return $message;
  }

  /**
   * @param array<string,mixed> $result
   * @return array<int,array{type:string,label:string,path:string}>
   */
  private function reviewOnlyItems(array $result): array {
    $unique = [];
    foreach ((array) ($result['review_only_items'] ?? []) as $item) {
      $item = (array) $item;
      $compact = [
        'type' => trim((string) ($item['type'] ?? '')),
        'label' => trim((string) ($item['label'] ?? '')),
        'path' => trim((string) ($item['path'] ?? '')),
      ];
      $key = $compact['path'] !== '' ? $compact['path'] : $compact['type'] . '|' . $compact['label'];
      $unique[$key] = $compact;
    }
    return array_values($unique);
  }

  /**
   * @param array<string,mixed> $result
   * @param array<string,mixed> $job
   * @return array<string,mixed>
   */
  public function importSummary(array $result, array $job = []): array {
    $totals = [
      'created' => 0,
      'updated' => 0,
      'removed' => 0,
      'unchanged' => 0,
      'warnings' => 0,
      'errors' => 0,
    ];
    foreach ((array) ($result['items'] ?? []) as $item) {
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
    $totals['warnings'] += count((array) ($result['warnings'] ?? []));
    $totals['errors'] += count((array) ($result['errors'] ?? []));

    $jobStatus = trim((string) ($job['status'] ?? ''));
    $ok = ($jobStatus === '' || $jobStatus === 'complete')
      && $totals['errors'] === 0
      && (($result['ok'] ?? TRUE) !== FALSE);
    $problem = trim((string) ($job['error'] ?? ''));
    if ($problem === '') {
      $problem = $this->firstProblem($result);
    }

    return $totals + [
      'ok' => $ok,
      'problem' => $problem,
      'next_action' => $ok ? '' : ts('Review the problem and current Synchronize state before retrying. Fix the blocked configuration first; if earlier changes were applied, verify them before another import.'),
      'completed_at' => (string) ($job['finished_at'] ?? date('Y-m-d H:i:s')),
      'summary_message' => trim((string) ($result['summary_message'] ?? '')),
    ];
  }

  /** @param array<string,mixed> $result */
  private function firstProblem(array $result): string {
    foreach ((array) ($result['errors'] ?? []) as $error) {
      if (is_array($error) && trim((string) ($error['message'] ?? '')) !== '') {
        return trim((string) $error['message']);
      }
      if (is_string($error) && trim($error) !== '') {
        return trim($error);
      }
    }
    foreach ((array) ($result['items'] ?? []) as $item) {
      $item = (array) $item;
      foreach ((array) ($item['errors'] ?? []) as $error) {
        if (is_array($error) && trim((string) ($error['message'] ?? '')) !== '') {
          return trim((string) $error['message']);
        }
        if (is_string($error) && trim($error) !== '') {
          return trim($error);
        }
      }
    }
    return trim((string) ($result['message'] ?? ''));
  }
}
