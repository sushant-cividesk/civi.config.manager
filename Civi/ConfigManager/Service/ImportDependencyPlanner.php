<?php
namespace Civi\ConfigManager\Service;

/**
 * Builds user-reviewable dependency blocker components for Import.
 *
 * The planner is intentionally read-only. It groups actual Saved Config
 * dependency edges and proves whether removing a complete type component leaves
 * the remaining requested import closed over those observed dependencies.
 */
final class ImportDependencyPlanner {

  /** @return array<string,array<int,string>> */
  public function relatedTypeMap(): array {
    return [
      'searchkit-saved-searches' => ['searchkit-displays', 'formbuilder-afforms'],
      'searchkit-displays' => ['searchkit-saved-searches', 'formbuilder-afforms'],
      'formbuilder-afforms' => ['searchkit-displays', 'searchkit-saved-searches'],
      'custom-data' => ['option-groups', 'contact-types', 'site-tokens'],
      'extensions' => ['message-templates', 'contact-types', 'custom-data', 'option-groups'],
      'relationship-types' => ['contact-types'],
      'civirules' => ['extensions'],
      'site-tokens' => ['extensions'],
    ];
  }

  /**
   * @param array<string,array<string,array<string,mixed>>> $dependencyMetadata
   * @param array<string,array<string,bool>> $available
   * @param array<string,string> $registeredTypes
   * @param array<string,string> $managedTypes
   * @return array<string,mixed>
   */
  public function analyze(
    array $dependencyMetadata,
    array $available,
    array $registeredTypes,
    array $managedTypes,
    callable $activeDependencyExists,
    callable $ignoredDependencyHint
  ): array {
    $edges = [];
    $blockers = [];
    $warningsByType = [];

    foreach ($dependencyMetadata as $type => $files) {
      foreach ($files as $filename => $metadata) {
        foreach ((array) ($metadata['dependencies'] ?? []) as $dependency) {
          $dependencyType = trim((string) ($dependency['type'] ?? ''));
          $dependencyName = trim((string) ($dependency['name'] ?? ''));
          if ($dependencyType === '' || $dependencyName === '' || !isset($registeredTypes[$dependencyType])) {
            continue;
          }

          $edge = [
            'source_type' => (string) $type,
            'source_file' => (string) $filename,
            'dependency_type' => $dependencyType,
            'dependency_name' => $dependencyName,
            'reason' => trim((string) ($dependency['reason'] ?? 'This Saved Config references another managed configuration item.')),
          ];
          $edges[] = $edge;

          if (isset($available[$dependencyType][$dependencyName])) {
            continue;
          }
          if ($activeDependencyExists($dependencyType, $dependencyName)) {
            continue;
          }

          $ignoredHint = (string) $ignoredDependencyHint($dependencyType, $dependencyName);
          $message = $this->missingDependencyMessage($edge, $ignoredHint);
          $blockers[] = $edge + [
            'ignored_hint' => $ignoredHint,
            'message' => $message,
          ];
        }

        foreach ((array) ($metadata['required_by'] ?? []) as $requiredBy) {
          $requiredByType = trim((string) ($requiredBy['type'] ?? ''));
          $requiredByName = trim((string) ($requiredBy['name'] ?? ''));
          if ($requiredByType === '' || $requiredByName === '' || !isset($managedTypes[$requiredByType])) {
            continue;
          }
          if (!isset($available[$requiredByType][$requiredByName])) {
            $warningsByType[(string) $type][] = [
              'file' => (string) $filename,
              'message' => sprintf(
                'Reverse dependency metadata says this item is required by %s "%s", but that Saved Config is not present. Re-export the related items together before relying on this dependency graph.',
                $requiredByType,
                $requiredByName
              ),
            ];
          }
        }
      }
    }

    return [
      'dependency_edges' => $this->uniqueEdges($edges),
      'dependency_blockers' => $blockers,
      'dependency_components' => $this->buildComponents($blockers, $edges, $registeredTypes),
      'warnings_by_type' => $warningsByType,
    ];
  }

  /**
   * Add safe-exclusion information for the exact requested/effective import.
   *
   * @param array<int,array<string,mixed>> $components
   * @param string[] $requestedTypes
   * @param string[] $effectiveTypes
   * @return array<int,array<string,mixed>>
   */
  public function addExclusionSafety(array $components, array $requestedTypes, array $effectiveTypes): array {
    $effectiveTypes = $this->sortedStrings($effectiveTypes);
    $effectiveMap = array_fill_keys($effectiveTypes, TRUE);

    foreach ($components as &$component) {
      $excludedTypes = [];
      foreach ((array) ($component['types'] ?? []) as $type) {
        $type = (string) $type;
        if (isset($effectiveMap[$type])) {
          $excludedTypes[] = $type;
        }
      }
      $excludedTypes = $this->expandRelatedClosure($this->sortedStrings($excludedTypes), $effectiveMap);
      $remainingEffective = array_values(array_diff($effectiveTypes, $excludedTypes));

      if ($requestedTypes) {
        $remainingRequested = [];
        foreach ($requestedTypes as $requestedType) {
          if (!in_array($this->baseType((string) $requestedType), $excludedTypes, TRUE)) {
            $remainingRequested[] = (string) $requestedType;
          }
        }
      }
      else {
        // An empty request means "all managed types". Once exclusions are
        // explicit, freeze the remainder as an explicit requested set so the
        // excluded component cannot be silently re-added by related-type expansion.
        $remainingRequested = $remainingEffective;
      }
      $remainingRequested = array_values(array_unique($remainingRequested));

      $component['excluded_types'] = $excludedTypes;
      $component['remaining_requested_types'] = $remainingRequested;
      $component['can_exclude'] = $excludedTypes !== [] && $remainingRequested !== [];
      $component['exclusion_reason'] = $component['can_exclude']
        ? 'The complete dependency component can be excluded and a new full preview can be built from the remaining configuration.'
        : ($excludedTypes === []
          ? 'This blocker does not belong to the current import scope.'
          : 'Excluding this dependency component would leave nothing to import. Fix the blocker and preview again instead.');
    }
    unset($component);

    return $components;
  }

  /**
   * Add the concrete dry-run actions affected by each blocked component.
   *
   * @param array<int,array<string,mixed>> $components
   * @param array<int,array<string,mixed>> $items
   * @return array<int,array<string,mixed>>
   */
  public function addActionSummaries(array $components, array $items): array {
    $byType = [];
    foreach ($items as $item) {
      $item = (array) $item;
      $type = trim((string) ($item['type'] ?? ''));
      if ($type === '') {
        continue;
      }
      $counts = [
        'create' => (int) ($item['create'] ?? 0),
        'update' => (int) ($item['update'] ?? 0),
        'delete' => (int) ($item['delete'] ?? 0),
        'install' => (int) ($item['install'] ?? 0),
        'enable' => (int) ($item['enable'] ?? 0),
        'disable' => (int) ($item['disable'] ?? 0),
      ];
      foreach (['groups', 'values', 'settings', 'config'] as $group) {
        $groupResult = (array) ($item[$group] ?? []);
        $counts['create'] += (int) ($groupResult['create'] ?? 0);
        $counts['update'] += (int) ($groupResult['update'] ?? 0);
        $counts['delete'] += (int) ($groupResult['delete'] ?? 0);
      }
      foreach ($counts as $action => $count) {
        $byType[$type][$action] = (int) (($byType[$type][$action] ?? 0) + $count);
      }
    }

    foreach ($components as &$component) {
      $counts = ['create' => 0, 'update' => 0, 'delete' => 0, 'install' => 0, 'enable' => 0, 'disable' => 0];
      foreach ((array) ($component['types'] ?? []) as $type) {
        foreach ($counts as $action => $ignored) {
          $counts[$action] += (int) (($byType[(string) $type][$action] ?? 0));
        }
      }
      $actions = array_filter($counts, static fn(int $count): bool => $count > 0);
      $labels = [
        'create' => 'Create',
        'update' => 'Update',
        'delete' => 'Remove',
        'install' => 'Install',
        'enable' => 'Enable',
        'disable' => 'Disable',
      ];
      $parts = [];
      foreach ($actions as $action => $count) {
        $parts[] = $labels[$action] . ' ' . $count;
      }
      $component['actions'] = $actions;
      $component['action_text'] = $parts ? implode(', ', $parts) : 'Validation only';
    }
    unset($component);

    return $components;
  }

  /**
   * @param array<int,array<string,mixed>> $components
   * @return array<string,mixed>|null
   */
  public function findComponent(array $components, string $componentId): ?array {
    foreach ($components as $component) {
      if (hash_equals((string) ($component['id'] ?? ''), $componentId)) {
        return $component;
      }
    }
    return NULL;
  }

  /**
   * @param array<int,array<string,mixed>> $blockers
   * @param array<int,array<string,mixed>> $edges
   * @param array<string,string> $labels
   * @return array<int,array<string,mixed>>
   */
  private function buildComponents(array $blockers, array $edges, array $labels): array {
    if (!$blockers) {
      return [];
    }

    $adjacency = [];
    foreach ($edges as $edge) {
      $source = (string) ($edge['source_type'] ?? '');
      $target = (string) ($edge['dependency_type'] ?? '');
      if ($source === '' || $target === '') {
        continue;
      }
      $adjacency[$source][$target] = TRUE;
      $adjacency[$target][$source] = TRUE;
    }

    $components = [];
    $seen = [];
    foreach ($blockers as $blocker) {
      $seed = (string) ($blocker['source_type'] ?? '');
      if ($seed === '' || isset($seen[$seed])) {
        continue;
      }
      $types = $this->connectedTypes($seed, $adjacency);
      foreach ($types as $type) {
        $seen[$type] = TRUE;
      }
      $typeMap = array_fill_keys($types, TRUE);
      $componentBlockers = array_values(array_filter($blockers, static function(array $candidate) use ($typeMap): bool {
        return isset($typeMap[(string) ($candidate['source_type'] ?? '')])
          || isset($typeMap[(string) ($candidate['dependency_type'] ?? '')]);
      }));
      if (!$componentBlockers) {
        continue;
      }
      // Dependency discovery order can vary by provider/runtime. Sort the
      // semantic blocker identity before hashing so equivalent graphs retain
      // the same component ID across previews.
      usort($componentBlockers, static function(array $a, array $b): int {
        $left = implode('|', [
          (string) ($a['source_type'] ?? ''),
          (string) ($a['source_file'] ?? ''),
          (string) ($a['dependency_type'] ?? ''),
          (string) ($a['dependency_name'] ?? ''),
        ]);
        $right = implode('|', [
          (string) ($b['source_type'] ?? ''),
          (string) ($b['source_file'] ?? ''),
          (string) ($b['dependency_type'] ?? ''),
          (string) ($b['dependency_name'] ?? ''),
        ]);
        return strcmp($left, $right);
      });

      $files = [];
      foreach ($componentBlockers as $candidate) {
        $path = trim((string) ($candidate['source_type'] ?? '') . '/' . (string) ($candidate['source_file'] ?? ''), '/');
        if ($path !== '') {
          $files[$path] = TRUE;
        }
      }
      $typeLabels = [];
      foreach ($types as $type) {
        $typeLabels[] = (string) ($labels[$type] ?? $type);
      }
      $identity = [
        'types' => $types,
        'blockers' => array_map(static function(array $candidate): array {
          return [
            'source_type' => (string) ($candidate['source_type'] ?? ''),
            'source_file' => (string) ($candidate['source_file'] ?? ''),
            'dependency_type' => (string) ($candidate['dependency_type'] ?? ''),
            'dependency_name' => (string) ($candidate['dependency_name'] ?? ''),
          ];
        }, $componentBlockers),
      ];
      $encoded = json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
      $id = substr(hash('sha256', $encoded === FALSE ? serialize($identity) : $encoded), 0, 24);
      $fileList = array_keys($files);
      sort($fileList, SORT_STRING);
      $components[] = [
        'id' => $id,
        'title' => implode(' + ', $typeLabels),
        'types' => $types,
        'type_labels' => $typeLabels,
        'files' => $fileList,
        'blockers' => $componentBlockers,
        'blocker_count' => count($componentBlockers),
        'can_exclude' => FALSE,
        'excluded_types' => [],
        'remaining_requested_types' => [],
        'exclusion_reason' => 'Dependency closure has not been evaluated for the current import scope.',
      ];
    }

    usort($components, static function(array $a, array $b): int {
      return strcmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
    });
    return $components;
  }

  /** @return string[] */
  private function connectedTypes(string $seed, array $adjacency): array {
    $queue = [$seed];
    $seen = [];
    while ($queue) {
      $type = (string) array_shift($queue);
      if ($type === '' || isset($seen[$type])) {
        continue;
      }
      $seen[$type] = TRUE;
      foreach (array_keys((array) ($adjacency[$type] ?? [])) as $neighbor) {
        if (!isset($seen[$neighbor])) {
          $queue[] = (string) $neighbor;
        }
      }
    }
    $types = array_keys($seen);
    sort($types, SORT_STRING);
    return $types;
  }

  /** @param array<string,mixed> $edge */
  private function missingDependencyMessage(array $edge, string $ignoredHint): string {
    $ownerType = (string) ($edge['source_type'] ?? '');
    $filename = (string) ($edge['source_file'] ?? '');
    $dependencyType = (string) ($edge['dependency_type'] ?? '');
    $dependencyName = (string) ($edge['dependency_name'] ?? '');
    $reason = (string) ($edge['reason'] ?? 'This Saved Config references another managed configuration item.');

    $message = sprintf(
      'Cannot import %s/%s: dependency %s "%s" is not available in the managed Saved Config set or Current CiviCRM.',
      $ownerType,
      $filename,
      $dependencyType,
      $dependencyName
    );
    if ($dependencyType === 'contact-types' && preg_match('/^[0-9]+$/', $dependencyName)) {
      $message .= ' The dependency name is numeric, which usually means this Saved Config was exported by an older alpha using a local database ID instead of the Contact Type machine name.';
      $message .= ' Re-export Custom Groups and Fields together with Contact Types using the current build, or update the Saved Config dependency to the stable contact type name before importing.';
    }
    else {
      $message .= ' ' . $reason . ' Re-export the related items together, or restore the missing Saved Config before importing.';
    }
    if ($ignoredHint !== '') {
      $message .= ' The dependency appears to be hidden by Config Ignore: ' . $ignoredHint . '. Remove or narrow that ignore rule before importing this item.';
    }
    return $message;
  }

  /** @param array<int,array<string,mixed>> $edges @return array<int,array<string,mixed>> */
  private function uniqueEdges(array $edges): array {
    $unique = [];
    foreach ($edges as $edge) {
      $key = implode('|', [
        (string) ($edge['source_type'] ?? ''),
        (string) ($edge['source_file'] ?? ''),
        (string) ($edge['dependency_type'] ?? ''),
        (string) ($edge['dependency_name'] ?? ''),
      ]);
      $unique[$key] = $edge;
    }
    ksort($unique, SORT_STRING);
    return array_values($unique);
  }

  /** @param string[] $seed @param array<string,bool> $effectiveMap @return string[] */
  private function expandRelatedClosure(array $seed, array $effectiveMap): array {
    $excluded = array_fill_keys($seed, TRUE);
    $map = $this->relatedTypeMap();
    $changed = TRUE;
    while ($changed) {
      $changed = FALSE;
      foreach ($map as $source => $relatedTypes) {
        $connected = isset($excluded[$source]);
        if (!$connected) {
          foreach ($relatedTypes as $relatedType) {
            if (isset($excluded[$relatedType])) {
              $connected = TRUE;
              break;
            }
          }
        }
        if (!$connected) {
          continue;
        }
        foreach (array_merge([$source], $relatedTypes) as $type) {
          if (isset($effectiveMap[$type]) && !isset($excluded[$type])) {
            $excluded[$type] = TRUE;
            $changed = TRUE;
          }
        }
      }
    }
    return $this->sortedStrings(array_keys($excluded));
  }

  private function baseType(string $type): string {
    $position = strpos($type, ':');
    return $position === FALSE ? $type : substr($type, 0, $position);
  }

  /** @param string[] $values @return string[] */
  private function sortedStrings(array $values): array {
    $values = array_values(array_unique(array_filter(array_map('strval', $values), 'strlen')));
    sort($values, SORT_STRING);
    return $values;
  }
}
