<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Handler\AbstractHandler;
use Civi\ConfigManager\Service\ConfigManager;
use Civi\ConfigManager\Service\HandlerRegistry;
use Civi\ConfigManager\Tests\Support\TemporaryDirectoryTrait;
use Civi\ConfigManager\Util\SimpleYaml;
use PHPUnit\Framework\TestCase;

final class ConfigManagerImportPreflightTest extends TestCase {
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

  public function testForeignSiteDoesNotHideHandlerBlockersAndNoWritesOccur(): void {
    $root = $this->createTemporaryDirectory();
    \Civi::settings()->set('civicfg_sync_dir', $root);
    $this->writeYaml($root . '/manifest.yml', [
      'schema_version' => 1,
      'site_id' => 'foreign-site',
    ]);
    @mkdir($root . '/preflight-test', 0775, TRUE);
    $this->writeYaml($root . '/preflight-test/item.yml', [
      'schema_version' => 1,
      'type' => 'preflight.item',
      'name' => 'item',
      'item' => ['name' => 'item'],
    ]);

    $handler = new ImportPreflightFixtureHandler(TRUE);
    $manager = new ImportPreflightFixtureManager('local-site', new ImportPreflightFixtureRegistry([$handler]));

    $preview = $manager->import(TRUE, FALSE);

    self::assertFalse($preview['ok']);
    self::assertFalse($preview['applied']);
    self::assertStringContainsString('does not match this site_id', (string) json_encode($preview['validation']['errors']));
    self::assertStringContainsString('fixture handler blocker', (string) json_encode($preview['items']));
    self::assertSame(0, $handler->realApplyCalls);

    $apply = $manager->import(FALSE, TRUE);
    self::assertFalse($apply['ok']);
    self::assertFalse($apply['applied']);
    self::assertSame(0, $handler->realApplyCalls);
    self::assertStringContainsString('complete preflight', (string) ($apply['message'] ?? ''));
  }

  public function testCreateUpdateFailureSkipsEntireDeleteMissingPhase(): void {
    $root = $this->createTemporaryDirectory();
    \Civi::settings()->set('civicfg_sync_dir', $root);
    $this->writeYaml($root . '/manifest.yml', [
      'schema_version' => 1,
      'site_id' => 'local-site',
    ]);
    @mkdir($root . '/preflight-test', 0775, TRUE);
    $this->writeYaml($root . '/preflight-test/item.yml', [
      'schema_version' => 1,
      'type' => 'preflight.item',
      'name' => 'item',
      'item' => ['name' => 'item'],
    ]);

    $handler = new ImportPreflightFixtureHandler(FALSE, TRUE);
    $manager = new ImportPreflightFixtureManager('local-site', new ImportPreflightFixtureRegistry([$handler]));

    $result = $manager->import(FALSE, TRUE);

    self::assertFalse($result['ok']);
    self::assertTrue($result['partial_apply']);
    self::assertTrue($result['delete_phase_skipped']);
    self::assertSame(1, $handler->realApplyCalls);
    self::assertSame(0, $handler->deletePhaseCalls);
    self::assertStringContainsString('Delete-missing was not started', (string) $result['message']);
  }

  private function writeYaml(string $path, array $data): void {
    file_put_contents($path, SimpleYaml::dump($data));
  }
}

final class ImportPreflightFixtureManager extends ConfigManager {
  private string $siteId;

  public function __construct(string $siteId, HandlerRegistry $registry) {
    parent::__construct($registry);
    $this->siteId = $siteId;
  }

  public function getSiteIdentifier(): string {
    return $this->siteId;
  }
}

final class ImportPreflightFixtureRegistry extends HandlerRegistry {
  private array $handlers;

  public function __construct(array $handlers) {
    $this->handlers = $handlers;
  }

  public function getHandlers(): array {
    return $this->handlers;
  }
}

final class ImportPreflightFixtureHandler extends AbstractHandler {
  private bool $preflightBlocker;
  private bool $failCreateUpdate;
  private bool $importWritesEnabled = TRUE;
  private bool $deleteMissingEnabled = TRUE;
  public int $realApplyCalls = 0;
  public int $deletePhaseCalls = 0;

  public function __construct(bool $preflightBlocker, bool $failCreateUpdate = FALSE) {
    $this->preflightBlocker = $preflightBlocker;
    $this->failCreateUpdate = $failCreateUpdate;
  }

  public function getType(): string { return 'preflight-test'; }
  public function getLabel(): string { return 'Preflight Test'; }
  public function getDirectory(): string { return 'preflight-test'; }
  public function getWeight(): int { return 10; }

  public function export(): array {
    return [[
      'filename' => 'item.yml',
      'data' => [
        'schema_version' => 1,
        'type' => 'preflight.item',
        'name' => 'item',
        'item' => ['name' => 'item'],
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
    $this->importWritesEnabled = $enabled;
    return $this;
  }

  public function setDeleteMissingEnabled(bool $enabled): self {
    $this->deleteMissingEnabled = $enabled;
    return $this;
  }

  public function import(array $items, bool $dryRun = TRUE): array {
    if ($dryRun) {
      return [
        'type' => $this->getType(),
        'status' => 'dry_run',
        'dry_run' => TRUE,
        'create' => 0,
        'update' => 0,
        'delete' => 0,
        'skip' => count($items),
        'warnings' => [],
        'errors' => $this->preflightBlocker ? [['message' => 'fixture handler blocker']] : [],
        'ok' => !$this->preflightBlocker,
      ];
    }

    if ($this->importWritesEnabled) {
      $this->realApplyCalls++;
      if ($this->failCreateUpdate) {
        return [
          'type' => $this->getType(),
          'status' => 'applied',
          'dry_run' => FALSE,
          'create' => 0,
          'update' => 0,
          'delete' => 0,
          'skip' => 0,
          'warnings' => [],
          'errors' => [['message' => 'fixture create/update failure']],
          'ok' => FALSE,
        ];
      }
    }
    elseif ($this->deleteMissingEnabled) {
      $this->deletePhaseCalls++;
    }

    return [
      'type' => $this->getType(),
      'status' => 'applied',
      'dry_run' => FALSE,
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
