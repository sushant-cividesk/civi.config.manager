<?php
namespace Civi\ConfigManager\Handler;

/**
 * Manages site-wide token definitions when a supported SiteToken API/table is available.
 * The handler is optional: sites without site-token support export no files and import
 * will explain that the dependency is missing instead of fataling.
 */
class SiteTokenHandler extends AbstractHandler {
  private bool $importWritesEnabled = TRUE;
  private bool $deleteMissingEnabled = TRUE;

  public function getType(): string { return 'site-tokens'; }
  public function getLabel(): string { return 'Site Tokens'; }
  public function getDirectory(): string { return 'site-tokens'; }
  public function getWeight(): int { return 85; }

  public function getProviderMetadata(): array {
    return [
      'owner' => 'civi.config.manager', 'api_version' => 'api4', 'entity' => 'SiteToken',
      'actions' => ['read' => TRUE, 'create' => TRUE, 'update' => TRUE, 'delete' => TRUE],
      'field_names' => ['name','token_name','label','provider_owned_fields'],
      'identity_fields' => ['name','token_name','label'], 'reference_fields' => ['provider_declared_only'],
      'sensitive_fields' => ['provider_declared_only'], 'runtime_fields' => ['provider_declared_only'], 'management_capability' => 'full',
      'identity_evidence' => 'handler_policy', 'metadata_completeness' => 'declared_dynamic_provider',
    ];
  }

  public function getRuntimeAvailability(): array {
    return $this->api4ManagementAvailability('SiteToken', ['get', 'create', 'update', 'delete']);
  }

  public function setImportWriteEnabled(bool $enabled): self { $this->importWritesEnabled = $enabled; return $this; }
  public function setDeleteMissingEnabled(bool $enabled): self { $this->deleteMissingEnabled = $enabled; return $this; }

  public function export(): array {
    if (!$this->isAvailable()) {
      throw new \RuntimeException('SiteToken API4 entity is not available on this site.');
    }
    $rows = $this->api4Get('SiteToken', [], ['*'], ['name' => 'ASC']);
    $files = [];
    foreach ($rows as $row) {
      $row = (array) $row;
      $sourceId = isset($row['id']) && is_scalar($row['id']) ? (int) $row['id'] : NULL;
      $row = $this->cleanValues($row);
      $name = (string) ($row['name'] ?? $row['token_name'] ?? $row['label'] ?? '');
      if ($name === '') {
        continue;
      }
      $files[] = [
        'filename' => $this->safeName($name) . '.yml',
        'source_id' => $sourceId,
        'data' => [
          'schema_version' => 1,
          'type' => 'site_token.item',
          'entity' => 'SiteToken',
          'name' => $name,
          'identity_field' => array_key_exists('name', $row) ? 'name' : (array_key_exists('token_name', $row) ? 'token_name' : 'label'),
          'dependencies' => [],
          'item' => $row,
        ],
      ];
    }
    return $files;
  }

  public function validate(array $items): array {
    $errors = [];
    $warnings = [];
    if ($items && !$this->isAvailable()) {
      $errors[] = ['message' => 'SiteToken API4 entity is not available on this site. Install/enable the site-token provider before importing site token YAML.'];
    }
    foreach ($items as $filename => $file) {
      if (($file['type'] ?? '') !== 'site_token.item') {
        $errors[] = ['file' => $filename, 'message' => 'Invalid type. Expected site_token.item.'];
        continue;
      }
      $row = (array) ($file['item'] ?? []);
      if (empty($row['name']) && empty($row['token_name']) && empty($row['label'])) {
        $errors[] = ['file' => $filename, 'message' => 'Site token item is missing a stable identity field.'];
      }
    }
    return ['type' => $this->getType(), 'valid' => empty($errors), 'warnings' => $warnings, 'errors' => $errors, 'count' => count($items)];
  }

  public function import(array $items, bool $dryRun = TRUE): array {
    $summary = $this->baseImportSummary($dryRun);
    if ($items && !$this->isAvailable()) {
      $summary['errors'][] = ['message' => 'SiteToken API4 entity is not available on this site.'];
      $summary['ok'] = FALSE;
      return $summary;
    }
    $desired = [];
    foreach ($items as $filename => $file) {
      $row = $this->cleanValues((array) ($file['item'] ?? []));
      $identity = $this->identityField($row);
      if (!$identity) {
        $summary['errors'][] = ['file' => $filename, 'message' => 'Site token item is missing a stable identity field.'];
        continue;
      }
      $value = (string) $row[$identity];
      $desired[$identity . ':' . $value] = TRUE;
      if (!$this->importWritesEnabled) {
        continue;
      }
      try {
        $existing = $this->api4GetFirst('SiteToken', [[$identity, '=', $value]], ['*']);
        if ($existing) {
          if ($this->desiredDiffers($existing, $row)) {
            $summary['update']++;
            if (!$dryRun) {
              $this->api4Update('SiteToken', [['id', '=', $existing['id']]], $row);
            }
          }
          else {
            $summary['skip']++;
          }
        }
        else {
          $summary['create']++;
          if (!$dryRun) {
            $this->api4Create('SiteToken', $row);
          }
        }
      }
      catch (\Throwable $e) {
        $summary['errors'][] = ['file' => $filename, 'name' => $value, 'message' => $e->getMessage()];
      }
    }
    if ($this->deleteMissingEnabled && $this->isAvailable()) {
      foreach ($this->api4Get('SiteToken', [], ['id', 'name', 'token_name', 'label'], ['name' => 'ASC']) as $existing) {
        $existing = (array) $existing;
        $identity = $this->identityField($existing);
        if (!$identity || isset($desired[$identity . ':' . (string) $existing[$identity]])) {
          continue;
        }
        $summary['delete']++;
        $summary['warnings'][] = ['name' => (string) $existing[$identity], 'message' => 'Site token exists in CiviCRM but not YAML and will be deleted: ' . (string) $existing[$identity]];
        if (!$dryRun) {
          $this->api4Delete('SiteToken', [['id', '=', (int) $existing['id']]]);
        }
      }
    }
    $summary['ok'] = empty($summary['errors']);
    return $summary;
  }

  private function isAvailable(): bool {
    return class_exists('Civi\\Api4\\SiteToken');
  }

  private function identityField(array $row): ?string {
    foreach (['name', 'token_name', 'label'] as $field) {
      if (!empty($row[$field])) {
        return $field;
      }
    }
    return NULL;
  }

  private function safeName(string $name): string {
    $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name);
    return trim((string) $safe, '-') ?: sha1($name);
  }
}
