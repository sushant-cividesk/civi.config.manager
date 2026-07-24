<?php

declare(strict_types=1);

use Civi\Api4\OptionGroup;
use Civi\Api4\OptionValue;
use Civi\ConfigManager\Service\ConfigManager;

final class CivicfgUiFixture {
  private string $action;
  private string $runId;
  private string $name;
  private string $root;
  private string $stateFile;

  public function __construct(array $argv) {
    $this->action = (string) ($argv[1] ?? 'seed');
    $rawRunId = getenv('CIVICFG_QA_RUN_ID') ?: 'local';
    $this->runId = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $rawRunId) ?: 'qa-run';
    $this->name = getenv('CIVICFG_UI_FIXTURE_NAME') ?: ('qa_civicfg_ui_' . strtolower(str_replace('-', '_', $this->runId)));
    $this->root = '/tmp/civicfg-ui-qa-' . $this->runId;
    $artifactDir = getenv('CIVICFG_QA_ARTIFACTS') ?: '/qa-artifacts';
    $this->stateFile = rtrim($artifactDir, '/') . '/ui-fixture-state.json';
  }

  public function run(): void {
    if ($this->action === 'cleanup') {
      $this->cleanup();
      return;
    }
    if ($this->action !== 'seed') {
      throw new InvalidArgumentException('Expected action seed or cleanup.');
    }
    $this->seed();
  }

  private function seed(): void {
    $this->removeDirectory($this->root);
    if (!mkdir($this->root . '/sync', 0775, TRUE) && !is_dir($this->root . '/sync')) {
      throw new RuntimeException('Could not create UI fixture sync directory.');
    }

    $backup = [];
    foreach ([
      'civicfg_sync_dir',
      'civicfg_enabled_types',
      'civicfg_ignore_paths',
      'civicfg_ignore_values',
      'civicfg_allow_cross_site_import',
    ] as $setting) {
      $backup[$setting] = \Civi::settings()->get($setting);
    }

    \Civi::settings()->set('civicfg_sync_dir', $this->root . '/sync');
    \Civi::settings()->set('civicfg_enabled_types', ['option-groups']);
    \Civi::settings()->set('civicfg_ignore_paths', []);
    \Civi::settings()->set('civicfg_ignore_values', []);
    \Civi::settings()->set('civicfg_allow_cross_site_import', FALSE);

    $group = OptionGroup::create(FALSE)
      ->addValue('name', $this->name)
      ->addValue('title', 'QA UI Fixture')
      ->addValue('description', 'Disposable browser-test fixture')
      ->addValue('data_type', 'String')
      ->addValue('is_reserved', FALSE)
      ->addValue('is_active', TRUE)
      ->execute()
      ->first();
    $groupId = (int) $group['id'];

    $value = OptionValue::create(FALSE)
      ->addValue('option_group_id', $groupId)
      ->addValue('name', 'qa_ui_value')
      ->addValue('label', 'QA UI Value')
      ->addValue('value', 'qa-ui-value')
      ->addValue('weight', 1)
      ->addValue('is_reserved', FALSE)
      ->addValue('is_active', TRUE)
      ->execute()
      ->first();

    $export = (new ConfigManager())->export(FALSE, ['option-groups']);
    if (empty($export['ok'])) {
      throw new RuntimeException('Could not export the UI test fixture.');
    }

    OptionGroup::update(FALSE)
      ->addWhere('id', '=', $groupId)
      ->addValue('title', 'QA UI Fixture changed in CiviCRM')
      ->execute();

    $state = [
      'name' => $this->name,
      'relative_path' => 'option-groups/' . $this->name . '.yml',
      'root' => $this->root,
      'option_group_id' => $groupId,
      'option_value_id' => (int) $value['id'],
      'settings_backup' => $backup,
    ];
    file_put_contents($this->stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    echo json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
  }

  private function cleanup(): void {
    if (!is_file($this->stateFile)) {
      $this->removeDirectory($this->root);
      return;
    }
    $state = json_decode((string) file_get_contents($this->stateFile), TRUE);
    if (!is_array($state)) {
      throw new RuntimeException('The UI fixture state file is invalid.');
    }

    if (!empty($state['option_value_id'])) {
      OptionValue::delete(FALSE)->addWhere('id', '=', (int) $state['option_value_id'])->execute();
    }
    if (!empty($state['option_group_id'])) {
      OptionGroup::delete(FALSE)->addWhere('id', '=', (int) $state['option_group_id'])->execute();
    }
    if (!empty($state['option_value_id'])) {
      $remaining = OptionValue::get(FALSE)->addWhere('id', '=', (int) $state['option_value_id'])->execute()->count();
      if ($remaining !== 0) {
        throw new RuntimeException('Disposable UI Option Value still exists after cleanup.');
      }
    }
    if (!empty($state['option_group_id'])) {
      $remaining = OptionGroup::get(FALSE)->addWhere('id', '=', (int) $state['option_group_id'])->execute()->count();
      if ($remaining !== 0) {
        throw new RuntimeException('Disposable UI Option Group still exists after cleanup.');
      }
    }
    foreach ((array) ($state['settings_backup'] ?? []) as $name => $value) {
      \Civi::settings()->set((string) $name, $value);
    }
    $this->removeDirectory((string) ($state['root'] ?? $this->root));
    @unlink($this->stateFile);
    echo "UI fixture cleaned.\n";
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

(new CivicfgUiFixture($argv))->run();
