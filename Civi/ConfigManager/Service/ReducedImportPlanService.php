<?php
namespace Civi\ConfigManager\Service;

/**
 * Rebuild a blocked Import preview after one proven-safe component exclusion.
 *
 * This service never edits a reviewed plan. It recomputes the current blocker
 * graph, verifies the selected component again, and asks ConfigManager to run a
 * completely fresh preview for the remaining scope.
 */
final class ReducedImportPlanService {
  private ImportDependencyPlanner $planner;

  public function __construct(ImportDependencyPlanner $planner) {
    $this->planner = $planner;
  }

  /** @return array<string,mixed> */
  public function build(ConfigManager $manager, string $componentId, array $typeFilter = []): array {
    if (!preg_match('/^[a-f0-9]{24}$/', $componentId)) {
      throw new \RuntimeException('Invalid dependency component identifier. Build and review a fresh Import preview.');
    }

    $current = $manager->createImportReviewPlan($typeFilter);
    if (!empty($current['ok'])) {
      if (!empty($current['plan_id'])) {
        $manager->discardImportReviewPlan((string) $current['plan_id']);
      }
      throw new \RuntimeException('The selected dependency blocker is no longer present. Review the fresh Import preview instead.');
    }

    $component = $this->planner->findComponent(
      (array) ($current['dependency_components'] ?? []),
      $componentId
    );
    if ($component === NULL) {
      throw new \RuntimeException('The selected dependency component changed or is no longer available. Build and review a fresh Import preview.');
    }
    if (empty($component['can_exclude'])) {
      throw new \RuntimeException((string) ($component['exclusion_reason'] ?? 'This dependency component cannot be safely excluded. Fix the blocker and preview again.'));
    }

    $remainingTypes = array_values(array_map('strval', (array) ($component['remaining_requested_types'] ?? [])));
    if (!$remainingTypes) {
      throw new \RuntimeException('Excluding this dependency component would leave nothing to import. Fix the blocker and preview again.');
    }

    $fresh = $manager->createImportReviewPlan($remainingTypes);
    $fresh['reduced_plan'] = TRUE;
    $fresh['excluded_component'] = [
      'id' => (string) $component['id'],
      'title' => (string) ($component['title'] ?? 'Dependency component'),
      'types' => array_values(array_map('strval', (array) ($component['excluded_types'] ?? []))),
      'type_labels' => array_values(array_map('strval', (array) ($component['type_labels'] ?? []))),
      'files' => array_values(array_map('strval', (array) ($component['files'] ?? []))),
      'blocker_count' => (int) ($component['blocker_count'] ?? 0),
    ];
    $fresh['remaining_requested_types'] = $remainingTypes;
    return $fresh;
  }
}
