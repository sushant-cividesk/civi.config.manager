<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Handler\AbstractHandler;
use Civi\ConfigManager\Service\ConfigManager;
use Civi\ConfigManager\Service\HandlerRegistry;
use Civi\ConfigManager\Service\ImportPlanStore;
use Civi\ConfigManager\Tests\Support\TemporaryDirectoryTrait;
use Civi\ConfigManager\Util\SimpleYaml;
use PHPUnit\Framework\TestCase;

final class ConfigManagerImportPlanTest extends TestCase {
  use TemporaryDirectoryTrait;

  protected function setUp(): void {
    parent::setUp();
    \Civi::settings()->reset();
    $GLOBALS['civicrm_setting'] = [];
  }

  protected function tearDown(): void {
    \Civi::settings()->reset();
    $GLOBALS['civicrm_setting'] = [];
    $this->removeTemporaryDirectories();
    parent::tearDown();
  }

  public function testUnchangedReviewedPlanRemainsValidAcrossReload(): void {
    [$manager, $handler] = $this->fixture();
    $preview = $manager->createImportReviewPlan(['review-plan-test']);

    self::assertTrue($preview['ok']);
    self::assertTrue($preview['review_plan_ready']);
    self::assertMatchesRegularExpression('/^[a-f0-9]{48}$/', (string) $preview['plan_id']);

    $loaded = $manager->validateImportReviewPlan((string) $preview['plan_id'], ['review-plan-test'], TRUE);
    self::assertSame(['review-plan-test'], $loaded['requested_types']);
    self::assertSame(0, $handler->realApplyCalls);
  }

  public function testDirectWriteCannotBypassReviewedPlan(): void {
    [$manager, $handler] = $this->fixture();
    try {
      $manager->import(FALSE, TRUE, ['review-plan-test']);
      self::fail('Direct write mode must not bypass reviewed Import plans.');
    }
    catch (\RuntimeException $e) {
      self::assertStringContainsString('immutable reviewed Import plan', $e->getMessage());
    }
    self::assertSame(0, $handler->realApplyCalls);
  }

  public function testReviewedPlanCanResumeWithoutChangingItsIdentity(): void {
    [$manager, $handler] = $this->fixture();
    $preview = $manager->createImportReviewPlan(['review-plan-test']);

    $resumed = $manager->resumeImportReviewPlan((string) $preview['plan_id'], ['review-plan-test']);

    self::assertSame($preview['plan_id'], $resumed['plan_id']);
    self::assertSame($preview['plan_fingerprint'], $resumed['plan_fingerprint']);
    self::assertTrue($resumed['review_plan_ready']);
    self::assertSame(0, $handler->realApplyCalls);
  }

  public function testAppliedReviewedPlanIsSingleUse(): void {
    [$manager, $handler] = $this->fixture();
    $preview = $manager->createImportReviewPlan(['review-plan-test']);

    $result = $manager->applyImportReviewPlan((string) $preview['plan_id']);
    self::assertTrue($result['ok']);
    self::assertGreaterThan(0, $handler->realApplyCalls);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('no longer available');
    $manager->validateImportReviewPlan((string) $preview['plan_id']);
  }

  public function testSavedConfigChangeMakesReviewedPlanStaleBeforeWrite(): void {
    [$manager, $handler, $root] = $this->fixture();
    $preview = $manager->createImportReviewPlan(['review-plan-test']);
    $this->writeYaml($root . '/review-plan-test/item.yml', $this->document('item', 'changed Saved Config'));

    try {
      $manager->applyImportReviewPlan((string) $preview['plan_id']);
      self::fail('Changed Saved Config should make the reviewed plan stale.');
    }
    catch (\RuntimeException $e) {
      self::assertStringContainsString('Saved Config changed after preview', $e->getMessage());
    }
    self::assertSame(0, $handler->realApplyCalls);
  }

  public function testCurrentCiviChangeMakesReviewedPlanStaleBeforeWrite(): void {
    [$manager, $handler] = $this->fixture();
    $preview = $manager->createImportReviewPlan(['review-plan-test']);
    $handler->activeLabel = 'changed Current CiviCRM';

    try {
      $manager->applyImportReviewPlan((string) $preview['plan_id']);
      self::fail('Changed Current CiviCRM should make the reviewed plan stale.');
    }
    catch (\RuntimeException $e) {
      self::assertStringContainsString('Current CiviCRM changed after preview', $e->getMessage());
    }
    self::assertSame(0, $handler->realApplyCalls);
  }

  public function testScopeChangeMakesReviewedPlanStaleBeforeWrite(): void {
    [$manager, $handler] = $this->fixture();
    $preview = $manager->createImportReviewPlan(['review-plan-test']);
    \Civi::settings()->set('civicfg_ignore_paths', ['review-plan-test/other.yml']);

    try {
      $manager->applyImportReviewPlan((string) $preview['plan_id']);
      self::fail('Scope/ignore changes should make the reviewed plan stale.');
    }
    catch (\RuntimeException $e) {
      self::assertStringContainsString('Configuration Scope or Config Ignore changed', $e->getMessage());
    }
    self::assertSame(0, $handler->realApplyCalls);
  }

  public function testSubmittedTypeTamperingIsRejected(): void {
    [$manager] = $this->fixture();
    $preview = $manager->createImportReviewPlan(['review-plan-test']);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('type selection changed');
    $manager->validateImportReviewPlan((string) $preview['plan_id'], ['settings'], FALSE);
  }

  public function testQueuedImportPlanRequiresReviewedPlanAndCarriesOpaqueId(): void {
    [$manager] = $this->fixture();

    try {
      $manager->buildQueuedImportPlan(['review-plan-test']);
      self::fail('Queued import should require a reviewed plan.');
    }
    catch (\RuntimeException $e) {
      self::assertStringContainsString('reviewed Import plan', $e->getMessage());
    }

    $preview = $manager->createImportReviewPlan(['review-plan-test']);
    $queuePlan = $manager->buildQueuedImportPlan(['review-plan-test'], (string) $preview['plan_id']);
    self::assertSame($preview['plan_id'], $queuePlan[0]['review_plan_id']);
    self::assertSame('preflight', $queuePlan[0]['action']);
    foreach ($queuePlan as $task) {
      self::assertSame($preview['plan_id'], $task['review_plan_id']);
    }
  }

  /** @return array{0:ImportPlanFixtureManager,1:ImportPlanFixtureHandler,2:string} */
  private function fixture(): array {
    $root = $this->createTemporaryDirectory();
    $planRoot = $this->createTemporaryDirectory();
    \Civi::settings()->set('civicfg_sync_dir', $root);
    \Civi::settings()->set('civicfg_scope_default_mode', 'all');
    \Civi::settings()->set('civicfg_site_id', 'review-plan-site');
    $this->writeYaml($root . '/manifest.yml', [
      'schema_version' => 1,
      'site_id' => 'review-plan-site',
    ]);
    @mkdir($root . '/review-plan-test', 0775, TRUE);
    $this->writeYaml($root . '/review-plan-test/item.yml', $this->document('item', 'original'));

    $handler = new ImportPlanFixtureHandler();
    $manager = new ImportPlanFixtureManager(
      'review-plan-site',
      new ImportPlanFixtureRegistry([$handler]),
      new ImportPlanStore($planRoot)
    );
    return [$manager, $handler, $root];
  }

  private function document(string $name, string $label): array {
    return [
      'schema_version' => 1,
      'type' => 'review-plan.item',
      'name' => $name,
      'item' => ['name' => $name, 'label' => $label],
    ];
  }

  private function writeYaml(string $path, array $data): void {
    file_put_contents($path, SimpleYaml::dump($data));
  }
}

final class ImportPlanFixtureManager extends ConfigManager {
  private string $siteId;

  public function __construct(string $siteId, HandlerRegistry $registry, ImportPlanStore $store) {
    parent::__construct($registry, NULL, $store);
    $this->siteId = $siteId;
  }

  public function getSiteIdentifier(): string {
    return $this->siteId;
  }
}

final class ImportPlanFixtureRegistry extends HandlerRegistry {
  private array $handlers;

  public function __construct(array $handlers) {
    $this->handlers = $handlers;
  }

  public function getHandlers(): array {
    return $this->handlers;
  }
}

final class ImportPlanFixtureHandler extends AbstractHandler {
  public string $activeLabel = 'original';
  public int $realApplyCalls = 0;
  private bool $writeEnabled = TRUE;
  private bool $deleteEnabled = TRUE;

  public function getType(): string { return 'review-plan-test'; }
  public function getLabel(): string { return 'Review Plan Test'; }
  public function getDirectory(): string { return 'review-plan-test'; }
  public function getWeight(): int { return 10; }

  public function export(): array {
    return [[
      'filename' => 'item.yml',
      'data' => [
        'schema_version' => 1,
        'type' => 'review-plan.item',
        'name' => 'item',
        'item' => ['name' => 'item', 'label' => $this->activeLabel],
      ],
    ]];
  }

  public function validate(array $items): array {
    return [
      'type' => $this->getType(),
      'valid' => TRUE,
      'warnings' => [],
      'errors' => [],
      'count' => count($items),
    ];
  }

  public function setImportWriteEnabled(bool $enabled): self {
    $this->writeEnabled = $enabled;
    return $this;
  }

  public function setDeleteMissingEnabled(bool $enabled): self {
    $this->deleteEnabled = $enabled;
    return $this;
  }

  public function import(array $items, bool $dryRun = TRUE): array {
    if (!$dryRun && $this->writeEnabled) {
      $this->realApplyCalls++;
    }
    return [
      'type' => $this->getType(),
      'status' => $dryRun ? 'dry_run' : 'applied',
      'dry_run' => $dryRun,
      'create' => 0,
      'update' => 0,
      'delete' => 0,
      'skip' => count($items),
      'warnings' => [],
      'errors' => [],
      'ok' => TRUE,
    ];
  }
}
