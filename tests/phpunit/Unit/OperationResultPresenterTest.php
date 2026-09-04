<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\UI\OperationResultPresenter;
use PHPUnit\Framework\TestCase;

final class OperationResultPresenterTest extends TestCase {

  /** Requirement: the persistent export summary stays compact and reports durable created/updated counts. */
  public function testExportSummaryUsesDurableCounts(): void {
    $presenter = new OperationResultPresenter();
    $summary = $presenter->exportSummary([
      'ok' => TRUE,
      'created_count' => 2,
      'updated_count' => 5,
      'written' => ['a.yml', 'b.yml', 'c.yml', 'd.yml', 'e.yml', 'f.yml', 'g.yml'],
      'skipped' => ['same.yml'],
      'deleted' => ['old.yml'],
      'monitor_only' => 3,
      'warnings' => [['message' => 'warning']],
      'errors' => [],
      'processed_items' => 44,
    ], [
      'saved_config_count' => 1107,
    ], [
      'status' => 'complete',
      'finished_at' => '2026-09-04 15:00:00',
    ]);

    self::assertTrue($summary['ok']);
    self::assertSame(2, $summary['created']);
    self::assertSame(5, $summary['updated']);
    self::assertSame(1, $summary['unchanged']);
    self::assertSame(1, $summary['removed']);
    self::assertSame(3, $summary['monitor_only']);
    self::assertSame(1107, $summary['saved_config_count']);
    self::assertSame('2026-09-04 15:00:00', $summary['completed_at']);
    $message = $presenter->exportMessage($summary);
    self::assertStringContainsString('2 Saved Config file(s) created, 5 updated', $message);
    self::assertStringNotContainsString('7 Saved Config file(s) updated', $message);
  }

  /** Requirement: the DEV first-export toast must agree with Last Export created/updated accounting. */
  public function testExportMessageDoesNotCollapseCreatedFilesIntoUpdated(): void {
    $presenter = new OperationResultPresenter();
    $summary = $presenter->exportSummary([
      'ok' => TRUE,
      'created_count' => 1108,
      'updated_count' => 0,
      'written' => array_fill(0, 1108, 'written.yml'),
      'skipped' => ['manifest.yml'],
      'monitor_only' => 2,
    ], ['saved_config_count' => 1108]);

    $message = $presenter->exportMessage($summary);

    self::assertSame(1108, $summary['created']);
    self::assertSame(0, $summary['updated']);
    self::assertStringContainsString('1108 Saved Config file(s) created, 0 updated', $message);
    self::assertStringNotContainsString('1108 Saved Config file(s) updated', $message);
  }

  /** Requirement: a failed queued export must never render as a successful Last Export. */
  public function testFailedExportJobIsShownAsError(): void {
    $summary = (new OperationResultPresenter())->exportSummary([], ['saved_config_count' => 20], [
      'status' => 'failed',
      'error' => 'Provider scan failed safely.',
    ]);

    self::assertFalse($summary['ok']);
    self::assertSame(1, $summary['errors']);
    self::assertSame('Provider scan failed safely.', $summary['problem']);
  }

  /** Requirement: Last Import must summarize nested handler activity without retaining record/path payloads. */
  public function testImportSummaryCountsNestedActivity(): void {
    $summary = (new OperationResultPresenter())->importSummary([
      'ok' => TRUE,
      'items' => [[
        'create' => 1,
        'update' => 2,
        'delete' => 1,
        'skip' => 3,
        'values' => ['create' => 2, 'update' => 1, 'delete' => 1, 'skip' => 4],
        'settings' => ['update' => 2, 'skip' => 1],
        'warnings' => [['message' => 'review']],
        'errors' => [],
      ]],
    ], [
      'status' => 'complete',
      'finished_at' => '2026-09-04 15:30:00',
    ]);

    self::assertTrue($summary['ok']);
    self::assertSame(3, $summary['created']);
    self::assertSame(5, $summary['updated']);
    self::assertSame(2, $summary['removed']);
    self::assertSame(8, $summary['unchanged']);
    self::assertSame(1, $summary['warnings']);
    self::assertSame(0, $summary['errors']);
    self::assertArrayNotHasKey('items', $summary);
    self::assertSame('2026-09-04 15:30:00', $summary['completed_at']);
  }

  /** Requirement: a failed import must show the first actionable problem rather than a green summary. */
  public function testFailedImportShowsProblem(): void {
    $summary = (new OperationResultPresenter())->importSummary([
      'ok' => FALSE,
      'items' => [[
        'errors' => [['message' => 'Tag is still assigned to contacts and cannot be removed safely.']],
      ]],
    ]);

    self::assertFalse($summary['ok']);
    self::assertSame(1, $summary['errors']);
    self::assertSame('Tag is still assigned to contacts and cannot be removed safely.', $summary['problem']);
  }
}
