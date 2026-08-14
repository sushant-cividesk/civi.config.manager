<?php
namespace Civi\Api4\Action\ConfigManager;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\ConfigManager\Service\ConfigManager;

/**
 * Confirm a reviewed machine-identity rename.
 */
class ConfirmIdentityAlias extends AbstractAction {
  /** @var string */
  protected $providerKey = '';

  /** @var string */
  protected $oldConfigKey = '';

  /** @var string */
  protected $newConfigKey = '';

  public function _run(Result $result) {
    $result[] = (new ConfigManager())->confirmIdentityAlias(
      (string) $this->providerKey,
      (string) $this->oldConfigKey,
      (string) $this->newConfigKey
    );
  }
}
