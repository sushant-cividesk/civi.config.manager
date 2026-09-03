<?php
namespace Civi\ConfigManager\Handler;

class FinancialTypeHandler extends AbstractHandler {
  public function getType(): string { return 'financial-types'; }
  public function getLabel(): string { return 'Financial Types'; }
  public function getDirectory(): string { return 'financial'; }
  public function getWeight(): int { return 40; }

  public function getProviderMetadata(): array {
    return [
      'owner' => 'civi.config.manager', 'api_version' => 'api4', 'entity' => 'FinancialType',
      'actions' => ['read' => TRUE, 'create' => TRUE, 'update' => TRUE, 'delete' => FALSE],
      'field_names' => ['name','label','description','is_deductible','is_reserved','is_active'],
      'identity_fields' => ['name'], 'reference_fields' => [], 'sensitive_fields' => [], 'runtime_fields' => [],
      'management_capability' => 'managed_no_delete', 'identity_evidence' => 'handler_policy', 'metadata_completeness' => 'declared',
    ];
  }

  public function getRuntimeAvailability(): array {
    $availability = $this->api4ManagementAvailability('FinancialType', ['get', 'create', 'update']);
    if (empty($availability['available'])) {
      $availability['reason'] .= ' Install/enable CiviContribute before managing financial types.';
    }
    return $availability;
  }

  public function export(): array {
    $rows = $this->api4Get('FinancialType', [], ['id', 'name', 'label', 'description', 'is_deductible', 'is_reserved', 'is_active'], ['name' => 'ASC']);
    $files = [];
    foreach ($rows as $row) {
      $row = (array) $row;
      $sourceId = isset($row['id']) && is_scalar($row['id']) ? (int) $row['id'] : NULL;
      unset($row['id']);
      $name = trim((string) ($row['name'] ?? ''));
      if ($name === '') {
        continue;
      }
      $files[] = [
        'filename' => $this->safeName($name) . '.yml',
        'source_id' => $sourceId,
        'data' => [
          'schema_version' => 1,
          'type' => 'financial_type.item',
          'entity' => 'FinancialType',
          'name' => $name,
          'identity_field' => 'name',
          'identity_confidence' => 'DISCOVERED_UNIQUE',
          'dependencies' => [],
          'item' => $row,
        ],
      ];
    }
    return $files;
  }

  public function import(array $items, bool $dryRun = TRUE): array {
    $summary = $this->baseImportSummary($dryRun);
    foreach ($items as $filename => $file) {
      $rows = [];
      if (($file['type'] ?? '') === 'financial_type.item') {
        $rows[] = (array) ($file['item'] ?? []);
      }
      elseif (($file['type'] ?? '') === 'financial_type.collection') {
        // Transitional development compatibility for earlier alpha exports.
        $rows = array_values(array_filter((array) ($file['items'] ?? []), 'is_array'));
      }
      else {
        $summary['errors'][] = ['file' => $filename, 'message' => 'Invalid type. Expected financial_type.item.'];
        continue;
      }

      foreach ($rows as $row) {
        $row = $this->cleanValues((array) $row);
        if (empty($row['name'])) {
          $summary['errors'][] = ['file' => $filename, 'message' => 'Financial type is missing name.'];
          continue;
        }
        try {
          $existing = $this->api4GetFirst('FinancialType', [['name', '=', (string) $row['name']]], ['*']);
          if ($existing) {
            if ($this->desiredDiffers($existing, $row)) {
              $summary['update']++;
              if (!$dryRun) {
                $this->api4Update('FinancialType', [['id', '=', $existing['id']]], $row);
              }
            }
            else {
              $summary['skip']++;
            }
          }
          else {
            $summary['create']++;
            if (!$dryRun) {
              $this->api4Create('FinancialType', $row);
            }
          }
        }
        catch (\Throwable $e) {
          $summary['errors'][] = ['file' => $filename, 'name' => $row['name'], 'message' => $e->getMessage()];
        }
      }
    }
    $summary['ok'] = empty($summary['errors']);
    return $summary;
  }

  private function safeName(string $name): string {
    $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name);
    return trim((string) $safe, '-') ?: sha1($name);
  }
}
