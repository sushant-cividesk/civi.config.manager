<?php
namespace Civi\ConfigManager\UI;

use Civi\ConfigManager\Service\ConfigManager;

/**
 * Reconnect the Import page to its last reviewed server-side plan.
 *
 * Keeping the opaque plan ID in the authenticated CiviCRM session lets a page
 * refresh reuse the same reviewed plan without trusting mutable browser state.
 */
final class ImportReviewPlanCoordinator {
  private const SESSION_KEY = 'civicfg_import_review_plan_id';
  private ConfigManager $manager;

  public function __construct(ConfigManager $manager) {
    $this->manager = $manager;
  }

  /** @return array{result:array<string,mixed>,plan_id:string,reconnected:bool} */
  public function loadOrCreate(array $types): array {
    $session = \CRM_Core_Session::singleton();
    $planId = trim((string) $session->get(self::SESSION_KEY));
    if (preg_match('/^[a-f0-9]{48}$/', $planId)) {
      try {
        $result = $this->manager->resumeImportReviewPlan($planId, $types);
        return ['result' => $result, 'plan_id' => $planId, 'reconnected' => TRUE];
      }
      catch (\Throwable $e) {
        // Stale/expired plans are not reusable. Rendering a fresh preview is a
        // safe read-only recovery path; apply still requires the new plan ID.
        $this->manager->discardImportReviewPlan($planId);
        $session->set(self::SESSION_KEY, NULL);
      }
    }

    $result = $this->manager->createImportReviewPlan($types);
    $planId = (string) ($result['plan_id'] ?? '');
    $session->set(self::SESSION_KEY, $planId !== '' ? $planId : NULL);
    return ['result' => $result, 'plan_id' => $planId, 'reconnected' => FALSE];
  }
}
