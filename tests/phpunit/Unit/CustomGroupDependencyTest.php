<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Handler\CustomGroupHandler;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CustomGroupDependencyTest extends TestCase {
  public function testOnlyContactBasedGroupsUseContactTypeScopeValues(): void {
    $handler = new CustomGroupHandler();
    $method = new ReflectionMethod($handler, 'usesContactTypeScope');
    $method->setAccessible(TRUE);

    foreach (['Contact', 'Individual', 'Organization', 'Household'] as $extends) {
      self::assertTrue($method->invoke($handler, $extends), $extends . ' should use ContactType dependencies.');
    }

    foreach (['Activity', 'Participant', 'Contribution', 'Membership', 'Case', 'Event'] as $extends) {
      self::assertFalse($method->invoke($handler, $extends), $extends . ' must not treat local scope IDs as ContactType IDs.');
    }
  }
}
