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

        $yamlValues = (array) ($item['values'] ?? []);
        $duplicateValueNames = $this->duplicateOptionValueNames($yamlValues);
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
          $machineNameConflict = NULL;
          if ($existingGroup) {
            $where = [
              ['option_group_id', '=', $existingGroup['id']],
              ['name', '=', $valueName],
            ];
            if (isset($duplicateValueNames[$valueName]) && array_key_exists('value', $desiredValue) && $desiredValue['value'] !== NULL && $desiredValue['value'] !== '') {
              $where[] = ['value', '=', (string) $desiredValue['value']];
            }
            $existingValue = $this->api4GetFirst('OptionValue', $where, ['*']);

            if (!$existingValue && !isset($duplicateValueNames[$valueName]) && array_key_exists('value', $desiredValue) && $desiredValue['value'] !== NULL && $desiredValue['value'] !== '') {
              $machineNameConflict = $this->api4GetFirst('OptionValue', [
                ['option_group_id', '=', $existingGroup['id']],
                ['value', '=', (string) $desiredValue['value']],
              ], ['id', 'name', 'label', 'value']);
              if ($machineNameConflict && ($machineNameConflict['name'] ?? '') !== $valueName) {
                $summary['warnings'][] = [
                  'file' => $filename,
                  'name' => $valueName,
                  'message' => 'Machine name appears to have changed from "' . $machineNameConflict['name'] . '" to "' . $valueName . '" for option value "' . $desiredValue['value'] . '". Machine names are identities and are not renamed automatically. Revert the name, or create a new option value with a new unique value.',
                ];
                $summary['values']['skip']++;
                continue;
              }
            }
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
          $this->handleExtraOptionValues($existingGroup, $desiredValueKeys, $duplicateValueNames, $filename, $dryRun, $summary);
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

  private function handleExtraOptionValues(array $existingGroup, array $desiredValueKeys, array $duplicateValueNames, string $filename, bool $dryRun, array &$summary): void {
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
