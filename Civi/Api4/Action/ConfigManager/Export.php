<?php
namespace Civi\Api4\Action\ConfigManager;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\ConfigManager\Service\ConfigManager;

class Export extends AbstractAction {
  /**
   * Preview only. Set false to write files.
   *
   * @var bool
   */
  protected $dryRun = TRUE;

  /**
   * Optional type filter.
   *
   * @var array
   */
  protected $type = [];

  public function _run(Result $result) {
    $result[] = (new ConfigManager())->export((bool) $this->dryRun, (array) $this->type);
  }

}
