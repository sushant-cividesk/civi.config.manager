<?php
namespace Civi\ConfigManager\Service;

/**
 * Counts Saved Config paths without parsing file contents.
 */
final class SavedConfigInventory {

  /**
   * @param iterable<int|string,string> $paths
   * @param array<string,string> $typeDirectories Map of type => directory.
   * @return array{total:int,by_type:array<string,int>}
   */
  public function count(iterable $paths, array $typeDirectories): array {
    $byType = array_fill_keys(array_keys($typeDirectories), 0);
    uasort($typeDirectories, static function(string $a, string $b): int {
      return strlen(trim($b, '/')) <=> strlen(trim($a, '/'));
    });

    $total = 0;
    foreach ($paths as $path) {
      $path = trim((string) $path, '/');
      if ($path === '') {
        continue;
      }
      $total++;
      if ($path === 'manifest.yml') {
        continue;
      }
      foreach ($typeDirectories as $type => $directory) {
        $directory = trim((string) $directory, '/');
        if ($directory !== '' && ($path === $directory || strpos($path, $directory . '/') === 0)) {
          $byType[$type]++;
          break;
        }
      }
    }

    return ['total' => $total, 'by_type' => $byType];
  }
}
