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
      'providerInventory' => [Permission::ADMINISTER],
      'diff' => [Permission::ACCESS],
      'validate' => [Permission::ACCESS],
      'export' => [Permission::EXPORT],
      'import' => [Permission::IMPORT],
      'confirmIdentityAlias' => [Permission::IMPORT],
      'watch' => [Permission::ADMINISTER],
      'scopeGet' => [Permission::ADMINISTER],
      'scopeItems' => [Permission::ADMINISTER],
      'scopeSet' => [Permission::ADMINISTER],
      'crossSiteStatus' => [Permission::ADMINISTER],
      'crossSiteSet' => [Permission::ADMINISTER],
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

  public static function providerInventory($checkPermissions = TRUE) {
    return (new Action\ConfigManager\ProviderInventory(__CLASS__, __FUNCTION__))
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

  public static function watch($checkPermissions = TRUE) {
    return (new Action\ConfigManager\Watch(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  public static function scopeGet($checkPermissions = TRUE) {
    return (new Action\ConfigManager\ScopeGet(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  public static function scopeItems($checkPermissions = TRUE) {
    return (new Action\ConfigManager\ScopeItems(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  public static function scopeSet($checkPermissions = TRUE) {
    return (new Action\ConfigManager\ScopeSet(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  public static function crossSiteStatus($checkPermissions = TRUE) {
    return (new Action\ConfigManager\CrossSiteStatus(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  public static function crossSiteSet($checkPermissions = TRUE) {
    return (new Action\ConfigManager\CrossSiteSet(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

}
