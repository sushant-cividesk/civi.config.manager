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
  private const REDUCED_STATE_KEY = 'civicfg_import_reduced_state';
  private ConfigManager $manager;

  public function __construct(ConfigManager $manager) {
    $this->manager = $manager;
  }

  /** @return array{result:array<string,mixed>,plan_id:string,reconnected:bool} */
  public function loadOrCreate(array $types): array {
    $this->clearReducedStateWhenScopeChanged($types);
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

  /** @param array<string,mixed> $result */
  public function adoptResult(array $result, array $remainingTypes): void {
    $session = \CRM_Core_Session::singleton();
    $planId = trim((string) ($result['plan_id'] ?? ''));
    $session->set(self::SESSION_KEY, preg_match('/^[a-f0-9]{48}$/', $planId) ? $planId : NULL);

    $existing = $this->getReducedState();
    $components = (array) ($existing['components'] ?? []);
    $excluded = (array) ($result['excluded_component'] ?? []);
    if ($excluded) {
      $components[] = $excluded;
    }
    $session->set(self::REDUCED_STATE_KEY, [
      'remaining_types' => $this->sortedTypes($remainingTypes),
      'components' => $components,
    ]);
  }

  public function discardCurrentPlan(): void {
    $session = \CRM_Core_Session::singleton();
    $planId = trim((string) $session->get(self::SESSION_KEY));
    if (preg_match('/^[a-f0-9]{48}$/', $planId)) {
      try {
        $this->manager->discardImportReviewPlan($planId);
      }
      catch (\Throwable $e) {
        // The plan may already be stale/expired; clearing the session identity
        // is enough to prevent it being reused by the UI.
      }
    }
    $session->set(self::SESSION_KEY, NULL);
  }

  public function clear(): void {
    $this->discardCurrentPlan();
    \CRM_Core_Session::singleton()->set(self::REDUCED_STATE_KEY, NULL);
  }

  /** @return array<int,array<string,mixed>> */
  public function getExcludedComponents(array $types): array {
    $this->clearReducedStateWhenScopeChanged($types);
    return array_values((array) ($this->getReducedState()['components'] ?? []));
  }

  /** @return array<string,mixed> */
  private function getReducedState(): array {
    $state = \CRM_Core_Session::singleton()->get(self::REDUCED_STATE_KEY);
    return is_array($state) ? $state : [];
  }

  private function clearReducedStateWhenScopeChanged(array $types): void {
    $state = $this->getReducedState();
    if (!$state) {
      return;
    }
    if ($this->sortedTypes((array) ($state['remaining_types'] ?? [])) !== $this->sortedTypes($types)) {
      \CRM_Core_Session::singleton()->set(self::REDUCED_STATE_KEY, NULL);
    }
  }

  /** @return string[] */
  private function sortedTypes(array $types): array {
    $types = array_values(array_unique(array_filter(array_map('strval', $types), 'strlen')));
    sort($types, SORT_STRING);
    return $types;
  }
}
