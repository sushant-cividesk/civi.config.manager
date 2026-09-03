<?php
namespace Civi\ConfigManager\Handler;

use Civi\ConfigManager\Service\CoreEntityDefinitions;

/**
 * Tags use the generic portable export/validation contract plus dependency-safe import.
 *
 * Parent Tag references are self-referential. On an empty target the child may
 * be encountered before its parent, so apply performs bounded dependency
 * passes instead of depending on YAML filename order. Cycles and missing
 * parents fail closed. Delete-missing remains disabled.
 */
class TagHandler extends EntityDefinitionHandler {

  private const WRITABLE_FIELDS = [
    'name', 'label', 'description', 'parent_id', 'is_selectable', 'is_reserved',
    'is_tagset', 'used_for', 'color',
  ];

  public function __construct() {
    parent::__construct('tags', CoreEntityDefinitions::tag());
  }

  public function import(array $items, bool $dryRun = TRUE): array {
    return $this->importIterable($items, $dryRun);
  }

  public function importIterable(iterable $items, bool $dryRun = TRUE): array {
    $summary = [
      'type' => 'tags',
      'status' => $dryRun ? 'dry_run' : 'applied',
      'dry_run' => $dryRun,
      'create' => 0,
      'update' => 0,
      'delete' => 0,
      'skip' => 0,
      'warnings' => [],
      'errors' => [],
    ];

    $entries = $this->expandTagItems($items, $summary);
    $plannedNames = [];
    foreach ($entries as $entry) {
      $name = trim((string) ($entry['row']['name'] ?? ''));
      if ($name === '') {
        continue;
      }
      if (isset($plannedNames[$name])) {
        $summary['errors'][] = [
          'file' => $entry['filename'],
          'name' => $name,
          'message' => 'Duplicate Tag name in import YAML: ' . $name . '.',
        ];
        continue;
      }
      $plannedNames[$name] = TRUE;
    }
    if ($summary['errors']) {
      $summary['ok'] = FALSE;
      return $summary;
    }

    if ($dryRun) {
      foreach ($entries as $entry) {
        $this->previewEntry($entry, $plannedNames, $summary);
      }
      $summary['ok'] = !$summary['errors'];
      return $summary;
    }

    $pending = $entries;
    while ($pending) {
      $next = [];
      $progress = FALSE;
      foreach ($pending as $entry) {
        try {
          $this->applyEntry($entry, $plannedNames, $summary);
          $progress = TRUE;
        }
        catch (TagParentPendingException $e) {
          $next[] = $entry;
        }
        catch (\Throwable $e) {
          $summary['errors'][] = [
            'file' => $entry['filename'],
            'name' => (string) ($entry['row']['name'] ?? ''),
            'message' => $e->getMessage(),
          ];
        }
      }

      if (!$next) {
        break;
      }
      if (!$progress) {
        foreach ($next as $entry) {
          $name = (string) ($entry['row']['name'] ?? '');
          $parentName = $this->parentName((array) $entry['row']);
          $summary['errors'][] = [
            'file' => $entry['filename'],
            'name' => $name,
            'message' => 'Tag parent dependency could not be resolved; check for a missing parent or parent cycle: ' . $parentName . '.',
          ];
        }
        break;
      }
      $pending = $next;
    }

    $summary['ok'] = !$summary['errors'];
    return $summary;
  }

  /**
   * @return array<int,array{filename:string,row:array<string,mixed>}>
   */
  private function expandTagItems(iterable $items, array &$summary): array {
    $entries = [];
    foreach ($items as $filename => $document) {
      $type = (string) ($document['type'] ?? '');
      if ($type === 'tags.collection') {
        foreach ((array) ($document['items'] ?? []) as $row) {
          $entries[] = ['filename' => (string) $filename, 'row' => (array) $row];
        }
      }
      elseif ($type === 'tags.item') {
        $entries[] = ['filename' => (string) $filename, 'row' => (array) ($document['item'] ?? [])];
      }
      else {
        $summary['errors'][] = ['file' => $filename, 'message' => 'Invalid type. Expected tags.item or tags.collection.'];
      }
    }
    return $entries;
  }

  private function previewEntry(array $entry, array $plannedNames, array &$summary): void {
    $row = $this->portableRow((array) $entry['row']);
    $name = trim((string) ($row['name'] ?? ''));
    if ($name === '') {
      $summary['errors'][] = ['file' => $entry['filename'], 'message' => 'Tag is missing stable name identity.'];
      return;
    }

    try {
      $parentName = $this->parentName($row);
      if ($parentName !== '' && !$this->tagExists($parentName) && !isset($plannedNames[$parentName])) {
        throw new \RuntimeException('Tag parent does not exist on the target and is not included in YAML: ' . $parentName . '.');
      }
      $existing = $this->api4GetFirst('Tag', [['name', '=', $name]], ['*']);
      if (!$existing) {
        $summary['create']++;
        return;
      }
      $existingPortable = $this->existingPortableRow((array) $existing);
      if ($this->desiredDiffers($existingPortable, $row)) {
        $summary['update']++;
      }
      else {
        $summary['skip']++;
      }
    }
    catch (\Throwable $e) {
      $summary['errors'][] = ['file' => $entry['filename'], 'name' => $name, 'message' => $e->getMessage()];
    }
  }

  private function applyEntry(array $entry, array $plannedNames, array &$summary): void {
    $portable = $this->portableRow((array) $entry['row']);
    $name = trim((string) ($portable['name'] ?? ''));
    if ($name === '') {
      throw new \RuntimeException('Tag is missing stable name identity.');
    }

    $desired = $portable;
    $parentName = $this->parentName($portable);
    if ($parentName !== '') {
      $parent = $this->api4GetFirst('Tag', [['name', '=', $parentName]], ['id', 'name']);
      if (!$parent || empty($parent['id'])) {
        if (isset($plannedNames[$parentName])) {
          throw new TagParentPendingException('Parent Tag is planned but not created yet: ' . $parentName . '.');
        }
        throw new \RuntimeException('Tag parent does not exist on the target and is not included in YAML: ' . $parentName . '.');
      }
      $desired['parent_id'] = $parent['id'];
    }
    else {
      $desired['parent_id'] = NULL;
    }

    $existing = $this->api4GetFirst('Tag', [['name', '=', $name]], ['*']);
    if ($existing) {
      if ($this->desiredDiffers((array) $existing, $desired)) {
        if (empty($existing['id'])) {
          throw new \RuntimeException('Existing Tag has no local ID for update: ' . $name . '.');
        }
        $summary['update']++;
        $this->api4Update('Tag', [['id', '=', $existing['id']]], $desired);
      }
      else {
        $summary['skip']++;
      }
      return;
    }

    $summary['create']++;
    $this->api4Create('Tag', $desired);
  }

  private function portableRow(array $row): array {
    $row = array_intersect_key($row, array_flip(self::WRITABLE_FIELDS));
    unset($row['id'], $row['created_id'], $row['created_date']);
    $parent = $row['parent_id'] ?? NULL;
    if ($parent !== NULL && $parent !== '') {
      $this->assertParentReference($parent);
    }
    return $row;
  }

  private function existingPortableRow(array $row): array {
    $portable = array_intersect_key($row, array_flip(self::WRITABLE_FIELDS));
    $parentId = $portable['parent_id'] ?? NULL;
    if ($parentId !== NULL && $parentId !== '') {
      $parent = $this->api4GetFirst('Tag', [['id', '=', $parentId]], ['id', 'name']);
      if (!$parent || empty($parent['name'])) {
        throw new \RuntimeException('Existing Tag parent cannot be resolved to portable name identity.');
      }
      $portable['parent_id'] = [
        'provider' => 'api4:Tag',
        'entity' => 'Tag',
        'key' => ['name' => (string) $parent['name']],
      ];
    }
    else {
      $portable['parent_id'] = NULL;
    }
    return $portable;
  }

  private function parentName(array $row): string {
    $parent = $row['parent_id'] ?? NULL;
    if ($parent === NULL || $parent === '') {
      return '';
    }
    $this->assertParentReference($parent);
    return trim((string) $parent['key']['name']);
  }

  private function assertParentReference($parent): void {
    if (!is_array($parent)
      || ($parent['provider'] ?? '') !== 'api4:Tag'
      || ($parent['entity'] ?? '') !== 'Tag'
      || !isset($parent['key'])
      || !is_array($parent['key'])
      || array_keys($parent['key']) !== ['name']
      || !is_scalar($parent['key']['name'] ?? NULL)
      || trim((string) ($parent['key']['name'] ?? '')) === '') {
      throw new \RuntimeException('Tag parent_id must use an api4:Tag semantic reference keyed exactly by name.');
    }
  }

  private function tagExists(string $name): bool {
    return $this->api4GetFirst('Tag', [['name', '=', $name]], ['id']) !== NULL;
  }
}

