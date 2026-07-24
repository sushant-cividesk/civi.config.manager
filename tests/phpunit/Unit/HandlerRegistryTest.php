<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Service\HandlerRegistry;
use PHPUnit\Framework\TestCase;

final class HandlerRegistryTest extends TestCase {
  public function testBaseHandlerTypesAreUniqueAndWeightOrdered(): void {
    $handlers = (new HandlerRegistry())->getHandlers();
    $types = array_map(static fn($handler): string => $handler->getType(), $handlers);
    $weights = array_map(static fn($handler): int => $handler->getWeight(), $handlers);

    self::assertSame($types, array_values(array_unique($types)));
    self::assertSame($weights, array_values($weights));

    $sorted = $weights;
    sort($sorted);
    self::assertSame($sorted, $weights);
    self::assertContains('option-groups', $types);
    self::assertContains('message-templates', $types);
    self::assertContains('searchkit-saved-searches', $types);
    self::assertContains('formbuilder-afforms', $types);
  }
}
