<?php
namespace Civi\ConfigManager\Service;

use Civi\ConfigManager\Storage\YamlFileStorage;

/**
 * ConfigManagerImportReview methods from ConfigManager.
 *
 * Methods run on the original owning object and retain the same state and
 * access modifiers. Do not instantiate this trait separately.
 */
trait ConfigManagerImportReviewTrait {

  /**
   * Build and persist the exact green Import preview which may later be applied.
   *
   * The returned opaque plan ID is the only browser/API value needed for apply;
   * mutable type/scope state is retained and verified server-side.
   *
   * @return array<string,mixed>
   */
  public function createImportReviewPlan(array $typeFilter = [], array $reviewMetadata = []): array {
    $context = $this->buildImportReviewContext($typeFilter);
    $preview = $this->import(TRUE, FALSE, $context['requested_types'], NULL, TRUE);
    $activeSnapshots = (array) ($preview['_active_fingerprints'] ?? []);
    unset($preview['_active_fingerprints']);

    if (empty($preview['ok'])) {
      $preview['plan_id'] = '';
      $preview['review_plan_ready'] = FALSE;
      return $this->importResultAccounting->attach($preview);
    }

    $storage = new YamlFileStorage($this->getSyncDir());
    $yamlSnapshots = [];
    foreach ($context['handlers'] as $handler) {
      $type = (string) $handler->getType();
      $yamlSnapshots[$type] = $this->compactManagedYamlSnapshot($handler, $storage);
      if (!array_key_exists($type, $activeSnapshots)) {
        throw new \RuntimeException('Could not freeze Current CiviCRM state for reviewed Import type: ' . $type);
      }
    }

    $plan = $this->importPlanGuard->create([
      'operation' => 'import',
      'initiating_user' => $this->importPlanGuard->currentUserId(),
      'site_identifier' => $this->getSiteIdentifier(),
      'sync_root_hash' => hash('sha256', $this->getSyncDir()),
      'requested_types' => $context['requested_types'],
      'effective_types' => $context['effective_types'],
      'validation_types' => $context['validation_types'],
      'apply_types' => array_values(array_map(static function($handler): string {
        return (string) $handler->getType();
      }, $context['handlers'])),
      'scope_fingerprint' => $this->importPlanGuard->scopeFingerprint($context['handlers'], $this->getIgnoreRules()),
      'manifest_fingerprint' => $this->importPlanGuard->fingerprint($storage->readFile('manifest.yml')),
      'provider_fingerprint' => $this->importPlanGuard->providerFingerprint($context['handlers']),
      'implementation_fingerprint' => $this->importPlanGuard->implementationFingerprint($context['handlers']),
      'yaml_snapshots' => $yamlSnapshots,
      'active_snapshots' => $activeSnapshots,
      'preview_fingerprint' => $this->importPlanGuard->preflightFingerprint($preview),
      'excluded_components' => $this->sanitizeExcludedComponents((array) ($reviewMetadata['excluded_components'] ?? [])),
    ]);

    $preview['plan_id'] = (string) $plan['plan_id'];
    $preview['plan_fingerprint'] = (string) $plan['integrity_hash'];
    $preview['plan_expires_at'] = (string) $plan['expires_at'];
    $preview['review_plan_ready'] = TRUE;
    $preview['excluded_components'] = (array) ($plan['excluded_components'] ?? []);
    return $this->importResultAccounting->attach($preview, $preview['excluded_components']);
  }

  /** @return array<string,mixed> */
  public function createReducedImportReviewPlan(string $componentId, array $typeFilter = []): array {
    return (new ReducedImportPlanService($this->importDependencyPlanner))->build($this, $componentId, $typeFilter);
  }

  /**
   * Load a reviewed Import plan and fail closed if its immutable inputs changed.
   *
   * @return array<string,mixed>
   */
  public function validateImportReviewPlan(string $planId, array $submittedTypes = [], bool $checkContentState = TRUE): array {
    $plan = $this->importPlanGuard->load($planId);
    if ((string) ($plan['operation'] ?? '') !== 'import') {
      throw $this->importPlanGuard->stale('the stored operation is not an Import preview');
    }

    $owner = (int) ($plan['initiating_user'] ?? 0);
    $currentUser = $this->importPlanGuard->currentUserId();
    if ($owner > 0 && $currentUser > 0 && $owner !== $currentUser) {
      throw $this->importPlanGuard->stale('the preview belongs to another administrator');
    }
    if ((string) ($plan['site_identifier'] ?? '') !== $this->getSiteIdentifier()) {
      throw $this->importPlanGuard->stale('the site identity changed');
    }
    if ((string) ($plan['sync_root_hash'] ?? '') !== hash('sha256', $this->getSyncDir())) {
      throw $this->importPlanGuard->stale('the Saved Config directory changed');
    }

    $planTypes = array_values(array_map('strval', (array) ($plan['requested_types'] ?? [])));
    // Compare submitted values before managed-type normalization. Dropping an
    // unknown extra type here would turn request tampering into a valid plan.
    $submitted = $this->sortedInventoryStrings($submittedTypes);
    if ($submitted && $submitted !== $this->sortedInventoryStrings($planTypes)) {
      throw $this->importPlanGuard->stale('the requested configuration type selection changed');
    }

    $context = $this->buildImportReviewContext($planTypes);
    $actualApplyTypes = array_values(array_map(static function($handler): string {
      return (string) $handler->getType();
    }, $context['handlers']));
    foreach (['requested_types', 'effective_types', 'validation_types'] as $key) {
      if ($this->sortedInventoryStrings((array) ($plan[$key] ?? [])) !== $this->sortedInventoryStrings((array) $context[$key])) {
        throw $this->importPlanGuard->stale('the effective Import scope changed');
      }
    }
    if ($this->sortedInventoryStrings((array) ($plan['apply_types'] ?? [])) !== $this->sortedInventoryStrings($actualApplyTypes)) {
      throw $this->importPlanGuard->stale('the available Import handlers changed');
    }

    $storage = new YamlFileStorage($this->getSyncDir());
    if ((string) ($plan['scope_fingerprint'] ?? '') !== $this->importPlanGuard->scopeFingerprint($context['handlers'], $this->getIgnoreRules())) {
      throw $this->importPlanGuard->stale('Configuration Scope or Config Ignore changed');
    }
    if ((string) ($plan['manifest_fingerprint'] ?? '') !== $this->importPlanGuard->fingerprint($storage->readFile('manifest.yml'))) {
      throw $this->importPlanGuard->stale('the Saved Config manifest changed');
    }
    if ((string) ($plan['provider_fingerprint'] ?? '') !== $this->importPlanGuard->providerFingerprint($context['handlers'])) {
      throw $this->importPlanGuard->stale('provider availability or management capability changed');
    }
    if ((string) ($plan['implementation_fingerprint'] ?? '') !== $this->importPlanGuard->implementationFingerprint($context['handlers'])) {
      throw $this->importPlanGuard->stale('Configuration Manager or a selected handler changed');
    }

    if ($checkContentState) {
      foreach ($context['handlers'] as $handler) {
        $type = (string) $handler->getType();
        $expectedYaml = (array) (($plan['yaml_snapshots'] ?? [])[$type] ?? []);
        $expectedActive = (array) (($plan['active_snapshots'] ?? [])[$type] ?? []);
        if ($this->compactManagedYamlSnapshot($handler, $storage) !== $expectedYaml) {
          throw $this->importPlanGuard->stale('Saved Config changed after preview');
        }
        if ($this->compactManagedActiveSnapshot($handler, $storage) !== $expectedActive) {
          throw $this->importPlanGuard->stale('Current CiviCRM changed after preview');
        }
      }
    }

    return $plan;
  }

  /** @return array<string,mixed> */
  public function applyImportReviewPlan(string $planId, ?callable $progress = NULL, array $submittedTypes = []): array {
    try {
      $plan = $this->validateImportReviewPlan($planId, $submittedTypes, TRUE);
    }
    catch (\Throwable $e) {
      if (preg_match('/^[a-f0-9]{48}$/', $planId)) {
        $this->importPlanGuard->discard($planId);
      }
      throw $e;
    }
    try {
      $result = $this->import(FALSE, TRUE, (array) $plan['requested_types'], $progress, FALSE, $plan);
      $excluded = (array) ($plan['excluded_components'] ?? []);
      $verifiedDiff = $this->verifiedPostImportDiff();
      return $this->importResultAccounting->attach($result, $excluded, $verifiedDiff);
    }
    finally {
      // A plan is single-use once apply has started. Even a partial runtime
      // failure changes the safety context and must be reviewed again.
      $this->importPlanGuard->discard($planId);
    }
  }

  /** @return array<string,mixed> */
  public function resumeImportReviewPlan(string $planId, array $submittedTypes = []): array {
    $plan = $this->validateImportReviewPlan($planId, $submittedTypes, TRUE);
    $preview = $this->import(TRUE, FALSE, (array) $plan['requested_types'], NULL, TRUE);
    $this->assertReviewPlanPreflightMatches($plan, $preview);
    unset($preview['_active_fingerprints']);
    $preview['plan_id'] = $planId;
    $preview['plan_fingerprint'] = (string) ($plan['integrity_hash'] ?? '');
    $preview['plan_expires_at'] = (string) ($plan['expires_at'] ?? '');
    $preview['review_plan_ready'] = TRUE;
    $preview['excluded_components'] = (array) ($plan['excluded_components'] ?? []);
    return $this->importResultAccounting->attach($preview, $preview['excluded_components']);
  }

  public function discardImportReviewPlan(string $planId): void {
    $this->importPlanGuard->discard($planId);
  }

  /** @return array<string,mixed> */
  private function buildImportReviewContext(array $typeFilter): array {
    $requestedTypes = $this->normaliseTypeFilter($typeFilter);
    $effectiveTypes = $this->getEffectiveExportTypeFilter($requestedTypes);
    $validationTypes = $this->getImportValidationTypeFilter($requestedTypes, $effectiveTypes);
    $applyTypes = $this->getImportApplyTypeFilter($requestedTypes, $effectiveTypes);
    $handlers = [];
    foreach ($this->getHandlers() as $handler) {
      if ($applyTypes && !in_array((string) $handler->getType(), $applyTypes, TRUE)) {
        continue;
      }
      $this->prepareHandlerForTypeFilter($handler, $requestedTypes);
      $handlers[] = $handler;
    }
    return [
      'requested_types' => $requestedTypes,
      'effective_types' => $effectiveTypes,
      'validation_types' => $validationTypes,
      'apply_types' => $applyTypes,
      'handlers' => $handlers,
    ];
  }

  private function compactManagedYamlSnapshot($handler, YamlFileStorage $storage): array {
    $identityService = new ConfigIdentity();
    $canonicalizer = new Canonicalizer();
    $options = method_exists($handler, 'getCanonicalizationOptions') ? (array) $handler->getCanonicalizationOptions() : [];
    $directory = trim((string) $handler->getDirectory(), '/');
    $groups = [];
    foreach ($this->iterateManagedYamlFilesForHandler($handler, $storage) as $filename => $data) {
      $filename = ltrim((string) $filename, '/');
      $relative = $directory === '' ? $filename : $directory . '/' . $filename;
      $row = $this->compactDiffRow((string) $handler->getType(), $filename, $relative, (array) $data, $identityService, $canonicalizer, $options);
      $groups[(string) $row['identity']['config_key']][] = $row;
    }
    $indexed = $this->indexCompactDiffGroups($groups);
    $result = [];
    foreach ($indexed as $key => $row) {
      $result[(string) $key] = (string) ($row['hash'] ?? '');
    }
    ksort($result, SORT_STRING);
    return $result;
  }

  private function assertReviewPlanPreflightMatches(array $plan, array $preflight): void {
    $active = (array) ($preflight['_active_fingerprints'] ?? []);
    $expectedActive = (array) ($plan['active_snapshots'] ?? []);
    if ($active !== $expectedActive) {
      throw $this->importPlanGuard->stale('Current CiviCRM changed while the reviewed preview was being rechecked');
    }
    $copy = $preflight;
    unset($copy['_active_fingerprints']);
    $actualPreview = $this->importPlanGuard->preflightFingerprint($copy);
    if (!hash_equals((string) ($plan['preview_fingerprint'] ?? ''), $actualPreview)) {
      throw $this->importPlanGuard->stale('the Import preflight result no longer matches the reviewed preview');
    }
  }

  private function assertReviewPlanHandlerYamlMatches(array $plan, $handler, YamlFileStorage $storage): void {
    $type = (string) $handler->getType();
    $expected = (array) (($plan['yaml_snapshots'] ?? [])[$type] ?? []);
    if ($this->compactManagedYamlSnapshot($handler, $storage) !== $expected) {
      throw $this->importPlanGuard->stale('Saved Config changed after preview');
    }
  }

  /** @return array<string,mixed> */
  private function validateQueuedReviewPlan(array $state): array {
    $planId = trim((string) ($state['review_plan_id'] ?? ''));
    $storedPlan = (array) ($state['review_plan'] ?? []);
    if ($planId === '' || !$storedPlan) {
      throw new \RuntimeException('Reviewed Import plan state is missing. Start a fresh Import preview.');
    }
    $plan = $this->validateImportReviewPlan(
      $planId,
      array_values(array_map('strval', (array) ($state['requested_types'] ?? []))),
      FALSE
    );
    if (!hash_equals((string) ($storedPlan['integrity_hash'] ?? ''), (string) ($plan['integrity_hash'] ?? ''))) {
      throw $this->importPlanGuard->stale('the reviewed plan identity changed while the queued import was running');
    }
    return $plan;
  }
}
