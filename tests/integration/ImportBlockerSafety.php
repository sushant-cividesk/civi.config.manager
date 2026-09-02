<?php

declare(strict_types=1);

use Civi\Api4\OptionGroup;
use Civi\Api4\OptionValue;
use Civi\ConfigManager\Service\ConfigManager;
use Civi\ConfigManager\Util\SimpleYaml;

/**
 * Real-runtime requirement: an OptionValue identity-rename blocker must make
 * both preview and confirmed import perform zero writes. The database, an
 * unrelated YAML file, unrelated configuration, and business-record counts
 * must remain unchanged.
 *
 * Failure mode: the importer treats a changed machine name with the same
 * stable value as an update/delete pair, or reports a blocker but still writes.
 *
 * Oracle: direct API4/SQL reads and raw filesystem hashes. Assertions never use
 * the handler's resolver, classifier, canonicalizer, or returned ok value as
 * the final-state oracle.
 */
final class CivicfgImportBlockerSafety {
  private string $runId;
  private string $root;
  private string $syncDir;
  private string $artifactDir;
  private array $created = [];
  private array $settingsBackup = [];
  private array $evidence = [];

  public function __construct() {
    $rawRunId = getenv('CIVICFG_QA_RUN_ID') ?: ('local-' . bin2hex(random_bytes(4)));
    $this->runId = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $rawRunId) ?: 'qa-run';
    $qaRoot = getenv('CIVICFG_QA_ROOT') ?: (sys_get_temp_dir() . '/civicfg-qa');
    $this->root = rtrim($qaRoot, '/') . '/import-blocker-' . substr(hash('sha256', $this->runId), 0, 12);
    $this->syncDir = $this->root . '/sync';
    $this->artifactDir = getenv('CIVICFG_QA_ARTIFACTS') ?: ($this->root . '/artifacts');
  }

  public function run(): void {
    $failure = NULL;
    try {
      $this->prepareIsolation();
      $this->proveIdentityRenameBlocksAllWrites();
      $this->evidence['ok'] = TRUE;
    }
    catch (Throwable $e) {
      $failure = $e;
      $this->evidence['ok'] = FALSE;
      $this->evidence['failure'] = $e->getMessage();
    }
    finally {
      $this->cleanup();
      $this->writeEvidence();
    }

    if ($failure !== NULL || empty($this->evidence['ok'])) {
      throw new RuntimeException('Real-runtime import-blocker safety requirement failed.', 0, $failure);
    }

    echo json_encode($this->evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
  }

  private function prepareIsolation(): void {
    $this->removeDirectory($this->root);
    $this->ensureDirectory($this->syncDir);
    $this->ensureDirectory($this->artifactDir);

    foreach ([
      'civicfg_sync_dir',
      'civicfg_scope_default_mode',
      'civicfg_scope',
      'civicfg_scope_resolved',
      'civicfg_ignore_paths',
      'civicfg_ignore_values',
      'civicfg_allow_cross_site_import',
      'smtpPassword',
    ] as $name) {
      $this->settingsBackup[$name] = \Civi::settings()->get($name);
    }

    \Civi::settings()->set('civicfg_sync_dir', $this->syncDir);
    \Civi::settings()->set('civicfg_scope_default_mode', 'all');
    \Civi::settings()->set('civicfg_scope', [
      'message-templates' => ['mode' => 'ignore'],
    ]);
    \Civi::settings()->set('civicfg_scope_resolved', []);
    \Civi::settings()->set('civicfg_ignore_paths', ['unrelated-preservation/keep.yml']);
    \Civi::settings()->set('civicfg_ignore_values', []);
    \Civi::settings()->set('civicfg_allow_cross_site_import', FALSE);
    \Civi::settings()->set('smtpPassword', 'preserve-' . substr(hash('sha256', $this->runId), 0, 16));
  }

  private function proveIdentityRenameBlocksAllWrites(): void {
    $suffix = substr(hash('sha256', $this->runId . '-blocker'), 0, 12);
    $groupName = 'qa_civicfg_blocker_' . $suffix;
    $unrelatedGroupName = 'qa_civicfg_unrelated_' . $suffix;
    $oldName = 'qa_old_identity_' . $suffix;
    $newName = 'qa_new_identity_' . $suffix;
    $stableValue = 'qa-stable-' . $suffix;

    $group = OptionGroup::create(FALSE)
      ->addValue('name', $groupName)
      ->addValue('title', 'QA import blocker ' . $suffix)
      ->addValue('data_type', 'String')
      ->addValue('is_reserved', FALSE)
      ->addValue('is_active', TRUE)
      ->execute()
      ->first();
    $this->created['group'] = (int) $group['id'];

    $value = OptionValue::create(FALSE)
      ->addValue('option_group_id', $this->created['group'])
      ->addValue('name', $oldName)
      ->addValue('label', 'QA blocked identity')
      ->addValue('value', $stableValue)
      ->addValue('weight', 1)
      ->addValue('is_reserved', FALSE)
      ->addValue('is_active', TRUE)
      ->execute()
      ->first();
    $this->created['value'] = (int) $value['id'];

    $unrelatedGroup = OptionGroup::create(FALSE)
      ->addValue('name', $unrelatedGroupName)
      ->addValue('title', 'QA unrelated preservation ' . $suffix)
      ->addValue('data_type', 'String')
      ->addValue('is_reserved', FALSE)
      ->addValue('is_active', TRUE)
      ->execute()
      ->first();
    $this->created['unrelated_group'] = (int) $unrelatedGroup['id'];

    $unrelatedValue = OptionValue::create(FALSE)
      ->addValue('option_group_id', $this->created['unrelated_group'])
      ->addValue('name', 'qa_unrelated_value_' . $suffix)
      ->addValue('label', 'QA unrelated value')
      ->addValue('value', 'qa-unrelated-' . $suffix)
      ->addValue('weight', 1)
      ->addValue('is_reserved', FALSE)
      ->addValue('is_active', TRUE)
      ->execute()
      ->first();
    $this->created['unrelated_value'] = (int) $unrelatedValue['id'];

    $manager = new ConfigManager();
    $export = $manager->export(FALSE, ['option-groups']);
    $this->assertTrue(!empty($export['ok']), 'Fixture export failed before the blocker test could run.');

    $yamlPath = $this->syncDir . '/option-groups/' . $groupName . '.yml';
    $this->assertTrue(is_file($yamlPath), 'The blocker fixture YAML was not exported.');
    $yaml = SimpleYaml::parseFile($yamlPath);
    $changed = FALSE;
    foreach ((array) ($yaml['values'] ?? []) as $index => $yamlValue) {
      if ((string) ($yamlValue['value'] ?? '') === $stableValue) {
        $yaml['values'][$index]['name'] = $newName;
        $changed = TRUE;
      }
    }
    $this->assertTrue($changed, 'The exported stable OptionValue could not be found independently in YAML.');
    $bytes = file_put_contents($yamlPath, SimpleYaml::dump($yaml), LOCK_EX);
    $this->assertTrue($bytes !== FALSE, 'The changed blocker YAML could not be written.');

    $unrelatedYaml = $this->syncDir . '/unrelated-preservation/keep.yml';
    $this->ensureDirectory(dirname($unrelatedYaml));
    $this->assertTrue(file_put_contents($unrelatedYaml, "purpose: preserve-me\n", LOCK_EX) !== FALSE, 'The unrelated YAML preservation fixture could not be written.');
    $unrelatedYamlHash = hash_file('sha256', $unrelatedYaml);

    $rawYaml = file_get_contents($yamlPath);
    $this->assertTrue(is_string($rawYaml) && strpos($rawYaml, $newName) !== FALSE, 'Raw YAML does not contain the requested new identity.');
    $this->assertTrue(strpos((string) $rawYaml, $stableValue) !== FALSE, 'Raw YAML no longer contains the stable value.');
    $blockerYamlHash = hash_file('sha256', $yamlPath);

    $before = $this->independentState();
    $preview = $manager->import(TRUE, FALSE, ['option-groups']);
    $this->assertTrue(empty($preview['ok']), 'Preview did not report the required blocking result.');
    $this->assertContains('Possible OptionValue identity rename detected', $preview, 'Preview did not identify the OptionValue rename blocker.');
    $this->assertSame($before, $this->independentState(), 'Preview changed independently queried CiviCRM state.');

    $apply = $manager->import(FALSE, TRUE, ['option-groups']);
    $this->assertTrue(empty($apply['ok']), 'Confirmed import did not remain blocked.');
    $this->assertTrue(empty($apply['applied']), 'Confirmed blocked import incorrectly reports that it applied.');
    $this->assertContains('Possible OptionValue identity rename detected', $apply, 'Confirmed import lost the blocker diagnostic.');

    $after = $this->independentState();
    $this->assertSame($before, $after, 'Blocked confirmed import changed independently queried CiviCRM state.');
    $this->assertSame($blockerYamlHash, hash_file('sha256', $yamlPath), 'Blocked import changed the source blocker YAML.');
    $this->assertSame($unrelatedYamlHash, hash_file('sha256', $unrelatedYaml), 'Blocked import changed unrelated YAML.');

    $rawYamlAfter = file_get_contents($yamlPath);
    $this->assertTrue(is_string($rawYamlAfter) && strpos($rawYamlAfter, $newName) !== FALSE, 'Blocked import removed the requested identity from source YAML.');
    $this->assertTrue(strpos((string) $rawYamlAfter, $stableValue) !== FALSE, 'Blocked import removed the stable value from source YAML.');

    $valueAfter = $this->getOptionValue($this->created['value']);
    $this->assertSame($oldName, (string) ($valueAfter['name'] ?? ''), 'Blocked import renamed the database OptionValue.');
    $this->assertSame($stableValue, (string) ($valueAfter['value'] ?? ''), 'Blocked import changed the stable database OptionValue value.');

    $this->evidence['requirement'] = 'OptionValue identity-rename blockers perform zero writes in preview and confirmed import.';
    $this->evidence['boundary'] = 'ConfigManager import service against disposable real CiviCRM';
    $this->evidence['oracle'] = 'direct API4 rows, direct SQL counts, setting fingerprint, and raw YAML hash';
    $this->evidence['adversarial_review'] = 'This service-boundary test could still pass if API4, CLI, browser, or queued execution bypassed the service; each of those boundaries therefore remains a separate required gate.';
    $this->evidence['preserved'] = [
      'blocked_option_value',
      'unrelated_option_group_and_value',
      'contact_count',
      'ignored_message_template_count',
      'unselected_scheduled_job_count',
      'smtp_password_fingerprint',
      'ignored_unrelated_yaml',
    ];
  }

  private function independentState(): array {
    $secret = (string) \Civi::settings()->get('smtpPassword');
    return [
      'blocked_group' => $this->getOptionGroup($this->created['group']),
      'blocked_value' => $this->getOptionValue($this->created['value']),
      'unrelated_group' => $this->getOptionGroup($this->created['unrelated_group']),
      'unrelated_value' => $this->getOptionValue($this->created['unrelated_value']),
      'contact_count' => (int) \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_contact'),
      'message_template_count' => (int) \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_msg_template'),
      'scheduled_job_count' => (int) \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_job'),
      'smtp_password_fingerprint' => hash('sha256', $secret),
      'scope_fingerprint' => hash('sha256', serialize(\Civi::settings()->get('civicfg_scope'))),
      'ignore_paths_fingerprint' => hash('sha256', serialize(\Civi::settings()->get('civicfg_ignore_paths'))),
    ];
  }

  private function getOptionGroup(int $id): array {
    return (array) OptionGroup::get(FALSE)
      ->addSelect('id', 'name', 'title', 'data_type', 'is_active', 'is_reserved')
      ->addWhere('id', '=', $id)
      ->execute()
      ->first();
  }

  private function getOptionValue(int $id): array {
    return (array) OptionValue::get(FALSE)
      ->addSelect('id', 'option_group_id', 'name', 'label', 'value', 'weight', 'is_active', 'is_reserved')
      ->addWhere('id', '=', $id)
      ->execute()
      ->first();
  }

  private function assertContains(string $needle, array $result, string $message): void {
    $encoded = json_encode($result, JSON_UNESCAPED_SLASHES);
    $this->assertTrue(is_string($encoded) && strpos($encoded, $needle) !== FALSE, $message);
  }

  private function assertTrue(bool $condition, string $message): void {
    if (!$condition) {
      throw new RuntimeException($message);
    }
  }

  private function assertSame($expected, $actual, string $message): void {
    if ($expected !== $actual) {
      $this->evidence['expected_fingerprint'] = hash('sha256', serialize($expected));
      $this->evidence['actual_fingerprint'] = hash('sha256', serialize($actual));
      throw new RuntimeException($message);
    }
  }

  private function cleanup(): void {
    foreach (['value', 'unrelated_value'] as $key) {
      if (!empty($this->created[$key])) {
        try {
          OptionValue::delete(FALSE)->addWhere('id', '=', (int) $this->created[$key])->execute();
        }
        catch (Throwable $e) {
          $this->evidence['cleanup_errors'][] = $key . ': ' . $e->getMessage();
        }
      }
    }
    foreach (['group', 'unrelated_group'] as $key) {
      if (!empty($this->created[$key])) {
        try {
          OptionGroup::delete(FALSE)->addWhere('id', '=', (int) $this->created[$key])->execute();
        }
        catch (Throwable $e) {
          $this->evidence['cleanup_errors'][] = $key . ': ' . $e->getMessage();
        }
      }
    }
    foreach ($this->settingsBackup as $name => $value) {
      try {
        \Civi::settings()->set($name, $value);
      }
      catch (Throwable $e) {
        $this->evidence['cleanup_errors'][] = 'setting ' . $name . ': ' . $e->getMessage();
      }
    }
    $this->removeDirectory($this->root);
    if (!empty($this->evidence['cleanup_errors'])) {
      $this->evidence['ok'] = FALSE;
    }
  }

  private function writeEvidence(): void {
    $this->ensureDirectory($this->artifactDir);
    $this->evidence['run_id'] = $this->runId;
    $encoded = json_encode($this->evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === FALSE || file_put_contents($this->artifactDir . '/import-blocker-safety.json', $encoded . PHP_EOL, LOCK_EX) === FALSE) {
      throw new RuntimeException('Could not write import-blocker evidence.');
    }
  }

  private function ensureDirectory(string $directory): void {
    if (!is_dir($directory) && !mkdir($directory, 0775, TRUE) && !is_dir($directory)) {
      throw new RuntimeException('Could not create directory: ' . $directory);
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

(new CivicfgImportBlockerSafety())->run();
