<?php
namespace Civi\Api4\Action\ConfigManager;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\ConfigManager\Service\ConfigManager;

class CrossSiteSet extends AbstractAction {
  /** @var bool */
  protected $allowed = FALSE;

  public function _run(Result $result) {
    $result[] = (new ConfigManager())->setCrossSiteImportAllowed((bool) $this->allowed);
  }
}
