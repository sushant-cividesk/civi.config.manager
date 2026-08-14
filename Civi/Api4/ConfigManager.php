<?php

namespace Civi\Api4;

use Civi\Api4\Generic\AbstractEntity;
use Civi\Api4\Generic\BasicGetFieldsAction;
use Civi\ConfigManager\UI\Permission;

/**
 * Configuration Manager API4 facade.
 *
 * This is intentionally implemented as normal API4 actions so it works with
 * core cv commands, e.g. `cv api4 ConfigManager.status`.
 *
 * @package Civi\Api4
 */
class ConfigManager extends AbstractEntity {

  public static function permissions() {
    return [
      'default' => [Permission::ACCESS],
      'status' => [Permission::ACCESS],
      'listTypes' => [Permission::ACCESS],
      'diff' => [Permission::ACCESS],
      'validate' => [Permission::ACCESS],
      'export' => [Permission::EXPORT],
      'import' => [Permission::IMPORT],
      'confirmIdentityAlias' => [Permission::IMPORT],
      'getFields' => [Permission::ACCESS],
    ];
  }

  /**
   * API4 metadata action.
   *
   * AbstractEntity requires getFields(), and SearchKit metadata loading expects
   * this method to return an API4 action object, not a plain array.
   */
  public static function getFields($checkPermissions = TRUE) {
    return (new BasicGetFieldsAction(__CLASS__, __FUNCTION__, function () {
      return [];
    }))->setCheckPermissions($checkPermissions);
  }

  public static function status($checkPermissions = TRUE) {
    return (new Action\ConfigManager\Status(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  public static function listTypes($checkPermissions = TRUE) {
    return (new Action\ConfigManager\ListTypes(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  public static function export($checkPermissions = TRUE) {
    return (new Action\ConfigManager\Export(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  public static function diff($checkPermissions = TRUE) {
    return (new Action\ConfigManager\Diff(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  public static function validate($checkPermissions = TRUE) {
    return (new Action\ConfigManager\Validate(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  public static function import($checkPermissions = TRUE) {
    return (new Action\ConfigManager\Import(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  public static function confirmIdentityAlias($checkPermissions = TRUE) {
    return (new Action\ConfigManager\ConfirmIdentityAlias(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

}
