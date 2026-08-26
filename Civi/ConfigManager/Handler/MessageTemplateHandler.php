<?php
namespace Civi\ConfigManager\Handler;

class MessageTemplateHandler extends AbstractHandler implements ScopePickerHintProviderInterface {
  private bool $importWritesEnabled = TRUE;
  private bool $deleteMissingEnabled = TRUE;

  public function getType(): string { return 'message-templates'; }
  public function getLabel(): string { return 'Message Templates'; }
  public function getDirectory(): string { return 'message-templates'; }
  public function getWeight(): int { return 90; }

  public function getRuntimeAvailability(): array {
    return $this->api4ManagementAvailability('MessageTemplate', ['get', 'create', 'update', 'delete']);
  }

  public function setImportWriteEnabled(bool $enabled): self {
    $this->importWritesEnabled = $enabled;
    return $this;
  }

  public function setDeleteMissingEnabled(bool $enabled): self {
    $this->deleteMissingEnabled = $enabled;
    return $this;
  }

  public function export(): array {
    $rows = $this->api4Get('MessageTemplate', [], ['id', 'msg_title', 'msg_subject', 'msg_text', 'msg_html', 'workflow_name', 'is_default', 'is_reserved', 'is_active'], ['workflow_name' => 'ASC', 'msg_title' => 'ASC', 'id' => 'ASC']);
    $userTitleCounts = [];
    foreach ($rows as $row) {
      $row = (array) $row;
      if (empty($row['workflow_name']) && !empty($row['msg_title'])) {
        $title = (string) $row['msg_title'];
        $userTitleCounts[$title] = ($userTitleCounts[$title] ?? 0) + 1;
      }
    }

    $files = [];
    $used = [];
    foreach ($rows as $row) {
      $row = (array) $row;
      $sourceId = isset($row['id']) && is_scalar($row['id']) ? (int) $row['id'] : NULL;
      $folder = !empty($row['workflow_name']) || !empty($row['is_reserved']) ? 'system' : 'user';
      $name = (string) ($row['workflow_name'] ?: $row['msg_title']);
      $base = $name;
      if (!empty($row['workflow_name']) && array_key_exists('is_default', $row)) {
        $base .= !empty($row['is_default']) ? '_default' : '_custom';
      }

      if (!empty($row['workflow_name'])) {
        $identityKey = 'workflow_name=' . (string) $row['workflow_name'] . '|is_default=' . (!empty($row['is_default']) ? '1' : '0');
        $identityConfidence = 'API_VERIFIED';
      }
      else {
        $title = (string) ($row['msg_title'] ?? '');
        $identityKey = 'msg_title=' . $title;
        $identityConfidence = ($title !== '' && ($userTitleCounts[$title] ?? 0) === 1) ? 'DISCOVERED_UNIQUE' : 'AMBIGUOUS';
      }

      unset($row['id']);
      $filename = $folder . '/' . $this->uniqueFileName($base, $used) . '.yml';
      $files[] = [
        'filename' => $filename,
        'source_id' => $sourceId,
        'data' => [
          'schema_version' => 1,
          'type' => 'message_template',
          'name' => $name,
          'identity_key' => $identityKey,
          'identity_confidence' => $identityConfidence,
          'dependencies' => [],
          'template' => $row,
        ],
      ];
    }
    return $files;
  }

  /**
   * Identify workflow templates that CiviCRM would offer to revert.
   *
   * CiviCRM compares each workflow template with its reserved reference copy
   * using the subject, text, and HTML fields. Reuse the already-exported API4
   * data here so the scope picker follows that model without raw SQL or a
   * version-specific dependency on the Message Templates admin page.
   *
   * @param array $exported
   *   Files returned by export().
   *
   * @return array
   *   Picker hints keyed by exported filename.
   */
  public function getScopePickerHints(array $exported): array {
    $reservedByWorkflow = [];
    $hints = [];
    foreach ($exported as $file) {
      $file = (array) $file;
      $filename = (string) ($file['filename'] ?? '');
      $template = (array) (($file['data']['template'] ?? []));
      $workflow = trim((string) ($template['workflow_name'] ?? ''));
      if ($workflow === '' || empty($template['is_reserved'])) {
        continue;
      }
      $reservedByWorkflow[$workflow][] = $template;
      if ($filename !== '') {
        // CiviCRM's Message Templates screen does not list these reserved
        // reference copies as editable workflow templates. Keep them available
        // in advanced scope selection, but clearly classify them and sort them
        // after the live templates so the picker mirrors core terminology.
        $hints[$filename] = [
          'reference' => TRUE,
          'recommendation' => 'System reference used by CiviCRM Revert',
        ];
      }
    }

    foreach ($exported as $file) {
      $file = (array) $file;
      $filename = (string) ($file['filename'] ?? '');
      $template = (array) (($file['data']['template'] ?? []));
      $workflow = trim((string) ($template['workflow_name'] ?? ''));
      if ($filename === '' || $workflow === '' || !empty($template['is_reserved'])) {
        continue;
      }

      $references = (array) ($reservedByWorkflow[$workflow] ?? []);
      if (count($references) !== 1 || !$this->workflowContentDiffers($template, (array) $references[0])) {
        continue;
      }

      $hints[$filename] = [
        'recommended' => TRUE,
        'recommendation' => 'CiviCRM shows Revert for this customized workflow template',
      ];
    }

    return $hints;
  }

  public function validate(array $items): array {
    $errors = [];
    $warnings = [];
    foreach ($items as $filename => $file) {
      if (($file['type'] ?? '') !== 'message_template') {
        $errors[] = ['file' => $filename, 'message' => 'Invalid type. Expected message_template.'];
        continue;
      }
      $template = (array) ($file['template'] ?? []);
      if (($file['identity_confidence'] ?? '') === 'AMBIGUOUS') {
        $errors[] = ['file' => $filename, 'message' => 'Message template identity is ambiguous. Use a unique user template title or a system workflow identity before importing.'];
      }
      if (empty($template['workflow_name']) && empty($template['msg_title'])) {
        $errors[] = ['file' => $filename, 'message' => 'Message template needs workflow_name or msg_title.'];
      }
      if (!array_key_exists('msg_html', $template) && !array_key_exists('msg_text', $template)) {
        $warnings[] = ['file' => $filename, 'message' => 'Message template has no msg_html or msg_text body.'];
      }
    }
    return ['type' => $this->getType(), 'valid' => empty($errors), 'warnings' => $warnings, 'errors' => $errors, 'count' => count($items)];
  }

  public function import(array $items, bool $dryRun = TRUE): array {
    $summary = $this->baseImportSummary($dryRun);
    $currentFiles = $this->currentExportedTemplatesByFilename();
    $seenFiles = [];
    foreach ($items as $filename => $file) {
      $seenFiles[$filename] = TRUE;
      if (($file['type'] ?? '') !== 'message_template') {
        $summary['errors'][] = ['file' => $filename, 'message' => 'Invalid type. Expected message_template.'];
        continue;
      }
      $template = $this->cleanValues((array) ($file['template'] ?? []));
      if (($file['identity_confidence'] ?? '') === 'AMBIGUOUS') {
        $summary['errors'][] = ['file' => $filename, 'message' => 'Message template identity is ambiguous. Import will not choose between duplicate user template titles.'];
        continue;
      }
      if (!$template) {
        $summary['errors'][] = ['file' => $filename, 'message' => 'No template data found.'];
        continue;
      }
      $where = $this->identityWhere($template);
      if (!$where) {
        $summary['errors'][] = ['file' => $filename, 'message' => 'Message template needs workflow_name or msg_title.'];
        continue;
      }

      if (!$this->importWritesEnabled) {
        continue;
      }

      try {
        $existing = $this->findExistingTemplate($template, $where);
        if ($existing) {
          if ($this->desiredDiffers($existing, $template)) {
            $summary['update']++;
            if (!$dryRun) {
              $this->api4Update('MessageTemplate', [['id', '=', $existing['id']]], $template);
            }
          }
          else {
            $summary['skip']++;
          }
        }
        else {
          $summary['create']++;
          if (!$dryRun) {
            $this->api4Create('MessageTemplate', $template);
          }
        }
      }
      catch (\Throwable $e) {
        $summary['errors'][] = ['file' => $filename, 'message' => $e->getMessage()];
      }
    }
    if ($this->deleteMissingEnabled) {
      $this->deleteTemplatesMissingFromYaml($currentFiles, $seenFiles, $dryRun, $summary);
    }
    $summary['ok'] = empty($summary['errors']);
    return $summary;
  }

  private function currentExportedTemplatesByFilename(): array {
    $files = [];
    foreach ($this->export() as $file) {
      if (!empty($file['filename'])) {
        $files[(string) $file['filename']] = (array) ($file['data']['template'] ?? []);
      }
    }
    return $files;
  }

  private function deleteTemplatesMissingFromYaml(array $currentFiles, array $seenFiles, bool $dryRun, array &$summary): void {
    foreach ($currentFiles as $filename => $template) {
      if (isset($seenFiles[$filename])) {
        continue;
      }
      $where = $this->identityWhere($template);
      if (!$where) {
        continue;
      }
      $existing = $this->findExistingTemplate($template, $where, ['id', 'msg_title', 'workflow_name', 'is_default']);
      if (!$existing || empty($existing['id'])) {
        continue;
      }
      $label = (string) (($existing['workflow_name'] ?? '') ?: ($existing['msg_title'] ?? $filename));
      $summary['delete']++;
      $summary['warnings'][] = [
        'file' => $filename,
        'name' => $label,
        'message' => 'Message template exists in CiviCRM but not in YAML and will be deleted when import is applied.',
      ];
      if (!$dryRun) {
        try {
          $this->api4Delete('MessageTemplate', [['id', '=', $existing['id']]]);
        }
        catch (\Throwable $e) {
          $summary['errors'][] = ['file' => $filename, 'message' => 'Delete failed: ' . $e->getMessage()];
        }
      }
    }
  }

  private function findExistingTemplate(array $template, array $where, array $select = ['*']): ?array {
    if (!empty($template['workflow_name'])) {
      return $this->api4GetFirst('MessageTemplate', $where, $select);
    }

    $title = trim((string) ($template['msg_title'] ?? ''));
    if ($title === '') {
      return NULL;
    }
    $matches = $this->api4Get('MessageTemplate', [['msg_title', '=', $title]], $select, ['id' => 'ASC']);
    $matches = array_values(array_filter($matches, static function($row) {
      $row = (array) $row;
      return empty($row['workflow_name']);
    }));
    if (count($matches) > 1) {
      throw new \RuntimeException('Multiple user Message Templates have the same title "' . $title . '". Automatic matching is unsafe until the titles are made unique.');
    }
    return $matches[0] ?? NULL;
  }

  private function identityWhere(array $template): array {
    if (!empty($template['workflow_name'])) {
      $where = [['workflow_name', '=', (string) $template['workflow_name']]];
      if (array_key_exists('is_default', $template)) {
        $where[] = ['is_default', '=', !empty($template['is_default'])];
      }
      return $where;
    }
    if (!empty($template['msg_title'])) {
      return [['msg_title', '=', (string) $template['msg_title']]];
    }
    return [];
  }

  private function uniqueFileName(string $name, array &$used): string {
    $base = $this->safeName($name);
    $candidate = $base;
    $i = 2;
    while (isset($used[$candidate])) {
      $candidate = $base . '_' . $i;
      $i++;
    }
    $used[$candidate] = TRUE;
    return $candidate;
  }

  /**
   * Mirror the content fields used by CiviCRM's Revert availability check.
   */
  private function workflowContentDiffers(array $current, array $reserved): bool {
    foreach (['msg_subject', 'msg_text', 'msg_html'] as $field) {
      $currentValue = $current[$field] ?? NULL;
      $reservedValue = $reserved[$field] ?? NULL;

      // CiviCRM's historical comparison is SQL-based, where NULL does not
      // satisfy an inequality comparison. Preserve that behavior here.
      if ($currentValue === NULL || $reservedValue === NULL) {
        continue;
      }
      if ((string) $currentValue !== (string) $reservedValue) {
        return TRUE;
      }
    }
    return FALSE;
  }

  private function safeName(string $name): string {
    $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '_', strtolower($name));
    return trim($safe, '_') ?: 'message_template';
  }
}
