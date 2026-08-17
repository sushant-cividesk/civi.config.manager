<?php
namespace Civi\Api4\Action\ConfigManager;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\ConfigManager\Service\ConfigManager;

class ScopeSet extends AbstractAction {
  /** @var string */
  protected $type = '';

  /** @var string */
  protected $mode = '';

  /** @var array */
  protected $selectors = [];

  /** @var bool */
  protected $watchUnmanaged = FALSE;

  public function _run(Result $result) {
    $result[] = (new ConfigManager())->setScopePolicy(
      (string) $this->type,
      (string) $this->mode,
      (array) $this->selectors,
      (bool) $this->watchUnmanaged
    );
  }
}
