<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Service\ImportDependencyPlanner;
use PHPUnit\Framework\TestCase;

final class ImportDependencyPlannerTest extends TestCase {

  public function testGroupsMissingDependenciesIntoOneConnectedComponent(): void {
    $planner = new ImportDependencyPlanner();
    $analysis = $planner->analyze(
      [
        'custom-data' => [
          'groups/example.yml' => [
            'dependencies' => [
              ['type' => 'option-groups', 'name' => 'missing_choices', 'reason' => 'Custom field choices.'],
              ['type' => 'contact-types', 'name' => 'Individual', 'reason' => 'Entity scope.'],
            ],
          ],
        ],
      ],
      ['contact-types' => ['Individual' => TRUE]],
      [
        'custom-data' => 'Custom Data',
        'option-groups' => 'Option Groups',
        'contact-types' => 'Contact Types',
      ],
      [
        'custom-data' => 'Custom Data',
        'option-groups' => 'Option Groups',
        'contact-types' => 'Contact Types',
      ],
      static fn(string $type, string $name): bool => FALSE,
      static fn(string $type, string $name): string => ''
    );

    self::assertCount(2, $analysis['dependency_edges']);
    self::assertCount(1, $analysis['dependency_blockers']);
    self::assertCount(1, $analysis['dependency_components']);
    self::assertSame(['contact-types', 'custom-data', 'option-groups'], $analysis['dependency_components'][0]['types']);
    self::assertSame(['custom-data/groups/example.yml'], $analysis['dependency_components'][0]['files']);
  }

  public function testSafeExclusionExpandsCanonicalRelatedTypeClosure(): void {
    $planner = new ImportDependencyPlanner();
    $components = [[
      'id' => str_repeat('a', 24),
      'types' => ['custom-data', 'option-groups'],
      'type_labels' => ['Custom Data', 'Option Groups'],
      'files' => ['custom-data/groups/example.yml'],
      'blockers' => [],
      'blocker_count' => 1,
    ]];

    $safe = $planner->addExclusionSafety(
      $components,
      ['custom-data', 'relationship-types', 'financial-types'],
      ['custom-data', 'option-groups', 'contact-types', 'site-tokens', 'extensions', 'message-templates', 'relationship-types', 'financial-types']
    );

    self::assertTrue($safe[0]['can_exclude']);
    self::assertContains('custom-data', $safe[0]['excluded_types']);
    self::assertContains('option-groups', $safe[0]['excluded_types']);
    self::assertContains('contact-types', $safe[0]['excluded_types']);
    self::assertContains('site-tokens', $safe[0]['excluded_types']);
    self::assertContains('extensions', $safe[0]['excluded_types']);
    self::assertContains('message-templates', $safe[0]['excluded_types']);
    self::assertSame(['financial-types'], $safe[0]['remaining_requested_types']);
  }

  public function testExclusionFailsClosedWhenItWouldRemoveWholeImport(): void {
    $planner = new ImportDependencyPlanner();
    $components = [[
      'id' => str_repeat('b', 24),
      'types' => ['custom-data'],
      'type_labels' => ['Custom Data'],
      'files' => ['custom-data/groups/example.yml'],
      'blockers' => [],
      'blocker_count' => 1,
    ]];

    $safe = $planner->addExclusionSafety(
      $components,
      ['custom-data'],
      ['custom-data', 'option-groups', 'contact-types', 'site-tokens', 'extensions', 'message-templates']
    );

    self::assertFalse($safe[0]['can_exclude']);
    self::assertSame([], $safe[0]['remaining_requested_types']);
    self::assertStringContainsString('leave nothing to import', $safe[0]['exclusion_reason']);
  }

  public function testBlockedComponentExplainsConcreteDryRunActions(): void {
    $planner = new ImportDependencyPlanner();
    $components = [[
      'id' => str_repeat('d', 24),
      'types' => ['custom-data', 'option-groups'],
      'type_labels' => ['Custom Data', 'Option Groups'],
      'files' => ['custom-data/groups/example.yml'],
      'blockers' => [],
      'blocker_count' => 1,
    ]];

    $withActions = $planner->addActionSummaries($components, [
      ['type' => 'custom-data', 'groups' => ['create' => 1, 'update' => 2], 'delete' => 0],
      ['type' => 'option-groups', 'create' => 1, 'update' => 0, 'delete' => 1],
    ]);

    self::assertSame(2, $withActions[0]['actions']['create']);
    self::assertSame(2, $withActions[0]['actions']['update']);
    self::assertSame(1, $withActions[0]['actions']['delete']);
    self::assertSame('Create 2, Update 2, Remove 1', $withActions[0]['action_text']);
  }

  public function testComponentIdsAreStableForEquivalentInputOrderings(): void {
    $planner = new ImportDependencyPlanner();
    $dependencies = [
      ['type' => 'option-groups', 'name' => 'missing_choices'],
      ['type' => 'contact-types', 'name' => 'MissingSubtype'],
    ];
    $metadata = [
      'custom-data' => [
        'groups/example.yml' => ['dependencies' => $dependencies],
      ],
    ];
    $reordered = [
      'custom-data' => [
        'groups/example.yml' => ['dependencies' => array_reverse($dependencies)],
      ],
    ];
    $registered = [
      'custom-data' => 'Custom Data',
      'option-groups' => 'Option Groups',
      'contact-types' => 'Contact Types',
    ];
    $active = static fn(string $type, string $name): bool => FALSE;
    $ignored = static fn(string $type, string $name): string => '';

    $first = $planner->analyze($metadata, [], $registered, $registered, $active, $ignored);
    $second = $planner->analyze($reordered, [], $registered, $registered, $active, $ignored);
    self::assertSame($first['dependency_components'][0]['id'], $second['dependency_components'][0]['id']);
    self::assertSame($first['dependency_components'][0]['blockers'], $second['dependency_components'][0]['blockers']);
    self::assertMatchesRegularExpression('/^[a-f0-9]{24}$/', $first['dependency_components'][0]['id']);
  }
}
