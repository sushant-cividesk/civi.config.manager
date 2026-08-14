<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Service\DiffStateClassifier;
use PHPUnit\Framework\TestCase;

final class DiffStateClassifierTest extends TestCase {
  public function testTwoWayStatesWithoutBaseline(): void {
    $classifier = new DiffStateClassifier();

    self::assertSame('IN_SYNC', $classifier->classify(NULL, 'A', 'A'));
    self::assertSame('DIFFERENT', $classifier->classify(NULL, 'A', 'B'));
    self::assertSame('ONLY_IN_CIVICRM', $classifier->classify(NULL, NULL, 'A'));
    self::assertSame('ONLY_IN_YAML', $classifier->classify(NULL, 'A', NULL));
  }

  public function testBaselineAwareStates(): void {
    $classifier = new DiffStateClassifier();

    self::assertSame('IN_SYNC', $classifier->classify('A', 'A', 'A'));
    self::assertSame('SYNCED_CHANGE', $classifier->classify('A', 'B', 'B'));
    self::assertSame('ACTIVE_DRIFT', $classifier->classify('A', 'A', 'B'));
    self::assertSame('YAML_CHANGE', $classifier->classify('A', 'B', 'A'));
    self::assertSame('BOTH_CHANGED', $classifier->classify('A', 'B', 'C'));
  }

  public function testBaselineAttributesOneSidedDeletion(): void {
    $classifier = new DiffStateClassifier();

    self::assertSame('YAML_CHANGE', $classifier->classify('A', NULL, 'A'));
    self::assertSame('ACTIVE_DRIFT', $classifier->classify('A', 'A', NULL));
    self::assertSame('BOTH_CHANGED', $classifier->classify('A', NULL, 'B'));
    self::assertSame('BOTH_CHANGED', $classifier->classify('A', 'B', NULL));
  }

  public function testThreeWayAnalysisSeparatesNonConflictingChanges(): void {
    $classifier = new DiffStateClassifier();
    $result = $classifier->analyzeThreeWay(
      ['label' => 'Old', 'color' => 'blue'],
      ['label' => 'New', 'color' => 'blue'],
      ['label' => 'Old', 'color' => 'red']
    );

    self::assertSame('NON_CONFLICTING_DIVERGENCE', $result['status']);
    self::assertSame(['label'], $result['yaml_changed_paths']);
    self::assertSame(['color'], $result['active_changed_paths']);
    self::assertSame([], $result['conflicts']);
  }

  public function testThreeWayAnalysisReportsSameFieldConflict(): void {
    $classifier = new DiffStateClassifier();
    $result = $classifier->analyzeThreeWay(
      ['label' => 'Old'],
      ['label' => 'Development'],
      ['label' => 'Production']
    );

    self::assertSame('CONFLICT', $result['status']);
    self::assertSame('label', $result['conflicts'][0]['path']);
    self::assertSame('Old', $result['conflicts'][0]['baseline']);
    self::assertSame('Development', $result['conflicts'][0]['yaml']);
    self::assertSame('Production', $result['conflicts'][0]['active']);
  }
}
