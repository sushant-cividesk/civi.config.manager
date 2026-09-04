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
      'identity_fields' => ['report_id', 'name|title'],
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
    $seen = [];
    foreach ($this->iterateApi3Rows() as $row) {
      $raw = (array) $row;
      $sourceId = isset($raw['id']) && is_scalar($raw['id']) ? (int) $raw['id'] : NULL;
      $row = $this->cleanRow($raw);
      $identity = $this->portableIdentity($row, $sourceId);
      if (isset($seen[$identity['key']])) {
        throw new \RuntimeException('ReportInstance portable identity is not unique: ' . $identity['key'] . '; source IDs ' . (string) $seen[$identity['key']] . ' and ' . (string) ($sourceId ?? 'unknown') . '. No live YAML was changed.');
      }
      $seen[$identity['key']] = $sourceId ?? 'unknown';
      yield [
        'filename' => $this->safeFilePart($identity['report_id']) . '__' . $this->safeFilePart($identity['value']) . '--' . substr(sha1($identity['key']), 0, 10) . '.yml',
        'source_id' => $sourceId,
        'data' => [
          'schema_version' => 1,
          'type' => 'report-instances.item',
          'entity' => 'ReportInstance',
          'key_fields' => $identity['fields'],
          'key' => $identity['key'],
          'identity_mode' => $identity['mode'],
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
      try {
        $this->portableIdentity($row, NULL);
      }
      catch (\Throwable $e) {
        $errors[] = ['file' => $filename, 'message' => $e->getMessage()];
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
      if (($document['type'] ?? '') !== 'report-instances.item') {
        $summary['errors'][] = ['file' => $filename, 'message' => 'Invalid report instance document.'];
        continue;
      }
      try {
        $identity = $this->portableIdentity($row, NULL);
        $existing = $this->findByIdentity($identity);
        if ($existing) {
          if ($this->desiredDiffers($existing, $row)) {
            $summary['update']++;
            if (!$dryRun) {
              if (empty($existing['id'])) {
                throw new \RuntimeException('Existing report instance has no local ID for update: ' . $identity['key']);
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
        $summary['errors'][] = ['file' => $filename, 'name' => (string) ($row['name'] ?? $row['title'] ?? ''), 'message' => $e->getMessage()];
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

  /** @param array{mode:string,fields:array<int,string>,key:string,report_id:string,value:string} $identity */
  private function findByIdentity(array $identity): array {
    $params = [
      'sequential' => 1,
      'report_id' => $identity['report_id'],
      'return' => array_merge(['id'], self::FIELDS),
      'options' => ['limit' => 3],
    ];
    if ($identity['mode'] === 'name') {
      $params['name'] = $identity['value'];
    }
    else {
      $params['title'] = $identity['value'];
    }
    $result = $this->api3('ReportInstance', 'get', $params);
    $rows = array_values((array) ($result['values'] ?? []));
    if ($identity['mode'] === 'legacy-title') {
      $rows = array_values(array_filter($rows, static function($row): bool {
        return trim((string) ((array) $row)['name'] ?? '') === '';
      }));
    }
    if (count($rows) > 1) {
      throw new \RuntimeException('ReportInstance portable identity is ambiguous on the target site: ' . $identity['key'] . '.');
    }
    return isset($rows[0]) ? (array) $rows[0] : [];
  }

  /** @return array{mode:string,fields:array<int,string>,key:string,report_id:string,value:string} */
  private function portableIdentity(array $row, ?int $sourceId): array {
    $reportId = trim((string) ($row['report_id'] ?? ''));
    $name = trim((string) ($row['name'] ?? ''));
    $title = trim((string) ($row['title'] ?? ''));
    $source = $sourceId === NULL ? '' : ' Source ID: ' . $sourceId . '.';
    if ($reportId === '') {
      throw new \RuntimeException('ReportInstance is missing report_id (report template/provider), so it cannot be managed portably.' . $source . ' No live YAML was changed.');
    }
    if ($name !== '') {
      return [
        'mode' => 'name',
        'fields' => ['report_id', 'name'],
        'key' => 'report_id=' . $reportId . '|name=' . $name,
        'report_id' => $reportId,
        'value' => $name,
      ];
    }
    if ($title === '') {
      throw new \RuntimeException('ReportInstance has neither a portable name nor a title fallback.' . $source . ' No live YAML was changed.');
    }
    return [
      'mode' => 'legacy-title',
      'fields' => ['report_id', 'title'],
      'key' => 'report_id=' . $reportId . '|title=' . $title,
      'report_id' => $reportId,
      'value' => $title,
    ];
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
