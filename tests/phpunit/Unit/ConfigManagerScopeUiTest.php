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


  /**
   * Requirement: one Settings request must not repeat runtime capability
   * discovery simply because status and scope presentation both need it.
   * Failure mode: optional provider metadata is probed repeatedly during the
   * same request, multiplying Settings latency as providers grow.
   */
  public function testScopeTypeOptionsAreRequestCachedWithoutExporting(): void {
    $handler = new ScopeUiUnavailableFixtureHandler('optional-type', 'Optional Type');
    $manager = new ConfigManager(new ScopeUiFixtureRegistry([$handler]));

    $first = $manager->getScopeTypeOptions();
    $second = $manager->getScopeTypeOptions();

    self::assertSame($first, $second);
    self::assertSame(1, $handler->availabilityCalls);
    self::assertSame(0, $handler->exportCalls);
  }

  public function testScopeTypeOptionsExposeDeploymentDependenciesAndDependents(): void {
    $manager = new ConfigManager(new ScopeUiFixtureRegistry([
      new ScopeUiFixtureHandler('option-groups', 'Option Groups and Values', TRUE),
      new ScopeUiFixtureHandler('contact-types', 'Contact Types', TRUE),
      new ScopeUiFixtureHandler('site-tokens', 'Site Tokens', TRUE),
      new ScopeUiFixtureHandler('custom-data', 'Custom Groups and Fields', TRUE),
    ]));

    $rows = [];
    foreach ($manager->getScopeTypeOptions() as $row) {
      $rows[(string) $row['type']] = $row;
    }

    self::assertSame('option-groups,contact-types,site-tokens', $rows['custom-data']['scope_dependency_types']);
    self::assertSame(3, count($rows['custom-data']['scope_dependencies']));
    self::assertSame('custom-data', $rows['option-groups']['scope_dependents'][0]['type']);
  }

  public function testScopeDependencyWarningsFlagIgnoredWatchedAndSelectedRelatedTypes(): void {
    \Civi::settings()->set('civicfg_scope_default_mode', 'ignore');
    \Civi::settings()->set('civicfg_scope', [
      'custom-data' => ['mode' => 'all'],
      'option-groups' => ['mode' => 'ignore'],
      'contact-types' => ['mode' => 'watch'],
      'site-tokens' => [
        'mode' => 'selected',
        'selectors' => ['key:site-tokens|SiteToken|name=example'],
      ],
    ]);
    $manager = new ConfigManager(new ScopeUiFixtureRegistry([
      new ScopeUiFixtureHandler('option-groups', 'Option Groups and Values', TRUE),
      new ScopeUiFixtureHandler('contact-types', 'Contact Types', TRUE),
      new ScopeUiFixtureHandler('site-tokens', 'Site Tokens', TRUE),
      new ScopeUiFixtureHandler('custom-data', 'Custom Groups and Fields', TRUE),
    ]));

    $warnings = $manager->getScopeDependencyWarnings();
    $messages = implode("\n", array_map(static function($warning) {
      return (string) ($warning['message'] ?? '');
    }, $warnings));

    self::assertCount(3, $warnings);
    self::assertStringContainsString('Option Groups and Values is ignored', $messages);
    self::assertStringContainsString('Contact Types is monitor-only', $messages);
    self::assertStringContainsString('Site Tokens uses selected-item scope', $messages);
  }

  public function testScopeDependencyWarningsFlagUnavailableRelatedProvider(): void {
    \Civi::settings()->set('civicfg_scope_default_mode', 'ignore');
    \Civi::settings()->set('civicfg_scope', [
      'custom-data' => ['mode' => 'all'],
      'option-groups' => ['mode' => 'all'],
      'contact-types' => ['mode' => 'all'],
      'site-tokens' => ['mode' => 'all'],
    ]);
    $manager = new ConfigManager(new ScopeUiFixtureRegistry([
      new ScopeUiFixtureHandler('option-groups', 'Option Groups and Values', TRUE),
      new ScopeUiFixtureHandler('contact-types', 'Contact Types', TRUE),
      new ScopeUiUnavailableFixtureHandler('site-tokens', 'Site Tokens'),
      new ScopeUiFixtureHandler('custom-data', 'Custom Groups and Fields', TRUE),
    ]));

    $warnings = $manager->getScopeDependencyWarnings();

    self::assertCount(1, $warnings);
    self::assertSame('error', $warnings[0]['level']);
    self::assertStringContainsString('Site Tokens is unavailable on this site', $warnings[0]['message']);
  }

  public function testScopeDependencyWarningsClearWhenRelatedTypesAreFullyManaged(): void {
    \Civi::settings()->set('civicfg_scope_default_mode', 'ignore');
    \Civi::settings()->set('civicfg_scope', [
      'custom-data' => ['mode' => 'all'],
      'option-groups' => ['mode' => 'all'],
      'contact-types' => ['mode' => 'all'],
      'site-tokens' => ['mode' => 'all'],
    ]);
    $manager = new ConfigManager(new ScopeUiFixtureRegistry([
      new ScopeUiFixtureHandler('option-groups', 'Option Groups and Values', TRUE),
      new ScopeUiFixtureHandler('contact-types', 'Contact Types', TRUE),
      new ScopeUiFixtureHandler('site-tokens', 'Site Tokens', TRUE),
      new ScopeUiFixtureHandler('custom-data', 'Custom Groups and Fields', TRUE),
    ]));

    self::assertSame([], $manager->getScopeDependencyWarnings());
  }

  public function testUnavailableRuntimeProviderIsReportedWithoutExporting(): void {
    $handler = new ScopeUiUnavailableFixtureHandler('optional-type', 'Optional Type');
    $manager = new ConfigManager(new ScopeUiFixtureRegistry([$handler]));

    $rows = $manager->getScopeTypeOptions();

    self::assertSame(0, $handler->exportCalls);
    self::assertSame('unavailable', $rows[0]['capability']);
    self::assertSame('Unavailable on this site', $rows[0]['capability_label']);
    self::assertStringContainsString('Optional API4 provider', $rows[0]['capability_help']);
  }

  public function testUnavailableScopePickerReturnsReasonWithoutExporting(): void {
    $handler = new ScopeUiUnavailableFixtureHandler('optional-type', 'Optional Type');
    $manager = new ConfigManager(new ScopeUiFixtureRegistry([$handler]));

    $picker = $manager->getScopePickerItems('optional-type');

    self::assertFalse($picker['available']);
    self::assertSame([], $picker['items']);
    self::assertStringContainsString('Optional API4 provider', $picker['unavailable_reason']);
    self::assertSame(0, $handler->exportCalls);
  }

  public function testRuntimeWriteGapDowngradesFullHandlerToExportOnly(): void {
    $handler = new ScopeUiRuntimeExportOnlyFixtureHandler('partial-api4', 'Partial API4');
    $manager = new ConfigManager(new ScopeUiFixtureRegistry([$handler]));

    $rows = $manager->getScopeTypeOptions();

    self::assertSame('export_only', $rows[0]['capability']);
    self::assertStringContainsString('delete', $rows[0]['capability_help']);
    self::assertSame(0, $handler->exportCalls);
  }


  public function testUnavailableProviderSettingsKeepOnlyIgnoreActionable(): void {
    $template = (string) file_get_contents(dirname(__DIR__, 3) . '/templates/CRM/Configmanager/Page/Partials/Settings.tpl');

    self::assertStringContainsString('$row.capability eq \'unavailable\'}disabled="disabled"', $template);
    self::assertStringContainsString('Current saved scope is retained but cannot run safely.', $template);
    self::assertStringContainsString('Choose Ignore and save, or restore a supported provider version.', $template);
  }

  public function testFreshIgnoreScopeRequiresSetupInsteadOfReportingSync(): void {
    $root = $this->createTemporaryDirectory();
    \Civi::settings()->set('civicfg_sync_dir', $root);
    \Civi::settings()->set('civicfg_scope_default_mode', 'ignore');

    $handler = new ScopeUiFixtureHandler('scheduled-jobs', 'Scheduled Jobs', TRUE, [
      $this->jobFile(10, 'job_one'),
    ]);
    $manager = new ConfigManager(new ScopeUiFixtureRegistry([$handler]));

    $state = $manager->getScopeSetupState();
    self::assertFalse($state['managed']);
    self::assertFalse($state['watched']);
    self::assertTrue($state['setup_required']);

    $result = $manager->diff();
    self::assertTrue($result['no_managed_scope']);
    self::assertTrue($result['setup_required']);
    self::assertArrayNotHasKey('initial_export_required', $result);
    self::assertSame(0, $handler->exportCalls);

    $health = $manager->getHealth();
    self::assertSame('Configuration Manager: Setup required', $health['title']);
  }

  public function testWatchOnlyScopeIsNotReportedAsManagedSync(): void {
    $root = $this->createTemporaryDirectory();
    \Civi::settings()->set('civicfg_sync_dir', $root);
    \Civi::settings()->set('civicfg_scope_default_mode', 'ignore');
    \Civi::settings()->set('civicfg_scope', [
      'scheduled-jobs' => ['mode' => 'watch'],
    ]);

    $handler = new ScopeUiFixtureHandler('scheduled-jobs', 'Scheduled Jobs', TRUE, [
      $this->jobFile(10, 'job_one'),
    ]);
    $manager = new ConfigManager(new ScopeUiFixtureRegistry([$handler]));

    $state = $manager->getScopeSetupState();
    self::assertFalse($state['managed']);
    self::assertTrue($state['watched']);
    self::assertTrue($state['watch_only']);

    $result = $manager->diff();
    self::assertTrue($result['no_managed_scope']);
    self::assertTrue($result['watch_only']);
    self::assertSame(0, $handler->exportCalls);

    $health = $manager->getHealth();
    self::assertSame('Configuration Manager: Monitoring only', $health['title']);
  }

  public function testSelectedScopeWithoutItemsStillRequiresSetup(): void {
    $root = $this->createTemporaryDirectory();
    \Civi::settings()->set('civicfg_sync_dir', $root);
    \Civi::settings()->set('civicfg_scope_default_mode', 'ignore');
    \Civi::settings()->set('civicfg_scope', [
      'scheduled-jobs' => [
        'mode' => 'selected',
        'selectors' => [],
        'watch_unmanaged' => FALSE,
      ],
    ]);

    $manager = new ConfigManager(new ScopeUiFixtureRegistry([
      new ScopeUiFixtureHandler('scheduled-jobs', 'Scheduled Jobs', TRUE),
    ]));

    self::assertFalse($manager->hasManagedScopeConfigured());
    $result = $manager->diff();
    self::assertTrue($result['no_managed_scope']);
    self::assertTrue($result['setup_required']);
  }

  public function testSelectedScopeWithItemRequiresInitialExportBeforeDiff(): void {
    $root = $this->createTemporaryDirectory();
    \Civi::settings()->set('civicfg_sync_dir', $root);
    \Civi::settings()->set('civicfg_scope_default_mode', 'ignore');
    \Civi::settings()->set('civicfg_scope', [
      'scheduled-jobs' => [
        'mode' => 'selected',
        'selectors' => ['key:scheduled-jobs|Job|name=job_one'],
        'watch_unmanaged' => TRUE,
      ],
    ]);

    $handler = new ScopeUiFixtureHandler('scheduled-jobs', 'Scheduled Jobs', TRUE, [
      $this->jobFile(10, 'job_one'),
    ]);
    $manager = new ConfigManager(new ScopeUiFixtureRegistry([$handler]));

    self::assertTrue($manager->hasManagedScopeConfigured());
    $result = $manager->diff();
    self::assertTrue($result['initial_export_required']);
    self::assertArrayNotHasKey('no_managed_scope', $result);
    self::assertSame(0, $handler->exportCalls);
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

  public function testMessageTemplatePickerPrefersHumanTemplateTitle(): void {
    $root = $this->createTemporaryDirectory();
    \Civi::settings()->set('civicfg_sync_dir', $root);

    $templates = new ScopeUiFixtureHandler('message-templates', 'Message Templates', TRUE, [[
      'filename' => 'system/case_activity_default.yml',
      'source_id' => 1,
      'data' => [
        'schema_version' => 1,
        'type' => 'message_template',
        'name' => 'case_activity',
        'identity_key' => 'workflow_name=case_activity|is_default=1',
        'identity_confidence' => 'API_VERIFIED',
        'template' => [
          'workflow_name' => 'case_activity',
          'msg_title' => 'Case Activity Notification',
          'is_default' => TRUE,
        ],
      ],
    ]]);

    $manager = new ConfigManager(new ScopeUiFixtureRegistry([$templates]));
    $result = $manager->getScopePickerItems('message-templates');

    self::assertCount(1, $result['items']);
    self::assertSame('Case Activity Notification', $result['items'][0]['label']);
    self::assertStringStartsWith('key:', $result['items'][0]['selector']);
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

  public function testScopePolicyCanBeChangedWithoutTouchingOtherTypes(): void {
    $manager = new ConfigManager(new ScopeUiFixtureRegistry([
      new ScopeUiFixtureHandler('scheduled-jobs', 'Scheduled Jobs', TRUE),
      new ScopeUiFixtureHandler('message-templates', 'Message Templates', TRUE),
    ]));
    \Civi::settings()->set('civicfg_scope', [
      'message-templates' => ['mode' => 'watch'],
    ]);

    $result = $manager->setScopePolicy(
      'scheduled-jobs',
      'selected',
      ['key:scheduled-jobs|Job|name=job_one', 'key:scheduled-jobs|Job|name=job_one'],
      TRUE
    );

    self::assertSame('selected', $result['policy']['mode']);
    self::assertSame(['key:scheduled-jobs|Job|name=job_one'], $result['policy']['selectors']);
    self::assertTrue($result['policy']['watch_unmanaged']);
    self::assertSame('watch', $manager->getScopePolicies()['message-templates']['mode']);
  }

  public function testScopePolicyRejectsUnknownTypeAndInvalidMode(): void {
    $manager = new ConfigManager(new ScopeUiFixtureRegistry([
      new ScopeUiFixtureHandler('scheduled-jobs', 'Scheduled Jobs', TRUE),
    ]));

    try {
      $manager->setScopePolicy('missing-type', 'all');
      self::fail('Unknown scope type should throw.');
    }
    catch (\RuntimeException $e) {
      self::assertStringContainsString('Unknown Configuration Scope type', $e->getMessage());
    }

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Invalid Configuration Scope mode');
    $manager->setScopePolicy('scheduled-jobs', 'dangerous');
  }

  public function testScopePolicyCannotBeChangedWhenSettingsPhpOwnsIt(): void {
    $GLOBALS['civicrm_setting'] = [
      'domain' => [
        'civicfg_scope' => [
          'scheduled-jobs' => ['mode' => 'watch'],
        ],
      ],
    ];
    $manager = new ConfigManager(new ScopeUiFixtureRegistry([
      new ScopeUiFixtureHandler('scheduled-jobs', 'Scheduled Jobs', TRUE),
    ]));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('overridden in civicrm.settings.php');
    $manager->setScopePolicy('scheduled-jobs', 'all');
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

  public function testManageEverythingReplacesStaleIgnoreManifestAndExportsFiles(): void {
    $root = $this->createTemporaryDirectory();
    \Civi::settings()->set('civicfg_sync_dir', $root);
    \Civi::settings()->set('civicfg_scope_default_mode', 'ignore');
    \Civi::settings()->set('civicfg_scope', [
      'extensions' => ['mode' => 'all'],
    ]);

    $this->writeYaml($root, 'manifest.yml', [
      'schema_version' => 1,
      'extension' => 'civi.config.manager',
      'managed_scope' => [
        'extensions' => ['mode' => 'ignore'],
      ],
    ]);

    $handler = new ScopeUiFixtureHandler('extensions', 'Extensions', TRUE, [[
      'filename' => 'example.extension.yml',
      'data' => [
        'schema_version' => 1,
        'type' => 'extension.item',
        'key' => 'example.extension',
        'extension' => ['key' => 'example.extension', 'status' => 'installed'],
      ],
    ]]);
    $manager = new ConfigManager(new ScopeUiFixtureRegistry([$handler]));

    $result = $manager->export(FALSE);

    self::assertTrue($result['ok'], json_encode($result, JSON_PRETTY_PRINT));
    self::assertFileExists($root . '/extensions/example.extension.yml');
    $manifest = SimpleYaml::parseFile($root . '/manifest.yml');
    self::assertSame('all', $manifest['managed_scope']['extensions']['mode'] ?? NULL);
  }

  public function testPartialHandlerErrorKeepsPreviousSnapshotAndNeverPublishesPartialYaml(): void {
    $root = $this->createTemporaryDirectory();
    \Civi::settings()->set('civicfg_sync_dir', $root);
    \Civi::settings()->set('civicfg_scope_default_mode', 'ignore');
    \Civi::settings()->set('civicfg_scope', [
      'extensions' => ['mode' => 'all'],
    ]);

    $this->writeYaml($root, 'manifest.yml', [
      'schema_version' => 1,
      'extension' => 'civi.config.manager',
      'managed_scope' => [
        'extensions' => ['mode' => 'ignore'],
      ],
    ]);
    $this->writeYaml($root, 'extensions/stale-provider.yml', [
      'schema_version' => 1,
      'type' => 'extension.item',
      'key' => 'stale-provider',
      'extension' => ['key' => 'stale-provider', 'status' => 'installed'],
    ]);

    $handler = new ScopeUiPartialErrorFixtureHandler('extensions', 'Extensions', [[
      'filename' => 'safe-extension.yml',
      'data' => [
        'schema_version' => 1,
        'type' => 'extension.item',
        'key' => 'safe-extension',
        'extension' => ['key' => 'safe-extension', 'status' => 'installed'],
      ],
    ]], ['One contributed provider could not be read.']);
    $manager = new ConfigManager(new ScopeUiFixtureRegistry([$handler]));

    $result = $manager->export(FALSE);

    self::assertFalse($result['ok']);
    self::assertFileDoesNotExist($root . '/extensions/safe-extension.yml');
    self::assertFileExists($root . '/extensions/stale-provider.yml');
    self::assertSame('extensions', $result['errors'][0]['type'] ?? NULL);
    self::assertStringContainsString('could not be read', (string) ($result['errors'][0]['message'] ?? ''));
    $manifest = SimpleYaml::parseFile($root . '/manifest.yml');
    self::assertSame('ignore', $manifest['managed_scope']['extensions']['mode'] ?? NULL);
  }

  public function testPartialSelectedHandlerErrorPreservesExistingSelectedManifest(): void {
    $root = $this->createTemporaryDirectory();
    \Civi::settings()->set('civicfg_sync_dir', $root);
    \Civi::settings()->set('civicfg_scope_default_mode', 'ignore');
    \Civi::settings()->set('civicfg_scope', [
      'scheduled-jobs' => [
        'mode' => 'selected',
        'selectors' => ['key:scheduled-jobs|Job|name=job_one'],
        'watch_unmanaged' => TRUE,
      ],
    ]);

    $existingKey = 'scheduled-jobs|Job|name=previously_resolved_job';
    $this->writeYaml($root, 'manifest.yml', [
      'schema_version' => 1,
      'extension' => 'civi.config.manager',
      'managed_scope' => [
        'scheduled-jobs' => [
          'mode' => 'selected',
          'config_keys' => [$existingKey],
          'selector_map' => [
            'key:' . $existingKey => $existingKey,
          ],
        ],
      ],
    ]);

    $handler = new ScopeUiPartialErrorFixtureHandler('scheduled-jobs', 'Scheduled Jobs', [
      $this->jobFile(10, 'job_one'),
    ], ['Provider read was incomplete.']);
    $manager = new ConfigManager(new ScopeUiFixtureRegistry([$handler]));

    $result = $manager->export(FALSE);

    self::assertFalse($result['ok']);
    $manifest = SimpleYaml::parseFile($root . '/manifest.yml');
    self::assertSame('selected', $manifest['managed_scope']['scheduled-jobs']['mode'] ?? NULL);
    self::assertSame([$existingKey], $manifest['managed_scope']['scheduled-jobs']['config_keys'] ?? []);
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

final class ScopeUiPartialErrorFixtureHandler extends AbstractHandler {
  private string $type;
  private string $label;
  private array $files;
  private array $errors;

  public function __construct(string $type, string $label, array $files, array $errors) {
    $this->type = $type;
    $this->label = $label;
    $this->files = $files;
    $this->errors = $errors;
  }

  public function getType(): string { return $this->type; }
  public function getLabel(): string { return $this->label; }
  public function getDirectory(): string { return $this->type; }
  public function getWeight(): int { return 20; }
  public function export(): array { return $this->files; }

  public function consumeExportErrors(): array {
    $errors = $this->errors;
    $this->errors = [];
    return $errors;
  }
}

final class ScopeUiUnavailableFixtureHandler extends AbstractHandler {
  private string $type;
  private string $label;
  public int $exportCalls = 0;
  public int $availabilityCalls = 0;

  public function __construct(string $type, string $label) {
    $this->type = $type;
    $this->label = $label;
  }

  public function getType(): string { return $this->type; }
  public function getLabel(): string { return $this->label; }
  public function getDirectory(): string { return $this->type; }
  public function getWeight(): int { return 20; }
  public function getRuntimeAvailability(): array {
    $this->availabilityCalls++;
    return ['available' => FALSE, 'reason' => 'Optional API4 provider is not available.'];
  }
  public function export(): array { $this->exportCalls++; return []; }
}

final class ScopeUiRuntimeExportOnlyFixtureHandler extends AbstractHandler {
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
  public function getRuntimeAvailability(): array {
    return [
      'available' => TRUE,
      'management_capability' => 'export_only',
      'reason' => 'Provider is readable but delete is unavailable.',
    ];
  }
  public function export(): array { $this->exportCalls++; return []; }
  public function import(array $items, bool $dryRun = TRUE): array { return $this->baseImportSummary($dryRun); }
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
