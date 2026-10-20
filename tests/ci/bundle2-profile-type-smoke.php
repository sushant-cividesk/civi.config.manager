<?php
/**
 * Dependency-free Profile group_type comparison regression.
 * API4 may read a comma-separated field as an array even when YAML or a
 * historical import supplied its equivalent scalar representation.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
  $prefix = 'Civi\\ConfigManager\\';
  if (strpos($class, $prefix) !== 0) {
    return;
  }
  $file = $root . '/Civi/ConfigManager/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
  if (is_file($file)) {
    require_once $file;
  }
});

// The handler's real API4 transport methods are overridden below. The stub
// only satisfies its provider-availability gate, never calls the database.
class_alias(\stdClass::class, 'Civi\\Api4\\UFGroup');

use Civi\ConfigManager\Handler\EntityDefinitionHandler;
use Civi\ConfigManager\Service\CoreEntityDefinitions;

$definition = CoreEntityDefinitions::metadataDriven()['profiles'];
$handler = new class('profiles', $definition) extends EntityDefinitionHandler {
  /**
   * @var array<string,mixed> */
  public array $existing = [];
  /**
   * @var array<int,array<string,mixed>> */
  public array $writes = [];

  protected function api4GetFirst(string $entity, array $where, array $select = ['*']): ?array {
    return $this->existing ?: NULL;
  }

  protected function api4Update(string $entity, array $where, array $values): array {
    $this->writes[] = $values;
    return $values;
  }

};

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
  $checks++;
  if (!$condition) {
    fwrite(STDERR, 'Bundle 2 Profile type comparison FAILED: ' . $message . "\n");
    exit(1);
  }
};

$handler->existing = [
  'id' => 71,
  'name' => 'bundle2_profile',
  'title' => 'Fixture Profile',
  'is_active' => FALSE,
  'group_type' => ['Individual'],
];
$doc = [
  'profile.yml' => [
    'type' => 'profiles.item',
    'entity' => 'UFGroup',
    'item' => ['name' => 'bundle2_profile', 'title' => 'Fixture Profile', 'is_active' => FALSE, 'group_type' => 'Individual'],
  ],
];
$second = $handler->import($doc, FALSE);
$check(!empty($second['ok']) && $second['skip'] === 1 && $second['update'] === 0, 'equivalent API4 array and scalar must skip');
$check($handler->writes === [], 'equivalent group_type must not trigger an API4 update');
$doc['profile.yml']['item']['group_type'] = 'Contact';
$changed = $handler->import($doc, TRUE);
$check(!empty($changed['ok']) && $changed['update'] === 1 && $changed['skip'] === 0, 'genuine group_type change must be detected');
$doc['profile.yml']['item']['group_type'] = 'Individual,Contact';
$handler->existing['group_type'] = ['Individual', 'Contact'];
$equivalentMulti = $handler->import($doc, FALSE);
$check(!empty($equivalentMulti['ok']) && $equivalentMulti['skip'] === 1, 'multi-value CSV/array representations must compare equal');
$check($handler->writes === [], 'no equivalent Profile group_type representation may write');
$doc['profile.yml']['item']['group_type'] = 'Individual';
$changedMulti = $handler->import($doc, TRUE);
$check(!empty($changedMulti['ok']) && $changedMulti['update'] === 1, 'removing a Profile type is a genuine change');

echo 'Bundle 2 Profile type comparison OK (' . $checks . " checks; no database writes).\n";
