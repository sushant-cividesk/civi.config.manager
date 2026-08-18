<?php

declare(strict_types=1);

use Civi\ConfigManager\Handler\ExtensionHandler;
use Civi\ConfigManager\Service\ConfigManager;
use Civi\ConfigManager\Util\SimpleYaml;

final class CivicfgFullRealFixtures {
  private string $runId;
  private string $root;
  private string $syncDir;
  private string $artifactDir;
  private array $created = [];
  private array $settingsBackup = [];
  private array $results = [];
  private bool $allowMissingExtensions;

  public function __construct() {
    $rawRunId = getenv('CIVICFG_QA_RUN_ID') ?: ('local-' . bin2hex(random_bytes(4)));
    $this->runId = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $rawRunId) ?: 'qa-run';
    $this->root = getenv('CIVICFG_QA_ROOT') ?: (sys_get_temp_dir() . '/civicfg-qa-' . $this->runId);
    $this->syncDir = $this->root . '/full-sync';
    $this->artifactDir = getenv('CIVICFG_QA_ARTIFACTS') ?: ($this->root . '/artifacts');
    $this->allowMissingExtensions = (getenv('CIVICFG_ALLOW_MISSING_FIXTURE_EXTENSIONS') === 'true');
  }

  public function run(): void {
    $started = microtime(TRUE);
    $failure = NULL;
    $completed = FALSE;

    try {
      $this->prepareIsolation();
      $this->testScheduledJobRoundTripAndRevert();
      $this->testDedupeRuleGroupRoundTrip();
      $this->testContactTypeRoundTrip();
      $this->testFieldLevelSettingIgnoreAndRevert();
      $this->testRealExtensionDiscoveryAndIdempotency();
      $this->testFullExportImportIdempotency();
      $completed = TRUE;
    }
    catch (Throwable $e) {
      $failure = $e;
      $this->results['failure'] = $e->getMessage();
    }
    finally {
      try {
        $this->snapshotArtifacts();
      }
      catch (Throwable $e) {
        $this->results['artifact_errors'][] = $e->getMessage();
        $failure = $failure ?? $e;
      }

      $this->cleanup();
      if (!empty($this->results['cleanup_errors'])) {
        $failure = $failure ?? new RuntimeException('The full fixture cleanup reported errors.');
      }

      $this->results['run_id'] = $this->runId;
      $this->results['ok'] = $completed && $failure === NULL;
      $this->results['duration_seconds'] = round(microtime(TRUE) - $started, 3);
      $this->writeSummaryArtifact();
    }

    if (empty($this->results['ok'])) {
      throw new RuntimeException('Configuration Manager full real-fixture suite failed.', 0, $failure);
    }

    echo json_encode($this->results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
  }

  private function prepareIsolation(): void {
    $this->removeDirectory($this->syncDir);
    $this->ensureDirectory($this->syncDir);
    $this->ensureDirectory($this->artifactDir);

    foreach ([
      'civicfg_sync_dir',
      'civicfg_scope_default_mode',
      'civicfg_scope',
      'civicfg_scope_resolved',
      'civicfg_last_health',
      'civicfg_watch_summary',
      'civicfg_watch_history',
      'civicfg_ignore_paths',
      'civicfg_ignore_values',
      'civicfg_settings_allowlist',
      'civicfg_allow_cross_site_import',
    ] as $setting) {
      $this->settingsBackup[$setting] = \Civi::settings()->get($setting);
    }

    \Civi::settings()->set('civicfg_sync_dir', $this->syncDir);
    \Civi::settings()->set('civicfg_scope_default_mode', 'all');
    \Civi::settings()->set('civicfg_scope', []);
    \Civi::settings()->set('civicfg_scope_resolved', []);
    \Civi::settings()->set('civicfg_last_health', []);
    \Civi::settings()->set('civicfg_watch_summary', []);
    \Civi::settings()->set('civicfg_watch_history', []);
    \Civi::settings()->set('civicfg_ignore_paths', []);
    \Civi::settings()->set('civicfg_ignore_values', []);
    \Civi::settings()->set('civicfg_allow_cross_site_import', FALSE);

    $this->assertSame($this->syncDir, (new ConfigManager())->getSyncDir(), 'Full QA must use the isolated sync directory.');
    $this->record('isolation', ['sync_dir' => $this->syncDir, 'status' => 'passed']);
  }

  private function testScheduledJobRoundTripAndRevert(): void {
    $entity = 'Job';
    $this->requireApi4Entity($entity);
    $name = 'qa_civicfg_job_' . $this->safeRunId();
    $this->deleteApi4Rows($entity, [['name', '=', $name]]);

    $row = $this->api4Create($entity, [
      'name' => $name,
      'description' => 'QA scheduled job original ' . $this->runId,
      'api_entity' => 'Contact',
      'api_action' => 'get',
      'parameters' => '{"version":4,"limit":1}',
      'run_frequency' => 'Daily',
      'is_active' => TRUE,
    ]);
    $id = (int) ($row['id'] ?? 0);
    $this->created[] = ['entity' => $entity, 'id' => $id];

    $manager = new ConfigManager();
    $this->assertOk($manager->export(FALSE, ['scheduled-jobs']), 'Scheduled Job export must succeed.');
    $relativePath = 'scheduled-jobs/' . $name . '.yml';
    $this->assertTrue(is_file($this->syncDir . '/' . $relativePath), 'Scheduled Job split YAML must be written.');

    $this->api4Update($entity, [['id', '=', $id]], ['description' => 'QA scheduled job changed in CiviCRM']);
    $diff = $manager->diff(['scheduled-jobs']);
    $this->assertJsonContains($diff, $name, 'Scheduled Job change must be visible in diff.');

    $revert = $manager->revertCiviFromYaml($relativePath);
    $this->assertOk($revert, 'Scheduled Job per-file revert must succeed.');
    $restored = $this->api4GetFirst($entity, [['id', '=', $id]], ['description']);
    $this->assertSame('QA scheduled job original ' . $this->runId, (string) ($restored['description'] ?? ''), 'Scheduled Job revert must restore the YAML value.');

    $this->api4Delete($entity, [['id', '=', $id]]);
    $this->removeCreated($entity, $id);
    $deleteExport = $manager->export(FALSE, ['scheduled-jobs']);
    $this->assertOk($deleteExport, 'Scheduled Job stale YAML cleanup export must succeed.');
    $this->assertTrue(!is_file($this->syncDir . '/' . $relativePath), 'Export must remove stale Scheduled Job YAML after the DB record is deleted.');

    $this->record('scheduled_jobs', [
      'name' => $name,
      'revert' => 'passed',
      'stale_yaml_delete' => 'passed',
      'status' => 'passed',
    ]);
  }

  private function testDedupeRuleGroupRoundTrip(): void {
    $entity = 'DedupeRuleGroup';
    $this->requireApi4Entity($entity);
    $name = 'qa_civicfg_dedupe_' . $this->safeRunId();
    $this->deleteApi4Rows($entity, [['name', '=', $name]]);

    $row = $this->api4CreateWithFallback($entity, [
      [
        'name' => $name,
        'title' => 'QA Dedupe Rule Group ' . $this->runId,
        'contact_type' => 'Individual',
        'threshold' => 10,
        'used' => 'Unsupervised',
        'is_active' => TRUE,
        'is_reserved' => FALSE,
      ],
      [
        'name' => $name,
        'title' => 'QA Dedupe Rule Group ' . $this->runId,
        'contact_type' => 'Individual',
        'threshold' => 10,
        'used' => 'General',
        'is_active' => TRUE,
        'is_reserved' => FALSE,
      ],
    ]);
    $id = (int) ($row['id'] ?? 0);
    $this->created[] = ['entity' => $entity, 'id' => $id];

    $manager = new ConfigManager();
    $this->assertOk($manager->export(FALSE, ['dedupe-rules']), 'Dedupe Rule Group export must succeed.');
    $this->api4Update($entity, [['id', '=', $id]], ['title' => 'QA Dedupe changed in CiviCRM']);
    $this->assertJsonContains($manager->diff(['dedupe-rules']), $name, 'Dedupe Rule Group diff must include the changed fixture.');
    $this->assertOk($manager->import(FALSE, TRUE, ['dedupe-rules']), 'Dedupe Rule Group import must restore YAML.');
    $restored = $this->api4GetFirst($entity, [['id', '=', $id]], ['title']);
    $this->assertSame('QA Dedupe Rule Group ' . $this->runId, (string) ($restored['title'] ?? ''), 'Dedupe Rule Group import must restore the YAML title.');

    $this->record('dedupe_rules', ['name' => $name, 'status' => 'passed']);
  }

  private function testContactTypeRoundTrip(): void {
    $entity = 'ContactType';
    $this->requireApi4Entity($entity);
    $name = 'qa_civicfg_subtype_' . $this->safeRunId();
    $this->deleteApi4Rows($entity, [['name', '=', $name]]);
    $parent = $this->api4GetFirst($entity, [['name', '=', 'Individual']], ['id', 'name']);
    $this->assertTrue(!empty($parent['id']), 'Individual Contact Type must exist before subtype fixture creation.');

    $row = $this->api4Create($entity, [
      'name' => $name,
      'label' => 'QA Contact Type ' . $this->runId,
      'parent_id' => (int) $parent['id'],
      'description' => 'Disposable Contact Type fixture',
      'is_active' => TRUE,
      'is_reserved' => FALSE,
    ]);
    $id = (int) ($row['id'] ?? 0);
    $this->created[] = ['entity' => $entity, 'id' => $id];

    $manager = new ConfigManager();
    $this->assertOk($manager->export(FALSE, ['contact-types']), 'Contact Type export must succeed.');
    $this->api4Update($entity, [['id', '=', $id]], ['label' => 'QA Contact Type changed in CiviCRM']);
    $this->assertJsonContains($manager->diff(['contact-types']), $name, 'Contact Type diff must include the changed fixture.');
    $this->assertOk($manager->import(FALSE, TRUE, ['contact-types']), 'Contact Type import must restore YAML.');
    $restored = $this->api4GetFirst($entity, [['id', '=', $id]], ['label']);
    $this->assertSame('QA Contact Type ' . $this->runId, (string) ($restored['label'] ?? ''), 'Contact Type import must restore the YAML label.');

    $this->record('contact_types', ['name' => $name, 'status' => 'passed']);
  }

  private function testFieldLevelSettingIgnoreAndRevert(): void {
    $manager = new ConfigManager();
    \Civi::settings()->set('civicfg_settings_allowlist', ['theme_backend', 'menubar_color', 'menubar_position']);
    \Civi::settings()->set('theme_backend', 'default');
    \Civi::settings()->set('menubar_color', '#111111');
    \Civi::settings()->set('menubar_position', 'over-cms-menu');

    $this->assertOk($manager->export(FALSE, ['settings']), 'Settings export must succeed.');
    $relativePath = 'settings/menubar_color.yml';
    $this->assertTrue(is_file($this->syncDir . '/' . $relativePath), 'Individual setting YAML must be written.');

    \Civi::settings()->set('menubar_color', '#22cc55');
    $diff = $manager->diff(['settings']);
    $this->assertJsonContains($diff, 'menubar_color', 'Menubar color change must be visible before ignore.');

    $manager->addIgnoreValueRules($relativePath, ['item.value']);
    $ignoredDiff = $manager->diff(['settings']);
    $this->assertJsonNotContains($ignoredDiff, '#22cc55', 'Ignored menubar color value must not appear in diff output.');

    \Civi::settings()->set('civicfg_ignore_values', []);
    \Civi::settings()->set('menubar_color', '#44dd77');
    $this->assertOk($manager->revertCiviFromYaml($relativePath), 'Settings revert must succeed.');
    $this->assertSame('#111111', (string) \Civi::settings()->get('menubar_color'), 'Settings revert must restore menubar_color from YAML.');

    $this->record('settings_ignore_revert', ['field' => 'menubar_color', 'status' => 'passed']);
  }

  private function testRealExtensionDiscoveryAndIdempotency(): void {
    $expected = $this->expectedFixtureExtensionKeys();
    $manager = new ConfigManager();
    $extensionStatuses = [];

    foreach ($expected as $key) {
      $status = $this->extensionStatus($key);
      $extensionStatuses[$key] = $status;
      if (!in_array($status, ['installed', 'enabled'], TRUE)) {
        if ($this->allowMissingExtensions) {
          $this->results['extension_fixture_warnings'][] = 'Missing fixture extension: ' . $key;
          continue;
        }
        throw new RuntimeException('Required fixture extension is not installed/enabled: ' . $key . ' status=' . $status);
      }
    }

    $this->seedKnownExtensionFixtures();
    $this->assertOk($manager->export(FALSE, ['extensions', 'civirules']), 'Extension configuration export must succeed.');

    $compatibility = (new ExtensionHandler())->getCompatibilityReport();

    if (in_array($extensionStatuses['de.systopia.sqltasks'] ?? '', ['installed', 'enabled'], TRUE)) {
      $sqltasksYaml = SimpleYaml::parseFile($this->syncDir . '/extensions/de.systopia.sqltasks.yml');
      $this->assertTrue(
        array_key_exists('sqltask_export_append_scripts', (array) ($sqltasksYaml['settings'] ?? [])),
        'SQLTasks singular sqltask_* setting namespace must be exported.'
      );

      $sqltaskProviders = (array) ($compatibility['de.systopia.sqltasks']['providers'] ?? []);
      $sqltaskProvider = NULL;

      // SQLTasks 3.0.0-alpha3 exposes a native API4 SqlTask entity. The
      // contributed-provider engine intentionally prefers API4 and suppresses
      // the equivalent API3 provider. Keep the reviewed API3/BAO path only as
      // the compatibility fallback for installations without API4 SqlTask.
      foreach (['api4', 'api3'] as $preferredApi) {
        foreach ($sqltaskProviders as $provider) {
          $provider = (array) $provider;
          if (strtolower((string) ($provider['api'] ?? '')) === $preferredApi && strtolower((string) ($provider['entity'] ?? '')) === 'sqltask') {
            $sqltaskProvider = $provider;
            break 2;
          }
        }
      }

      $sqltaskProviderSummary = array_map(static function($provider): array {
        $provider = (array) $provider;
        return [
          'api' => (string) ($provider['api'] ?? ''),
          'entity' => (string) ($provider['entity'] ?? ''),
          'read_adapter' => (string) ($provider['read_adapter'] ?? ''),
          'can_create' => !empty($provider['can_create']),
          'can_delete' => !empty($provider['can_delete']),
          'importable' => !empty($provider['importable']),
          'error' => (string) ($provider['error'] ?? ''),
        ];
      }, $sqltaskProviders);
      $this->assertTrue(
        is_array($sqltaskProvider),
        'SQLTasks SqlTask provider must be discovered. Providers seen: ' . json_encode($sqltaskProviderSummary, JSON_UNESCAPED_SLASHES)
      );
      $this->assertTrue(!empty($sqltaskProvider['can_create']), 'SQLTasks SqlTask provider must expose create for cross-environment task restore.');
      $this->assertTrue(!empty($sqltaskProvider['can_delete']), 'SQLTasks SqlTask provider must expose delete for cross-environment task restore.');
      $this->assertTrue(!empty($sqltaskProvider['importable']), 'SQLTasks SqlTask provider must remain importable when its portable identity is safe.');

      $selectedApi = strtolower((string) ($sqltaskProvider['api'] ?? ''));
      if (class_exists('Civi\\Api4\\SqlTask')) {
        $this->assertSame('api4', $selectedApi, 'SQLTasks native API4 SqlTask provider must be preferred when available.');
      }
      elseif ($selectedApi === 'api3') {
        $this->assertSame('', trim((string) ($sqltaskProvider['list_action'] ?? '')), 'SQLTasks API3 fallback must not mistake single-record get for a collection action.');
        $this->assertSame('sqltasks_bao_generator', (string) ($sqltaskProvider['read_adapter'] ?? ''), 'SQLTasks API3 fallback must use the reviewed read-only BAO collection adapter.');
      }

      $this->record('sqltasks_provider', [
        'api' => (string) ($sqltaskProvider['api'] ?? ''),
        'entity' => (string) ($sqltaskProvider['entity'] ?? ''),
        'can_create' => !empty($sqltaskProvider['can_create']),
        'can_delete' => !empty($sqltaskProvider['can_delete']),
        'importable' => !empty($sqltaskProvider['importable']),
        'status' => 'passed',
      ]);
    }

    $coverage = [];
    foreach ($expected as $key) {
      if (!in_array($extensionStatuses[$key] ?? '', ['installed', 'enabled'], TRUE)) {
        continue;
      }
      $files = $this->findExtensionYamlFiles($key);
      $classification = (string) ($compatibility[$key]['classification'] ?? 'ERROR');
      if (!in_array($classification, ['FULL', 'PARTIAL', 'NO_PORTABLE_CONFIG', 'UNSUPPORTED'], TRUE)) {
        throw new RuntimeException('Invalid contrib compatibility classification for ' . $key . ': ' . $classification);
      }

      $providers = array_values((array) ($compatibility[$key]['providers'] ?? []));
      foreach ($providers as $provider) {
        $provider = (array) $provider;
        $records = (int) ($provider['records'] ?? 0);
        if (empty($provider['importable']) || $records < 1) {
          continue;
        }

        $api = trim((string) ($provider['api'] ?? ''));
        $entity = trim((string) ($provider['entity'] ?? ''));
        if ($api === '' || $entity === '') {
          throw new RuntimeException('Contrib compatibility provider for ' . $key . ' is missing API/entity metadata.');
        }

        $providerFiles = glob(
          $this->syncDir . '/extensions/' . $key . '/' . $api . '/' . $entity . '/*.yml'
        ) ?: [];
        $this->assertTrue(
          (bool) $providerFiles,
          sprintf(
            'Discovered contrib provider %s %s.%s reports %d record(s) but produced no YAML.',
            $key,
            $api,
            $entity,
            $records
          )
        );
      }

      $coverage[$key] = [
        'classification' => $classification,
        'classification_basis' => (string) ($compatibility[$key]['classification_basis'] ?? 'error'),
        'settings_count' => (int) ($compatibility[$key]['settings_count'] ?? 0),
        'providers' => $providers,
        'yaml_files' => array_values($files),
      ];
    }

    $allYaml = $this->listRelativeYamlFiles();
    foreach ($allYaml as $file) {
      if (strpos($file, 'MosaicoBaseTemplate') !== FALSE) {
        throw new RuntimeException('Generated MosaicoBaseTemplate YAML must not be exported: ' . $file);
      }
    }

    $preview = $manager->import(TRUE, FALSE, ['extensions', 'civirules']);
    $this->assertOk($preview, 'Extension configuration import preview must succeed.');
    $apply = $manager->import(FALSE, TRUE, ['extensions', 'civirules']);
    $this->assertOk($apply, 'Extension configuration import must succeed.');
    $second = $manager->import(TRUE, FALSE, ['extensions', 'civirules']);
    $this->assertOk($second, 'Second extension configuration import preview must be safe.');

    $this->record('real_extension_fixtures', [
      'extensions' => $coverage,
      'statuses' => $extensionStatuses,
      'status' => 'passed',
    ]);
  }

  private function seedKnownExtensionFixtures(): void {
    if ($this->extensionStatus('uk.co.vedaconsulting.mosaico') !== '') {
      $this->tryCreateMosaicoTemplateFixture();
    }
    if ($this->extensionStatus('de.systopia.sqltasks') !== '') {
      $this->seedSqltasksSettingsFixture();
    }
  }

  private function tryCreateMosaicoTemplateFixture(): void {
    if (!class_exists('Civi\\Api4\\MosaicoTemplate')) {
      $this->results['mosaico_fixture'][] = 'MosaicoTemplate API4 class is not available; export-only discovery will still be tested.';
      return;
    }
    $title = 'QA Mosaico Template ' . $this->runId;
    $variants = [
      ['title' => $title, 'is_active' => TRUE],
      ['title' => $title, 'name' => 'qa_mosaico_' . $this->safeRunId(), 'is_active' => TRUE],
      ['title' => $title, 'name' => 'qa_mosaico_' . $this->safeRunId(), 'base' => 'tedc15', 'is_active' => TRUE],
    ];
    foreach ($variants as $values) {
      try {
        $row = $this->api4Create('MosaicoTemplate', $values);
        if (!empty($row['id'])) {
          $this->created[] = ['entity' => 'MosaicoTemplate', 'id' => (int) $row['id']];
          $this->record('mosaico_template_fixture', ['title' => $title, 'status' => 'created']);
          return;
        }
      }
      catch (Throwable $e) {
        $last = $e->getMessage();
      }
    }
    $message = 'Could not create a disposable MosaicoTemplate fixture: ' . ($last ?? 'unknown error');
    if ($this->allowMissingExtensions) {
      $this->results['mosaico_fixture'][] = $message;
      return;
    }
    throw new RuntimeException($message);
  }

  private function seedSqltasksSettingsFixture(): void {
    try {
      foreach (['sqltasks_default_template', 'sqltask_export_append_scripts', 'sqltasks_global_tokens'] as $setting) {
        if (!array_key_exists($setting, $this->settingsBackup)) {
          $this->settingsBackup[$setting] = \Civi::settings()->get($setting);
        }
      }
      \Civi::settings()->set('sqltasks_default_template', 'qa-template-' . $this->safeRunId());
      \Civi::settings()->set('sqltask_export_append_scripts', TRUE);
      $tokens = (array) \Civi::settings()->get('sqltasks_global_tokens');
      $tokens['qa_civicfg_' . $this->safeRunId()] = 'qa-token-' . $this->runId;
      \Civi::settings()->set('sqltasks_global_tokens', $tokens);
      $this->record('sqltasks_settings_fixture', ['status' => 'created']);
    }
    catch (Throwable $e) {
      if (!$this->allowMissingExtensions) {
        throw new RuntimeException('Could not seed SQLTasks settings fixture: ' . $e->getMessage(), 0, $e);
      }
      $this->results['sqltasks_fixture'][] = $e->getMessage();
    }
  }

  private function testFullExportImportIdempotency(): void {
    $manager = new ConfigManager();
    $this->assertOk($manager->export(FALSE, []), 'Full export must succeed.');
    $this->assertOk($manager->validate([]), 'Full validation must succeed after export.');
    $preview = $manager->import(TRUE, FALSE, []);
    $this->assertOk($preview, 'Full import preview after export must succeed.');
    $apply = $manager->import(FALSE, TRUE, []);
    $this->assertOk($apply, 'Full import after export must succeed.');
    $second = $manager->import(TRUE, FALSE, []);
    $this->assertOk($second, 'Second full import preview must succeed.');

    $diff = $manager->diff([]);
    $changed = (int) ($diff['summary']['changed'] ?? $diff['changed'] ?? 0);
    $inCivi = (int) ($diff['summary']['in_civicrm'] ?? $diff['in_civicrm'] ?? 0);
    $inYaml = (int) ($diff['summary']['in_yaml'] ?? $diff['in_yaml'] ?? 0);
    $this->assertSame(0, $changed + $inCivi + $inYaml, 'Full export/import should be idempotent with no remaining differences.');

    $this->record('full_export_import_idempotency', ['remaining_differences' => 0, 'status' => 'passed']);
  }

  private function expectedFixtureExtensionKeys(): array {
    $default = implode(' ', [
      'user_dashboard',
      'search_kit_reports',
      'legacydedupefinder',
      'org.civicrm.afform',
      'civigrant',
      'civi_case',
      'civi_campaign',
      'uk.co.vedaconsulting.mosaico',
      'org.civicoop.civirules',
      'mjwshared',
      'sweetalert',
      'firewall',
      'org.civicrm.contactlayout',
      'com.drastikbydesign.stripe',
      'nz.co.fuzion.csvimport',
      'org.civicrm.module.cividiscount',
      'org.civicrm.recentmenu',
      'theisland',
      'org.civicrm.cdntaxreceipts',
      'org.wikimedia.geocoder',
      'easycopy',
      'com.agiliway.civimobileapi',
      'com.ixiam.modules.reportplus',
      'com.donordepot.authnetecheck',
      'de.systopia.donrec',
      'advimport',
      'invoicehelper',
      'org.civicrm.multisite',
      'formprotection',
      'com.osseed.eventcalendar',
      'uk.co.compucorp.membershipextras',
      'radiobuttons',
      'com.skvare.cmsuser',
      'ca.civicrm.contributionrecur',
      'de.systopia.campaign',
      'membershipreport',
      'com.skvare.crontab',
      'casesummary',
      'coop.symbiotic.floodcontrol',
      'de.systopia.sqltasks',
    ]);
    $raw = getenv('CIVICFG_QA_FIXTURE_EXTENSION_KEYS') ?: $default;
    return array_values(array_filter(preg_split('/[\s,]+/', trim($raw)) ?: []));
  }

  private function extensionStatus(string $key): string {
    try {
      $result = civicrm_api3('Extension', 'get', ['sequential' => 1, 'key' => $key, 'options' => ['limit' => 1]]);
      $values = array_values((array) ($result['values'] ?? []));
      return strtolower((string) ($values[0]['status'] ?? ''));
    }
    catch (Throwable $e) {
      return '';
    }
  }

  private function findExtensionYamlFiles(string $key): array {
    $matches = [];
    foreach ($this->listRelativeYamlFiles() as $file) {
      if ($file === 'extensions/' . $key . '.yml' || strpos($file, 'extensions/' . $key . '/') === 0) {
        $matches[] = $file;
      }
    }
    sort($matches, SORT_NATURAL | SORT_FLAG_CASE);
    return $matches;
  }

  private function listRelativeYamlFiles(): array {
    $files = [];
    if (!is_dir($this->syncDir)) {
      return [];
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->syncDir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
      if ($file instanceof SplFileInfo && $file->isFile() && strtolower($file->getExtension()) === 'yml') {
        $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($this->syncDir) + 1));
      }
    }
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);
    return $files;
  }

  private function requireApi4Entity(string $entity): void {
    $class = 'Civi\\Api4\\' . $entity;
    if (!class_exists($class)) {
      throw new RuntimeException('Required API4 entity is unavailable: ' . $entity);
    }
  }

  private function api4CreateWithFallback(string $entity, array $variants): array {
    $last = NULL;
    foreach ($variants as $values) {
      try {
        return $this->api4Create($entity, (array) $values);
      }
      catch (Throwable $e) {
        $last = $e;
      }
    }
    throw new RuntimeException('Could not create ' . $entity . ' fixture: ' . ($last ? $last->getMessage() : 'no variants'), 0, $last);
  }

  private function api4Create(string $entity, array $values): array {
    $class = 'Civi\\Api4\\' . $entity;
    $this->requireApi4Entity($entity);
    $action = $class::create(FALSE);
    foreach ($values as $field => $value) {
      $action->addValue((string) $field, $value);
    }
    $rows = (array) $action->execute();
    return (array) ($rows[0] ?? []);
  }

  private function api4Update(string $entity, array $where, array $values): void {
    $class = 'Civi\\Api4\\' . $entity;
    $this->requireApi4Entity($entity);
    $action = $class::update(FALSE);
    foreach ($where as $condition) {
      $action->addWhere(...$condition);
    }
    foreach ($values as $field => $value) {
      $action->addValue((string) $field, $value);
    }
    $action->execute();
  }

  private function api4Delete(string $entity, array $where): void {
    $class = 'Civi\\Api4\\' . $entity;
    if (!class_exists($class)) {
      return;
    }
    $action = $class::delete(FALSE);
    foreach ($where as $condition) {
      $action->addWhere(...$condition);
    }
    $action->execute();
  }

  private function deleteApi4Rows(string $entity, array $where): void {
    $rows = $this->api4Get($entity, $where, ['id']);
    foreach ($rows as $row) {
      if (!empty($row['id'])) {
        $this->api4Delete($entity, [['id', '=', (int) $row['id']]]);
      }
    }
  }

  private function api4Get(string $entity, array $where = [], array $select = ['*']): array {
    $class = 'Civi\\Api4\\' . $entity;
    $this->requireApi4Entity($entity);
    $action = $class::get(FALSE)->addSelect(...$select);
    foreach ($where as $condition) {
      $action->addWhere(...$condition);
    }
    return (array) $action->execute();
  }

  private function api4GetFirst(string $entity, array $where, array $select = ['*']): array {
    $rows = $this->api4Get($entity, $where, $select);
    return (array) ($rows[0] ?? []);
  }

  private function removeCreated(string $entity, int $id): void {
    foreach ($this->created as $index => $record) {
      if (($record['entity'] ?? '') === $entity && (int) ($record['id'] ?? 0) === $id) {
        unset($this->created[$index]);
      }
    }
  }

  private function assertOk(array $result, string $message): void {
    if (empty($result['ok'])) {
      throw new RuntimeException($message . ' Result: ' . json_encode($result, JSON_UNESCAPED_SLASHES));
    }
  }

  private function assertJsonContains(array $data, string $needle, string $message): void {
    $json = (string) json_encode($data, JSON_UNESCAPED_SLASHES);
    if (strpos($json, $needle) === FALSE) {
      throw new RuntimeException($message . ' Missing text: ' . $needle);
    }
  }

  private function assertJsonNotContains(array $data, string $needle, string $message): void {
    $json = (string) json_encode($data, JSON_UNESCAPED_SLASHES);
    if (strpos($json, $needle) !== FALSE) {
      throw new RuntimeException($message . ' Unexpected text: ' . $needle);
    }
  }

  private function assertTrue(bool $condition, string $message): void {
    if (!$condition) {
      throw new RuntimeException($message);
    }
  }

  private function assertSame($expected, $actual, string $message): void {
    if ($expected !== $actual) {
      throw new RuntimeException($message . ' Expected ' . var_export($expected, TRUE) . ', got ' . var_export($actual, TRUE));
    }
  }

  private function safeRunId(): string {
    $safe = strtolower(preg_replace('/[^a-zA-Z0-9_]+/', '_', $this->runId) ?: 'qa');
    return trim($safe, '_') ?: 'qa';
  }

  private function record(string $name, array $data): void {
    $this->results['tests'][$name] = $data;
  }

  private function snapshotArtifacts(): void {
    $this->ensureDirectory($this->artifactDir);
    $snapshot = $this->artifactDir . '/full-sync-snapshot';
    $this->removeDirectory($snapshot);
    if (is_dir($this->syncDir)) {
      $this->copyDirectory($this->syncDir, $snapshot);
    }
  }

  private function writeSummaryArtifact(): void {
    $this->ensureDirectory($this->artifactDir);
    file_put_contents($this->artifactDir . '/full-real-fixtures-summary.json', json_encode($this->results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
  }

  private function cleanup(): void {
    foreach (array_reverse($this->created) as $record) {
      try {
        if (!empty($record['entity']) && !empty($record['id'])) {
          $this->api4Delete((string) $record['entity'], [['id', '=', (int) $record['id']]]);
        }
      }
      catch (Throwable $e) {
        $this->results['cleanup_errors'][] = (string) $record['entity'] . '#' . (string) $record['id'] . ': ' . $e->getMessage();
      }
    }
    foreach ($this->settingsBackup as $name => $value) {
      try {
        \Civi::settings()->set((string) $name, $value);
      }
      catch (Throwable $e) {
        $this->results['cleanup_errors'][] = 'Setting ' . $name . ': ' . $e->getMessage();
      }
    }
    $this->removeDirectory($this->syncDir);
  }

  private function ensureDirectory(string $directory): void {
    if (!is_dir($directory) && !mkdir($directory, 0775, TRUE) && !is_dir($directory)) {
      throw new RuntimeException('Could not create directory: ' . $directory);
    }
  }

  private function copyDirectory(string $source, string $destination): void {
    $this->ensureDirectory($destination);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $item) {
      $target = $destination . '/' . substr($item->getPathname(), strlen($source) + 1);
      if ($item->isDir()) {
        $this->ensureDirectory($target);
      }
      elseif (!$item->isLink()) {
        copy($item->getPathname(), $target);
      }
    }
  }

  private function removeDirectory(string $path): void {
    if (!file_exists($path) && !is_link($path)) {
      return;
    }
    if (is_file($path) || is_link($path)) {
      @unlink($path);
      return;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
      if ($item->isDir() && !$item->isLink()) {
        @rmdir($item->getPathname());
      }
      else {
        @unlink($item->getPathname());
      }
    }
    @rmdir($path);
  }
}

(new CivicfgFullRealFixtures())->run();
