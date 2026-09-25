<?php
namespace Civi\ConfigManager\UI;

use Civi\ConfigManager\Service\ConfigManager;

/**
 * Owns Import action side effects so MainPage remains orchestration-only.
 */
final class ImportActionCoordinator {
  private ConfigManager $manager;
  private OperationResultPresenter $presenter;
  private ImportReviewPlanCoordinator $reviewCoordinator;

  public function __construct(ConfigManager $manager, OperationResultPresenter $presenter) {
    $this->manager = $manager;
    $this->presenter = $presenter;
    $this->reviewCoordinator = new ImportReviewPlanCoordinator($manager);
  }

  /** @return array{notice:string,type:string,result:array<string,mixed>} */
  public function apply(string $planId): array {
    $result = $this->manager->applyImportReviewPlan($planId);
    $session = \CRM_Core_Session::singleton();
    $session->set('civicfg_last_import_result', $result);
    $session->set('civicfg_last_export_result', NULL);
    $session->set('civicfg_last_import_summary', $this->presenter->importSummary($result));
    $this->reviewCoordinator->clear();

    $summary = trim((string) ($result['summary_message'] ?? ''));
    if (!empty($result['ok'])) {
      return [
        'notice' => trim(ts('Import complete. Synchronize will verify the resulting configuration state.') . ' ' . $summary),
        'type' => 'success',
        'result' => $result,
      ];
    }

    $problem = trim((string) ($this->presenter->importSummary($result)['problem'] ?? ''));
    return [
      'notice' => trim(ts('Import found problems.') . ' ' . ($problem !== '' ? $problem : ts('Review the warnings or errors below.')) . ' ' . $summary),
      'type' => 'error',
      'result' => $result,
    ];
  }

  /** @return array{notice:string,type:string,remaining_types:array<int,string>,result:array<string,mixed>} */
  public function excludeDependencyComponent(string $componentId, array $types): array {
    // Any prior apply-capable plan is invalid once the administrator chooses a
    // reduced scope. Never mutate an approved plan in place.
    $this->reviewCoordinator->discardCurrentPlan();
    $result = $this->manager->createReducedImportReviewPlan($componentId, $types);
    $remaining = array_values(array_map('strval', (array) ($result['remaining_requested_types'] ?? [])));
    $this->reviewCoordinator->adoptResult($result, $remaining);

    $excluded = (array) ($result['excluded_component'] ?? []);
    $title = trim((string) ($excluded['title'] ?? 'dependency component'));
    $count = (int) ($excluded['blocker_count'] ?? 0);
    return [
      'notice' => ts('Excluded %1 from this Import preview (%2 blocker(s)). A completely new preview was built from current Saved Config and Current CiviCRM state.', [1 => $title, 2 => $count]),
      'type' => !empty($result['ok']) ? 'warning' : 'error',
      'remaining_types' => $remaining,
      'result' => $result,
    ];
  }
}
