<?php
namespace Civi\Api4\Action\ConfigManager;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\ConfigManager\Service\ConfigManager;

/**
 * Explicitly scan watch-only configuration and update local fingerprints.
 */
class Watch extends AbstractAction {
  /**
   * Optional type filter.
   *
   * @var array
   */
  protected $type = [];

  public function _run(Result $result) {
    $result[] = (new ConfigManager())->scanWatched((array) $this->type);
  }
}
