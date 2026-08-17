<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/Support/CiviApi4Stubs.php';
require __DIR__ . '/Support/FakeCivi.php';

// CiviCRM provides the global ts() translation helper at runtime. Unit tests
// run outside a bootstrapped CiviCRM container, so provide the smallest
// compatible fallback needed to exercise translatable UI wording.
if (!function_exists('ts')) {
  function ts($text, array $params = []) {
    $translated = (string) $text;
    foreach ($params as $key => $value) {
      $translated = str_replace('%' . (string) $key, (string) $value, $translated);
    }
    return $translated;
  }
}
