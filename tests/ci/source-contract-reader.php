<?php

/**
 * Read a production source file and explicitly declared same-directory traits.
 *
 * The contracts inspect real source code. Refactoring a class into stateful
 * traits must not make safety assertions inspect only an empty facade, or
 * force functionality back into an oversized class just for source matching.
 * Follow only literal require_once paths to local *Trait.php files; missing
 * dependencies remain hard failures, not silently skipped checks.
 */
function civicfgReadContractSource(string $root, string $relative): string {
  $path = $root . '/' . $relative;
  if (!is_file($path)) {
    throw new \RuntimeException('Missing contract source file: ' . $relative);
  }
  $contents = file_get_contents($path);
  if ($contents === FALSE) {
    throw new \RuntimeException('Cannot read contract source file: ' . $relative);
  }
  // Only explicitly referenced local concern source is included.
  if (strpos($relative, 'Civi/ConfigManager/') !== 0 || substr($relative, -4) !== '.php') {
    return $contents;
  }
  preg_match_all("~require_once __DIR__ \\. '/([A-Za-z][A-Za-z0-9]*Trait\.php)'~", $contents, $matches);
  foreach (array_unique($matches[1]) as $name) {
    $traitFile = dirname($path) . '/' . $name;
    if (!is_file($traitFile)) {
      throw new \RuntimeException('Missing required source concern: ' . $traitFile);
    }
    $body = file_get_contents($traitFile);
    if ($body === FALSE) {
      throw new \RuntimeException('Cannot read required source concern: ' . $traitFile);
    }
    $contents .= "\n" . $body;
  }
  return $contents;
}
