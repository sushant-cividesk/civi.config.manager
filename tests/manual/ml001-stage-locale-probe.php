<?php
/**
 * ML-001: read-only multilingual Afform probe for disposable DDEV STAGE.
 *
 * The one synthetic Afform exists only in this PHP process as a listener to
 * civi.afform.get. Nothing is saved to disk, CiviCRM or translation tables.
 * Compare API4 outputs under explicitly negotiated locales; do not infer that
 * distinct translated strings are equivalent without source provenance.
 *
 * From /var/www/html/build/dcivi-stage/web:
 * ML001_STAGE_PROBE=YES_STAGE_ONLY ML001_LANGUAGES=en_US,fr_FR \
 *   drush php:script /var/www/html/ext/civi.config.manager/tests/manual/ml001-stage-locale-probe.php
 */

declare(strict_types=1);

if (getenv('ML001_STAGE_PROBE') !== 'YES_STAGE_ONLY') {
  throw new \RuntimeException('STOP: Explicit ML001_STAGE_PROBE=YES_STAGE_ONLY opt-in is required.');
}
$stageRoot = realpath('/var/www/html/build/dcivi-stage/web');
if (!$stageRoot || realpath(getcwd()) !== $stageRoot) {
  throw new \RuntimeException('STOP: ML-001 probe is restricted to the DDEV STAGE Drupal webroot.');
}
if (!class_exists('Drupal') || !\Drupal::getContainer()->has('civicrm')) {
  throw new \RuntimeException('STOP: DDEV STAGE Drupal/CiviCRM bootstrap is unavailable.');
}
\Drupal::service('civicrm')->initialize();
$database = (string) \CRM_Core_DAO::singleValueQuery('SELECT DATABASE()');
if (!preg_match('/^dcivistage[a-z0-9_]*$/i', $database)) {
  throw new \RuntimeException('STOP: CiviCRM database is not the expected disposable STAGE database.');
}
if (!class_exists(\Civi\Api4\Afform::class)) {
  throw new \RuntimeException('STOP: Afform API4 is not available.');
}

$rawLanguages = (string) (getenv('ML001_LANGUAGES') ?: 'en_US,fr_FR');
$languages = array_values(array_unique(array_map('trim', explode(',', $rawLanguages))));
if (count($languages) !== 2) {
  throw new \RuntimeException('STOP: Supply exactly two locales with ML001_LANGUAGES, e.g. en_US,fr_FR.');
}
foreach ($languages as $language) {
  if (!preg_match('/^[a-z]{2,3}_[A-Z]{2}$/D', $language)) {
    throw new \RuntimeException('STOP: Invalid locale ' . $language . '. Use en_US,fr_FR format.');
  }
}

$config = [];
foreach (['lcMessages', 'enableMultipleLanguages', 'enabledLanguages'] as $setting) {
  try {
    $config[$setting] = \Civi::settings()->get($setting);
  }
  catch (\Throwable $e) {
    $config[$setting] = 'unavailable';
  }
}
$negotiated = [];
foreach ($languages as $language) {
  $locale = \Civi\Core\Locale::negotiate($language);
  $negotiated[$language] = $locale ? (string) ($locale->nominal ?? '') : '';
}
// No unreliable cross-language conclusion when both requests resolve to the
// same locale, or when a requested language falls back to another locale.
$negotiationOk = TRUE;
foreach ($negotiated as $requested => $actual) {
  if ($requested !== $actual) {
    $negotiationOk = FALSE;
  }
}

$beforeLocale = \CRM_Core_I18n::getLocale();
$fixtureName = 'afMl001Stage' . bin2hex(random_bytes(6));
$listener = static function (\Civi\Core\Event\GenericHookEvent $event) use ($fixtureName): void {
  // This title is a genuine gettext lookup, not a hard-coded EN/FR mapping.
  // The hook receives the locale chosen by Afform.get()->setLanguage().
  $event->afforms[] = [
    'name' => $fixtureName,
    'title' => ts('Save'),
    'type' => 'system',
    'layout' => '<div><strong>ML-001 temporary read-only test</strong></div>',
    'is_active' => TRUE,
  ];
};
$dispatcher = \Civi::dispatcher();
$dispatcher->addListener('civi.afform.get', $listener);

try {
  $snapshots = [];
  foreach ($languages as $language) {
    // The API4 language argument is restored by CiviCRM's API subscriber.
    $rows = \Civi\Api4\Afform::get(FALSE)
      ->addSelect('name', 'title', 'layout')
      ->setLanguage($language)
      ->setLimit(251)
      ->execute();
    $byName = [];
    foreach ($rows as $row) {
      $name = (string) ($row['name'] ?? '');
      if ($name === '') {
        continue;
      }
      $title = (string) ($row['title'] ?? '');
      $layout = json_encode($row['layout'] ?? NULL, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      if ($layout === FALSE) {
        throw new \RuntimeException('Unable to encode Afform layout for ' . $name . '.');
      }
      $byName[$name] = [
        'title_sha256' => hash('sha256', $title),
        'layout_sha256' => hash('sha256', $layout),
        'has_title' => $title !== '',
      ];
      if ($name === $fixtureName) {
        // Only our generated fixture is printed in cleartext.
        $byName[$name]['generated_fixture_title'] = $title;
      }
    }
    // The synthetic form must be sampled even when a large site has more
    // than the bounded list limit of 251 forms. The exact-name lookup is read-only.
    if (!isset($byName[$fixtureName])) {
      $fixture = \Civi\Api4\Afform::get(FALSE)
        ->addSelect('name', 'title', 'layout')
        ->addWhere('name', '=', $fixtureName)
        ->setLanguage($language)
        ->setLimit(1)
        ->execute()->first();
      if ($fixture) {
        $title = (string) ($fixture['title'] ?? '');
        $layout = json_encode($fixture['layout'] ?? NULL, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($layout === FALSE) {
          throw new \RuntimeException('Unable to encode temporary Afform layout.');
        }
        $byName[$fixtureName] = [
          'title_sha256' => hash('sha256', $title),
          'layout_sha256' => hash('sha256', $layout),
          'has_title' => $title !== '',
          'generated_fixture_title' => $title,
        ];
      }
    }
    $snapshots[$language] = $byName;
  }
}
finally {
  // Listener was registered only in this process; remove it even on failure.
  $dispatcher->removeListener('civi.afform.get', $listener);
}

$first = $snapshots[$languages[0]];
$second = $snapshots[$languages[1]];
if (!isset($first[$fixtureName], $second[$fixtureName])) {
  throw new \RuntimeException('INCOMPLETE: The temporary hook-supplied Afform did not appear in both API4 calls.');
}
$fixture = [
  'in_memory_only' => TRUE,
  'created_db_or_files' => FALSE,
  'title_by_locale' => [
    $languages[0] => $first[$fixtureName]['generated_fixture_title'],
    $languages[1] => $second[$fixtureName]['generated_fixture_title'],
  ],
  'title_differs' => $first[$fixtureName]['title_sha256'] !== $second[$fixtureName]['title_sha256'],
  'layout_same' => $first[$fixtureName]['layout_sha256'] === $second[$fixtureName]['layout_sha256'],
];
unset($first[$fixtureName], $second[$fixtureName]);
$shared = array_intersect(array_keys($first), array_keys($second));
$changed = [];
$changedCount = 0;
$changedLayoutCount = 0;
$scanner = \Civi::service('afform_scanner');
foreach ($shared as $name) {
  $titleDifferent = $first[$name]['title_sha256'] !== $second[$name]['title_sha256'];
  $layoutDifferent = $first[$name]['layout_sha256'] !== $second[$name]['layout_sha256'];
  if (!$titleDifferent && !$layoutDifferent) {
    continue;
  }
  $changedCount++;
  if ($layoutDifferent) {
    $changedLayoutCount++;
  }
  if (count($changed) >= 10) {
    continue;
  }
  $sourceHashes = [];
  foreach (['aff.json', 'aff.php', 'aff.html'] as $suffix) {
    $file = $scanner->findFilePath((string) $name, $suffix);
    if ($file && is_file($file)) {
      $sourceHashes[$suffix] = hash_file('sha256', $file);
    }
  }
  $changed[] = [
    'name' => $name,
    'title_differs' => $titleDifferent,
    'layout_differs' => $layoutDifferent,
    'file_source_hashes' => $sourceHashes,
    'hook_only_or_no_files' => !$sourceHashes,
  ];
}

$report = [
  'purpose' => 'ML-001 guarded read-only multilingual STAGE diagnosis; NOT a synchronization fix or import validation',
  'civicrm_db' => $database,
  'civicrm_version' => defined('CIVICRM_VERSION') ? CIVICRM_VERSION : \CRM_Utils_System::version(),
  'settings' => $config,
  'is_multilingual' => \CRM_Core_I18n::isMultiLingual(),
  'active_locale_restored' => $beforeLocale === \CRM_Core_I18n::getLocale(),
  'requested_languages' => $languages,
  'negotiated_languages' => $negotiated,
  'requested_locales_honored' => $negotiationOk,
  'temporary_fixture' => $fixture,
  'existing_afforms' => [
    'shared_sampled' => count($shared),
    'possible_more_than_limit' => count($first) >= 250 || count($second) >= 250,
    'locale_varying_count' => $changedCount,
    'locale_varying_layout_count' => $changedLayoutCount,
    'examples' => $changed,
  ],
  'notes' => [
    'No real forms, files, translations, site settings or Saved Config were changed.',
    'A locale-only difference is not proof that two text values share authoritative translation provenance.',
    'A real translated edit and cross-site round-trip have NOT been exercised.',
  ],
];
echo "ML-001 STAGE READ-ONLY LOCALE PROBE: " . ($negotiationOk ? 'PASS' : 'INCOMPLETE') . "\n";
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
if (!$negotiationOk) {
  throw new \RuntimeException('INCOMPLETE: Requested locales did not negotiate independently; correct STAGE language configuration before interpreting translation hashes.');
}
