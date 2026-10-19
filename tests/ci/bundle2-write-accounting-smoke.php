<?php
/**
 * Dependency-free Bundle 2 regression: rejected API4 writes are never counted
 * as applied for Tags or Profile Fields. All data is held in memory.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
  $prefix = 'Civi\\ConfigManager\\';
  if (strpos($class, $prefix) !== 0) {
    return;
  }
  $path = $root . '/Civi/ConfigManager/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
  if (is_file($path)) {
    require_once $path;
  }
});

use Civi\ConfigManager\Handler\ProfileFieldHandler;
use Civi\ConfigManager\Handler\TagHandler;

/**
 * Fake database boundary: no CiviCRM bootstrapping or live writes. */
class Bundle2TagFixture extends TagHandler {
  public bool $failWrite = FALSE;
  public ?array $existing = NULL;

  protected function api4GetFirst(string $entity, array $where, array $select = ['*']): ?array {
    return $entity === 'Tag' ? $this->existing : NULL;
  }

  protected function api4Create(string $entity, array $values): array {
    if ($this->failWrite) {
      throw new \RuntimeException('Simulated Tag create failure.');
    }
    return ['id' => 17] + $values;
  }

  protected function api4Update(string $entity, array $where, array $values): array {
    if ($this->failWrite) {
      throw new \RuntimeException('Simulated Tag update failure.');
    }
    return ['id' => 17] + $values;
  }

}

/**
 * Fake API4 UFGroup/UFField identities and writes, with no database access. */
class Bundle2ProfileFieldFixture extends ProfileFieldHandler {
  public bool $failWrite = FALSE;
  public ?array $existing = NULL;

  protected function api4GetFirst(string $entity, array $where, array $select = ['*']): ?array {
    if ($entity === 'UFGroup') {
      return ['id' => 41, 'name' => 'test_profile'];
    }
    return NULL;
  }

  protected function api4Get(string $entity, array $where = [], array $select = ['*'], array $orderBy = []): array {
    return $entity === 'UFField' && $this->existing ? [$this->existing] : [];
  }

  protected function api4Create(string $entity, array $values): array {
    if ($this->failWrite) {
      throw new \RuntimeException('Simulated UFField create failure.');
    }
    return ['id' => 43] + $values;
  }

  protected function api4Update(string $entity, array $where, array $values): array {
    if ($this->failWrite) {
      throw new \RuntimeException('Simulated UFField update failure.');
    }
    return ['id' => 43] + $values;
  }

}

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
  $checks++;
  if (!$condition) {
    fwrite(STDERR, 'Bundle 2 accounting FAILED: ' . $message . "\n");
    exit(1);
  }
};

$tagDocument = ['type' => 'tags.item', 'entity' => 'Tag', 'item' => ['name' => 'test_tag', 'label' => 'Updated']];
$profileDocument = [
  'type' => 'profile-fields.item',
  'entity' => 'UFField',
  'item' => [
    'uf_group_id' => ['provider' => 'api4:UFGroup', 'entity' => 'UFGroup', 'key' => ['name' => 'test_profile']],
    'field_name' => 'first_name',
    'field_type' => 'Individual',
    'label' => 'First name',
    'is_active' => TRUE,
  ],
];

foreach (['create', 'update'] as $operation) {
  foreach ([FALSE, TRUE] as $fail) {
    $tag = new Bundle2TagFixture();
    $tag->failWrite = $fail;
    if ($operation === 'update') {
      $tag->existing = ['id' => 17, 'name' => 'test_tag', 'label' => 'Original', 'parent_id' => NULL];
    }
    $result = $tag->importIterable(['tag.yml' => $tagDocument], FALSE);
    $check((int) $result[$operation] === ($fail ? 0 : 1), 'Tag ' . $operation . ' must count only successful writes');
    $check((bool) $result['ok'] === !$fail, 'Tag ' . $operation . ' reports failure accurately');
    $check(count($result['errors']) === ($fail ? 1 : 0), 'Tag ' . $operation . ' preserves the API error');

    $profile = new Bundle2ProfileFieldFixture();
    $profile->failWrite = $fail;
    if ($operation === 'update') {
      $profile->existing = ['id' => 43, 'uf_group_id' => 41, 'field_name' => 'first_name', 'field_type' => 'Individual', 'label' => 'First name', 'is_active' => FALSE];
    }
    $result = $profile->importIterable(['field.yml' => $profileDocument], FALSE);
    $check((int) $result[$operation] === ($fail ? 0 : 1), 'UFField ' . $operation . ' must count only successful writes');
    $check((bool) $result['ok'] === !$fail, 'UFField ' . $operation . ' reports failure accurately');
    $check(count($result['errors']) === ($fail ? 1 : 0), 'UFField ' . $operation . ' preserves the API error');
  }
}

// Deliberately exercise dry-run: no write should be made or error fabricated.
$tag = new Bundle2TagFixture();
$tag->failWrite = TRUE;
$preview = $tag->importIterable(['tag.yml' => $tagDocument], TRUE);
$check($preview['create'] === 1 && $preview['ok'], 'Tag dry-run predicts create without invoking a write');

$profile = new Bundle2ProfileFieldFixture();
$profile->failWrite = TRUE;
$preview = $profile->importIterable(['field.yml' => $profileDocument], TRUE);
$check($preview['create'] === 1 && $preview['ok'], 'Profile Field dry-run predicts create without invoking a write');

fwrite(STDOUT, 'Bundle 2 write accounting OK (' . $checks . " checks; no database writes).\n");
