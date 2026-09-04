<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Service\SavedConfigInventory;
use PHPUnit\Framework\TestCase;

final class SavedConfigInventoryTest extends TestCase {

  /** Requirement: Saved Config totals count every YAML path, while per-type counts use the most specific managed directory. */
  public function testCountsTotalAndPerTypeWithoutDoubleCountingNestedDirectories(): void {
    $result = (new SavedConfigInventory())->count([
      'manifest.yml',
      'profiles/groups/member.yml',
      'profiles/fields/member__phone.yml',
      'tags/member.yml',
      'extensions/org.example/api4/Thing/one.yml',
    ], [
      'profiles' => 'profiles/groups',
      'profile-fields' => 'profiles/fields',
      'tags' => 'tags',
      'extensions' => 'extensions',
    ]);

    self::assertSame(5, $result['total']);
    self::assertSame([
      'profiles' => 1,
      'profile-fields' => 1,
      'tags' => 1,
      'extensions' => 1,
    ], $result['by_type']);
  }
}
