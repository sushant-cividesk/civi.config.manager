<?php
namespace Civi\ConfigManager\Service;

// Include stateful concerns for direct CiviCRM/legacy bootstrap loaders too.
require_once __DIR__ . '/ConfigManagerDiffTrait.php';
require_once __DIR__ . '/ConfigManagerDirectExportTrait.php';
require_once __DIR__ . '/ConfigManagerEnvironmentTrait.php';
require_once __DIR__ . '/ConfigManagerExportStagingTrait.php';
require_once __DIR__ . '/ConfigManagerImportExecutionTrait.php';
require_once __DIR__ . '/ConfigManagerImportReviewTrait.php';
require_once __DIR__ . '/ConfigManagerManagedFilesTrait.php';
require_once __DIR__ . '/ConfigManagerMonitoringTrait.php';
require_once __DIR__ . '/ConfigManagerProviderCatalogTrait.php';
require_once __DIR__ . '/ConfigManagerQueuedExportTrait.php';
require_once __DIR__ . '/ConfigManagerQueuedImportTrait.php';
require_once __DIR__ . '/ConfigManagerRevertTrait.php';
require_once __DIR__ . '/ConfigManagerScopeTrait.php';
require_once __DIR__ . '/ConfigManagerSnapshotTrait.php';
require_once __DIR__ . '/ConfigManagerValidationTrait.php';

class ConfigManager {
  use ConfigManagerDiffTrait;
  use ConfigManagerDirectExportTrait;
  use ConfigManagerEnvironmentTrait;
  use ConfigManagerExportStagingTrait;
  use ConfigManagerImportExecutionTrait;
  use ConfigManagerImportReviewTrait;
  use ConfigManagerManagedFilesTrait;
  use ConfigManagerMonitoringTrait;
  use ConfigManagerProviderCatalogTrait;
  use ConfigManagerQueuedExportTrait;
  use ConfigManagerQueuedImportTrait;
  use ConfigManagerRevertTrait;
  use ConfigManagerScopeTrait;
  use ConfigManagerSnapshotTrait;
  use ConfigManagerValidationTrait;


  private const WATCH_HISTORY_LIMIT = 200;

  private ?ConfigIgnoreRules $ignoreRuleEngine = NULL;
  private HandlerRegistry $registry;
  private ConfigScope $scope;
  private ImportReviewPlanGuard $importPlanGuard;
  private ImportDependencyPlanner $importDependencyPlanner;
  private ImportResultAccounting $importResultAccounting;
  private ?array $allHandlersCache = NULL;
  private ?array $managedTypeOptionsCache = NULL;
  private ?array $scopeTypeOptionsCache = NULL;
  private array $activeDependencyNamesCache = [];

  public function __construct(?HandlerRegistry $registry = NULL, ?ConfigScope $scope = NULL, ?ImportPlanStore $importPlanStore = NULL, ?ImportDependencyPlanner $importDependencyPlanner = NULL) {
    $this->registry = $registry ?: new HandlerRegistry();
    $this->scope = $scope ?: new ConfigScope();
    $this->importPlanGuard = new ImportReviewPlanGuard($importPlanStore ?: new ImportPlanStore(), $this->scope);
    $this->importDependencyPlanner = $importDependencyPlanner ?: new ImportDependencyPlanner();
    $this->importResultAccounting = new ImportResultAccounting();
  }

  private function ignoreRuleEngine(): ConfigIgnoreRules {
    if ($this->ignoreRuleEngine === NULL) {
      $this->ignoreRuleEngine = new ConfigIgnoreRules();
    }
    return $this->ignoreRuleEngine;
  }

  public function getIgnorePatterns(): array {
    return $this->ignoreRuleEngine()->getIgnorePatterns();
  }

  private function filterIgnoredFiles(string $directory, array $files): array {
    return $this->ignoreRuleEngine()->filterIgnoredFiles($directory, $files);
  }

  private function filterIgnoredDiffItem(array $item, string $directory = ''): array {
    return $this->ignoreRuleEngine()->filterIgnoredDiffItem($item, $directory);
  }

  public function shouldIgnorePath(string $relativePath): bool {
    return $this->ignoreRuleEngine()->shouldIgnorePath($relativePath);
  }

  /**
   * Preserve the original private path guard for internal orchestration.
   * The Config Ignore engine now owns matching rules and persistence.
   */
  private function isIgnoredPath(string $relativePath): bool {
    return $this->ignoreRuleEngine()->shouldIgnorePath($relativePath);
  }

  public function getBuiltInIgnoreValueRules(): array {
    return $this->ignoreRuleEngine()->getBuiltInIgnoreValueRules();
  }

  public function getBuiltInIgnoreRules(): array {
    return $this->ignoreRuleEngine()->getBuiltInIgnoreRules();
  }

  public function getConfiguredIgnoreRules(): array {
    return $this->ignoreRuleEngine()->getConfiguredIgnoreRules();
  }

  public function getIgnoreRules(): array {
    return $this->ignoreRuleEngine()->getIgnoreRules();
  }

  public function setConfiguredIgnoreRules(array $rules): array {
    return $this->ignoreRuleEngine()->setConfiguredIgnoreRules($rules);
  }

  public function getConfiguredIgnoreValueRules(): array {
    return $this->ignoreRuleEngine()->getConfiguredIgnoreValueRules();
  }

  public function getIgnoreValuePatterns(): array {
    return $this->ignoreRuleEngine()->getIgnoreValuePatterns();
  }

  private function filterIgnoredValuesInFiles(string $directory, array $files): array {
    return $this->ignoreRuleEngine()->filterIgnoredValuesInFiles($directory, $files);
  }

  private function filterIgnoredValuesInExportFiles(string $directory, array $files): array {
    return $this->ignoreRuleEngine()->filterIgnoredValuesInExportFiles($directory, $files);
  }

  public function applyIgnoredValueRules(string $relativePath, array $data): array {
    return $this->ignoreRuleEngine()->applyIgnoredValueRules($relativePath, $data);
  }

}
