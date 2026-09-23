<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Service\ImportPlanStore;
use Civi\ConfigManager\Tests\Support\TemporaryDirectoryTrait;
use PHPUnit\Framework\TestCase;

final class ImportPlanStoreTest extends TestCase {
  use TemporaryDirectoryTrait;

  protected function tearDown(): void {
    $this->removeTemporaryDirectories();
    parent::tearDown();
  }

  public function testPlanRoundTripAndStableFingerprint(): void {
    $root = $this->createTemporaryDirectory();
    $store = new ImportPlanStore($root);

    self::assertSame(
      $store->fingerprint(['b' => 2, 'a' => ['y' => 2, 'x' => 1]]),
      $store->fingerprint(['a' => ['x' => 1, 'y' => 2], 'b' => 2])
    );

    $plan = $store->create(['operation' => 'import', 'requested_types' => ['custom-data']]);
    $loaded = $store->load((string) $plan['plan_id']);

    self::assertSame($plan, $loaded);
    self::assertMatchesRegularExpression('/^[a-f0-9]{48}$/', (string) $plan['plan_id']);
  }

  public function testTamperedPlanFailsClosed(): void {
    $root = $this->createTemporaryDirectory();
    $store = new ImportPlanStore($root);
    $plan = $store->create(['operation' => 'import', 'requested_types' => ['custom-data']]);
    $path = $root . '/' . $plan['plan_id'] . '.json';
    $data = json_decode((string) file_get_contents($path), TRUE);
    $data['requested_types'] = ['settings'];
    file_put_contents($path, json_encode($data));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('integrity check');
    $store->load((string) $plan['plan_id']);
  }

  public function testExpiredPlanFailsClosedAndIsRemoved(): void {
    $root = $this->createTemporaryDirectory();
    $store = new ImportPlanStore($root, 1);
    $plan = $store->create(['operation' => 'import']);
    sleep(2);

    try {
      $store->load((string) $plan['plan_id']);
      self::fail('Expired plan should have been rejected.');
    }
    catch (\RuntimeException $e) {
      self::assertStringContainsString('expired', $e->getMessage());
    }
    self::assertFileDoesNotExist($root . '/' . $plan['plan_id'] . '.json');
  }
}
