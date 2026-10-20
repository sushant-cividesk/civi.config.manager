<?php
/**
 * Detect PHP 8-only trailing commas in function declarations on PHP 7.4 builds.
 * PHP_CodeSniffer's formatting rules must not change the extension's runtime
 * compatibility contract. This check is dependency-free.
 */

declare(strict_types=1);

/**
 * @return int[] 1-based lines of invalid parameter-list closing commas. */
function civicfgPhp74ParameterTrailingCommas(string $source): array {
  $tokens = token_get_all($source);
  $count = count($tokens);
  $errors = [];
  $line = 1;
  for ($i = 0; $i < $count; $i++) {
    $token = $tokens[$i];
    if ($token !== '(' && (!is_array($token) || !in_array($token[0], [T_FUNCTION, T_FN], TRUE))) {
      continue;
    }
    if (!is_array($token)) {
      $line += substr_count($token, "\n");
      continue;
    }
    $line = $token[2];
    if (!in_array($token[0], [T_FUNCTION, T_FN], TRUE)) {
      continue;
    }
    // Skip the optional function name, reference marker, and whitespace.
    $start = NULL;
    for ($j = $i + 1; $j < $count; $j++) {
      if ($tokens[$j] === '(') {
        $start = $j;
        break;
      }
      if ($tokens[$j] === ';' || $tokens[$j] === '{') {
        break;
      }
    }
    if ($start === NULL) {
      continue;
    }
    $depth = 1;
    $lastSignificant = NULL;
    for ($j = $start + 1; $j < $count; $j++) {
      $current = $tokens[$j];
      if ($current === '(') {
        $depth++;
      }
      elseif ($current === ')') {
        $depth--;
        if ($depth === 0) {
          if ($lastSignificant === ',') {
            $errors[] = $line;
          }
          break;
        }
      }
      if (is_array($current)) {
        if (!in_array($current[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], TRUE)) {
          $lastSignificant = $current[1];
        }
      }
      elseif ($depth > 0) {
        $lastSignificant = $current;
      }
    }
  }
  return $errors;
}

$fixtures = [
  ["<?php function go(\$a, ) {}", TRUE],
  ["<?php function go(\$a, /*comment*/ ) {}", TRUE],
  ["<?php function go(\$a, \$b = [1,2]) {}", FALSE],
  ["<?php function go(\$a) {}", FALSE],
  ["<?php \$go = fn(\$a,) => \$a;", TRUE],
];
foreach ($fixtures as $fixture) {
  [$code, $expectFailure] = $fixture;
  // Fixtures are raw PHP source, not evaluated.
  $actualFailure = (bool) civicfgPhp74ParameterTrailingCommas(str_replace('\\$', '$', $code));
  if ($actualFailure !== $expectFailure) {
    fwrite(STDERR, "PHP 7.4 signature guard self-test failed.\n");
    exit(1);
  }
}

$root = dirname(__DIR__, 2);
$paths = [
  $root . '/Civi', $root . '/CRM', $root . '/settings',
];
$paths[] = $root . '/configmanager.php';
$errors = [];
$files = 0;
foreach ($paths as $path) {
  if (is_file($path)) {
    $candidates = [$path];
  }
  else {
    $candidates = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
      if ($file->isFile() && $file->getExtension() === 'php') {
        $candidates[] = $file->getPathname();
      }
    }
  }
  foreach ($candidates as $file) {
    $files++;
    foreach (civicfgPhp74ParameterTrailingCommas((string) file_get_contents($file)) as $line) {
      $errors[] = substr($file, strlen($root) + 1) . ':' . $line;
    }
  }
}
if ($errors) {
  fwrite(STDERR, "PHP 7.4 rejects trailing commas in parameter declarations:\n" . implode("\n", $errors) . "\n");
  exit(1);
}
printf("PHP 7.4 function-signature compatibility OK (%d PHP files).\n", $files);
