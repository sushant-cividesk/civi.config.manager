<?php
namespace Civi\Api4\Action\ConfigManager;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\ConfigManager\Service\ConfigManager;

class Import extends AbstractAction {
  /**
   * Preview only. Set false to apply import.
   *
   * @var bool
   */
  protected $dryRun = TRUE;

  /**
   * Required to apply writes when dryRun=false.
   *
   * @var bool
   */
  protected $yes = FALSE;

  /**
   * Opaque reviewed-plan identifier required for write mode.
   *
   * @var string
   */
  protected $planId = '';


  /**
   * Optional blocked dependency component to exclude while building a new preview.
   *
   * @var string
   */
  protected $excludeComponentId = '';

  /**
   * Optional type filter.
   *
   * @var array
   */
  protected $type = [];

  public function _run(Result $result) {
    $manager = new ConfigManager();
    $effectiveDryRun = (bool) $this->dryRun || !(bool) $this->yes;
    $excludeComponentId = trim((string) $this->excludeComponentId);
    if ($effectiveDryRun) {
      $result[] = $excludeComponentId !== ''
        ? $manager->createReducedImportReviewPlan($excludeComponentId, (array) $this->type)
        : $manager->createImportReviewPlan((array) $this->type);
      return;
    }
    if ($excludeComponentId !== '') {
      throw new \RuntimeException('excludeComponentId is only valid while building a new Import preview. Apply the planId returned by that preview instead.');
    }

    $planId = trim((string) $this->planId);
    if ($planId === '') {
      throw new \RuntimeException('Import apply requires planId from a reviewed dry-run preview. Run ConfigManager.import in dry-run mode, review the result, then apply that planId.');
    }
    $result[] = $manager->applyImportReviewPlan($planId, NULL, (array) $this->type);
  }
}
