<?php
namespace Civi\ConfigManager\Handler;

class OptionGroupHandler extends AbstractHandler {
  private bool $importWritesEnabled = TRUE;
  private bool $deleteMissingEnabled = TRUE;

  public function setImportWriteEnabled(bool $enabled): self {
    $this->importWritesEnabled = $enabled;
    return $this;
  }

  public function setDeleteMissingEnabled(bool $enabled): self {
    $this->deleteMissingEnabled = $enabled;
    return $this;
  }

  public function getType(): string { return 'option-groups'; }
  public function getLabel(): string { return 'Option Groups and Values'; }
  public function getDirectory(): string { return 'option-groups'; }
  public function getWeight(): int { return 20; }

  public function getRuntimeAvailability(): array {
    $group = $this->api4ManagementAvailability('OptionGroup', ['get', 'create', 'update']);
    $value = $this->api4ManagementAvailability('OptionValue', ['get', 'create', 'update', 'delete']);
    return $this->combineApi4ManagementAvailability([$group, $value], 'Option Groups and Values');
  }

  public function export(): array {
    $groups = $this->api4Get('OptionGroup', [], ['id', 'name', 'title', 'description', 'data_type', 'is_reserved', 'is_active'], ['name' => 'ASC']);
    $files = [];
    foreach ($groups as $group) {
      $sourceId = isset($group['id']) && is_scalar($group['id']) ? (int) $group['id'] : NULL;
      $values = $this->api4Get('OptionValue', [["option_group_id", "=", $group['id']]], ['name', 'label', 'value', 'description', 'weight', 'is_default', 'is_optgroup', 'is_reserved', 'is_active', 'component_id', 'domain_id', 'visibility_id'], ['weight' => 'ASC', 'name' => 'ASC']);
      unset($group['id']);
      foreach ($values as &$value) {
        unset($value['id'], $value['option_group_id']);
      }
      $files[] = [
        'filename' => $this->safeName($group['name']) . '.yml',
        'source_id' => $sourceId,
        'data' => [
          'schema_version' => 1,
          'type' => 'option_group',
          'name' => $group['name'],
          'dependencies' => [],
          'group' => $group,
          'values' => array_values($values),
        ],
      ];
    }
    return $files;
  }

  public function validate(array $items): array {
    $errors = [];
    $warnings = [];
    foreach ($items as $filename => $item) {
      if (($item['type'] ?? '') !== 'option_group') {
        $errors[] = [
          'file' => $filename,
          'message' => 'Invalid type. Expected option_group.',
        ];
        continue;
      }
      $groupName = (string) ($item['name'] ?? '');
      $groupArrayName = (string) ($item['group']['name'] ?? '');
      if ($groupName === '' || $groupArrayName === '') {
        $errors[] = [
          'file' => $filename,
          'message' => 'Missing option group machine name.',
        ];
      }
      elseif ($groupName !== $groupArrayName) {
        $errors[] = [
          'file' => $filename,
          'message' => 'Top-level option group name and group.name do not match.',
        ];
      }

      $names = [];
      $composite = [];
      foreach (($item['values'] ?? []) as $index => $value) {
        $name = (string) ($value['name'] ?? '');
        $optionValue = array_key_exists('value', $value) ? (string) $value['value'] : '';
        if ($name === '') {
          $errors[] = [
            'file' => $filename,
            'message' => 'Option value at index ' . $index . ' is missing name.',
          ];
          continue;
        }

        $compositeKey = $name . "\0" . $optionValue;
        if (isset($composite[$compositeKey])) {
          $errors[] = [
            'file' => $filename,
            'message' => 'Duplicate option value entry: ' . $name . ' / ' . $optionValue,
          ];
          continue;
        }
        $composite[$compositeKey] = TRUE;

        // Some core CiviCRM option groups reuse the display-like option
        // value name while keeping distinct values. That is valid source data;
        // import handles those rows by matching name + value where needed.
        $names[$name] = TRUE;
      }
    }
    return [
      'type' => $this->getType(),
      'valid' => empty($errors),
      'warnings' => $warnings,
      'errors' => $errors,
      'count' => count($items),
    ];
  }

  public function import(array $items, bool $dryRun = TRUE): array {
    $summary = [
      'type' => $this->getType(),
      'status' => $dryRun ? 'dry_run' : 'applied',
      'dry_run' => $dryRun,
      'groups' => ['create' => 0, 'update' => 0, 'skip' => 0],
      'values' => ['create' => 0, 'update' => 0, 'delete' => 0, 'skip' => 0],
      'warnings' => [],
      'errors' => [],
    ];

    foreach ($items as $filename => $item) {
      if (($item['type'] ?? '') !== 'option_group') {
        $summary['errors'][] = ['file' => $filename, 'message' => 'Invalid type. Expected option_group.'];
        continue;
      }
      $group = $item['group'] ?? [];
      $groupName = (string) ($item['name'] ?? $group['name'] ?? '');
      if ($groupName === '') {
        $summary['errors'][] = ['file' => $filename, 'message' => 'Missing option group machine name.'];
        continue;
      }
      $group['name'] = $groupName;
      $desiredGroup = $this->cleanGroupValues($group);
      $existingGroup = $this->api4GetFirst('OptionGroup', [['name', '=', $groupName]], ['*']);
      $yamlValues = (array) ($item['values'] ?? []);
      $duplicateValueNames = $this->duplicateOptionValueNames($yamlValues);
      $machineNameConflicts = $existingGroup
        ? $this->findMachineNameConflicts($existingGroup, $yamlValues, $duplicateValueNames)
        : [];
      $protectedValueIds = [];
      foreach ($machineNameConflicts as $valueName => $conflict) {
        if (!empty($conflict['existing']['id'])) {
          $protectedValueIds[(int) $conflict['existing']['id']] = TRUE;
        }
        $summary['errors'][] = [
          'file' => $filename,
          'name' => (string) $valueName,
          'message' => 'Possible OptionValue identity rename detected from "' . ($conflict['existing']['name'] ?? '') . '" to "' . $valueName . '" for stable option value "' . ($conflict['desired']['value'] ?? '') . '". Automatic rename and delete-missing are blocked for this pair. Revert the machine name, or create a genuinely new option value with a new unique value.',
        ];
      }

      // Direct handler calls must also fail closed. ConfigManager normally runs
      // this same check during its complete dry-run preflight before any write,
      // but never allow a caller to bypass that protection by invoking apply.
      if (!$dryRun && $machineNameConflicts) {
        $summary['values']['skip'] += count($machineNameConflicts);
        continue;
      }

      try {
        if ($existingGroup) {
          if ($this->importWritesEnabled && $this->desiredDiffers($existingGroup, $desiredGroup)) {
            $summary['groups']['update']++;
            if (!$dryRun) {
              $this->api4Update('OptionGroup', [['id', '=', $existingGroup['id']]], $desiredGroup);
            }
          }
          elseif ($this->importWritesEnabled) {
            $summary['groups']['skip']++;
          }
          $groupId = $existingGroup['id'];
        }
        else {
          if ($this->importWritesEnabled) {
            $summary['groups']['create']++;
          }
          if ($this->importWritesEnabled && !$dryRun) {
            $created = $this->api4Create('OptionGroup', $desiredGroup);
            $groupId = $created['id'] ?? NULL;
          }
          else {
            $groupId = NULL;
          }
        }

        $desiredValueKeys = [];
        foreach ($yamlValues as $value) {
          $valueName = (string) ($value['name'] ?? '');
          if ($valueName === '') {
            $summary['errors'][] = ['file' => $filename, 'message' => 'Option value is missing name.'];
            continue;
          }
          $desiredValue = $this->cleanOptionValueValues($value);
          $desiredValueKeys[$this->optionValueIdentityKey($desiredValue, $duplicateValueNames)] = TRUE;
          $existingValue = NULL;
          if (isset($machineNameConflicts[$valueName])) {
            $summary['values']['skip']++;
            continue;
          }
          if ($existingGroup) {
            $where = [
              ['option_group_id', '=', $existingGroup['id']],
              ['name', '=', $valueName],
            ];
            if (isset($duplicateValueNames[$valueName]) && array_key_exists('value', $desiredValue) && $desiredValue['value'] !== NULL && $desiredValue['value'] !== '') {
              $where[] = ['value', '=', (string) $desiredValue['value']];
            }
            $existingValue = $this->api4GetFirst('OptionValue', $where, ['*']);
          }

          if (!$this->importWritesEnabled) {
            continue;
          }

          if ($existingValue) {
            if ($this->desiredDiffers($existingValue, $desiredValue)) {
              $summary['values']['update']++;
              if (!$dryRun) {
                $this->api4Update('OptionValue', [['id', '=', $existingValue['id']]], $desiredValue);
              }
            }
            else {
              $summary['values']['skip']++;
            }
          }
          else {
            $summary['values']['create']++;
            if (!$dryRun) {
              if (empty($groupId)) {
                $summary['errors'][] = ['file' => $filename, 'message' => 'Could not resolve option_group_id for option value ' . $valueName . '.'];
                continue;
              }
              $desiredValue['option_group_id'] = $groupId;
              $this->api4Create('OptionValue', $desiredValue);
            }
          }
        }

        if ($existingGroup && $this->deleteMissingEnabled) {
          $this->handleExtraOptionValues($existingGroup, $desiredValueKeys, $duplicateValueNames, $protectedValueIds, $filename, $dryRun, $summary);
        }
      }
      catch (\Throwable $e) {
        $summary['errors'][] = [
          'file' => $filename,
          'name' => $groupName,
          'message' => $e->getMessage(),
        ];
      }
    }

    $summary['ok'] = empty($summary['errors']);
    return $summary;
  }


  /**
   * Find machine-name changes which reuse an existing stable option value.
   *
   * The option value is often referenced elsewhere. Treating a changed name as
   * CREATE(new) + DELETE(old) would defeat the identity-rename safeguard and
   * can break references. The pair is therefore a blocking preflight error.
   */
  private function findMachineNameConflicts(array $existingGroup, array $yamlValues, array $duplicateValueNames): array {
    if (empty($existingGroup['id'])) {
      return [];
    }
    $conflicts = [];
    foreach ($yamlValues as $value) {
      $value = (array) $value;
      $valueName = (string) ($value['name'] ?? '');
      $stableValue = array_key_exists('value', $value) && $value['value'] !== NULL ? (string) $value['value'] : '';
      if ($valueName === '' || $stableValue === '' || isset($duplicateValueNames[$valueName])) {
        continue;
      }
      $existingByName = $this->api4GetFirst('OptionValue', [
        ['option_group_id', '=', $existingGroup['id']],
        ['name', '=', $valueName],
      ], ['id', 'name', 'label', 'value']);
      if ($existingByName) {
        continue;
      }
      $existingByValue = $this->api4GetFirst('OptionValue', [
        ['option_group_id', '=', $existingGroup['id']],
        ['value', '=', $stableValue],
      ], ['id', 'name', 'label', 'value']);
      if ($existingByValue && (string) ($existingByValue['name'] ?? '') !== $valueName) {
        $conflicts[$valueName] = [
          'existing' => $existingByValue,
          'desired' => $value,
        ];
      }
    }
    return $conflicts;
  }

  private function handleExtraOptionValues(array $existingGroup, array $desiredValueKeys, array $duplicateValueNames, array $protectedValueIds, string $filename, bool $dryRun, array &$summary): void {
    if (empty($existingGroup['id'])) {
      return;
    }

    $existingValues = $this->api4Get('OptionValue', [
      ['option_group_id', '=', $existingGroup['id']],
    ], ['id', 'name', 'label', 'value', 'is_reserved']);

    foreach ($existingValues as $existingValue) {
      $existingName = (string) ($existingValue['name'] ?? '');
      if ($existingName === '') {
        continue;
      }
      if (isset($desiredValueKeys[$this->optionValueIdentityKey($existingValue, $duplicateValueNames)])) {
        continue;
      }
      if (!empty($existingValue['id']) && isset($protectedValueIds[(int) $existingValue['id']])) {
        // The incoming rename candidate was already counted as skipped above.
        // Preserve the existing value without double-counting the same
        // protected identity pair in the dry-run summary.
        continue;
      }

      if (!empty($existingValue['is_reserved'])) {
        $summary['values']['skip']++;
        $summary['warnings'][] = [
          'file' => $filename,
          'name' => $existingName,
          'message' => 'Reserved option value exists in CiviCRM but not in YAML. It was left unchanged for safety: ' . $existingName,
        ];
        continue;
      }

      $summary['values']['delete']++;
      $summary['warnings'][] = [
        'file' => $filename,
        'name' => $existingName,
        'message' => 'Option value exists in CiviCRM but not in YAML and will be deleted when import is applied: ' . $existingName,
      ];
      if (!$dryRun && !empty($existingValue['id'])) {
        $this->api4Delete('OptionValue', [['id', '=', (int) $existingValue['id']]]);
      }
    }
  }

  private function duplicateOptionValueNames(array $values): array {
    $counts = [];
    foreach ($values as $value) {
      $name = (string) (($value['name'] ?? ''));
      if ($name === '') {
        continue;
      }
      $counts[$name] = ($counts[$name] ?? 0) + 1;
    }
    return array_filter($counts, static fn($count) => $count > 1);
  }

  private function optionValueIdentityKey(array $value, array $duplicateValueNames): string {
    $name = (string) (($value['name'] ?? ''));
    if (isset($duplicateValueNames[$name])) {
      return $name . '::' . (string) (($value['value'] ?? ''));
    }
    return $name;
  }

  protected function desiredDiffers(array $existing, array $desired): bool {
    foreach ($desired as $key => $value) {
      if (!array_key_exists($key, $existing)) {
        continue;
      }
      if ($this->normaliseComparable($existing[$key]) !== $this->normaliseComparable($value)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  private function normaliseComparable($value) {
    if ($value === NULL || $value === '') {
      return '';
    }
    if (is_bool($value)) {
      return $value ? '1' : '0';
    }
    if (is_array($value)) {
      ksort($value);
      return json_encode($value);
    }
    return (string) $value;
  }

  private function cleanGroupValues(array $group): array {
    return $this->cleanValues($group, ['id']);
  }

  private function cleanOptionValueValues(array $value): array {
    return $this->cleanValues($value, ['id']);
  }

  private function safeName(string $name): string {
    return preg_replace('/[^A-Za-z0-9_.-]+/', '_', $name);
  }
}
