<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Handler\AbstractHandler;
use Civi\ConfigManager\Service\ConfigIdentity;
use Civi\ConfigManager\Service\ConfigManager;
use Civi\ConfigManager\Service\HandlerRegistry;
use Civi\ConfigManager\Util\SimpleYaml;
use Civi\ConfigManager\Tests\Support\TemporaryDirectoryTrait;
use PHPUnit\Framework\TestCase;

final class ConfigManagerScopeUiTest extends TestCase {
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

  public function testScopeTypeOptionsDoNotExportHandlers(): void {
    $full = new ScopeUiFixtureHandler('full-type', 'Full Type', TRUE);
    $readonly = new ScopeUiReadOnlyFixtureHandler('readonly-type', 'Read Only Type');
    $manager = new ConfigManager(new ScopeUiFixtureRegistry([$full, $readonly]));

    $rows = $manager->getScopeTypeOptions();

    self::assertSame(0, $full->exportCalls);
    self::assertSame(0, $readonly->exportCalls);
    self::assertSame('full', $rows[0]['capability']);
    self::assertSame('export_only', $rows[1]['capability']);
  }

  public function testPickerExportsOnlyRequestedTypeAndReturnsStableSelectors(): void {
    $root = $this->createTemporaryDirectory();
    \Civi::settings()->set('civicfg_sync_dir', $root);

    $jobs = new ScopeUiFixtureHandler('scheduled-jobs', 'Scheduled Jobs', TRUE, [
      $this->jobFile(10, 'job_one'),
      $this->jobFile(20, 'job_two'),
    ]);
    $other = new ScopeUiFixtureHandler('other-type', 'Other Type', TRUE, [
      $this->genericFile('other.yml', 'other'),
    ]);

    $jobIdentity = (new ConfigIdentity())->identify('scheduled-jobs', $this->jobFile(20, 'job_two')['data'], 'job_two.yml');
    \Civi::settings()->set('civicfg_scope', [
      'scheduled-jobs' => [
        'mode' => 'selected',
        'selectors' => ['key:' . $jobIdentity['config_key']],
        'watch_unmanaged' => TRUE,
      ],
    ]);

    $manager = new ConfigManager(new ScopeUiFixtureRegistry([$jobs, $other]));
    $result = $manager->getScopePickerItems('scheduled-jobs');

    self::assertSame(1, $jobs->exportCalls);
    self::assertSame(0, $other->exportCalls);
    self::assertCount(2, $result['items']);
    self::assertSame('scheduled-jobs/job_one.yml', $result['items'][0]['path']);
    self::assertStringStartsWith('key:', $result['items'][0]['selector']);
    self::assertFalse($result['items'][0]['selected']);
    self::assertTrue($result['items'][1]['selected']);
    self::assertSame('20', $result['items'][1]['source_id']);
  }

  public function testPickerPreservesMissingSelectedPortableIdentity(): void {
    $root = $this->createTemporaryDirectory();
    \Civi::settings()->set('civicfg_sync_dir', $root);
    \Civi::settings()->set('civicfg_scope', [
      'scheduled-jobs' => [
        'mode' => 'selected',
        'selectors' => ['10'],
      ],
    ]);
    \Civi::settings()->set('civicfg_scope_resolved', [
      'scheduled-jobs' => [
        '10' => 'scheduled-jobs|Job|name=missing_job',
      ],
    ]);

    $jobs = new ScopeUiFixtureHandler('scheduled-jobs', 'Scheduled Jobs', TRUE, [
      $this->jobFile(20, 'job_two'),
    ]);
    $manager = new ConfigManager(new ScopeUiFixtureRegistry([$jobs]));

    $result = $manager->getScopePickerItems('scheduled-jobs');
    $missing = array_values(array_filter($result['items'], static fn($item) => !empty($item['missing'])));

    self::assertCount(1, $missing);
    self::assertSame('key:scheduled-jobs|Job|name=missing_job', $missing[0]['selector']);
    self::assertTrue($missing[0]['selected']);
  }

  public function testFilteredImportValidatesRelatedDependencyTypes(): void {
    $root = $this->createTemporaryDirectory();
    \Civi::settings()->set('civicfg_sync_dir', $root);

    $customData = new ScopeUiFixtureHandler('custom-data', 'Custom Data', TRUE, []);
    $optionGroups = new ScopeUiFixtureHandler('option-groups', 'Option Groups', TRUE, []);
    $manager = new ConfigManager(new ScopeUiFixtureRegistry([$optionGroups, $customData]));

    $this->writeYaml($root, 'custom-data/groups/example.yml', [
      'schema_version' => 1,
      'type' => 'custom-data.item',
      'name' => 'example',
      'dependencies' => [[
        'type' => 'option-groups',
        'name' => 'example_choices',
        'reason' => 'Custom field uses this option group for choices.',
      ]],
      'item' => ['name' => 'example'],
    ]);
    $this->writeYaml($root, 'option-groups/example_choices.yml', [
      'schema_version' => 1,
      'type' => 'option-groups.item',
      'name' => 'example_choices',
      'item' => ['name' => 'example_choices'],
    ]);

    $result = $manager->import(TRUE, FALSE, ['custom-data']);

    self::assertTrue($result['ok'], json_encode($result, JSON_PRETTY_PRINT));
    self::assertSame(['option-groups', 'custom-data'], array_column($result['items'], 'type'));
  }

  public function testValidationAcceptsDependencyAlreadyPresentInActiveCivi(): void {
    $root = $this->createTemporaryDirectory();
    \Civi::settings()->set('civicfg_sync_dir', $root);

    $customData = new ScopeUiFixtureHandler('custom-data', 'Custom Data', TRUE, []);
    $optionGroups = new ScopeUiFixtureHandler('option-groups', 'Option Groups', TRUE, [
      $this->genericTypedFile('option-groups', 'example_choices.yml', 'example_choices'),
    ]);
    $manager = new ConfigManager(new ScopeUiFixtureRegistry([$optionGroups, $customData]));

    $this->writeYaml($root, 'custom-data/groups/example.yml', [
      'schema_version' => 1,
      'type' => 'custom-data.item',
      'name' => 'example',
      'dependencies' => [[
        'type' => 'option-groups',
        'name' => 'example_choices',
        'reason' => 'Custom field uses this option group for choices.',
      ]],
      'item' => ['name' => 'example'],
    ]);

    $result = $manager->validate();

    self::assertTrue($result['ok'], json_encode($result, JSON_PRETTY_PRINT));
    self::assertSame(1, $optionGroups->exportCalls);
  }

  public function testValidationStillBlocksDependencyMissingFromYamlAndActiveCivi(): void {
    $root = $this->createTemporaryDirectory();
    \Civi::settings()->set('civicfg_sync_dir', $root);

    $customData = new ScopeUiFixtureHandler('custom-data', 'Custom Data', TRUE, []);
    $optionGroups = new ScopeUiFixtureHandler('option-groups', 'Option Groups', TRUE, []);
    $manager = new ConfigManager(new ScopeUiFixtureRegistry([$optionGroups, $customData]));

    $this->writeYaml($root, 'custom-data/groups/example.yml', [
      'schema_version' => 1,
      'type' => 'custom-data.item',
      'name' => 'example',
      'dependencies' => [[
        'type' => 'option-groups',
        'name' => 'missing_choices',
        'reason' => 'Custom field uses this option group for choices.',
      ]],
      'item' => ['name' => 'example'],
    ]);

    $result = $manager->validate();

    self::assertFalse($result['ok']);
    $messages = [];
    foreach ($result['items'] as $item) {
      foreach (($item['errors'] ?? []) as $error) {
        $messages[] = (string) ($error['message'] ?? '');
      }
    }
    self::assertStringContainsString(
      'is not available in the managed YAML set or active CiviCRM',
      implode("\n", $messages)
    );
  }

  public function testSettingsExampleContainsOnlyNonDefaultPolicies(): void {
    \Civi::settings()->set('civicfg_scope', [
      'scheduled-jobs' => [
        'mode' => 'selected',
        'selectors' => ['key:scheduled-jobs|Job|name=job_one'],
        'watch_unmanaged' => TRUE,
      ],
      'message-templates' => ['mode' => 'watch'],
    ]);
    $manager = new ConfigManager(new ScopeUiFixtureRegistry([
      new ScopeUiFixtureHandler('scheduled-jobs', 'Scheduled Jobs', TRUE),
      new ScopeUiFixtureHandler('message-templates', 'Message Templates', TRUE),
      new ScopeUiFixtureHandler('other-type', 'Other Type', TRUE),
    ]));

    $example = $manager->getScopeSettingsExample();

    self::assertStringContainsString("'scheduled-jobs' => [", $example);
    self::assertStringContainsString("'watch_unmanaged' => TRUE", $example);
    self::assertStringContainsString("'message-templates' => [", $example);
    self::assertStringNotContainsString("'other-type' => [", $example);
  }

  private function writeYaml(string $root, string $relative, array $data): void {
    $path = $root . '/' . $relative;
    if (!is_dir(dirname($path))) {
      mkdir(dirname($path), 0775, TRUE);
    }
    file_put_contents($path, SimpleYaml::dump($data));
  }

  private function genericTypedFile(string $type, string $filename, string $name): array {
    return [
      'filename' => $filename,
      'data' => [
        'schema_version' => 1,
        'type' => $type . '.item',
        'entity' => ucfirst($type),
        'name' => $name,
        'identity_field' => 'name',
        'identity_confidence' => 'DISCOVERED_UNIQUE',
        'item' => ['name' => $name],
      ],
    ];
  }

  private function jobFile(int $id, string $name): array {
    return [
      'filename' => $name . '.yml',
      'source_id' => $id,
      'data' => [
        'schema_version' => 1,
        'type' => 'scheduled-jobs.item',
        'entity' => 'Job',
        'name' => $name,
        'identity_field' => 'name',
        'identity_confidence' => 'DISCOVERED_UNIQUE',
        'item' => ['name' => $name, 'is_active' => TRUE],
      ],
    ];
  }

  private function genericFile(string $filename, string $name): array {
    return [
      'filename' => $filename,
      'data' => [
        'schema_version' => 1,
        'type' => 'other-type.item',
        'entity' => 'Other',
        'name' => $name,
        'identity_field' => 'name',
        'identity_confidence' => 'DISCOVERED_UNIQUE',
        'item' => ['name' => $name],
      ],
    ];
  }
}

final class ScopeUiFixtureRegistry extends HandlerRegistry {
  private array $handlers;

  public function __construct(array $handlers) {
    $this->handlers = $handlers;
  }

  public function getHandlers(): array {
    return $this->handlers;
  }
}

final class ScopeUiFixtureHandler extends AbstractHandler {
  private string $type;
  private string $label;
  private bool $fullImport;
  private array $files;
  public int $exportCalls = 0;

  public function __construct(string $type, string $label, bool $fullImport, array $files = []) {
    $this->type = $type;
    $this->label = $label;
    $this->fullImport = $fullImport;
    $this->files = $files;
  }

  public function getType(): string { return $this->type; }
  public function getLabel(): string { return $this->label; }
  public function getDirectory(): string { return $this->type; }
  public function getWeight(): int { return $this->type === 'full-type' ? 10 : 20; }

  public function export(): array {
    $this->exportCalls++;
    return $this->files;
  }

  public function import(array $items, bool $dryRun = TRUE): array {
    if (!$this->fullImport) {
      return parent::import($items, $dryRun);
    }
    return [
      'type' => $this->type,
      'status' => $dryRun ? 'dry_run' : 'applied',
      'dry_run' => $dryRun,
      'count' => count($items),
      'create' => 0,
      'update' => 0,
      'skip' => count($items),
      'warnings' => [],
      'errors' => [],
    ];
  }
}

final class ScopeUiReadOnlyFixtureHandler extends AbstractHandler {
  private string $type;
  private string $label;
  public int $exportCalls = 0;

  public function __construct(string $type, string $label) {
    $this->type = $type;
    $this->label = $label;
  }

  public function getType(): string { return $this->type; }
  public function getLabel(): string { return $this->label; }
  public function getDirectory(): string { return $this->type; }
  public function getWeight(): int { return 20; }
  public function export(): array { $this->exportCalls++; return []; }
}
