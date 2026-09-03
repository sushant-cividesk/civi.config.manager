<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\UI\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase {
  private array $originalRequest = [];

  protected function setUp(): void {
    parent::setUp();
    $this->originalRequest = $_REQUEST;
  }

  protected function tearDown(): void {
    $_REQUEST = $this->originalRequest;
    parent::tearDown();
  }

  /**
   * Requirement: the Settings browser must reach the read-only provider
   * inventory endpoint instead of silently falling back to Synchronize.
   * Failure mode: the AJAX request is normalized to sync and triggers the
   * wrong controller path while the page appears stuck loading metadata.
   */
  public function testProviderInventoryOperationIsAcceptedExplicitly(): void {
    $_REQUEST = ['op' => 'provider-inventory-json'];

    self::assertSame('provider-inventory-json', (new Request())->getOperation());
  }
}
