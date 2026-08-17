<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Service\ConfigScope;
use PHPUnit\Framework\TestCase;

final class ConfigScopeTest extends TestCase {
  protected function setUp(): void {
    \Civi::settings()->reset();
    $GLOBALS['civicrm_setting'] = [];
  }

  protected function tearDown(): void {
    \Civi::settings()->reset();
    $GLOBALS['civicrm_setting'] = [];
  }

  public function testDefaultScopeManagesEverythingAndAllowsBulkDelete(): void {
    $scope = new ConfigScope();
    $files = [$this->jobFile(10, 'job_one'), $this->jobFile(20, 'job_two')];

    $partition = $scope->partition('scheduled-jobs', $files, TRUE);

    self::assertCount(2, $partition['managed']);
    self::assertSame([], $partition['watched']);
    self::assertTrue($scope->allowsDeleteMissing('scheduled-jobs'));
  }

  public function testSelectedScopeUsesLocalIdOnlyForSelectionAndPortableKeyAcrossSites(): void {
    \Civi::settings()->set('civicfg_scope', [
      'scheduled-jobs' => [
        'mode' => 'selected',
        'selectors' => ['10'],
        'watch_unmanaged' => TRUE,
      ],
    ]);
    $scope = new ConfigScope();

    $source = $scope->partition('scheduled-jobs', [
      $this->jobFile(10, 'job_one'),
      $this->jobFile(20, 'job_two'),
    ], TRUE);

    self::assertCount(1, $source['managed']);
    self::assertSame('job_one.yml', $source['managed'][0]['filename']);
    self::assertCount(1, $source['watched']);
    self::assertFalse($scope->allowsDeleteMissing('scheduled-jobs'));

    $portableKeys = $source['managed_config_keys'];
    self::assertCount(1, $portableKeys);
    $manifestEntry = $scope->manifestEntry('scheduled-jobs', $source);
    self::assertSame($portableKeys, $manifestEntry['config_keys']);
    self::assertArrayHasKey('10', $manifestEntry['selector_map']);

    // Target-site numeric IDs differ, but the manifest's selector-to-portable
    // identity mapping keeps the same object selected even for a later export.
    $targetFiles = [
      $this->jobFile(99, 'job_one'),
      $this->jobFile(20, 'job_two'),
    ];
    $target = $scope->partition(
      'scheduled-jobs',
      $targetFiles,
      FALSE,
      $manifestEntry['selector_map']
    );

    self::assertCount(1, $target['managed']);
    self::assertSame('job_one.yml', $target['managed'][0]['filename']);
    self::assertCount(1, $target['watched']);

    $targetExport = $scope->partition(
      'scheduled-jobs',
      $targetFiles,
      TRUE,
      $manifestEntry['selector_map']
    );
    self::assertCount(1, $targetExport['managed']);
    self::assertSame('job_one.yml', $targetExport['managed'][0]['filename']);

    // A historical manifest key must not keep an object managed after the
    // administrator removes its selector. Current scope is authoritative.
    \Civi::settings()->set('civicfg_scope', [
      'scheduled-jobs' => [
        'mode' => 'selected',
        'selectors' => ['20'],
        'watch_unmanaged' => TRUE,
      ],
    ]);
    $reselected = (new ConfigScope())->partition(
      'scheduled-jobs',
      $targetFiles,
      FALSE,
      $manifestEntry['selector_map']
    );
    self::assertCount(1, $reselected['managed']);
    self::assertSame('job_two.yml', $reselected['managed'][0]['filename']);
    self::assertCount(1, $reselected['watched']);
    self::assertSame('job_one.yml', $reselected['watched'][0]['filename']);
  }

  public function testPortableSelectorReportsMissingOnlyWhenSemanticObjectIsAbsent(): void {
    \Civi::settings()->set('civicfg_scope', [
      'scheduled-jobs' => [
        'mode' => 'selected',
        'selectors' => ['10'],
        'watch_unmanaged' => TRUE,
      ],
    ]);
    $scope = new ConfigScope();
    $source = $scope->partition('scheduled-jobs', [$this->jobFile(10, 'job_one')], TRUE);
    $manifestEntry = $scope->manifestEntry('scheduled-jobs', $source);

    $differentTargetId = $scope->partition(
      'scheduled-jobs',
      [$this->jobFile(99, 'job_one')],
      FALSE,
      $manifestEntry['selector_map']
    );
    self::assertSame([], $differentTargetId['missing_selectors']);

    $missingTarget = $scope->partition(
      'scheduled-jobs',
      [$this->jobFile(20, 'job_two')],
      FALSE,
      $manifestEntry['selector_map']
    );
    self::assertSame(['10'], $missingTarget['missing_selectors']);
    self::assertCount(0, $missingTarget['managed']);
    self::assertCount(1, $missingTarget['watched']);
  }

  public function testSelectedYamlFilteringExcludesStaleDeselectedBackups(): void {
    \Civi::settings()->set('civicfg_scope', [
      'scheduled-jobs' => [
        'mode' => 'selected',
        'selectors' => ['job_one'],
        'watch_unmanaged' => TRUE,
      ],
    ]);
    $scope = new ConfigScope();
    $files = [
      'job_one.yml' => $this->jobFile(10, 'job_one')['data'],
      'job_two.yml' => $this->jobFile(20, 'job_two')['data'],
    ];
    $partition = $scope->partition('scheduled-jobs', [
      ['filename' => 'job_one.yml', 'data' => $files['job_one.yml']],
      ['filename' => 'job_two.yml', 'data' => $files['job_two.yml']],
    ]);

    $filtered = $scope->filterYamlFiles(
      'scheduled-jobs',
      $files,
      array_map('strval', (array) $partition['managed_config_keys'])
    );

    self::assertSame(['job_one.yml'], array_keys($filtered));
    self::assertArrayNotHasKey('job_two.yml', $filtered);
  }

  public function testSelectedScopeCanSelectAnIndividualSettingByStableName(): void {
    \Civi::settings()->set('civicfg_scope', [
      'settings' => [
        'mode' => 'selected',
        'selectors' => ['theme_backend'],
        'watch_unmanaged' => TRUE,
      ],
    ]);
    $files = [
      $this->settingFile('theme_backend', 'riverlea'),
      $this->settingFile('menubar_color', '#123456'),
    ];

    $partition = (new ConfigScope())->partition('settings', $files, TRUE);

    self::assertCount(1, $partition['managed']);
    self::assertSame('theme_backend.yml', $partition['managed'][0]['filename']);
    self::assertCount(1, $partition['watched']);
    self::assertSame('menubar_color.yml', $partition['watched'][0]['filename']);
  }

  public function testSavingScopePrunesResolvedSelectorsThatAreNoLongerSelected(): void {
    \Civi::settings()->set('civicfg_scope_resolved', [
      'scheduled-jobs' => [
        '10' => 'scheduled-jobs|Job|name=job_one',
        '20' => 'scheduled-jobs|Job|name=job_two',
      ],
      'message-templates' => [
        '12' => 'message-templates|workflow_name=receipt|is_default=0',
      ],
    ]);

    $scope = new ConfigScope();
    $scope->savePolicies([
      'scheduled-jobs' => [
        'mode' => 'selected',
        'selectors' => ['20'],
      ],
      'message-templates' => [
        'mode' => 'watch',
      ],
    ]);

    self::assertSame([
      'scheduled-jobs' => [
        '20' => 'scheduled-jobs|Job|name=job_two',
      ],
    ], \Civi::settings()->get('civicfg_scope_resolved'));
  }

  public function testWatchModeNeverProducesManagedFiles(): void {
    \Civi::settings()->set('civicfg_scope', [
      'message-templates' => ['mode' => 'watch'],
    ]);
    $scope = new ConfigScope();
    $partition = $scope->partition('message-templates', [[
      'filename' => 'system/receipt.yml',
      'source_id' => 12,
      'data' => [
        'type' => 'message_template',
        'identity_key' => 'workflow_name=receipt|is_default=0',
        'identity_confidence' => 'API_VERIFIED',
        'template' => ['workflow_name' => 'receipt', 'is_default' => FALSE],
      ],
    ]]);

    self::assertSame([], $partition['managed']);
    self::assertCount(1, $partition['watched']);
    self::assertFalse($scope->isManagedType('message-templates'));
    self::assertTrue($scope->isWatchedType('message-templates'));
  }

  public function testCivicrmSettingsOverrideIsDetected(): void {
    $GLOBALS['civicrm_setting'] = [
      'domain' => [
        'civicfg_scope' => [
          'message-templates' => ['mode' => 'selected', 'selectors' => ['12']],
        ],
      ],
    ];

    $scope = new ConfigScope();
    self::assertTrue($scope->isPolicyOverridden());
    self::assertSame('selected', $scope->getPolicy('message-templates')['mode']);
    self::assertSame(['12'], $scope->getPolicy('message-templates')['selectors']);
  }

  private function settingFile(string $name, $value): array {
    return [
      'filename' => $name . '.yml',
      'data' => [
        'schema_version' => 1,
        'type' => 'setting.item',
        'name' => $name,
        'identity_field' => 'name',
        'identity_confidence' => 'EXPLICIT',
        'item' => ['name' => $name, 'value' => $value],
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
}
