<?php
/**
 * ReportInstance regression: a legacy report may have no name array key.
 * This test is dependency-free and never calls a live CiviCRM instance.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
  $prefix = 'Civi\\ConfigManager\\';
  if (strpos($class, $prefix) !== 0) {
    return;
  }
  $path = $root . '/Civi/ConfigManager/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
  if (is_file($path)) {
    require_once $path;
  }
});

$report = new class() extends \Civi\ConfigManager\Handler\ReportInstanceHandler {
  public int $writes = 0;

  protected function api3(string $entity, string $action, array $params): array {
    if ($entity !== 'ReportInstance') {
      throw new \RuntimeException('Unexpected entity.');
    }
    if ($action === 'get') {
      // Reproduce the ReportInstance record from STAGE that lacks 'name'.
      return [
        'is_error' => 0,
        'values' => [[
          'id' => 23,
          'report_id' => 'contact/summary',
          'title' => 'Legacy report',
        ]],
      ];
    }
    $this->writes++;
    throw new \RuntimeException('Unexpected report write.');
  }
};

$doc = [
  'legacy-report.yml' => [
    'type' => 'report-instances.item',
    'entity' => 'ReportInstance',
    'item' => ['report_id' => 'contact/summary', 'title' => 'Legacy report'],
  ],
];

// Convert PHP warnings into actual test failures instead of logging noise.
set_error_handler(static function (int $severity, string $message): bool {
  if (error_reporting() & $severity) {
    throw new \ErrorException($message, 0, $severity);
  }
  return FALSE;
});
try {
  $result = $report->import($doc, TRUE);
}
finally {
  restore_error_handler();
}

if (empty($result['ok']) || (int) $result['skip'] !== 1 ||
  (int) $result['create'] !== 0 || (int) $result['update'] !== 0 ||
  $report->writes !== 0) {
  fwrite(STDERR, 'Legacy unnamed report dry-run must skip without warnings or writes: ' . json_encode($result) . "\n");
  exit(1);
}

echo "Bundle 3 legacy unnamed ReportInstance: PASS (no warnings or writes).\n";
