<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Service\ConfigManager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ConfigManagerImportFilterTest extends TestCase {
  public function testExtensionSubtypeOnlyImportKeepsProviderValidationIsolated(): void {
    $manager = new ConfigManager();
    $requested = ['extensions:de.systopia.sqltasks:api3:Sqltask'];
    $effective = ['extensions', 'option-groups', 'contact-types', 'custom-data', 'message-templates'];

    $validation = new ReflectionMethod($manager, 'getImportValidationTypeFilter');
    $validation->setAccessible(TRUE);
    $apply = new ReflectionMethod($manager, 'getImportApplyTypeFilter');
    $apply->setAccessible(TRUE);

    self::assertSame($requested, $validation->invoke($manager, $requested, $effective));
    self::assertSame(['extensions'], $apply->invoke($manager, $requested, $effective));
  }

  public function testNormalImportKeepsDependencyClosureAndRequestedType(): void {
    $manager = new ConfigManager();
    $requested = ['custom-data'];
    $effective = ['option-groups', 'contact-types', 'custom-data'];

    $validation = new ReflectionMethod($manager, 'getImportValidationTypeFilter');
    $validation->setAccessible(TRUE);
    $apply = new ReflectionMethod($manager, 'getImportApplyTypeFilter');
    $apply->setAccessible(TRUE);

    self::assertSame(['option-groups', 'contact-types', 'custom-data'], $validation->invoke($manager, $requested, $effective));
    self::assertSame($effective, $apply->invoke($manager, $requested, $effective));
  }
}
