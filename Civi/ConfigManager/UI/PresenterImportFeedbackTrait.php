<?php
namespace Civi\ConfigManager\UI;

/**
 * PresenterImportFeedback methods from Presenter.
 *
 * Methods run on the original owning object and retain the same state and
 * access modifiers. Do not instantiate this trait separately.
 */
trait PresenterImportFeedbackTrait {

  public function getImportApplyTypes(array $importPlan, array $selectedTypes = []): array {
    $types = [];
    foreach ($importPlan as $item) {
      if (!empty($item['importable']) && !empty($item['type'])) {
        $types[] = (string) $item['type'];
      }
    }
    $types = array_values(array_unique($types));

    // Diff rows use the base `extensions` handler type, but the UI filter may
    // have selected one or more virtual contributed-provider subtypes. Keep
    // those subtype selectors through preview/apply so a SQLTasks-only import
    // does not expand back to every extension-owned provider at submit time.
    if (in_array('extensions', $types, TRUE) && !in_array('extensions', $selectedTypes, TRUE)) {
      $providerTypes = array_values(array_unique(array_filter(array_map('strval', $selectedTypes), static function ($type) {
        return strpos($type, 'extensions:') === 0;
      })));
      if ($providerTypes) {
        $types = array_values(array_filter($types, static fn($type) => $type !== 'extensions'));
        $types = array_values(array_unique(array_merge($types, $providerTypes)));
      }
    }

    return $types;
  }

  public function countDiffChanges(array $result): int {
    $count = 0;
    foreach (($result['items'] ?? []) as $item) {
      $count += !empty($item['changed']) ? count($item['changed']) : 0;
      $count += !empty($item['new_in_db']) ? count($item['new_in_db']) : 0;
      $count += !empty($item['missing_in_db']) ? count($item['missing_in_db']) : 0;
    }
    return $count;
  }

  public function firstImportProblem(?array $importResult): string {
    if (!$importResult) {
      return '';
    }
    if (!empty($importResult['errors'])) {
      foreach ($importResult['errors'] as $error) {
        $type = !empty($error['type']) ? ((string) $error['type'] . ': ') : '';
        return $type . (string) ($error['message'] ?? json_encode($error));
      }
    }
    if (!empty($importResult['validation']['errors'])) {
      foreach ($importResult['validation']['errors'] as $error) {
        $type = !empty($error['type']) ? ((string) $error['type'] . ': ') : '';
        return $type . (string) ($error['message'] ?? json_encode($error));
      }
    }
    if (!empty($importResult['validation']['items'])) {
      foreach ($importResult['validation']['items'] as $item) {
        foreach (($item['errors'] ?? []) as $error) {
          $file = !empty($error['file']) ? ((string) $error['file'] . ': ') : '';
          return $this->humanizeType((string) ($item['type'] ?? 'validation')) . ': ' . $file . (string) ($error['message'] ?? json_encode($error));
        }
      }
    }
    foreach (($importResult['items'] ?? []) as $item) {
      foreach (($item['errors'] ?? []) as $error) {
        $file = !empty($error['file']) ? ((string) $error['file'] . ': ') : '';
        return $this->humanizeType((string) ($item['type'] ?? 'import')) . ': ' . $file . (string) ($error['message'] ?? json_encode($error));
      }
    }
    return '';
  }

  /**
   * Explain why the server will not offer the reviewed Import apply action.
   * This is presentation only: the existing server-side plan and permission
   * checks remain authoritative, including on direct POST and API4 calls.
   *
   * @param bool $canImport Whether the current user may apply Import.
   * @param array<int,string> $applyTypes Importable types in the reviewed scope.
   * @param int $errorCount Number of operator-visible Import errors.
   * @param array<string,mixed>|null $result Current Import preview result, if any.
   * @param string $planId Server-issued reviewed-plan token, if present.
   * @param array<int,array<string,mixed>> $components Blocked dependency groups.
   * @return array{ready:bool,reason:string,next_action:string}
   */
  public function getImportReadiness(bool $canImport, array $applyTypes, int $errorCount, ?array $result, string $planId, array $components = []): array {
    if (!$canImport) {
      return [
        'ready' => FALSE,
        'reason' => 'permission',
        'next_action' => ts('Your account cannot apply Import. Ask an administrator to review the required Configuration Manager permissions.'),
      ];
    }
    if ($components) {
      return [
        'ready' => FALSE,
        'reason' => 'dependencies',
        'next_action' => ts('Review the dependency components listed below. Fix the missing references and preview again, or exclude a complete component only when the safety check allows it.'),
      ];
    }
    if ($errorCount > 0 || (is_array($result) && ($result['ok'] ?? TRUE) === FALSE)) {
      return [
        'ready' => FALSE,
        'reason' => 'validation',
        'next_action' => ts('Fix the reported validation or provider errors, then build a fresh Import preview. No blocked Import may be applied.'),
      ];
    }
    if (!$applyTypes) {
      return [
        'ready' => FALSE,
        'reason' => 'no_changes',
        'next_action' => ts('No importable changes are available in the selected scope. Review Synchronize and Export first if Current CiviCRM changes must be saved.'),
      ];
    }
    if (!preg_match('/^[a-f0-9]{48}$/', $planId)) {
      return [
        'ready' => FALSE,
        'reason' => 'unreviewed_plan',
        'next_action' => ts('A current reviewed Import plan is required. Refresh the Import preview; never apply an expired or missing plan.'),
      ];
    }
    return ['ready' => TRUE, 'reason' => 'ready', 'next_action' => ''];
  }

  public function extractImportMessages(?array $importResult): array {
    $messages = [];
    if (!$importResult) {
      return $messages;
    }
    if (!empty($importResult['error'])) {
      $messages[] = [
        'type' => 'error',
        'title' => ts('Import'),
        'message' => (string) $importResult['error'],
      ];
    }
    if (!empty($importResult['errors']) && is_array($importResult['errors'])) {
      foreach ($importResult['errors'] as $error) {
        $messages[] = [
          'type' => 'error',
          'title' => !empty($error['type']) ? $this->humanizeType((string) $error['type']) : ts('Import'),
          'message' => (string) ($error['message'] ?? json_encode($error)),
        ];
      }
    }
    if (!empty($importResult['validation']['errors']) && is_array($importResult['validation']['errors'])) {
      foreach ($importResult['validation']['errors'] as $error) {
        $messages[] = [
          'type' => 'error',
          'title' => !empty($error['type']) ? $this->humanizeType((string) $error['type']) : ts('Validation'),
          'message' => (string) ($error['message'] ?? json_encode($error)),
        ];
      }
    }
    if (!empty($importResult['validation']['items']) && is_array($importResult['validation']['items'])) {
      foreach ($importResult['validation']['items'] as $item) {
        foreach (($item['warnings'] ?? []) as $warning) {
          $messages[] = [
            'type' => 'warning',
            'title' => $this->humanizeType((string) ($item['type'] ?? 'validation')),
            'message' => (string) ($warning['message'] ?? json_encode($warning)),
          ];
        }
        foreach (($item['errors'] ?? []) as $error) {
          $messages[] = [
            'type' => 'error',
            'title' => $this->humanizeType((string) ($item['type'] ?? 'validation')),
            'message' => (string) ($error['message'] ?? json_encode($error)),
          ];
        }
      }
    }
    foreach ((array) ($importResult['items'] ?? []) as $item) {
      foreach (($item['warnings'] ?? []) as $warning) {
        $messages[] = [
          'type' => 'warning',
          'title' => $this->humanizeType((string) ($item['type'] ?? 'import')),
          'message' => (string) ($warning['message'] ?? json_encode($warning)),
        ];
      }
      foreach (($item['errors'] ?? []) as $error) {
        $messages[] = [
          'type' => 'error',
          'title' => $this->humanizeType((string) ($item['type'] ?? 'import')),
          'message' => (string) ($error['message'] ?? json_encode($error)),
        ];
      }
    }

    // One provider can surface the same compatibility/import warning through
    // several YAML records. Repeating the same banner makes the actual blocker
    // difficult to find, so show each distinct message only once.
    $unique = [];
    foreach ($messages as $message) {
      $key = implode("\0", [
        (string) ($message['type'] ?? ''),
        (string) ($message['title'] ?? ''),
        (string) ($message['message'] ?? ''),
      ]);
      $unique[$key] = $message;
    }
    return array_values($unique);
  }

}
