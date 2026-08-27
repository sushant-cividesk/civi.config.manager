<?php
namespace Civi\ConfigManager\Handler;

class CustomGroupHandler extends AbstractHandler implements StreamingHandlerInterface, StreamingImportHandlerInterface {
  private bool $importWritesEnabled = TRUE;
  private bool $deleteMissingEnabled = TRUE;
  private array $plannedDependencyNames = [];

  public function setImportWriteEnabled(bool $enabled): self {
    $this->importWritesEnabled = $enabled;
    return $this;
  }

  public function setDeleteMissingEnabled(bool $enabled): self {
    $this->deleteMissingEnabled = $enabled;
    return $this;
  }

  /**
   * Provide managed YAML identities that are planned earlier in this import.
   *
   * Dry-run does not create prerequisite records, so dependent handlers must
   * be able to recognize dependencies that are present in the same import
   * bundle and will exist before the real write phase reaches them.
   */
  public function setPlannedDependencyNames(array $names): self {
    $this->plannedDependencyNames = $names;
    return $this;
  }

  public function getType(): string { return 'custom-data'; }
  public function getLabel(): string { return 'Custom Groups and Fields'; }
  public function getDirectory(): string { return 'custom-data'; }
  public function getWeight(): int { return 60; }

  public function getRuntimeAvailability(): array {
    $group = $this->api4ManagementAvailability('CustomGroup', ['get', 'create', 'update', 'delete']);
    $field = $this->api4ManagementAvailability('CustomField', ['get', 'create', 'update', 'delete']);
    return $this->combineApi4ManagementAvailability([$group, $field], 'Custom Groups and Fields');
  }

  public function export(): array {
    return iterator_to_array($this->iterateExport(), FALSE);
  }

  public function iterateExport(): iterable {
    foreach ($this->api4Iterate('CustomGroup', [], ['id', 'name', 'title', 'extends', 'extends_entity_column_id', 'extends_entity_column_value', 'style', 'collapse_display', 'help_pre', 'help_post', 'weight', 'is_active', 'is_multiple', 'min_multiple', 'max_multiple', 'collapse_adv_display', 'is_reserved', 'is_public'], ['name' => 'ASC']) as $group) {
      $group = (array) $group;
      $sourceId = isset($group['id']) && is_scalar($group['id']) ? (int) $group['id'] : NULL;
      $fields = $this->api4Get('CustomField', [['custom_group_id', '=', $group['id']]], ['name', 'label', 'data_type', 'html_type', 'default_value', 'is_required', 'is_searchable', 'is_search_range', 'weight', 'help_pre', 'help_post', 'attributes', 'is_active', 'is_view', 'options_per_line', 'text_length', 'start_date_years', 'end_date_years', 'date_format', 'time_format', 'note_columns', 'note_rows', 'column_name', 'option_group_id'], ['weight' => 'ASC', 'name' => 'ASC']);
      unset($group['id']);
      foreach ($fields as &$field) {
        unset($field['id'], $field['custom_group_id']);
        if (!empty($field['option_group_id']) && is_numeric($field['option_group_id'])) {
          $optionGroup = $this->api4GetFirst('OptionGroup', [['id', '=', (int) $field['option_group_id']]], ['name']);
          if (!empty($optionGroup['name'])) {
            $field['option_group_name'] = $optionGroup['name'];
            unset($field['option_group_id']);
          }
        }
      }
      unset($field);
      yield [
        'filename' => 'groups/' . $this->safeName($group['name']) . '.yml',
        'source_id' => $sourceId,
        'data' => [
          'schema_version' => 1,
          'type' => 'custom_group',
          'name' => $group['name'],
          'dependencies' => $this->dependenciesForGroup((array) $group, $fields),
          'group' => $group,
          'fields' => $fields,
        ],
      ];
      unset($fields);
    }
  }

  public function validate(array $items): array {
    $errors = [];
    $warnings = [];
    $desiredGroupNames = [];
    foreach ($items as $filename => $file) {
      if (($file['type'] ?? '') !== 'custom_group') {
        $errors[] = ['file' => $filename, 'message' => 'Invalid type. Expected custom_group.'];
        continue;
      }
      $group = (array) ($file['group'] ?? []);
      if (empty($group['name'])) {
        $errors[] = ['file' => $filename, 'message' => 'Custom group is missing group.name.'];
      }
      foreach (($file['fields'] ?? []) as $field) {
        $field = (array) $field;
        if (empty($field['name'])) {
          $errors[] = ['file' => $filename, 'message' => 'Custom field is missing name.'];
        }
        // Legacy YAML from earlier alpha builds may contain numeric
        // option_group_id. Keep validation quiet for compatibility; new exports
        // write option_group_name and dependency metadata instead.
      }
    }
    return ['type' => $this->getType(), 'valid' => empty($errors), 'warnings' => $warnings, 'errors' => $errors, 'count' => count($items)];
  }

  public function import(array $items, bool $dryRun = TRUE): array {
    return $this->importIterable($items, $dryRun);
  }

  public function importIterable(iterable $items, bool $dryRun = TRUE): array {
    $summary = $this->baseImportSummary($dryRun);
    $desiredGroupNames = [];
    foreach ($items as $filename => $file) {
      if (($file['type'] ?? '') !== 'custom_group') {
        $summary['errors'][] = ['file' => $filename, 'message' => 'Invalid type. Expected custom_group.'];
        continue;
      }
      $group = $this->cleanValues((array) ($file['group'] ?? []));
      if (empty($group['name'])) {
        $summary['errors'][] = ['file' => $filename, 'message' => 'Custom group is missing group.name.'];
        continue;
      }
      $desiredGroupNames[(string) $group['name']] = TRUE;

      try {
        $existingGroup = $this->api4GetFirst('CustomGroup', [['name', '=', (string) $group['name']]], ['*']);
        $groupId = $existingGroup['id'] ?? NULL;
        if (!$this->importWritesEnabled) {
          // Delete phase only. We still collect desired group names above.
        }
        elseif ($existingGroup) {
          if ($this->desiredDiffers($existingGroup, $group)) {
            $summary['update']++;
            if (!$dryRun) {
              $this->api4Update('CustomGroup', [['id', '=', $existingGroup['id']]], $group);
            }
          }
          else {
            $summary['skip']++;
          }
        }
        else {
          $summary['create']++;
          if (!$dryRun) {
            $created = $this->api4Create('CustomGroup', $group);
            $groupId = $created['id'] ?? NULL;
          }
        }

        if (!$dryRun && !$groupId) {
          $existingGroup = $this->api4GetFirst('CustomGroup', [['name', '=', (string) $group['name']]], ['id']);
          $groupId = $existingGroup['id'] ?? NULL;
        }

        $desiredFieldNames = [];
        foreach (($file['fields'] ?? []) as $field) {
          $field = $this->cleanValues((array) $field);
          $this->resolveFieldOptionGroup($field, $filename, $summary, $dryRun);
          if (empty($field['name'])) {
            $summary['errors'][] = ['file' => $filename, 'message' => 'Custom field is missing name.'];
            continue;
          }
          $desiredFieldNames[(string) $field['name']] = TRUE;
          $existingField = $groupId ? $this->api4GetFirst('CustomField', [['custom_group_id', '=', $groupId], ['name', '=', (string) $field['name']]], ['*']) : NULL;
          if (!$this->importWritesEnabled) {
            continue;
          }
          if (!$dryRun && $groupId) {
            $field['custom_group_id'] = $groupId;
          }
          if ($existingField) {
            if ($this->desiredDiffers($existingField, $field)) {
              $summary['update']++;
              if (!$dryRun) {
                $this->api4Update('CustomField', [['id', '=', $existingField['id']]], $field);
              }
            }
            else {
              $summary['skip']++;
            }
          }
          else {
            $summary['create']++;
            if (!$dryRun) {
              if (!$groupId) {
                throw new \RuntimeException('Cannot create custom field without custom group id.');
              }
              $this->api4Create('CustomField', $field);
            }
          }
        }

        if ($this->deleteMissingEnabled && $groupId) {
          $this->deleteFieldsMissingFromYaml((int) $groupId, (string) $group['name'], $desiredFieldNames, $dryRun, $summary);
        }
      }
      catch (\Throwable $e) {
        $summary['errors'][] = ['file' => $filename, 'message' => $e->getMessage()];
      }
    }
    if ($this->deleteMissingEnabled && empty($summary['errors'])) {
      $this->deleteGroupsMissingFromYaml($desiredGroupNames, $dryRun, $summary);
    }
    $summary['ok'] = empty($summary['errors']);
    return $summary;
  }

  private function deleteFieldsMissingFromYaml(int $groupId, string $groupName, array $desiredFieldNames, bool $dryRun, array &$summary): void {
    foreach ($this->api4Iterate('CustomField', [['custom_group_id', '=', $groupId]], ['id', 'name', 'label'], ['name' => 'ASC']) as $field) {
      $name = (string) ($field['name'] ?? '');
      if ($name === '' || isset($desiredFieldNames[$name])) {
        continue;
      }
      $summary['delete']++;
      $summary['warnings'][] = [
        'name' => $groupName . '.' . $name,
        'message' => 'Custom field exists in CiviCRM but not YAML and will be deleted: ' . $groupName . '.' . $name,
      ];
      if (!$dryRun) {
        $this->api4Delete('CustomField', [['id', '=', (int) $field['id']]]);
      }
    }
  }

  private function deleteGroupsMissingFromYaml(array $desiredGroupNames, bool $dryRun, array &$summary): void {
    foreach ($this->api4Iterate('CustomGroup', [], ['id', 'name', 'title', 'is_reserved'], ['name' => 'ASC']) as $group) {
      $name = (string) ($group['name'] ?? '');
      if ($name === '' || isset($desiredGroupNames[$name])) {
        continue;
      }
      if (!empty($group['is_reserved'])) {
        $summary['skip']++;
        continue;
      }
      $summary['delete']++;
      $summary['warnings'][] = [
        'name' => $name,
        'message' => 'Custom group exists in CiviCRM but not YAML and will be deleted: ' . $name,
      ];
      if (!$dryRun) {
        $this->api4Delete('CustomGroup', [['id', '=', (int) $group['id']]]);
      }
    }
  }

  private function dependenciesForGroup(array $group, array $fields): array {
    $dependencies = [];
    $seen = [];
    if ($this->usesContactTypeScope((string) ($group['extends'] ?? ''))) {
      foreach ((array) ($group['extends_entity_column_value'] ?? []) as $extendsValue) {
        $contactTypeName = $this->resolveContactTypeDependencyName($extendsValue);
        if ($contactTypeName !== '') {
          $dependencies[] = [
            'type' => 'contact-types',
            'entity' => 'ContactType',
            'name' => $contactTypeName,
            'reason' => 'Custom group is scoped to this contact/sub-contact type.',
          ];
        }
      }
    }
    foreach ($fields as $field) {
      $field = (array) $field;
      $optionGroupName = (string) ($field['option_group_name'] ?? '');
      if ($optionGroupName === '' && !empty($field['option_group_id']) && is_numeric($field['option_group_id'])) {
        $optionGroup = $this->api4GetFirst('OptionGroup', [['id', '=', (int) $field['option_group_id']]], ['name']);
        $optionGroupName = (string) ($optionGroup['name'] ?? '');
      }
      if ($optionGroupName !== '' && empty($seen[$optionGroupName])) {
        $seen[$optionGroupName] = TRUE;
        $dependencies[] = [
          'type' => 'option-groups',
          'entity' => 'OptionGroup',
          'name' => $optionGroupName,
          'reason' => 'Custom field uses this option group for choices.',
        ];
      }
    }
    return $dependencies;
  }

  /**
   * Contact-based custom groups use extends_entity_column_value for contact
   * subtype IDs. Other entities reuse that field for unrelated local IDs
   * (for example ActivityType IDs), which must never be declared as
   * ContactType dependencies.
   */
  private function usesContactTypeScope(string $extends): bool {
    return in_array($extends, ['Contact', 'Individual', 'Organization', 'Household'], TRUE);
  }


  private function resolveContactTypeDependencyName($value): string {
    if ($value === NULL || $value === '') {
      return '';
    }
    if (is_numeric($value)) {
      try {
        $contactType = $this->api4GetFirst('ContactType', [['id', '=', (int) $value]], ['name']);
        if (!empty($contactType['name'])) {
          return (string) $contactType['name'];
        }
      }
      catch (\Throwable $e) {
        // Fall through to the raw value so validation can explain the issue.
      }
    }
    return is_scalar($value) ? (string) $value : '';
  }

  private function resolveFieldOptionGroup(array &$field, string $filename, array &$summary, bool $dryRun): void {
    if (!empty($field['option_group_name'])) {
      $optionGroupName = (string) $field['option_group_name'];
      $optionGroup = $this->api4GetFirst('OptionGroup', [['name', '=', $optionGroupName]], ['id', 'name']);
      if (empty($optionGroup['id'])) {
        if ($dryRun && !empty($this->plannedDependencyNames['option-groups'][$optionGroupName])) {
          // The prerequisite is part of the same managed import. The real
          // apply runs Option Groups before Custom Data, at which point this
          // branch resolves the newly-created numeric ID normally.
          unset($field['option_group_name']);
          return;
        }
        throw new \RuntimeException('Custom field ' . ($field['name'] ?? '') . ' requires missing option group: ' . $optionGroupName . '. Import option groups first or restore the dependency YAML file.');
      }
      $field['option_group_id'] = $optionGroup['id'];
      unset($field['option_group_name']);
      return;
    }

    if (!empty($field['option_group_id']) && is_numeric($field['option_group_id'])) {
      $summary['warnings'][] = [
        'file' => $filename,
        'message' => 'Custom field ' . ($field['name'] ?? '') . ' still uses numeric option_group_id. Re-export to make this environment-independent.',
      ];
    }
  }

  private function safeName(string $name): string {
    return preg_replace('/[^A-Za-z0-9_.-]+/', '_', $name);
  }
}
