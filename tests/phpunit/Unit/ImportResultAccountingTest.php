<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Service\ImportResultAccounting;
use PHPUnit\Framework\TestCase;

final class ImportResultAccountingTest extends TestCase {

  /** Requirement: every public Import surface reports the same compact outcome categories. */
  public function testAttachBuildsCanonicalOutcomeAccounting(): void {
    $accounting = new ImportResultAccounting();
    $result = $accounting->attach([
      'ok' => TRUE,
      'dry_run' => FALSE,
      'items' => [[
        'create' => 1,
        'update' => 2,
        'delete' => 1,
        'skip' => 3,
        'values' => ['create' => 2, 'update' => 1, 'delete' => 0, 'skip' => 4],
      ]],
    ], [[
      'types' => ['blocked-source'],
      'files' => ['blocked-source/a.yml', 'blocked-source/b.yml'],
    ]], [
      'ok' => TRUE,
      'items' => [[
        'changed' => ['one.yml'],
        'new_in_db' => ['two.yml'],
        'missing_in_db' => [],
      ]],
    ]);

    self::assertSame(7, $result['result_accounting']['applied']);
    self::assertSame(0, $result['result_accounting']['blocked']);
    self::assertSame(2, $result['result_accounting']['excluded']);
    self::assertSame(2, $result['result_accounting']['remaining_difference']);
    self::assertTrue($result['result_accounting']['remaining_difference_known']);
    self::assertSame(3, $result['result_accounting']['created']);
    self::assertSame(3, $result['result_accounting']['updated']);
    self::assertSame(1, $result['result_accounting']['removed']);
    self::assertSame(7, $result['result_accounting']['unchanged']);
  }

  /** Requirement: blocked previews must never be presented as applied work. */
  public function testBlockedDryRunReportsZeroAppliedAndSemanticBlockers(): void {
    $result = (new ImportResultAccounting())->attach([
      'ok' => FALSE,
      'dry_run' => TRUE,
      'dependency_components' => [
        ['id' => 'component-one'],
        ['id' => 'component-two'],
      ],
      'items' => [[
        'create' => 4,
        'update' => 2,
      ]],
    ]);

    self::assertSame(0, $result['result_accounting']['applied']);
    self::assertSame(2, $result['result_accounting']['blocked']);
    self::assertSame(6, $result['result_accounting']['remaining_difference']);
    self::assertFalse($result['result_accounting']['remaining_difference_known']);
  }

  /** Requirement: exclusion accounting uses unique Saved Config files rather than component count. */
  public function testExcludedFilesAreDeduplicated(): void {
    $service = new ImportResultAccounting();
    self::assertSame(3, $service->countExcluded([
      ['files' => ['a.yml', 'b.yml'], 'types' => ['a']],
      ['files' => ['b.yml', 'c.yml'], 'types' => ['b']],
    ]));
  }
}
