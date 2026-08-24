<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Service\StateStore;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class StateStoreNullableBindingTest extends TestCase {
  public function testNullStringUsesSqlNullWithoutTypedParameter(): void {
    $store = new StateStore();
    $method = new ReflectionMethod($store, 'nullableStringSql');
    $method->setAccessible(TRUE);

    /** @var array<int, array{0:mixed, 1:string}> $params */
    $params = [
      1 => ['provider', 'String'],
    ];

    $sql = $method->invokeArgs($store, [NULL, 6, &$params]);

    self::assertSame('NULL', $sql);
    self::assertArrayNotHasKey(6, $params);
  }

  public function testRealStringKeepsTypedPlaceholderBinding(): void {
    $store = new StateStore();
    $method = new ReflectionMethod($store, 'nullableStringSql');
    $method->setAccessible(TRUE);

    /** @var array<int, array{0:mixed, 1:string}> $params */
    $params = [];

    $sql = $method->invokeArgs($store, ['abc123', 7, &$params]);

    self::assertSame('%7', $sql);
    self::assertArrayHasKey(7, $params);
    self::assertSame(['abc123', 'String'], $params[7]);
  }
}
