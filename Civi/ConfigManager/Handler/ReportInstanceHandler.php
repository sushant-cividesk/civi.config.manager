<?php
namespace Civi\ConfigManager\Handler;

/**
 * Reviewed APIv3 adapter for traditional CiviReport instances.
 *
 * ReportInstance is not exposed as a normal API4 DAO entity on supported
 * installations. This adapter intentionally manages only portable instance
 * configuration and never report output, contacts, contributions, or other
 * business rows returned by a report.
 */
class ReportInstanceHandler extends AbstractHandler implements StreamingHandlerInterface, StreamingImportHandlerInterface {

  private const FIELDS = [
    'name', 'title', 'report_id', 'description', 'permission', 'grouprole',
    'is_active', 'is_reserved', 'form_values',
  ];

  public function getType(): string { return 'report-instances'; }
  public function getLabel(): string { return 'Reports'; }
  public function getDirectory(): string { return 'reports/instances'; }
  public function getWeight(): int { return 150; }

  public function getProviderMetadata(): array {
    return [
      'owner' => 'civicrm-core',
      'api_version' => 'api3',
      'entity' => 'ReportInstance',
      'actions' => ['read' => TRUE, 'create' => TRUE, 'update' => TRUE, 'delete' => FALSE],
      'field_names' => self::FIELDS,
      'identity_fields' => ['report_id', 'name'],
      'reference_fields' => [],
      'sensitive_fields' => [],
      'runtime_fields' => ['id', 'navigation_id'],
      'management_capability' => 'managed_no_delete',
      'identity_evidence' => 'reviewed_adapter',
      'metadata_completeness' => 'declared',
    ];
  }

  public function getRuntimeAvailability(): array {
    if (!function_exists('civicrm_api3')) {
      return ['available' => FALSE, 'management_capability' => 'unsupported', 'reason' => 'CiviCRM APIv3 is unavailable for ReportInstance.'];
    }
    try {
      $actions = $this->api3('ReportInstance', 'getactions', ['sequential' => 1]);
      $names = [];
      foreach ((array) ($actions['values'] ?? []) as $key => $value) {
        if (is_string($key) && $key !== '') {
          $names[] = strtolower($key);
        }
        if (is_scalar($value) && (string) $value !== '') {
          $names[] = strtolower((string) $value);
        }
        elseif (is_array($value) && !empty($value['name'])) {
          $names[] = strtolower((string) $value['name']);
        }
      }
      $names = array_values(array_unique($names));
      foreach (['get', 'create'] as $required) {
        if (!in_array($required, $names, TRUE)) {
          return ['available' => FALSE, 'management_capability' => 'unsupported', 'reason' => 'ReportInstance APIv3 is missing required action: ' . $required . '.'];
        }
      }
      return ['available' => TRUE, 'management_capability' => 'managed_no_delete', 'reason' => 'Report instances support reviewed APIv3 read/create-update management; delete-missing is intentionally disabled.'];
    }
    catch (\Throwable $e) {
      return ['available' => FALSE, 'management_capability' => 'unsupported', 'reason' => $e->getMessage()];
    }
  }

  public function export(): array {
    return iterator_to_array($this->iterateExport(), FALSE);
  }

  public function iterateExport(): iterable {
    foreach ($this->iterateApi3Rows() as $row) {
      $row = $this->cleanRow((array) $row);
      $reportId = trim((string) ($row['report_id'] ?? ''));
      $name = trim((string) ($row['name'] ?? ''));
      if ($reportId === '' || $name === '') {
        throw new \RuntimeException('ReportInstance requires portable report_id + name identity; an unnamed or template-less report cannot be exported safely.');
      }
      yield [
        'filename' => $this->safeFilePart($reportId) . '__' . $this->safeFilePart($name) . '.yml',
        'data' => [
          'schema_version' => 1,
          'type' => 'report-instances.item',
          'entity' => 'ReportInstance',
          'key_fields' => ['report_id', 'name'],
          'key' => 'report_id=' . $reportId . '|name=' . $name,
          'capabilities' => ['create' => TRUE, 'update' => TRUE, 'delete' => FALSE],
          'dependencies' => [],
          'item' => $row,
        ],
      ];
    }
  }

  public function validate(array $items): array {
    $errors = [];
    $warnings = [];
    foreach ($items as $filename => $document) {
      if (($document['type'] ?? '') !== 'report-instances.item' || ($document['entity'] ?? '') !== 'ReportInstance') {
        $errors[] = ['file' => $filename, 'message' => 'Expected a report-instances.item ReportInstance document.'];
        continue;
      }
      $row = (array) ($document['item'] ?? []);
      if (trim((string) ($row['report_id'] ?? '')) === '' || trim((string) ($row['name'] ?? '')) === '') {
        $errors[] = ['file' => $filename, 'message' => 'Report instance is missing portable report_id + name identity.'];
      }
      foreach (['id', 'navigation_id', 'email_to', 'email_cc'] as $forbidden) {
        if (array_key_exists($forbidden, $row)) {
          $errors[] = ['file' => $filename, 'message' => 'Non-portable or delivery-specific field must not be present in report YAML: ' . $forbidden . '.'];
        }
      }
      if (!array_key_exists('dependencies', $document)) {
        $warnings[] = ['file' => $filename, 'message' => 'Dependency metadata is missing. Re-export this report before deployment.'];
      }
    }
    return ['type' => $this->getType(), 'valid' => !$errors, 'warnings' => $warnings, 'errors' => $errors, 'count' => count($items)];
  }

  public function import(array $items, bool $dryRun = TRUE): array {
    return $this->importIterable($items, $dryRun);
  }

  public function importIterable(iterable $items, bool $dryRun = TRUE): array {
    $summary = ['type' => $this->getType(), 'status' => $dryRun ? 'dry_run' : 'applied', 'dry_run' => $dryRun, 'create' => 0, 'update' => 0, 'delete' => 0, 'skip' => 0, 'warnings' => [], 'errors' => []];
    foreach ($items as $filename => $document) {
      $row = $this->cleanRow((array) ($document['item'] ?? []));
      $reportId = trim((string) ($row['report_id'] ?? ''));
      $name = trim((string) ($row['name'] ?? ''));
      if (($document['type'] ?? '') !== 'report-instances.item' || $reportId === '' || $name === '') {
        $summary['errors'][] = ['file' => $filename, 'message' => 'Invalid report instance document or missing report_id + name identity.'];
        continue;
      }
      try {
        $existing = $this->findByIdentity($reportId, $name);
        if ($existing) {
          if ($this->desiredDiffers($existing, $row)) {
            $summary['update']++;
            if (!$dryRun) {
              if (empty($existing['id'])) {
                throw new \RuntimeException('Existing report instance has no local ID for update: ' . $name);
              }
              $this->api3('ReportInstance', 'create', $row + ['id' => $existing['id'], 'sequential' => 1]);
            }
          }
          else {
            $summary['skip']++;
          }
        }
        else {
          $summary['create']++;
          if (!$dryRun) {
            $this->api3('ReportInstance', 'create', $row + ['sequential' => 1]);
          }
        }
      }
      catch (\Throwable $e) {
        $summary['errors'][] = ['file' => $filename, 'name' => $name, 'message' => $e->getMessage()];
      }
    }
    $summary['ok'] = !$summary['errors'];
    return $summary;
  }


  /** @return \Generator<int,array<string,mixed>> */
  private function iterateApi3Rows(): \Generator {
    $limit = 200;
    $offset = 0;
    while (TRUE) {
      $result = $this->api3('ReportInstance', 'get', [
        'sequential' => 1,
        'return' => self::FIELDS,
        'options' => ['limit' => $limit, 'offset' => $offset, 'sort' => 'name ASC'],
      ]);
      $rows = array_values((array) ($result['values'] ?? []));
      foreach ($rows as $row) {
        yield (array) $row;
      }
      if (count($rows) < $limit) {
        break;
      }
      $offset += count($rows);
    }
  }

  protected function normaliseDataForDiff(array $data): array {
    $data = parent::normaliseDataForDiff($data);
    if (isset($data['item']) && is_array($data['item'])) {
      $data['item'] = $this->cleanRow($data['item']);
    }
    return $data;
  }

  protected function api3(string $entity, string $action, array $params): array {
    return (array) civicrm_api3($entity, $action, $params);
  }

  private function findByIdentity(string $reportId, string $name): array {
    $result = $this->api3('ReportInstance', 'get', ['sequential' => 1, 'report_id' => $reportId, 'name' => $name, 'return' => array_merge(['id'], self::FIELDS), 'options' => ['limit' => 2]]);
    $rows = array_values((array) ($result['values'] ?? []));
    if (count($rows) > 1) {
      throw new \RuntimeException('ReportInstance report_id + name identity is not unique on the target site: ' . $reportId . ' / ' . $name);
    }
    return isset($rows[0]) ? (array) $rows[0] : [];
  }

  private function cleanRow(array $row): array {
    $allowed = array_flip(self::FIELDS);
    $row = array_intersect_key($row, $allowed);
    unset($row['id'], $row['navigation_id']);
    ksort($row, SORT_NATURAL | SORT_FLAG_CASE);
    return $row;
  }

  private function safeFilePart(string $name): string {
    $safe = trim((string) preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name), '-');
    return $safe === '' ? sha1($name) : $safe;
  }
}
