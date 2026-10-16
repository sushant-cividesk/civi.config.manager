<?php
namespace Civi\ConfigManager\UI;

use Civi\ConfigManager\Service\QueuedOperationService;

/**
 * MainPageOperationEndpoints methods from MainPage.
 *
 * Methods run on the original owning object and retain the same state and
 * access modifiers. Do not instantiate this trait separately.
 */
trait MainPageOperationEndpointsTrait {

  /**
   * Stream truthful server-side progress as newline-delimited JSON.
   * Export/import remain normal synchronous PHP operations, but the browser can
   * render each completed server step instead of showing a timer animation.
   */
  private function streamOperation(string $postAction, array $types): void {
    if (!in_array($postAction, ['export_write', 'import_apply'], TRUE)) {
      throw new \RuntimeException('Unsupported Configuration Manager streamed operation.');
    }
    while (ob_get_level() > 0) {
      @ob_end_flush();
    }
    @ini_set('output_buffering', '0');
    @ini_set('zlib.output_compression', '0');
    header('Content-Type: application/x-ndjson; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Accel-Buffering: no');
    $emit = static function (array $event): void {
      echo json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
      @flush();
    };
    $progress = static function (array $event) use ($emit): void {
      $event['event'] = 'progress';
      $emit($event);
    };
    try {
      if ($postAction === 'export_write') {
        $result = $this->manager->export(FALSE, $types, $progress);
        $errors = (array) ($result['errors'] ?? []);
        $exportSummary = $this->operationResultPresenter->exportSummary($result, $this->manager->status());
        if ($errors) {
          $first = (array) reset($errors);
          $problem = trim((string) ($first['message'] ?? ''));
          $message = ts('Export stopped with %1 error(s). The previous Saved Config snapshot was left unchanged.', [1 => count($errors)]);
          if ($problem !== '') {
            $message .= ' ' . $problem;
          }
          $statusType = 'error';
        }
        else {
          $message = $this->operationResultPresenter->exportMessage($exportSummary);
          $statusType = 'success';
        }
        \CRM_Core_Session::singleton()->set('civicfg_last_import_summary', NULL);
        \CRM_Core_Session::singleton()->set('civicfg_last_export_result', $exportSummary);
        \CRM_Core_Session::setStatus($message, ts('Configuration Manager'), $statusType);
        $emit([
          'event' => 'complete',
          'ok' => !$errors,
          'percent' => 100,
          'message' => $message,
          'redirect_url' => \CRM_Utils_System::url('civicrm/admin/config-manager', 'reset=1&op=sync', FALSE, NULL, FALSE),
        ]);
      }
      else {
        $result = $this->manager->applyImportReviewPlan($this->request->requireImportPlanId(), $progress);
        \CRM_Core_Session::singleton()->set('civicfg_last_import_result', $result);
        $summaryMessage = (string) ($result['summary_message'] ?? '');
        if (!empty($result['ok'])) {
          $message = trim(ts('Import complete. Synchronize will verify the resulting configuration state.') . ' ' . $summaryMessage);
          $statusType = 'success';
        }
        else {
          $firstProblem = $this->presenter->firstImportProblem($result);
          $message = trim(ts('Import found problems.') . ' ' . ($firstProblem ?: ts('Review the warnings or errors below.')) . ' ' . $summaryMessage);
          $statusType = 'error';
        }
        \CRM_Core_Session::setStatus($message, ts('Configuration Manager'), $statusType);
        $emit([
          'event' => 'complete',
          'ok' => !empty($result['ok']),
          'percent' => 100,
          'message' => $message,
          'redirect_url' => \CRM_Utils_System::url('civicrm/admin/config-manager', 'reset=1&op=sync', FALSE, NULL, FALSE),
        ]);
      }
    }
    catch (\Throwable $e) {
      $emit([
        'event' => 'error',
        'ok' => FALSE,
        'message' => $e->getMessage(),
      ]);
    }
    exit;
  }

  /**
   * Start a persistent CiviCRM Queue job. The form action itself is used for
   * permission/CSRF checks, but the queue stores only operation metadata.
   */
  private function jsonStartOperation(string $postAction, array $types): void {
    try {
      if ($postAction === 'export_write') {
        Permission::require(Permission::EXPORT);
        $operation = 'export';
      }
      elseif ($postAction === 'import_apply') {
        Permission::require(Permission::IMPORT);
        $operation = 'import';
      }
      else {
        throw new \RuntimeException('Unsupported Configuration Manager queued operation.');
      }
      $reviewPlanId = $operation === 'import' ? $this->request->getImportPlanId() : '';
      $job = (new QueuedOperationService())->start($operation, $types, $reviewPlanId);
      $payload = ['ok' => TRUE, 'job' => $job] + $this->operationLinks((int) $job['id']);
    }
    catch (\Throwable $e) {
      $payload = ['ok' => FALSE, 'error' => $e->getMessage()];
    }
    $this->emitJsonAndExit($payload);
  }

  /**
   * Advance at most one persistent queue item. */
  private function jsonAdvanceOperation(): void {
    try {
      $jobId = isset($_REQUEST['job_id']) ? (int) $_REQUEST['job_id'] : 0;
      $service = new QueuedOperationService();
      $job = $service->status($jobId);
      $this->requireOperationPermission((string) ($job['operation'] ?? ''));
      $job = $service->advance($jobId);
      $payload = ['ok' => TRUE, 'job' => $job] + $this->operationLinks($jobId);
    }
    catch (\Throwable $e) {
      $payload = ['ok' => FALSE, 'error' => $e->getMessage()];
    }
    $this->emitJsonAndExit($payload);
  }

  /**
   * Read-only reconnect/status endpoint for a running queue job. */
  private function jsonOperationStatus(): void {
    try {
      $jobId = isset($_REQUEST['job_id']) ? (int) $_REQUEST['job_id'] : 0;
      $service = new QueuedOperationService();
      $job = $service->status($jobId);
      $this->requireOperationPermission((string) ($job['operation'] ?? ''));
      $payload = ['ok' => TRUE, 'job' => $job] + $this->operationLinks($jobId);
      if (in_array((string) ($job['status'] ?? ''), ['complete', 'failed', 'blocked'], TRUE)) {
        [$message, $type] = $this->operationTerminalMessage($job);
        $payload['terminal_message'] = $message;
        $payload['terminal_type'] = $type;
        if ((string) ($job['operation'] ?? '') === 'export') {
          \CRM_Core_Session::singleton()->set('civicfg_last_import_summary', NULL);
          \CRM_Core_Session::singleton()->set('civicfg_last_export_result', $this->operationResultPresenter->exportSummary((array) ($job['result'] ?? []), $this->manager->status(), $job));
        }
        elseif ((string) ($job['operation'] ?? '') === 'import' && is_array($job['result'] ?? NULL)) {
          \CRM_Core_Session::singleton()->set('civicfg_last_import_result', (array) $job['result']);
          \CRM_Core_Session::singleton()->set('civicfg_last_export_result', NULL);
          \CRM_Core_Session::singleton()->set('civicfg_last_import_summary', $this->operationResultPresenter->importSummary((array) $job['result'], $job));
        }
        \CRM_Core_Session::setStatus($message, ts('Configuration Manager'), $type);
      }
    }
    catch (\Throwable $e) {
      $payload = ['ok' => FALSE, 'error' => $e->getMessage()];
    }
    $this->emitJsonAndExit($payload);
  }

  private function requireOperationPermission(string $operation): void {
    if ($operation === 'export') {
      Permission::require(Permission::EXPORT);
      return;
    }
    if ($operation === 'import') {
      Permission::require(Permission::IMPORT);
      return;
    }
    throw new \RuntimeException('Unknown Configuration Manager operation type.');
  }

  /**
   * @return array{status_url:string,step_url:string,redirect_url:string} */
  private function operationLinks(int $jobId): array {
    return [
      'status_url' => \CRM_Utils_System::url('civicrm/admin/config-manager', 'reset=1&op=operation-status-json&job_id=' . $jobId, FALSE, NULL, FALSE),
      'step_url' => \CRM_Utils_System::url('civicrm/admin/config-manager', 'reset=1&op=operation-step-json&job_id=' . $jobId, FALSE, NULL, FALSE),
      'redirect_url' => \CRM_Utils_System::url('civicrm/admin/config-manager', 'reset=1&op=sync', FALSE, NULL, FALSE),
    ];
  }

  /**
   * @return array{0:string,1:string} */
  private function operationTerminalMessage(array $job): array {
    $operation = (string) ($job['operation'] ?? 'operation');
    $result = (array) ($job['result'] ?? []);
    if ((string) ($job['status'] ?? '') !== 'complete') {
      $error = trim((string) ($job['error'] ?? ''));
      return [
        ucfirst($operation) . ' stopped safely.' . ($error !== '' ? ' ' . $error : ' Review the recorded operation diagnostics.'),
        'error',
      ];
    }
    if ($operation === 'export') {
      $summary = $this->operationResultPresenter->exportSummary($result, [], $job);
      return [$this->operationResultPresenter->exportMessage($summary), 'success'];
    }
    $summary = trim((string) ($result['summary_message'] ?? ''));
    return [trim(ts('Import complete. Synchronize will verify the resulting configuration state.') . ' ' . $summary), 'success'];
  }

  /**
   * @param array<string,mixed> $payload */
  private function emitJsonAndExit(array $payload): void {
    // WordPress/contributed extensions can emit notices or buffered markup
    // during bootstrap. A JSON endpoint must never append its payload to that
    // HTML because response.json() would then fail with "Unexpected token <".
    // Discard only buffered output immediately before this terminal JSON
    // response; normal HTML page rendering never enters this method.
    while (ob_get_level() > 0) {
      if (!@ob_end_clean()) {
        break;
      }
    }

    \CRM_Utils_System::setHttpHeader('Content-Type', 'application/json; charset=utf-8');
    \CRM_Utils_System::setHttpHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === FALSE) {
      $json = '{"ok":false,"error":"Configuration Manager could not encode the JSON response."}';
    }
    echo $json;
    \CRM_Utils_System::civiExit();
  }

}
