<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Handler\GenericApi4CollectionHandler;
use PHPUnit\Framework\TestCase;

final class GenericApi4IdentityUpdateTest extends TestCase {
  public function testIdlessVirtualEntityUpdatesByPortableIdentity(): void {
    $handler = new GenericApi4IdentityUpdateFixture();

    $result = $handler->import([
      'Demo.yml' => [
        'schema_version' => 1,
        'type' => 'qa-afform.item',
        'entity' => 'Afform',
        'name' => 'afsearchDemo',
        'identity_field' => 'name',
        'dependencies' => [],
        'item' => [
          'name' => 'afsearchDemo',
          'title' => 'Updated title',
        ],
      ],
    ], FALSE);

    self::assertTrue($result['ok']);
    self::assertSame(1, $result['update']);
    self::assertSame([['name', '=', 'afsearchDemo']], $handler->updateWhere);
  }
}

final class GenericApi4IdentityUpdateFixture extends GenericApi4CollectionHandler {
  public array $updateWhere = [];

  public function __construct() {
    parent::__construct(
      'qa-afform',
      'QA Afform',
      'qa-afform',
      'Afform',
      ['name', 'title'],
      ['name' => 'ASC'],
      1,
      'items.yml',
      TRUE
    );
    $this->setDeleteMissingEnabled(FALSE);
  }

  protected function api4GetFirst(string $entity, array $where, array $select = ['*']): array {
    return [
      'name' => 'afsearchDemo',
      'title' => 'Original title',
    ];
  }

  protected function api4Update(string $entity, array $where, array $values): array {
    $this->updateWhere = $where;
    return $values;
  }
}
