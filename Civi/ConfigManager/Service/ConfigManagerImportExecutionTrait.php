<?php
namespace Civi\ConfigManager\Service;

use Civi\ConfigManager\Handler\StreamingHandlerInterface;
use Civi\ConfigManager\Storage\YamlFileStorage;

/**
 * ConfigManagerImportExecution methods from ConfigManager.
 *
 * Methods run on the original owning object and retain the same state and
 * access modifiers. Do not instantiate this trait separately.
 */
trait ConfigManagerImportExecutionTrait {

  public function import(bool $dryRun = TRUE, bool $yes = FALSE, array $typeFilter = [], ?callable $progress = NULL, bool $captureFingerprints = FALSE, ?array $reviewPlan = NULL): array {
    if (!$dryRun && $yes && $reviewPlan === NULL) {
      throw new \RuntimeException('Import apply requires an immutable reviewed Import plan. Build and review a fresh preview before applying changes.');
    }
    $storage = new YamlFileStorage($this->getSyncDir());
    $operationLock = OperationLock::acquire($storage->getRoot());
    $requestedTypes = $this->normaliseTypeFilter($typeFilter);
    $effectiveTypes = $this->getEffectiveExportTypeFilter($requestedTypes);
    $validationTypes = $this->getImportValidationTypeFilter($requestedTypes, $effectiveTypes);
    $applyTypes = $this->getImportApplyTypeFilter($requestedTypes, $effectiveTypes);

    $handlers = [];
    foreach ($this->getHandlers() as $handler) {
      if ($applyTypes && !in_array($handler->getType(), $applyTypes, TRUE)) {
        continue;
      }
      $this->prepareHandlerForTypeFilter($handler, $requestedTypes);
      $handlers[] = $handler;
    }

    $handlerCount = count($handlers);
    $willApply = !$dryRun && $yes;
    $totalSteps = max(1, 2 + ($handlerCount * ($willApply ? 5 : 2)));
    $completedSteps = 0;
    $processedItems = 0;
    $this->reportProgress($progress, 0, $totalSteps, 'Preparing import', 'Reading managed Saved Configs and checking dependencies.', 0);

    // Every dry-run sees the complete managed YAML dependency set. This lets a
    // dependent type recognize prerequisites which are absent from the target
    // DB today but are planned earlier in the same import (for example a new
    // Option Group followed by a Custom Field which uses it).
    $plannedDependencyNames = $this->collectImportPlannedDependencyNames($handlers, $storage);
    foreach ($handlers as $handler) {
      $this->setHandlerPlannedDependencyNames($handler, $plannedDependencyNames);
    }
    $completedSteps++;
    $this->reportProgress($progress, $completedSteps, $totalSteps, 'Dependency plan ready', 'Validating the complete YAML set before any write.', $processedItems);

    // Static validation and the handler dry-run are intentionally both run.
    // A foreign site_id, a YAML/dependency problem, and a handler-level safety
    // problem must be reported together in one preflight instead of forcing an
    // operator through one blocker at a time.
    $validation = $this->validate($validationTypes);
    $completedSteps++;
    $this->reportProgress($progress, $completedSteps, $totalSteps, 'YAML validation complete', 'Checking rename safety and handler dry-runs.', $processedItems);
    $preflight = $this->buildImportPreflight($handlers, $storage, $validation, function (array $event) use (&$completedSteps, $totalSteps, &$processedItems, $progress) {
      $completedSteps++;
      $processedItems += (int) ($event['processed_items'] ?? 0);
      $this->reportProgress(
        $progress,
        $completedSteps,
        $totalSteps,
        (string) ($event['label'] ?? 'Import preflight'),
        (string) ($event['message'] ?? 'Checking import safety.'),
        $processedItems
      );
    }, $willApply || $captureFingerprints || $reviewPlan !== NULL);
    $exclusionEffectiveTypes = $effectiveTypes ?: array_values(array_map(static function ($handler): string {
      return (string) $handler->getType();
    }, $handlers));
    $preflight['dependency_components'] = $this->importDependencyPlanner->addExclusionSafety(
      (array) ($preflight['dependency_components'] ?? []),
      $requestedTypes,
      $exclusionEffectiveTypes
    );
    $preflight['dependency_components'] = $this->importDependencyPlanner->addActionSummaries(
      (array) $preflight['dependency_components'],
      (array) ($preflight['items'] ?? [])
    );

    if ($dryRun || !$yes) {
      $this->reportProgress($progress, $totalSteps, $totalSteps, 'Import preview complete', 'Complete non-writing preflight finished.', $processedItems);
      return $preflight;
    }

    if (empty($preflight['ok'])) {
      $preflight['dry_run'] = FALSE;
      $preflight['applied'] = FALSE;
      $preflight['message'] = 'Import stopped before writes because the complete preflight found blocking errors. Resolve all listed blockers and preview again.';
      $this->reportProgress($progress, $totalSteps, $totalSteps, 'Import blocked safely', 'Preflight found blocking errors; zero writes were performed.', $processedItems);
      return $preflight;
    }

    if ($reviewPlan !== NULL) {
      $this->assertReviewPlanPreflightMatches($reviewPlan, $preflight);
    }

    // The complete preflight can contain every managed YAML document's
    // validation/dry-run diagnostics. Once it is green, retain only its compact
    // outcome during the write phases; keeping the full preview alive alongside
    // active provider collections needlessly doubles peak import memory.
    $preflightFingerprints = (array) ($preflight['_active_fingerprints'] ?? []);
    $result = [
      'ok' => TRUE,
      'dry_run' => FALSE,
      'applied' => TRUE,
      'items' => [],
      'preflight' => [
        'ok' => TRUE,
        'summary_message' => (string) ($preflight['summary_message'] ?? ''),
      ],
    ];
    unset($preflight, $validation);
    $postWriteFingerprints = [];

    // Apply create/update first for every type. Never enter delete-missing if
    // any write fails: destructive cleanup must not run after a partial
    // prerequisite/update phase.
    foreach ($handlers as $handler) {
      $handlerLabel = (string) $handler->getLabel();
      $this->reportProgress($progress, $completedSteps, $totalSteps, 'Applying ' . $handlerLabel, 'Create/update phase. Delete-missing has not started.', $processedItems);
      $this->setHandlerImportPhase($handler, TRUE, FALSE);
      try {
        if ($reviewPlan !== NULL) {
          $this->assertReviewPlanHandlerYamlMatches($reviewPlan, $handler, $storage);
        }
        $type = (string) $handler->getType();
        if (isset($preflightFingerprints[$type])) {
          $this->assertManagedActiveSnapshotMatches($handler, $storage, (array) $preflightFingerprints[$type], 'Import conflict: Current CiviCRM changed after preflight. No write was performed for this handler.');
        }
        $item = $this->importManagedYamlForHandler($handler, $storage, FALSE);
        if (empty($item['errors']) && (!array_key_exists('ok', $item) || !empty($item['ok']))) {
          $postWriteFingerprints[$type] = $this->compactManagedActiveSnapshot($handler, $storage);
        }
      }
      catch (\Throwable $e) {
        $item = [
          'type' => (string) $handler->getType(),
          'status' => 'applied',
          'dry_run' => FALSE,
          'errors' => [['message' => $e->getMessage()]],
          'warnings' => [],
        ];
      }
      $item['phase'] = 'create_update';
      $result['items'][] = $item;
      $processedItems += $this->countImportItemActivity($item);
      $completedSteps++;
      $this->reportProgress($progress, $completedSteps, $totalSteps, 'Applied ' . $handlerLabel, 'Create/update phase step complete.', $processedItems);
      if (!empty($item['errors']) || (array_key_exists('ok', $item) && empty($item['ok']))) {
        $result['ok'] = FALSE;
      }
    }

    if (empty($result['ok'])) {
      foreach ($handlers as $handler) {
        $this->setHandlerImportPhase($handler, TRUE, TRUE);
      }
      $result['partial_apply'] = TRUE;
      $result['delete_phase_skipped'] = TRUE;
      $result['message'] = 'Import stopped after a create/update runtime failure. Delete-missing was not started. Review the errors and restore/retry from the pre-import database backup if required.';
      $result['summary_message'] = $this->buildImportSummaryMessage($result);
      $this->reportProgress($progress, $totalSteps, $totalSteps, 'Import stopped safely', 'A create/update failed; delete-missing was not started.', $processedItems);
      return $result;
    }

    foreach (array_reverse($handlers) as $handler) {
      $handlerLabel = (string) $handler->getLabel();
      $this->reportProgress($progress, $completedSteps, $totalSteps, 'Cleaning ' . $handlerLabel, 'Delete-missing phase after all create/update steps succeeded.', $processedItems);
      $this->setHandlerImportPhase($handler, FALSE, TRUE);
      try {
        if ($reviewPlan !== NULL) {
          $this->assertReviewPlanHandlerYamlMatches($reviewPlan, $handler, $storage);
        }
        $type = (string) $handler->getType();
        if (isset($postWriteFingerprints[$type])) {
          $this->assertManagedActiveSnapshotMatches($handler, $storage, (array) $postWriteFingerprints[$type], 'Import conflict: Current CiviCRM changed after create/update. Delete-missing was not started for this handler.');
        }
        $item = $this->importManagedYamlForHandler($handler, $storage, FALSE);
      }
      catch (\Throwable $e) {
        $item = [
          'type' => (string) $handler->getType(),
          'status' => 'applied',
          'dry_run' => FALSE,
          'errors' => [['message' => $e->getMessage()]],
          'warnings' => [],
        ];
      }
      $item['phase'] = 'delete_missing';
      $result['items'][] = $item;
      $processedItems += $this->countImportItemActivity($item);
      $completedSteps++;
      $this->reportProgress($progress, $completedSteps, $totalSteps, 'Cleaned ' . $handlerLabel, 'Delete-missing phase step complete.', $processedItems);
      if (!empty($item['errors']) || (array_key_exists('ok', $item) && empty($item['ok']))) {
        $result['ok'] = FALSE;
      }
      $this->setHandlerImportPhase($handler, TRUE, TRUE);
    }

    if (!empty($result['ok'])) {
      try {
        $stateManager = new ConfigStateManager();
        foreach ($handlers as $handler) {
          if ($reviewPlan !== NULL) {
            $this->assertReviewPlanHandlerYamlMatches($reviewPlan, $handler, $storage);
          }
          $directory = trim((string) $handler->getDirectory(), '/');
          foreach ($storage->iterateDirectory($directory) as $filename => $data) {
            if ($this->isIgnoredPath(($directory === '' ? '' : $directory . '/') . (string) $filename)) {
              continue;
            }
            $stateManager->acceptYamlBaselineItem($handler, (string) $filename, (array) $data, 'import');
          }
          $completedSteps++;
          $this->reportProgress($progress, $completedSteps, $totalSteps, 'Recording ' . $handler->getLabel() . ' baseline', 'Synchronization baseline updated from committed YAML.', $processedItems);
        }
      }
      catch (\Throwable $e) {
        $result['state_warning'] = 'Import was applied successfully, but local baseline state could not be updated: ' . $e->getMessage();
      }
    }
    else {
      $result['partial_apply'] = TRUE;
    }

    $result['summary_message'] = $this->buildImportSummaryMessage($result);
    $this->reportProgress($progress, $totalSteps, $totalSteps, !empty($result['ok']) ? 'Import complete' : 'Import completed with errors', 'Import processing finished.', $processedItems);
    return $result;
  }

  private function buildImportPreflight(array $handlers, YamlFileStorage $storage, array $validation, ?callable $stepProgress = NULL, bool $captureFingerprints = FALSE): array {
    $result = [
      'ok' => !empty($validation['ok']),
      'dry_run' => TRUE,
      'applied' => FALSE,
      'validation' => $validation,
      'items' => [],
      'errors' => [],
      'dependency_edges' => (array) ($validation['dependency_edges'] ?? []),
      'dependency_blockers' => (array) ($validation['dependency_blockers'] ?? []),
      'dependency_components' => (array) ($validation['dependency_components'] ?? []),
    ];

    try {
      $possibleRenames = $this->findPossibleRenameCandidates($handlers, $storage, function ($handler) use ($stepProgress) {
        if ($stepProgress !== NULL) {
          $stepProgress([
            'label' => 'Checked ' . $handler->getLabel() . ' identities',
            'message' => 'Rename/identity safety check complete.',
            'processed_items' => 0,
          ]);
        }
      });
      if ($possibleRenames) {
        $result['possible_renames'] = $possibleRenames;
        $result['ok'] = FALSE;
        foreach ($possibleRenames as $candidate) {
          $result['errors'][] = [
            'type' => (string) ($candidate['type'] ?? 'import'),
            'message' => 'Possible configuration identity rename requires review before import: '
            . (string) ($candidate['old_config_key'] ?? '[old identity]') . ' -> '
            . (string) ($candidate['new_config_key'] ?? '[new identity]') . '.',
          ];
        }
      }
    }
    catch (\Throwable $e) {
      $result['ok'] = FALSE;
      $result['errors'][] = ['type' => 'import', 'message' => 'Rename preflight could not be completed: ' . $e->getMessage()];
    }

    foreach ($handlers as $handler) {
      $this->setHandlerImportPhase($handler, TRUE, TRUE);
      try {
        $item = $this->importManagedYamlForHandler($handler, $storage, TRUE);
      }
      catch (\Throwable $e) {
        $item = [
          'type' => (string) $handler->getType(),
          'status' => 'dry_run',
          'dry_run' => TRUE,
          'errors' => [['message' => $e->getMessage()]],
          'warnings' => [],
        ];
      }
      $result['items'][] = $item;
      if ($captureFingerprints && empty($item['errors']) && (!array_key_exists('ok', $item) || !empty($item['ok']))) {
        try {
          $result['_active_fingerprints'][(string) $handler->getType()] = $this->compactManagedActiveSnapshot($handler, $storage);
        }
        catch (\Throwable $e) {
          $result['ok'] = FALSE;
          $result['errors'][] = [
            'type' => (string) $handler->getType(),
            'message' => 'Could not capture the preflight active fingerprint: ' . $e->getMessage(),
          ];
        }
      }
      if ($stepProgress !== NULL) {
        $stepProgress([
          'label' => 'Preflighted ' . $handler->getLabel(),
          'message' => 'Handler dry-run completed with no writes.',
          'processed_items' => $this->countImportItemActivity($item),
        ]);
      }
      if (!empty($item['errors']) || (array_key_exists('ok', $item) && empty($item['ok']))) {
        $result['ok'] = FALSE;
      }
    }

    $result['summary_message'] = $this->buildImportSummaryMessage($result);
    return $result;
  }

  private function collectImportPlannedDependencyNames(array $handlers, YamlFileStorage $storage): array {
    $available = [];
    foreach ($handlers as $handler) {
      $type = (string) $handler->getType();
      try {
        foreach ($this->iterateManagedYamlFilesForHandler($handler, $storage) as $file) {
          foreach ($this->namesFromYamlFile((array) $file) as $name) {
            $available[$type][(string) $name] = TRUE;
          }
        }
      }
      catch (\Throwable $e) {
        if (!isset($available[$type])) {
          $available[$type] = [];
        }
      }
    }
    return $available;
  }

  private function setHandlerPlannedDependencyNames($handler, array $plannedDependencyNames): void {
    if (method_exists($handler, 'setPlannedDependencyNames')) {
      $handler->setPlannedDependencyNames($plannedDependencyNames);
    }
  }

  private function findPossibleRenameCandidates(array $handlers, YamlFileStorage $storage, ?callable $stepProgress = NULL): array {
    $candidates = [];
    foreach ($handlers as $handler) {
      $policy = $this->scope->getPolicy((string) $handler->getType());
      if ($handler instanceof StreamingHandlerInterface && (($policy['mode'] ?? ConfigScope::MODE_ALL) === ConfigScope::MODE_ALL)) {
        $errors = [];
        $compact = $this->buildCompactStreamingDiff($handler, $storage, $errors);
        if ($errors) {
          throw new \RuntimeException((string) ($errors[0]['message'] ?? 'Active provider scan failed during rename preflight.'));
        }
        $diff = (array) ($compact['item'] ?? []);
        unset($compact);
      }
      else {
        $files = $this->loadManagedYamlFiles($handler, $storage);
        $partition = $this->scopePartition($handler, $handler->export(), $storage, FALSE);
        $exported = $this->filterIgnoredValuesInExportFiles($handler->getDirectory(), (array) ($partition['managed'] ?? []));
        $diff = $handler->diffFromExports($exported, $files);
        unset($files, $partition, $exported);
      }
      foreach ((array) ($diff['possible_renames'] ?? []) as $candidate) {
        if (!is_array($candidate)) {
          continue;
        }
        $candidate['type'] = $handler->getType();
        $candidate['label'] = $handler->getLabel();
        $candidates[] = $candidate;
      }
      unset($diff);
      if ($stepProgress !== NULL) {
        $stepProgress($handler);
      }
    }
    return $candidates;
  }

  private function countImportItemActivity(array $item): int {
    return $this->importResultAccounting->countActivity($item);
  }

  private function buildImportSummaryMessage(array $result): string {
    $accounted = $this->importResultAccounting->attach($result);
    return (string) ($accounted['summary_message'] ?? '');
  }

  /**
   * @return array<string,mixed>|null */
  private function verifiedPostImportDiff(): ?array {
    try {
      $diff = $this->diff();
      return !empty($diff['ok']) ? $diff : NULL;
    }
    catch (\Throwable $e) {
      return NULL;
    }
  }

  /**
   * @return array<int,array<string,mixed>> */
  private function sanitizeExcludedComponents(array $components): array {
    $sanitized = [];
    foreach ($components as $component) {
      if (!is_array($component)) {
        continue;
      }
      $sanitized[] = [
        'id' => trim((string) ($component['id'] ?? '')),
        'title' => trim((string) ($component['title'] ?? 'Dependency component')),
        'types' => array_values(array_unique(array_map('strval', (array) ($component['types'] ?? [])))),
        'type_labels' => array_values(array_unique(array_map('strval', (array) ($component['type_labels'] ?? [])))),
        'files' => array_values(array_unique(array_map('strval', (array) ($component['files'] ?? [])))),
        'blocker_count' => max(0, (int) ($component['blocker_count'] ?? 0)),
        'action_count' => max(0, (int) ($component['action_count'] ?? 0)),
        'excluded_action_count' => max(0, (int) ($component['excluded_action_count'] ?? 0)),
      ];
    }
    return $sanitized;
  }

  private function setHandlerImportPhase($handler, bool $writeEnabled, bool $deleteEnabled): void {
    if (method_exists($handler, 'setImportWriteEnabled')) {
      $handler->setImportWriteEnabled($writeEnabled);
    }
    if (method_exists($handler, 'setDeleteMissingEnabled')) {
      $handler->setDeleteMissingEnabled($deleteEnabled && $this->scope->allowsDeleteMissing((string) $handler->getType()));
    }
  }

}
