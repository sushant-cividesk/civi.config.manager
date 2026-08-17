<?php
namespace Civi\Api4\Action\ConfigManager;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\ConfigManager\Service\ConfigManager;

class ScopeItems extends AbstractAction {
  /** @var string */
  protected $type = '';

  public function _run(Result $result) {
    $result[] = ['ok' => TRUE] + (new ConfigManager())->getScopePickerItems((string) $this->type);
  }
}
