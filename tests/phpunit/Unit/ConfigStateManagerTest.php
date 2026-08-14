<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Handler\AbstractHandler;
use Civi\ConfigManager\Service\Canonicalizer;
use Civi\ConfigManager\Service\ConfigStateManager;
use Civi\ConfigManager\Service\StateStore;
use PHPUnit\Framework\TestCase;

final class ConfigStateManagerTest extends TestCase {
  public function testDiffStateUsesAcceptedBaselineToDetectActiveDrift(): void {
    $store = new MemoryStateStore();
    $manager = new ConfigStateManager($store);
    $handler = new ConfigStateTestHandler();

    $baseline = [
      'type' => 'example.item',
      'item' => ['name' => 'alpha', 'label' => 'Baseline'],
    ];
    $identity = (new \Civi\ConfigManager\Service\ConfigIdentity())->identify('example', $baseline, 'alpha.yml');
    $canonicalizer = new Canonicalizer();
    $store->acceptBaseline($identity, $canonicalizer->canonicalize($baseline), $canonicalizer->hash($baseline), 'export');

    $yaml = ['alpha.yml' => $baseline];
    $active = [[
      'filename' => 'alpha.yml',
      'data' => [
        'type' => 'example.item',
        'item' => ['name' => 'alpha', 'label' => 'Changed in active CiviCRM'],
      ],
    ]];
    $diff = $handler->diffFromExports($active, $yaml);
    $enriched = $manager->enrichDiff($handler, $active, $yaml, $diff);

    self::assertSame(['ACTIVE_DRIFT' => 1], $enriched['state_summary']);
    self::assertSame('ACTIVE_DRIFT', $enriched['files'][0]['sync_state']);
    self::assertSame(1, count($store->objectStates));
  }

  public function testConfirmedRenameKeepsBaselineContinuityWithoutAutoMatching(): void {
    $store = new MemoryStateStore();
    $manager = new ConfigStateManager($store);
    $handler = new ConfigStateTestHandler();
    $identityService = new \Civi\ConfigManager\Service\ConfigIdentity();
    $canonicalizer = new Canonicalizer();

    $old = [
      'type' => 'example.item',
      'item' => ['name' => 'old_name', 'label' => 'Same label'],
    ];
    $new = [
      'type' => 'example.item',
      'item' => ['name' => 'new_name', 'label' => 'Same label'],
    ];
    $oldIdentity = $identityService->identify('example', $old, 'old-name.yml');
    $newIdentity = $identityService->identify('example', $new, 'new-name.yml');
    $store->acceptBaseline($oldIdentity, $canonicalizer->canonicalize($old), $canonicalizer->hash($old), 'export');
    $store->aliases[(string) $newIdentity['provider_key'] . '|' . (string) $newIdentity['identity_hash']] = (string) $oldIdentity['identity_hash'];

    $yaml = ['new-name.yml' => $new];
    $active = [[
      'filename' => 'new-name.yml',
      'data' => $new,
    ]];
    $diff = $handler->diffFromExports($active, $yaml);
    $enriched = $manager->enrichDiff($handler, $active, $yaml, $diff);

    self::assertSame(['SYNCED_CHANGE' => 1], $enriched['state_summary']);
  }

  public function testConfirmedRenameChainKeepsOriginalBaselineContinuity(): void {
    $store = new MemoryStateStore();
    $manager = new ConfigStateManager($store);
    $handler = new ConfigStateTestHandler();
    $identityService = new \Civi\ConfigManager\Service\ConfigIdentity();
    $canonicalizer = new Canonicalizer();

    $first = [
      'type' => 'example.item',
      'item' => ['name' => 'first_name', 'label' => 'Same label'],
    ];
    $second = [
      'type' => 'example.item',
      'item' => ['name' => 'second_name', 'label' => 'Same label'],
    ];
    $third = [
      'type' => 'example.item',
      'item' => ['name' => 'third_name', 'label' => 'Same label'],
    ];
    $firstIdentity = $identityService->identify('example', $first, 'first.yml');
    $secondIdentity = $identityService->identify('example', $second, 'second.yml');
    $thirdIdentity = $identityService->identify('example', $third, 'third.yml');
    $store->acceptBaseline($firstIdentity, $canonicalizer->canonicalize($first), $canonicalizer->hash($first), 'export');
    $store->aliases[(string) $secondIdentity['provider_key'] . '|' . (string) $secondIdentity['identity_hash']] = (string) $firstIdentity['identity_hash'];
    $store->aliases[(string) $thirdIdentity['provider_key'] . '|' . (string) $thirdIdentity['identity_hash']] = (string) $secondIdentity['identity_hash'];

    $yaml = ['third.yml' => $third];
    $active = [['filename' => 'third.yml', 'data' => $third]];
    $diff = $handler->diffFromExports($active, $yaml);
    $enriched = $manager->enrichDiff($handler, $active, $yaml, $diff);

    self::assertSame(['SYNCED_CHANGE' => 1], $enriched['state_summary']);
  }

  public function testDuplicateSemanticIdentitiesRemainAmbiguousInStateTracking(): void {
    $store = new MemoryStateStore();
    $manager = new ConfigStateManager($store);
    $handler = new ConfigStateTestHandler();

    $first = [
      'type' => 'example.item',
      'item' => ['name' => 'duplicate', 'label' => 'First'],
    ];
    $second = [
      'type' => 'example.item',
      'item' => ['name' => 'duplicate', 'label' => 'Second'],
    ];
    $yaml = [
      'first.yml' => $first,
      'second.yml' => $second,
    ];
    $active = [
      ['filename' => 'first.yml', 'data' => $first],
      ['filename' => 'second.yml', 'data' => $second],
    ];

    $diff = $handler->diffFromExports($active, $yaml);
    $enriched = $manager->enrichDiff($handler, $active, $yaml, $diff);

    self::assertSame(['IN_SYNC' => 2], $enriched['state_summary']);
    self::assertCount(2, $store->objectStates);
    foreach ($store->objectStates as $state) {
      self::assertSame('AMBIGUOUS', $state['identity']['identity_confidence']);
      self::assertFalse($state['identity']['write_safe']);
      self::assertStringContainsString('|duplicate=', $state['identity']['config_key']);
    }
  }

  public function testBaselineAttributesYamlDeletionAndDetectsDeleteVersusEditConflict(): void {
    $store = new MemoryStateStore();
    $manager = new ConfigStateManager($store);
    $handler = new ConfigStateTestHandler();
    $canonicalizer = new Canonicalizer();

    $baseline = [
      'type' => 'example.item',
      'item' => ['name' => 'alpha', 'label' => 'Baseline'],
    ];
    $identity = (new \Civi\ConfigManager\Service\ConfigIdentity())->identify('example', $baseline, 'alpha.yml');
    $store->acceptBaseline($identity, $canonicalizer->canonicalize($baseline), $canonicalizer->hash($baseline), 'export');

    $baselineActive = [[
      'filename' => 'alpha.yml',
      'data' => $baseline,
    ]];
    $deletionDiff = $handler->diffFromExports($baselineActive, []);
    $deletion = $manager->enrichDiff($handler, $baselineActive, [], $deletionDiff);
    self::assertSame('YAML_CHANGE', $deletion['files'][0]['sync_state']);

    $changedActive = [[
      'filename' => 'alpha.yml',
      'data' => [
        'type' => 'example.item',
        'item' => ['name' => 'alpha', 'label' => 'Changed active'],
      ],
    ]];
    $conflictDiff = $handler->diffFromExports($changedActive, []);
    $conflict = $manager->enrichDiff($handler, $changedActive, [], $conflictDiff);
    self::assertSame('BOTH_CHANGED', $conflict['files'][0]['sync_state']);
    self::assertSame('CONFLICT', $conflict['files'][0]['merge_state']);
    self::assertSame('__object__', $conflict['files'][0]['conflicts'][0]['path']);
  }

  public function testThreeWayConflictIsReportedForSameFieldChangedDifferently(): void {
    $store = new MemoryStateStore();
    $manager = new ConfigStateManager($store);
    $handler = new ConfigStateTestHandler();

    $baseline = [
      'type' => 'example.item',
      'item' => ['name' => 'alpha', 'label' => 'Old'],
    ];
    $identity = (new \Civi\ConfigManager\Service\ConfigIdentity())->identify('example', $baseline, 'alpha.yml');
    $canonicalizer = new Canonicalizer();
    $store->acceptBaseline($identity, $canonicalizer->canonicalize($baseline), $canonicalizer->hash($baseline), 'export');

    $yaml = ['alpha.yml' => [
      'type' => 'example.item',
      'item' => ['name' => 'alpha', 'label' => 'Development'],
    ]];
    $active = [[
      'filename' => 'alpha.yml',
      'data' => [
        'type' => 'example.item',
        'item' => ['name' => 'alpha', 'label' => 'Production'],
      ],
    ]];

    $diff = $handler->diffFromExports($active, $yaml);
    $enriched = $manager->enrichDiff($handler, $active, $yaml, $diff);

    self::assertSame('BOTH_CHANGED', $enriched['files'][0]['sync_state']);
    self::assertSame('CONFLICT', $enriched['files'][0]['merge_state']);
    self::assertSame('item.label', $enriched['files'][0]['conflicts'][0]['path']);
  }
}

final class ConfigStateTestHandler extends AbstractHandler {
  public function getType(): string {
    return 'example';
  }

  public function getLabel(): string {
    return 'Example';
  }

  public function getDirectory(): string {
    return 'example';
  }

  public function getWeight(): int {
    return 1;
  }

  public function export(): array {
    return [];
  }
}

final class MemoryStateStore extends StateStore {
  /** @var array<string, array<string, mixed>> */
  public array $baselines = [];

  /** @var array<int, array<string, mixed>> */
  public array $objectStates = [];

  /** @var array<string, string> */
  public array $aliases = [];

  public function isAvailable(): bool {
    return TRUE;
  }

  public function getBaseline(string $providerKey, string $identityHash): ?array {
    return $this->baselines[$providerKey . '|' . $identityHash] ?? NULL;
  }

  public function acceptBaseline(array $identity, array $canonicalData, string $hash, string $source): void {
    $this->baselines[(string) $identity['provider_key'] . '|' . (string) $identity['identity_hash']] = [
      'provider_key' => (string) $identity['provider_key'],
      'config_key' => (string) $identity['config_key'],
      'identity_hash' => (string) $identity['identity_hash'],
      'baseline_hash' => $hash,
      'baseline_data' => $canonicalData,
      'canonical_version' => Canonicalizer::VERSION,
      'accepted_source' => $source,
    ];
  }

  public function previousIdentityHash(string $providerKey, string $newIdentityHash): ?string {
    return $this->aliases[$providerKey . '|' . $newIdentityHash] ?? NULL;
  }

  public function upsertObjectState(array $identity, ?string $yamlHash, ?string $activeHash, string $state): void {
    $this->objectStates[] = [
      'identity' => $identity,
      'yaml_hash' => $yamlHash,
      'active_hash' => $activeHash,
      'state' => $state,
    ];
  }
}
