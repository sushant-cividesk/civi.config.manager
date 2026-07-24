<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$requiredSections = [
  'id',
  'feature',
  'level',
  'fixtures',
  'steps',
  'expected',
  'negative_cases',
  'isolation',
  'cleanup',
];
$allowedLevels = ['unit', 'headless', 'integration', 'browser'];
$files = glob(dirname(__DIR__) . '/scenarios/*.yml') ?: [];
$errors = [];
$seenIds = [];

if ($files === []) {
  $errors[] = 'No developer test scenario files were found.';
}

sort($files);
foreach ($files as $file) {
  $relative = 'tests/scenarios/' . basename($file);
  try {
    $scenario = Yaml::parseFile($file);
  }
  catch (ParseException $e) {
    $errors[] = $relative . ': invalid YAML: ' . $e->getMessage();
    continue;
  }

  if (!is_array($scenario)) {
    $errors[] = $relative . ': scenario must parse to a mapping.';
    continue;
  }

  foreach ($requiredSections as $section) {
    if (!array_key_exists($section, $scenario)) {
      $errors[] = $relative . ': missing required section `' . $section . '`.';
    }
  }

  $id = is_string($scenario['id'] ?? NULL) ? trim($scenario['id']) : '';
  if ($id === '' || !preg_match('/^[A-Z0-9][A-Z0-9_-]+$/', $id)) {
    $errors[] = $relative . ': `id` must be a non-empty uppercase machine identifier.';
  }
  elseif (isset($seenIds[$id])) {
    $errors[] = $relative . ': duplicate scenario id `' . $id . '` also used by ' . $seenIds[$id] . '.';
  }
  else {
    $seenIds[$id] = $relative;
  }

  if (!is_string($scenario['feature'] ?? NULL) || trim((string) $scenario['feature']) === '') {
    $errors[] = $relative . ': `feature` must be a non-empty description.';
  }

  $levels = $scenario['level'] ?? [];
  if (!is_array($levels) || $levels === []) {
    $errors[] = $relative . ': `level` must contain at least one test level.';
  }
  else {
    foreach ($levels as $level) {
      if (!is_string($level) || !in_array($level, $allowedLevels, TRUE)) {
        $errors[] = $relative . ': unsupported test level `' . (string) $level . '`.';
      }
    }
  }

  foreach (['fixtures', 'expected', 'isolation', 'cleanup'] as $mapping) {
    if (!is_array($scenario[$mapping] ?? NULL) || ($scenario[$mapping] ?? []) === []) {
      $errors[] = $relative . ': `' . $mapping . '` must be a non-empty mapping.';
    }
  }

  foreach (['steps', 'negative_cases'] as $list) {
    $values = $scenario[$list] ?? [];
    if (!is_array($values) || $values === []) {
      $errors[] = $relative . ': `' . $list . '` must be a non-empty list.';
      continue;
    }
    foreach ($values as $value) {
      if (!is_string($value) || trim($value) === '') {
        $errors[] = $relative . ': `' . $list . '` entries must be non-empty strings.';
      }
    }
  }

  $isolation = is_array($scenario['isolation'] ?? NULL) ? $scenario['isolation'] : [];
  foreach (['outbound_network', 'outbound_email'] as $boundary) {
    if (($isolation[$boundary] ?? NULL) !== 'blocked') {
      $errors[] = $relative . ': isolation boundary `' . $boundary . '` must be `blocked`.';
    }
  }
}

if ($errors !== []) {
  fwrite(STDERR, "Developer scenario validation failed:\n- " . implode("\n- ", $errors) . "\n");
  exit(1);
}

fwrite(STDOUT, sprintf("Validated %d developer test scenario(s).\n", count($files)));
