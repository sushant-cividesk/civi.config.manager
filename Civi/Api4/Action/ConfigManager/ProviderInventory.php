<?php
namespace Civi\Api4\Action\ConfigManager;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\ConfigManager\Service\ConfigManager;

/**
 * Admin-only read boundary for registered provider metadata.
 */
class ProviderInventory extends AbstractAction {
  public function _run(Result $result) {
    $result[] = (new ConfigManager())->getProviderInventory();
  }
}
