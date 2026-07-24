<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\Api4\ConfigManager;
use Civi\Api4\Generic\BasicGetFieldsAction;
use PHPUnit\Framework\TestCase;

final class Api4FacadeTest extends TestCase {
  public function testGetFieldsReturnsApi4ActionObject(): void {
    require_once dirname(__DIR__, 3) . '/Civi/ConfigManager/UI/Permission.php';
    require_once dirname(__DIR__, 3) . '/Civi/Api4/ConfigManager.php';

    $action = ConfigManager::getFields(FALSE);

    self::assertInstanceOf(BasicGetFieldsAction::class, $action);
    self::assertFalse($action->getCheckPermissions());
  }

  public function testPermissionsSeparateReadExportAndImportOperations(): void {
    require_once dirname(__DIR__, 3) . '/Civi/ConfigManager/UI/Permission.php';
    require_once dirname(__DIR__, 3) . '/Civi/Api4/ConfigManager.php';

    $permissions = ConfigManager::permissions();

    self::assertNotSame($permissions['diff'], $permissions['export']);
    self::assertNotSame($permissions['export'], $permissions['import']);
    self::assertSame($permissions['default'], $permissions['getFields']);
  }
}
