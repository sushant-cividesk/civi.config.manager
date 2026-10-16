<?php
namespace Civi\ConfigManager\Service;

use Civi\ConfigManager\Handler\StreamingHandlerInterface;
use Civi\ConfigManager\Storage\YamlFileStorage;

/**
 * ConfigManagerDirectExport methods from ConfigManager.
 *
 * Methods run on the original owning object and retain the same state and
 * access modifiers. Do not instantiate this trait separately.
 */
trait ConfigManagerDirectExportTrait {

  public function export(bool $dryRun = TRUE, array $typeFilter = [], ?callable $progress = NULL): array {
    $storage = new YamlFileStorage($this->getSyncDir());
    $operationLock = OperationLock::acquire($storage->getRoot());
    $requestedTypes = $this->normaliseTypeFilter($typeFilter);
    $effectiveTypes = $this->getEffectiveExportTypeFilter($requestedTypes);
    $dependencyTypes = $requestedTypes ? array_values(array_diff($effectiveTypes, $requestedTypes)) : [];
    $summary = [
      'ok' => TRUE,
      'dry_run' => $dryRun,
      'sync_dir' => $storage->getRoot(),
      'requested_types' => $requestedTypes,
      'effective_types' => $effectiveTypes,
      'dependency_types' => $dependencyTypes,
      'written' => [],
      'created_count' => 0,
      'updated_count' => 0,
      'deleted' => [],
      'planned' => [],
      'delete_planned' => [],
      'available' => [],
      'skipped' => [],
      'warnings' => [],
      'errors' => [],
      'message' => NULL,
    ];

    $workspace = new StagedExportWorkspace($storage);
    $successfulHandlers = [];
    $scopeManifestUpdates = [];
    $resolvedPartitions = [];
    $stagedActiveFingerprints = [];
    $eligibleHandlers = [];
    foreach ($this->getHandlers() as $candidateHandler) {
      if (!$effectiveTypes || in_array($candidateHandler->getType(), $effectiveTypes, TRUE)) {
        $eligibleHandlers[] = $candidateHandler;
      }
    }
    $totalSteps = max(1, count($eligibleHandlers) + 3);
    $completedSteps = 0;
    $processedItems = 0;
    $this->reportProgress($progress, $completedSteps, $totalSteps, 'Preparing export', 'Building a staged configuration snapshot.', $processedItems);

    try {
      // Scope modes which do not need resolved selected-item keys can be
      // represented before provider discovery. The live manifest is not touched
      // until the complete staged snapshot has passed every handler.
      foreach ($this->getScopeTypeOptions() as $scopeType) {
        $type = (string) ($scopeType['type'] ?? '');
        if ($type === '') {
          continue;
        }
        $policy = $this->scope->getPolicy($type);
        if (!in_array((string) ($policy['mode'] ?? ''), [ConfigScope::MODE_ALL, ConfigScope::MODE_WATCH, ConfigScope::MODE_IGNORE], TRUE)) {
          continue;
        }
        $scopeManifestUpdates[$type] = $this->scope->manifestEntry($type, ['policy' => $policy]);
      }

      foreach ($eligibleHandlers as $handler) {
        $this->prepareHandlerForTypeFilter($handler, $requestedTypes);
        $handlerType = (string) $handler->getType();
        $handlerPolicy = $this->scope->getPolicy($handlerType);
        $handlerLabel = (string) $handler->getLabel();
        $this->reportProgress($progress, $completedSteps, $totalSteps, 'Exporting ' . $handlerLabel, 'Streaming active configuration to the staged snapshot.', $processedItems);

        try {
          // Manage Everything is the large-site path. Stream each document
          // directly to disk so there is never a handler-wide export array or a
          // site-wide queue of complete YAML documents in memory.
          if (($handlerPolicy['mode'] ?? ConfigScope::MODE_ALL) === ConfigScope::MODE_ALL
            && $handler instanceof StreamingHandlerInterface) {
            $partition = [
              'policy' => $handlerPolicy,
              'managed_config_keys' => [],
              'matched_selectors' => [],
              'selector_config_keys' => [],
              'unresolved_selectors' => [],
              'missing_selectors' => [],
            ];
            foreach ($handler->iterateExport() as $file) {
              $this->stageExportFile($workspace, $handler, (array) $file, $summary);
              $processedItems++;
              if (($processedItems % 25) === 0) {
                $this->reportProgress($progress, $completedSteps, $totalSteps, 'Exporting ' . $handlerLabel, 'Streaming active configuration to the staged snapshot.', $processedItems);
              }
            }
          }
          else {
            // Selected/watch/ignore and legacy third-party handlers retain the
            // compatibility contract. They are staged transactionally even if
            // their old HandlerInterface::export() implementation materializes
            // one handler collection.
            $exported = $handler->export();
            $partition = $this->scopePartition($handler, $exported, $storage, TRUE);
            foreach ((array) ($partition['managed'] ?? []) as $file) {
              $this->stageExportFile($workspace, $handler, (array) $file, $summary);
              $processedItems++;
            }
            unset($exported);
          }

          $handlerExportErrors = $this->consumeHandlerExportErrors($handler, $summary['errors']);
          if ($handlerExportErrors === 0 || (string) ($handlerPolicy['mode'] ?? '') !== ConfigScope::MODE_SELECTED) {
            $scopeManifestUpdates[$handlerType] = $this->scope->manifestEntry($handlerType, $partition);
          }

          foreach ((array) ($partition['unresolved_selectors'] ?? []) as $selector) {
            $summary['warnings'][] = [
              'type' => $handlerType,
              'message' => 'Configured scope selector has never resolved to a Current CiviCRM object: ' . (string) $selector . '.',
            ];
          }
          foreach ((array) ($partition['missing_selectors'] ?? []) as $selector) {
            $summary['warnings'][] = [
              'type' => $handlerType,
              'message' => 'Configured managed object is currently missing from CiviCRM: ' . (string) $selector . '. Existing YAML backup is preserved for review or restore.',
            ];
          }

          if ($handlerExportErrors === 0) {
            $successfulHandlers[] = $handler;
            $resolvedPartitions[$handlerType] = [
              'policy' => (array) ($partition['policy'] ?? $handlerPolicy),
              'matched_selectors' => (array) ($partition['matched_selectors'] ?? []),
              'selector_config_keys' => (array) ($partition['selector_config_keys'] ?? []),
              'managed_config_keys' => array_values(array_map('strval', (array) ($partition['managed_config_keys'] ?? []))),
            ];
          }
          unset($partition);
          $completedSteps++;
          $this->reportProgress($progress, $completedSteps, $totalSteps, 'Exported ' . $handlerLabel, 'Handler snapshot completed.', $processedItems);
        }
        catch (\Throwable $e) {
          $summary['errors'][] = [
            'type' => $handlerType,
            'message' => $e->getMessage(),
          ];
        }
      }

      // A staged export is an all-or-nothing snapshot. Provider failure,
      // duplicate path, or any other handler error leaves the previous live
      // YAML tree untouched instead of publishing a partial snapshot.
      if ($summary['errors']) {
        $summary['ok'] = FALSE;
        $summary['message'] = 'Export staging failed. The previous Saved Config snapshot was left unchanged.';
        return $summary;
      }

      // Freeze a compact fingerprint of the active-derived staged documents
      // before reverse dependency metadata is added. We re-scan active CiviCRM
      // immediately before publish; a manual/admin change during staging must
      // never produce a mixed snapshot.
      foreach ($successfulHandlers as $handler) {
        $policy = $this->scope->getPolicy((string) $handler->getType());
        if (($policy['mode'] ?? ConfigScope::MODE_ALL) !== ConfigScope::MODE_ALL) {
          continue;
        }
        $stagedActiveFingerprints[(string) $handler->getType()] = $this->compactSnapshotFromYamlStorage(
          $handler,
          $workspace->getStageStorage()
        );
      }

      $this->reportProgress($progress, $completedSteps, $totalSteps, 'Finalizing staged YAML', 'Rebuilding provider indexes and reverse dependency metadata.', $processedItems);
      $this->pruneExtensionIndexesInStagedExport($workspace);
      $this->addReverseDependencyMetadataToStagedExport($workspace);
      $completedSteps++;
      $this->reportProgress($progress, $completedSteps, $totalSteps, 'Staged YAML finalized', 'Calculating the publish and stale-file plan.', $processedItems);

      $existingManifest = $this->readManifest($storage);
      $existingScope = (array) ($existingManifest['managed_scope'] ?? []);
      foreach ($scopeManifestUpdates as $type => $scopeEntry) {
        $existingScope[$type] = $scopeEntry;
      }
      ksort($existingScope, SORT_NATURAL | SORT_FLAG_CASE);
      $workspace->stage('__manifest__', '', 'manifest.yml', $this->getManifestData($existingScope));

      $stalePaths = $this->findStaleYamlPathsForStagedExport(
        $storage,
        $workspace,
        $successfulHandlers,
        $requestedTypes
      );
      $preview = $workspace->preview($stalePaths);

      $this->reportProgress($progress, $completedSteps, $totalSteps, 'Verifying active snapshot', 'Checking that CiviCRM configuration did not change while export was staged.', $processedItems);
      foreach ($successfulHandlers as $handler) {
        $type = (string) $handler->getType();
        if (!array_key_exists($type, $stagedActiveFingerprints)) {
          continue;
        }
        $this->assertActiveSnapshotMatches($handler, $stagedActiveFingerprints[$type]);
      }

      if ($dryRun) {
        $summary['created_count'] = count((array) ($preview['create'] ?? []));
        $summary['updated_count'] = count((array) ($preview['update'] ?? []));
        foreach ($preview['write'] as $relative) {
          $summary['planned'][] = $relative;
        }
        foreach ($preview['delete'] as $relative) {
          $summary['delete_planned'][] = $relative;
          $summary['planned'][] = $relative . ' (delete stale YAML)';
        }
        foreach ($preview['skip'] as $relative) {
          $summary['skipped'][] = $relative;
        }
      }
      else {
        $this->reportProgress($progress, $completedSteps, $totalSteps, 'Saving Saved Configs', 'Saving the verified files safely; the manifest is finalized last.', $processedItems);
        $published = $workspace->publish($stalePaths);
        $summary['created_count'] = count((array) ($preview['create'] ?? []));
        $summary['updated_count'] = count((array) ($preview['update'] ?? []));
        $summary['written'] = $published['written'];
        $summary['deleted'] = $published['deleted'];
        $summary['skipped'] = array_values(array_unique(array_merge($summary['skipped'], $published['skipped'])));

        // Persist selected-scope aliases only after the filesystem commit.
        foreach ($resolvedPartitions as $type => $partition) {
          $this->scope->persistResolvedMatches((string) $type, (array) $partition);
        }
        $completedSteps++;
        $this->reportProgress($progress, $completedSteps, $totalSteps, 'Saved Configs saved', 'Updating local synchronization baseline.', $processedItems);

        // Accept baseline state one staged YAML document at a time. This keeps
        // canonicalization memory bounded and ensures the baseline represents
        // the exact snapshot which was just committed.
        try {
          $stateManager = new ConfigStateManager();
          $stageStorage = $workspace->getStageStorage();
          foreach ($successfulHandlers as $handler) {
            $directory = trim((string) $handler->getDirectory(), '/');
            foreach ($stageStorage->iterateDirectory($directory) as $filename => $data) {
              $stateManager->acceptYamlBaselineItem($handler, (string) $filename, (array) $data, 'export');
            }
          }
        }
        catch (\Throwable $e) {
          $summary['warnings'][] = ['type' => 'state', 'message' => 'YAML export succeeded, but local baseline state could not be updated: ' . $e->getMessage()];
        }
      }
      if ($dryRun) {
        $completedSteps++;
      }
      $completedSteps = $totalSteps;
      $this->reportProgress($progress, $completedSteps, $totalSteps, $dryRun ? 'Export preview complete' : 'Export complete', 'Configuration snapshot processing finished.', $processedItems);
    }
    catch (\Throwable $e) {
      $summary['errors'][] = ['type' => 'export', 'message' => $e->getMessage()];
      $summary['ok'] = FALSE;
      $summary['message'] = 'Export failed. The previous Saved Config snapshot was preserved or rolled back.';
      return $summary;
    }
    finally {
      $workspace->cleanup();
    }

    $summary['ok'] = empty($summary['errors']);
    if ($dryRun && !$summary['planned'] && !$summary['errors']) {
      $summary['message'] = 'No export changes. Saved Configs already match Current CiviCRM.';
    }
    elseif (!$dryRun && !$summary['written'] && !$summary['deleted'] && !$summary['errors']) {
      $summary['message'] = 'No files written. Saved Configs already match Current CiviCRM.';
    }
    return $summary;
  }
}
