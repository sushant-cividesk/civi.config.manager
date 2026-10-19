<?php
/**
 * Check that Composer script aliases resolve before running expensive QA.
 *
 * Composer metadata validation does not verify every @script reference.
 * This gate rejects missing aliases rather than leaving an incomplete QA run.
 */

declare(strict_types=1);

/**
 * @param array<string, mixed> $scripts Composer's scripts definition.
 * @return string[] Missing aliases and the scripts that reference them.
 */
function civicfgMissingComposerScriptReferences(array $scripts): array {
  $missing = [];
  $builtIns = ['php', 'composer', 'putenv', 'no_additional_args', 'self'];
  foreach ($scripts as $name => $commands) {
    foreach ((array) $commands as $command) {
      if (!is_string($command) || !preg_match('/^@([A-Za-z0-9_.:-]+)(?:\s|$)/', trim($command), $matches)) {
        continue;
      }
      $reference = $matches[1];
      if (!array_key_exists($reference, $scripts) && !in_array($reference, $builtIns, TRUE)) {
        $missing[] = $name . ' -> @' . $reference;
      }
    }
  }
  return $missing;
}

// The regression guard itself must catch an undefined alias.
if (civicfgMissingComposerScriptReferences(['qa:fast' => ['@compatibility8.0']]) !== ['qa:fast -> @compatibility8.0']) {
  fwrite(STDERR, "Composer script reference self-test failed.\n");
  exit(1);
}

$manifestPath = dirname(__DIR__, 2) . '/composer.json';
$manifest = json_decode((string) file_get_contents($manifestPath), TRUE);
if (!is_array($manifest) || !isset($manifest['scripts']) || !is_array($manifest['scripts'])) {
  fwrite(STDERR, "Invalid Composer script manifest.\n");
  exit(1);
}

$missing = civicfgMissingComposerScriptReferences($manifest['scripts']);
if ($missing) {
  fwrite(STDERR, "Undefined Composer script reference(s):\n  " . implode("\n  ", $missing) . "\n");
  exit(1);
}

fwrite(STDOUT, "Composer script references OK.\n");
