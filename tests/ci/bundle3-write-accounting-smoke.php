<?php
/**
 * Dependency-free Bundle 3 accounting regression. Neither the mock providers
 * nor these checks connect to CiviCRM or write to any database.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
  $prefix = 'Civi\\ConfigManager\\';
  if (strpos($class, $prefix) !== 0) {
    return;
  }
  $file = $root . '/Civi/ConfigManager/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
  if (is_file($file)) {
    require_once $file;
  }
});

use Civi\ConfigManager\Handler\ContactLayoutHandler;
use Civi\ConfigManager\Handler\ReportInstanceHandler;

$checkCount = 0;
$check = static function (bool $condition, string $message) use (&$checkCount): void {
  $checkCount++;
  if (!$condition) {
    fwrite(STDERR, 'Bundle 3 write accounting FAILED: ' . $message . "\n");
    exit(1);
  }
};

$layout = new class() extends ContactLayoutHandler {
  public bool $failWrite = FALSE;
  public bool $exists = FALSE;
  public int $writes = 0;

  protected function api4Get(string $entity, array $where = [], array $select = ['*'], array $orderBy = []): array {
    return $this->exists ? [['id' => 13, 'label' => 'fixture_layout', 'weight' => 1, 'groups' => []]] : [];
  }

  protected function api4Create(string $entity, array $values): array {
    if ($this->failWrite) {
      throw new \RuntimeException('Simulated ContactLayout create failure.');
    }
    $this->writes++;
    return ['id' => 13] + $values;
  }

  protected function api4Update(string $entity, array $where, array $values): array {
    if ($this->failWrite) {
      throw new \RuntimeException('Simulated ContactLayout update failure.');
    }
    $this->writes++;
    return ['id' => 13] + $values;
  }
};

$report = new class() extends ReportInstanceHandler {
  public bool $failWrite = FALSE;
  public bool $failRead = FALSE;
  public bool $exists = FALSE;
  public int $writes = 0;

  protected function api3(string $entity, string $action, array $params): array {
    if ($action === 'get') {
      if ($this->failRead) {
        return ['is_error' => 1, 'error_message' => 'Simulated APIv3 read failure.'];
      }
      return ['values' => $this->exists ? [[
        'id' => 14, 'name' => 'fixture_report', 'report_id' => 'contact/summary', 'title' => 'Existing',
      ]] : []];
    }
    if ($action === 'create') {
      $this->writes++;
      return $this->failWrite
        ? ['is_error' => 1, 'error_message' => 'Simulated APIv3 create failure.']
        : ['is_error' => 0, 'id' => $params['id'] ?? 14];
    }
    return ['values' => []];
  }
};

$layoutDoc = ['type' => 'contact-layouts.item', 'item' => ['label' => 'fixture_layout', 'weight' => 2, 'groups' => []]];
$reportDoc = ['type' => 'report-instances.item', 'item' => ['name' => 'fixture_report', 'report_id' => 'contact/summary', 'title' => 'Requested']];

foreach ([FALSE, TRUE] as $exists) {
  $op = $exists ? 'update' : 'create';
  foreach ([FALSE, TRUE] as $fail) {
    $layout->exists = $exists;
    $layout->failWrite = $fail;
    $layout->writes = 0;
    $layoutResult = $layout->import(['layout.yml' => $layoutDoc], FALSE);
    $check((int) $layoutResult[$op] === ($fail ? 0 : 1), 'ContactLayout ' . $op . ' counts only completed writes');
    $check((bool) $layoutResult['ok'] === !$fail, 'ContactLayout ' . $op . ' reports failure');
    $check(count($layoutResult['errors']) === ($fail ? 1 : 0), 'ContactLayout ' . $op . ' reports error details');

    $report->exists = $exists;
    $report->failWrite = $fail;
    $report->writes = 0;
    $reportResult = $report->import(['report.yml' => $reportDoc], FALSE);
    $check((int) $reportResult[$op] === ($fail ? 0 : 1), 'ReportInstance ' . $op . ' counts only completed writes');
    $check((bool) $reportResult['ok'] === !$fail, 'ReportInstance ' . $op . ' reports APIv3 failure');
    $check(count($reportResult['errors']) === ($fail ? 1 : 0), 'ReportInstance ' . $op . ' preserves APIv3 error details');
  }
}
foreach ([$layout, $report] as $handler) {
  $handler->exists = FALSE;
  $handler->failWrite = TRUE;
  $handler->writes = 0;
  $docs = $handler === $layout ? ['layout.yml' => $layoutDoc] : ['report.yml' => $reportDoc];
  $preview = $handler->import($docs, TRUE);
  $check($preview['ok'] && $preview['create'] === 1 && $handler->writes === 0, $handler->getType() . ' dry run cannot write');
}

$report->failRead = TRUE;
$report->failWrite = FALSE;
$report->exists = FALSE;
$report->writes = 0;
$readFailure = $report->import(['report.yml' => $reportDoc], FALSE);
$check(empty($readFailure['ok']) && $readFailure['create'] === 0 && count($readFailure['errors']) === 1, 'failed APIv3 lookup must block create');
$check($report->writes === 0, 'failed APIv3 lookup must never issue a write');
try {
  $report->export();
  $check(FALSE, 'failed APIv3 export query must not appear empty');
}
catch (\RuntimeException $e) {
  $check(strpos($e->getMessage(), 'read failed') !== FALSE, 'failed APIv3 export query must explain the read failure');
}

echo 'Bundle 3 write accounting OK (' . $checkCount . " checks; no database writes).\n";
