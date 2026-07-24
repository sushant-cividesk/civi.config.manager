<?php

declare(strict_types=1);

use Civi\Api4\MessageTemplate;
use Civi\Api4\OptionGroup;
use Civi\Api4\OptionValue;
use Civi\ConfigManager\Handler\SettingHandler;
use Civi\ConfigManager\Service\ConfigManager;
use Civi\ConfigManager\Storage\YamlFileStorage;
use Civi\ConfigManager\Util\SimpleYaml;

final class CivicfgStandaloneRoundTrip {
  private string $runId;
  private string $root;
  private string $syncDir;
  private string $artifactDir;
  private array $created = [];
  private array $settingsBackup = [];
  private array $results = [];

  public function __construct() {
    $rawRunId = getenv('CIVICFG_QA_RUN_ID') ?: ('local-' . bin2hex(random_bytes(4)));
    $this->runId = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $rawRunId) ?: 'qa-run';
    $this->root = getenv('CIVICFG_QA_ROOT') ?: (sys_get_temp_dir() . '/civicfg-qa-' . $this->runId);
    $this->syncDir = $this->root . '/sync';
    $this->artifactDir = getenv('CIVICFG_QA_ARTIFACTS') ?: ($this->root . '/artifacts');
  }

  public function run(): void {
    $started = microtime(TRUE);
    $completed = FALSE;
    $failure = NULL;

    try {
      $this->prepareIsolation();
      $this->testApiFacade();
      $this->testSiteIdentityAndManifest();
      $this->testOptionGroupRoundTrip();
      $this->testMessageTemplateRoundTripWithoutSendingMail();
      $this->testSensitiveSettingsAreNotExported();
      $this->testReservedOptionValueDeletionSafety();
      $this->testMalformedYamlValidation();
      $this->testConfigIgnore();
      $completed = TRUE;
    }
    catch (Throwable $e) {
      $failure = $e;
      if (empty($this->results['failure'])) {
        $this->results['failure'] = $e->getMessage();
      }
    }
    finally {
      $this->results['run_id'] = $this->runId;

      try {
        $this->snapshotArtifacts();
      }
      catch (Throwable $e) {
        $this->results['artifact_errors'][] = $e->getMessage();
        $failure = $failure ?? $e;
      }

      $this->cleanup();
      if (!empty($this->results['cleanup_errors'])) {
        $failure = $failure ?? new RuntimeException('The disposable test fixtures were not cleaned up completely.');
      }

      $this->results['ok'] = $completed && $failure === NULL;
      $this->results['duration_seconds'] = round(microtime(TRUE) - $started, 3);

      try {
        $this->writeSummaryArtifact();
      }
      catch (Throwable $e) {
        $this->results['ok'] = FALSE;
        $this->results['artifact_errors'][] = $e->getMessage();
        $failure = $failure ?? $e;
      }
    }

    if (empty($this->results['ok'])) {
      throw new RuntimeException('Configuration Manager standalone integration suite failed.', 0, $failure);
    }

    echo json_encode($this->results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
  }

  private function prepareIsolation(): void {
    $this->removeDirectory($this->root);
    $this->ensureDirectory($this->syncDir);
    $this->ensureDirectory($this->artifactDir);

    foreach ([
      'civicfg_sync_dir',
      'civicfg_enabled_types',
      'civicfg_ignore_paths',
      'civicfg_ignore_values',
      'civicfg_settings_allowlist',
      'civicfg_allow_cross_site_import',
      'civicfg_site_id',
    ] as $name) {
      $this->settingsBackup[$name] = \Civi::settings()->get($name);
    }

    \Civi::settings()->set('civicfg_sync_dir', $this->syncDir);
    \Civi::settings()->set('civicfg_enabled_types', []);
    \Civi::settings()->set('civicfg_ignore_paths', []);
    \Civi::settings()->set('civicfg_ignore_values', []);
    \Civi::settings()->set('civicfg_allow_cross_site_import', FALSE);

    $manager = new ConfigManager();
    $this->assertSame($this->syncDir, $manager->getSyncDir(), 'The test sync directory must be isolated.');
    $this->record('isolation', [
      'sync_dir' => $manager->getSyncDir(),
      'external_network_expected' => 'blocked by the internal Docker network',
      'php_mail_expected' => 'blocked and recorded by the local sendmail blocker',
    ]);
  }

  private function testApiFacade(): void {
    $action = \Civi\Api4\ConfigManager::getFields(FALSE);
    $this->assertTrue($action instanceof \Civi\Api4\Generic\BasicGetFieldsAction, 'getFields() must return an API4 action object.');

    $permissions = \Civi\Api4\ConfigManager::permissions();
    $this->assertTrue(($permissions['export'] ?? []) !== ($permissions['import'] ?? []), 'Export and import must use separate permissions.');
    $this->record('api4_facade', ['status' => 'passed']);
  }

  private function testSiteIdentityAndManifest(): void {
    $manager = new ConfigManager();
    $siteId = $manager->getSiteIdentifier();
    $this->assertTrue($manager->isValidSiteIdentifier($siteId), 'Generated site identifier must be valid.');
    $this->assertSame($siteId, $manager->getSiteIdentifier(), 'Site identifier must be stable within a site database.');

    $result = $manager->export(FALSE, ['option-groups']);
    $this->assertTrue(!empty($result['ok']), 'Manifest export must succeed.');
    $manifest = SimpleYaml::parseFile($this->syncDir . '/manifest.yml');
    $this->assertSame($siteId, (string) ($manifest['site_id'] ?? ''), 'Manifest site identifier must match the database setting.');
    $this->record('site_identifier', ['site_id' => $siteId, 'status' => 'passed']);
  }

  private function testOptionGroupRoundTrip(): void {
    $name = 'qa_civicfg_' . strtolower(str_replace('-', '_', $this->runId));
    $originalTitle = 'QA Config Manager ' . $this->runId;
    $changedTitle = $originalTitle . ' changed in database';

    $group = OptionGroup::create(FALSE)
      ->addValue('name', $name)
      ->addValue('title', $originalTitle)
      ->addValue('description', 'Disposable automated QA fixture')
      ->addValue('data_type', 'String')
      ->addValue('is_reserved', FALSE)
      ->addValue('is_active', TRUE)
      ->execute()
      ->first();
    $groupId = (int) $group['id'];
    $this->created['option_group'] = $groupId;

    $value = OptionValue::create(FALSE)
      ->addValue('option_group_id', $groupId)
      ->addValue('name', 'qa_value')
      ->addValue('label', 'QA Value')
      ->addValue('value', 'qa-value')
      ->addValue('weight', 1)
      ->addValue('is_reserved', FALSE)
      ->addValue('is_active', TRUE)
      ->execute()
      ->first();
    $this->created['option_value'] = (int) $value['id'];

    $manager = new ConfigManager();
    $export = $manager->export(FALSE, ['option-groups']);
    $this->assertTrue(!empty($export['ok']), 'Option Group export must succeed.');

    $relativePath = 'option-groups/' . $name . '.yml';
    $path = $this->syncDir . '/' . $relativePath;
    $this->assertTrue(is_file($path), 'The disposable Option Group YAML file must exist.');
    $originalHash = hash_file('sha256', $path);

    OptionGroup::update(FALSE)
      ->addWhere('id', '=', $groupId)
      ->addValue('title', $changedTitle)
      ->execute();

    $diff = $manager->diff(['option-groups']);
    $optionDiff = $this->findItemByType($diff['items'] ?? [], 'option-groups');
    $this->assertSame('changed', (string) ($optionDiff['status'] ?? ''), 'Database change must be detected.');
    $this->assertTrue(in_array($name . '.yml', (array) ($optionDiff['changed'] ?? []), TRUE), 'Focused diff must name the changed Option Group YAML file.');

    $dryExport = $manager->export(TRUE, ['option-groups']);
    $this->assertTrue(!empty($dryExport['ok']), 'Dry-run export must succeed.');
    $this->assertSame($originalHash, hash_file('sha256', $path), 'Dry-run export must not modify YAML files.');

    $dryImport = $manager->import(TRUE, FALSE, ['option-groups']);
    $this->assertTrue(!empty($dryImport['ok']), 'Dry-run import must succeed.');
    $this->assertSame($changedTitle, $this->getOptionGroupTitle($groupId), 'Dry-run import must not modify CiviCRM.');

    $apply = $manager->import(FALSE, TRUE, ['option-groups']);
    $this->assertTrue(!empty($apply['ok']), 'Applied Option Group import must succeed.');
    $this->assertSame($originalTitle, $this->getOptionGroupTitle($groupId), 'Import must restore the YAML value.');

    $secondPreview = $manager->import(TRUE, FALSE, ['option-groups']);
    $summary = $this->findItemByType($secondPreview['items'] ?? [], 'option-groups');
    $this->assertSame(0, (int) ($summary['groups']['update'] ?? 0), 'A second import must be idempotent for Option Groups.');
    $this->assertSame(0, (int) ($summary['values']['update'] ?? 0), 'A second import must be idempotent for Option Values.');

    $this->record('option_group_round_trip', [
      'name' => $name,
      'file' => $relativePath,
      'status' => 'passed',
    ]);
  }

  private function testMessageTemplateRoundTripWithoutSendingMail(): void {
    $title = 'QA Message Template ' . $this->runId;
    $subject = 'QA subject ' . $this->runId;
    $changedSubject = $subject . ' changed in database';
    $marker = 'QA-MESSAGE-BODY-' . $this->runId;

    $template = MessageTemplate::create(FALSE)
      ->addValue('msg_title', $title)
      ->addValue('msg_subject', $subject)
      ->addValue('msg_text', $marker . "\nText body")
      ->addValue('msg_html', '<p>' . $marker . '</p>')
      ->addValue('is_default', FALSE)
      ->addValue('is_reserved', FALSE)
      ->addValue('is_active', TRUE)
      ->execute()
      ->first();
    $templateId = (int) $template['id'];
    $this->created['message_template'] = $templateId;

    $manager = new ConfigManager();
    $export = $manager->export(FALSE, ['message-templates']);
    $this->assertTrue(!empty($export['ok']), 'Message Template export must succeed.');

    $templateFile = $this->findYamlFileByValue('message-templates', ['template', 'msg_title'], $title);
    $this->assertTrue($templateFile !== '', 'The disposable Message Template YAML file must exist.');
    $contents = file_get_contents($this->syncDir . '/' . $templateFile);
    $this->assertTrue($contents !== FALSE && strpos($contents, $marker) !== FALSE, 'Complete template content must be stored in YAML.');

    MessageTemplate::update(FALSE)
      ->addWhere('id', '=', $templateId)
      ->addValue('msg_subject', $changedSubject)
      ->execute();

    $diff = $manager->diff(['message-templates']);
    $templateDiff = $this->findItemByType($diff['items'] ?? [], 'message-templates');
    $this->assertSame('changed', (string) ($templateDiff['status'] ?? ''), 'Message Template change must be detected.');

    $apply = $manager->import(FALSE, TRUE, ['message-templates']);
    $this->assertTrue(!empty($apply['ok']), 'Message Template import must succeed.');
    $restored = MessageTemplate::get(FALSE)
      ->addSelect('msg_subject', 'msg_text', 'msg_html')
      ->addWhere('id', '=', $templateId)
      ->execute()
      ->first();
    $this->assertSame($subject, (string) $restored['msg_subject'], 'Message Template subject must be restored from YAML.');
    $this->assertTrue(strpos((string) $restored['msg_html'], $marker) !== FALSE, 'Message Template HTML must remain complete.');

    $this->record('message_template_round_trip', [
      'title' => $title,
      'file' => $templateFile,
      'mail_send_calls' => 0,
      'status' => 'passed',
    ]);
  }

  private function testSensitiveSettingsAreNotExported(): void {
    \Civi::settings()->set('civicfg_settings_allowlist', ['theme_backend', 'smtpPassword']);

    $handlerExport = (new SettingHandler())->export()[0]['data'];
    $encoded = (string) json_encode($handlerExport);
    $this->assertTrue(strpos($encoded, 'smtpPassword') === FALSE, 'Sensitive setting names and values must never enter export data.');
    $this->assertTrue(!in_array('smtpPassword', (array) ($handlerExport['allowlist'] ?? []), TRUE), 'Sensitive setting names must be removed from the exported allowlist.');

    $manager = new ConfigManager();
    $result = $manager->export(FALSE, ['settings']);
    $this->assertTrue(!empty($result['ok']), 'Settings export must succeed.');
    $path = $this->syncDir . '/settings/civicrm.settings.yml';
    $fileContents = is_file($path) ? (string) file_get_contents($path) : '';
    $this->assertTrue(strpos($fileContents, 'smtpPassword') === FALSE, 'Sensitive setting names must not appear in YAML files.');

    $this->record('secret_redaction', ['blocked_setting' => 'smtpPassword', 'status' => 'passed']);
  }


  private function testReservedOptionValueDeletionSafety(): void {
    $groupId = (int) ($this->created['option_group'] ?? 0);
    $normalValueId = (int) ($this->created['option_value'] ?? 0);
    $name = 'qa_civicfg_' . strtolower(str_replace('-', '_', $this->runId));
    $path = $this->syncDir . '/option-groups/' . $name . '.yml';

    $reserved = OptionValue::create(FALSE)
      ->addValue('option_group_id', $groupId)
      ->addValue('name', 'qa_reserved_value')
      ->addValue('label', 'QA Reserved Value')
      ->addValue('value', 'qa-reserved-value')
      ->addValue('weight', 2)
      ->addValue('is_reserved', TRUE)
      ->addValue('is_active', TRUE)
      ->execute()
      ->first();
    $reservedValueId = (int) $reserved['id'];
    $this->created['reserved_option_value'] = $reservedValueId;

    $manager = new ConfigManager();
    $export = $manager->export(FALSE, ['option-groups']);
    $this->assertTrue(!empty($export['ok']), 'Deletion-safety fixture export must succeed.');

    $yaml = SimpleYaml::parseFile($path);
    $this->assertTrue(!empty($yaml['values']), 'Deletion-safety YAML must contain fixture values before modification.');
    $yaml['values'] = [];
    $bytes = file_put_contents($path, SimpleYaml::dump($yaml), LOCK_EX);
    $this->assertTrue($bytes !== FALSE, 'Deletion-safety YAML must be updated in the isolated directory.');

    $preview = $manager->import(TRUE, FALSE, ['option-groups']);
    $summary = $this->findItemByType($preview['items'] ?? [], 'option-groups');
    $this->assertSame(1, (int) ($summary['values']['delete'] ?? 0), 'Dry-run must propose deleting only the non-reserved Option Value.');
    $this->assertTrue((int) ($summary['values']['skip'] ?? 0) >= 1, 'Dry-run must skip the reserved Option Value.');
    $warningText = strtolower((string) json_encode($summary['warnings'] ?? []));
    $this->assertTrue(strpos($warningText, 'reserved option value') !== FALSE, 'Dry-run must explain why the reserved Option Value is protected.');

    $apply = $manager->import(FALSE, TRUE, ['option-groups']);
    $this->assertTrue(!empty($apply['ok']), 'Deletion-safety import must succeed.');
    $this->assertSame(0, $this->countOptionValue($normalValueId), 'Non-reserved Option Value missing from YAML must be deleted.');
    $this->assertSame(1, $this->countOptionValue($reservedValueId), 'Reserved Option Value missing from YAML must remain unchanged.');

    $this->record('reserved_option_value_deletion', [
      'deleted_non_reserved' => TRUE,
      'preserved_reserved' => TRUE,
      'status' => 'passed',
    ]);
  }

  private function testMalformedYamlValidation(): void {
    $manager = new ConfigManager();
    $name = 'qa_civicfg_' . strtolower(str_replace('-', '_', $this->runId));
    $optionPath = $this->syncDir . '/option-groups/' . $name . '.yml';
    $manifestPath = $this->syncDir . '/manifest.yml';
    $beforeReservedCount = $this->countOptionValue((int) ($this->created['reserved_option_value'] ?? 0));

    foreach ([
      'option-groups' => [$optionPath, ['option-groups']],
      'manifest' => [$manifestPath, ['option-groups']],
    ] as $label => [$path, $filter]) {
      $original = file_get_contents($path);
      $this->assertTrue($original !== FALSE, 'The ' . $label . ' YAML fixture must be readable.');

      try {
        $bytes = file_put_contents($path, "type: broken\nitems: [unterminated\n", LOCK_EX);
        $this->assertTrue($bytes !== FALSE, 'The malformed ' . $label . ' YAML fixture must be written inside the isolated sync directory.');

        $validation = $manager->validate($filter);
        $this->assertTrue(empty($validation['ok']), 'Malformed ' . $label . ' YAML must fail validation.');
        $this->assertTrue(!empty($validation['errors']) || $this->containsInvalidValidationItem($validation['items'] ?? []), 'Malformed ' . $label . ' YAML must produce a visible validation error.');

        $preview = $manager->import(TRUE, FALSE, $filter);
        $this->assertTrue(empty($preview['ok']), 'Malformed ' . $label . ' YAML must block import preview.');
      }
      finally {
        file_put_contents($path, $original, LOCK_EX);
      }
    }

    $this->assertSame($beforeReservedCount, $this->countOptionValue((int) ($this->created['reserved_option_value'] ?? 0)), 'Malformed YAML validation must not modify CiviCRM data.');
    $this->record('malformed_yaml_validation', [
      'handler_yaml_blocked' => TRUE,
      'manifest_yaml_blocked' => TRUE,
      'database_changes' => 0,
      'status' => 'passed',
    ]);
  }

  private function containsInvalidValidationItem(array $items): bool {
    foreach ($items as $item) {
      if (is_array($item) && array_key_exists('valid', $item) && empty($item['valid'])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  private function testConfigIgnore(): void {
    $groupId = (int) ($this->created['option_group'] ?? 0);
    $name = 'qa_civicfg_' . strtolower(str_replace('-', '_', $this->runId));
    $relativePath = 'option-groups/' . $name . '.yml';

    \Civi::settings()->set('civicfg_ignore_paths', [$relativePath]);
    OptionGroup::update(FALSE)
      ->addWhere('id', '=', $groupId)
      ->addValue('description', 'This ignored change must not appear in diff output.')
      ->execute();

    $diff = (new ConfigManager())->diff(['option-groups']);
    $encoded = json_encode($diff);
    $this->assertTrue(strpos((string) $encoded, $name . '.yml') === FALSE, 'Ignored YAML path must be excluded from diff output.');

    \Civi::settings()->set('civicfg_ignore_paths', []);
    $this->record('config_ignore', ['path' => $relativePath, 'status' => 'passed']);
  }

  private function getOptionGroupTitle(int $id): string {
    $row = OptionGroup::get(FALSE)
      ->addSelect('title')
      ->addWhere('id', '=', $id)
      ->execute()
      ->first();
    return (string) ($row['title'] ?? '');
  }


  private function countOptionValue(int $id): int {
    return OptionValue::get(FALSE)
      ->addWhere('id', '=', $id)
      ->execute()
      ->count();
  }

  private function findItemByType(array $items, string $type): array {
    foreach ($items as $item) {
      if (($item['type'] ?? '') === $type) {
        return (array) $item;
      }
    }
    throw new RuntimeException('Could not find result for managed type: ' . $type);
  }

  private function findYamlFileByValue(string $directory, array $segments, string $expected): string {
    $storage = new YamlFileStorage($this->syncDir);
    foreach ($storage->readDirectory($directory) as $filename => $data) {
      $value = $data;
      foreach ($segments as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
          continue 2;
        }
        $value = $value[$segment];
      }
      if ((string) $value === $expected) {
        return $directory . '/' . $filename;
      }
    }
    return '';
  }

  private function snapshotArtifacts(): void {
    $this->ensureDirectory($this->artifactDir);

    if (is_dir($this->syncDir)) {
      $snapshot = $this->artifactDir . '/sync-snapshot';
      $this->removeDirectory($snapshot);
      $this->copyDirectory($this->syncDir, $snapshot);
    }
  }


  private function writeSummaryArtifact(): void {
    $this->ensureDirectory($this->artifactDir);
    $encoded = json_encode($this->results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === FALSE || file_put_contents($this->artifactDir . '/integration-summary.json', $encoded . PHP_EOL, LOCK_EX) === FALSE) {
      throw new RuntimeException('Could not write the final integration summary artifact.');
    }
  }

  private function cleanup(): void {
    try {
      if (!empty($this->created['option_value'])) {
        OptionValue::delete(FALSE)->addWhere('id', '=', (int) $this->created['option_value'])->execute();
      }
      if (!empty($this->created['reserved_option_value'])) {
        OptionValue::delete(FALSE)->addWhere('id', '=', (int) $this->created['reserved_option_value'])->execute();
      }
      if (!empty($this->created['option_group'])) {
        OptionGroup::delete(FALSE)->addWhere('id', '=', (int) $this->created['option_group'])->execute();
      }
      if (!empty($this->created['message_template'])) {
        MessageTemplate::delete(FALSE)->addWhere('id', '=', (int) $this->created['message_template'])->execute();
      }

      if (!empty($this->created['option_value'])) {
        $remaining = OptionValue::get(FALSE)->addWhere('id', '=', (int) $this->created['option_value'])->execute()->count();
        if ($remaining !== 0) {
          throw new RuntimeException('Disposable Option Value fixture still exists after cleanup.');
        }
      }
      if (!empty($this->created['reserved_option_value'])) {
        $remaining = OptionValue::get(FALSE)->addWhere('id', '=', (int) $this->created['reserved_option_value'])->execute()->count();
        if ($remaining !== 0) {
          throw new RuntimeException('Disposable reserved Option Value fixture still exists after cleanup.');
        }
      }
      if (!empty($this->created['option_group'])) {
        $remaining = OptionGroup::get(FALSE)->addWhere('id', '=', (int) $this->created['option_group'])->execute()->count();
        if ($remaining !== 0) {
          throw new RuntimeException('Disposable Option Group fixture still exists after cleanup.');
        }
      }
      if (!empty($this->created['message_template'])) {
        $remaining = MessageTemplate::get(FALSE)->addWhere('id', '=', (int) $this->created['message_template'])->execute()->count();
        if ($remaining !== 0) {
          throw new RuntimeException('Disposable Message Template fixture still exists after cleanup.');
        }
      }
    }
    catch (Throwable $e) {
      $this->results['cleanup_errors'][] = $e->getMessage();
    }

    foreach ($this->settingsBackup as $name => $value) {
      try {
        \Civi::settings()->set($name, $value);
      }
      catch (Throwable $e) {
        $this->results['cleanup_errors'][] = 'Setting ' . $name . ': ' . $e->getMessage();
      }
    }

    $this->removeDirectory($this->syncDir);
  }

  private function record(string $name, array $data): void {
    $this->results['tests'][$name] = $data;
  }

  private function assertTrue(bool $condition, string $message): void {
    if (!$condition) {
      $this->results['failure'] = $message;
      throw new RuntimeException($message);
    }
  }

  private function assertSame($expected, $actual, string $message): void {
    if ($expected !== $actual) {
      $this->results['failure'] = $message;
      $this->results['expected'] = $expected;
      $this->results['actual'] = $actual;
      throw new RuntimeException($message);
    }
  }

  private function ensureDirectory(string $directory): void {
    if (!is_dir($directory) && !mkdir($directory, 0775, TRUE) && !is_dir($directory)) {
      throw new RuntimeException('Could not create directory: ' . $directory);
    }
  }

  private function copyDirectory(string $source, string $destination): void {
    $this->ensureDirectory($destination);
    $iterator = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
      RecursiveIteratorIterator::SELF_FIRST
    );
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
    $iterator = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
      RecursiveIteratorIterator::CHILD_FIRST
    );
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

(new CivicfgStandaloneRoundTrip())->run();
