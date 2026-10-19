<?php

/**
 * ML-001 diagnostic: read-only Afform/API4 locale and source provenance.
 *
 * Run via cv in a disposable CiviCRM runtime:
 * ML001_AFFORM_NAME=af_household ML001_LANGUAGES=en_US,fr_FR \
 *   cv scr tests/manual/ml001-afform-evidence.php
 *
 * No configuration changes, writes, impersonation or client-data exports.
 * API4 getters may apply the requested language for the duration of the call.
 */

declare(strict_types=1);

if (!class_exists(\Civi\Api4\Afform::class)) {
  fwrite(STDERR, "ML-001: Afform API4 is not installed.\n");
  exit(2);
}

$name = trim((string) (getenv('ML001_AFFORM_NAME') ?: 'af_household'));
if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/D', $name)) {
  fwrite(STDERR, "ML-001: Invalid Afform machine name.\n");
  exit(2);
}

$settings = [];
foreach (['lcMessages', 'enableMultipleLanguages', 'enabledLanguages'] as $key) {
  try {
    $settings[$key] = \Civi::settings()->get($key);
  }
  catch (\Throwable $e) {
    $settings[$key] = 'unavailable';
  }
}

$configured = $settings['enabledLanguages'];
if (is_string($configured)) {
  $configured = preg_split('/[,\s]+/', trim($configured));
}
$configured = is_array($configured) ? array_values(array_filter($configured, 'is_string')) : [];

$requested = trim((string) getenv('ML001_LANGUAGES'));
$languages = $requested !== '' ? explode(',', $requested) : $configured;
$languages = array_values(array_unique(array_filter(array_map('trim', $languages))));
if (count($languages) < 2) {
  fwrite(STDERR, "ML-001: Supply two enabled locales with ML001_LANGUAGES, e.g. en_US,fr_FR.\n");
  fwrite(STDERR, 'Detected enabledLanguages: ' . json_encode($configured) . "\n");
  exit(3);
}
if (count($languages) > 4) {
  fwrite(STDERR, "ML-001: Limit this diagnostic to four locales.\n");
  exit(2);
}
foreach ($languages as $language) {
  if (!preg_match('/^[a-z]{2,3}_[A-Z]{2}$/D', $language)) {
    fwrite(STDERR, "ML-001: Invalid locale syntax; use en_US or fr_FR.\n");
    exit(2);
  }
}

$report = [
  'purpose' => 'ML-001 read-only evidence; not a synchronization or import test',
  'afform' => $name,
  'runtime' => [
    'civicrm_version' => defined('CIVICRM_VERSION') ? CIVICRM_VERSION : \CRM_Utils_System::version(),
    'active_locale' => \CRM_Core_I18n::getLocale(),
    'settings' => $settings,
    'requested_languages' => $languages,
    'languages_confirmed_enabled' => $configured && !array_diff($languages, $configured),
  ],
  'scanner' => ['available' => FALSE, 'has_file_source' => FALSE],
  'translations' => [],
  'api4_by_language' => [],
];

try {
  $scanner = \Civi::service('afform_scanner');
  $filePaths = $scanner->findFilePaths();
  $report['scanner']['available'] = TRUE;
  $report['scanner']['has_file_source'] = array_key_exists($name, (array) $filePaths);
  $report['scanner']['source_entry_kind'] = isset($filePaths[$name]) ? gettype($filePaths[$name]) : 'absent';
  if (isset($filePaths[$name]) && is_string($filePaths[$name])) {
    $report['scanner']['source_basename'] = basename($filePaths[$name]);
    if (is_file($filePaths[$name])) {
      $report['scanner']['source_file_sha256'] = hash_file('sha256', $filePaths[$name]);
    }
  }
}
catch (\Throwable $e) {
  $report['scanner']['error'] = get_class($e) . ': ' . $e->getMessage();
}

// Introspect available translation APIs, but do not bulk-read translations.
foreach (['TranslationSource', 'Translation'] as $entity) {
  $class = 'Civi\\Api4\\' . $entity;
  if (!class_exists($class)) {
    $report['translations'][$entity] = ['available' => FALSE];
    continue;
  }
  try {
    $fields = $class::getFields(FALSE)->execute();
    $names = [];
    foreach ($fields as $field) {
      if (isset($field['name'])) {
        $names[] = $field['name'];
      }
    }
    $report['translations'][$entity] = ['available' => TRUE, 'field_names' => $names];
  }
  catch (\Throwable $e) {
    $report['translations'][$entity] = [
      'available' => TRUE,
      'field_read_error' => get_class($e) . ': ' . $e->getMessage(),
    ];
  }
}

$missing = 0;
foreach ($languages as $language) {
  try {
    $action = \Civi\Api4\Afform::get(FALSE)
      ->addSelect('name', 'title', 'layout')
      ->addWhere('name', '=', $name)
      ->setLanguage($language);
    $row = $action->execute()->first();
    if (!$row) {
      $report['api4_by_language'][$language] = ['found' => FALSE];
      $missing++;
      continue;
    }
    $payload = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $layout = json_encode($row['layout'] ?? NULL, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === FALSE || $layout === FALSE) {
      throw new \RuntimeException('Could not encode Afform API4 result.');
    }
    $report['api4_by_language'][$language] = [
      'found' => TRUE,
      'name' => $row['name'] ?? NULL,
      'title' => $row['title'] ?? NULL,
      'layout_sha256' => hash('sha256', $layout),
      'selected_payload_sha256' => hash('sha256', $payload),
      'selected_fields' => array_keys($row),
    ];
  }
  catch (\Throwable $e) {
    $report['api4_by_language'][$language] = ['error' => get_class($e) . ': ' . $e->getMessage()];
    $missing++;
  }
}

$report['interpretation'] = 'Hashes identify differences in API4 output only. ' .
  'They do not prove equivalent translations, source provenance or safe import.';
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
exit($missing ? 4 : 0);
