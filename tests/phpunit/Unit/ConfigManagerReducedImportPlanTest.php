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

final class ConfigManagerReducedImportPlanTest extends TestCase {
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

  public function testSafeComponentExclusionBuildsFreshPlanForRemainingScope(): void {
    [$manager, $blocked, $independent] = $this->fixture();

    $blockedPreview = $manager->createImportReviewPlan(['blocked-source', 'independent-test']);
    self::assertFalse($blockedPreview['ok']);
    self::assertSame('', $blockedPreview['plan_id']);
    self::assertCount(1, $blockedPreview['dependency_components']);
    self::assertTrue($blockedPreview['dependency_components'][0]['can_exclude']);
    self::assertContains('blocked-source', $blockedPreview['dependency_components'][0]['types']);
    self::assertContains('dependency-target', $blockedPreview['dependency_components'][0]['types']);

    $componentId = (string) $blockedPreview['dependency_components'][0]['id'];
    $reduced = $manager->createReducedImportReviewPlan($componentId, ['blocked-source', 'independent-test']);

    self::assertTrue($reduced['ok']);
    self::assertTrue($reduced['review_plan_ready']);
    self::assertTrue($reduced['reduced_plan']);
    self::assertMatchesRegularExpression('/^[a-f0-9]{48}$/', (string) $reduced['plan_id']);
    self::assertSame(['independent-test'], $reduced['remaining_requested_types']);
    self::assertContains('blocked-source', $reduced['excluded_component']['types']);

    $result = $manager->applyImportReviewPlan((string) $reduced['plan_id']);
    self::assertTrue($result['ok']);
    self::assertSame(0, $blocked->realApplyCalls);
    self::assertGreaterThan(0, $independent->realApplyCalls);
  }

  public function testStaleComponentIdCannotExcludeAfterBlockerWasFixed(): void {
    [$manager, , , $target] = $this->fixture();
    $blockedPreview = $manager->createImportReviewPlan(['blocked-source', 'independent-test']);
    $componentId = (string) $blockedPreview['dependency_components'][0]['id'];

    $target->setActive(TRUE);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('no longer present');
    $manager->createReducedImportReviewPlan($componentId, ['blocked-source', 'independent-test']);
  }

  /** @return array{0:ReducedPlanFixtureManager,1:ReducedPlanFixtureHandler,2:ReducedPlanFixtureHandler,3:ReducedPlanFixtureHandler,4:string} */
  private function fixture(): array {
    $root = $this->createTemporaryDirectory();
    $planRoot = $this->createTemporaryDirectory();
    \Civi::settings()->set('civicfg_sync_dir', $root);
    \Civi::settings()->set('civicfg_scope_default_mode', 'all');
    \Civi::settings()->set('civicfg_site_id', 'reduced-plan-site');

    $this->writeYaml($root . '/manifest.yml', [
      'schema_version' => 1,
      'site_id' => 'reduced-plan-site',
    ]);
    $this->writeYaml($root . '/blocked-source/source.yml', [
      'schema_version' => 1,
      'type' => 'blocked-source.item',
      'name' => 'source',
      'dependencies' => [[
        'type' => 'dependency-target',
        'name' => 'target',
        'reason' => 'The source requires this target.',
      ]],
      'item' => ['name' => 'source', 'label' => 'Source'],
    ]);
    $this->writeYaml($root . '/independent-test/item.yml', $this->document('independent-test.item', 'item'));
    @mkdir($root . '/dependency-target', 0775, TRUE);

    $blocked = new ReducedPlanFixtureHandler('blocked-source', 'Blocked Source', 'blocked-source', 'source');
    $target = new ReducedPlanFixtureHandler('dependency-target', 'Dependency Target', 'dependency-target', 'target', FALSE);
    $independent = new ReducedPlanFixtureHandler('independent-test', 'Independent Test', 'independent-test', 'item');
    $manager = new ReducedPlanFixtureManager(
      'reduced-plan-site',
      new ReducedPlanFixtureRegistry([$blocked, $target, $independent]),
      new ImportPlanStore($planRoot)
    );

    return [$manager, $blocked, $independent, $target, $root];
  }

  private function document(string $type, string $name): array {
    return [
      'schema_version' => 1,
      'type' => $type,
      'name' => $name,
      'item' => ['name' => $name, 'label' => ucfirst($name)],
    ];
  }

  private function writeYaml(string $path, array $data): void {
    @mkdir(dirname($path), 0775, TRUE);
    file_put_contents($path, SimpleYaml::dump($data));
  }
}

final class ReducedPlanFixtureManager extends ConfigManager {
  private string $siteId;

  public function __construct(string $siteId, HandlerRegistry $registry, ImportPlanStore $store) {
    parent::__construct($registry, NULL, $store);
    $this->siteId = $siteId;
  }

  public function getSiteIdentifier(): string {
    return $this->siteId;
  }
}

final class ReducedPlanFixtureRegistry extends HandlerRegistry {
  private array $handlers;

  public function __construct(array $handlers) {
    $this->handlers = $handlers;
  }

  public function getHandlers(): array {
    return $this->handlers;
  }
}

final class ReducedPlanFixtureHandler extends AbstractHandler {
  private string $type;
  private string $label;
  private string $directory;
  private string $name;
  private bool $active;
  private bool $writeEnabled = TRUE;
  public int $realApplyCalls = 0;

  public function __construct(string $type, string $label, string $directory, string $name, bool $active = TRUE) {
    $this->type = $type;
    $this->label = $label;
    $this->directory = $directory;
    $this->name = $name;
    $this->active = $active;
  }

  public function getType(): string { return $this->type; }
  public function getLabel(): string { return $this->label; }
  public function getDirectory(): string { return $this->directory; }
  public function getWeight(): int { return 10; }

  public function export(): array {
    if (!$this->active) {
      return [];
    }
    return [[
      'filename' => $this->name . '.yml',
      'data' => [
        'schema_version' => 1,
        'type' => $this->type . '.item',
        'name' => $this->name,
        'item' => ['name' => $this->name, 'label' => ucfirst($this->name)],
      ],
    ]];
  }

  public function validate(array $items): array {
    return [
      'type' => $this->type,
      'valid' => TRUE,
      'warnings' => [],
      'errors' => [],
      'count' => count($items),
    ];
  }

  public function setActive(bool $active): self {
    $this->active = $active;
    return $this;
  }

  public function setImportWriteEnabled(bool $enabled): self {
    $this->writeEnabled = $enabled;
    return $this;
  }

  public function import(array $items, bool $dryRun = TRUE): array {
    if (!$dryRun && $this->writeEnabled) {
      $this->realApplyCalls++;
    }
    return [
      'type' => $this->type,
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
