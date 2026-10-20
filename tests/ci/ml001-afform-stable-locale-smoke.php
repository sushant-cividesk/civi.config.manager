<?php
/**
 * ML-001: test true Afform API action locale, not just a Canonicalizer string.
 * Standalone fixture: no CiviCRM bootstrap or filesystem/database mutation.
 */

declare(strict_types=1);

namespace Civi\Api4 {
  class Afform {
    public static string $ambientLocale = 'en_US';
    public static string $baseTitle = 'Source title';
    public static array $requestedLocales = [];
    public static bool $simulateNoLanguage = FALSE;

    public static function get($checkPermissions = FALSE) {
      if (self::$simulateNoLanguage) {
        // An old provider action without the source-locale API must fail closed.
        return new class() {
          public function addSelect(...$fields) { return $this; }
        };
      }
      return new AfformGetAction();
    }
  }

  class AfformGetAction {
    private ?string $language = NULL;
    private array $conditions = [];
    public function addSelect(...$fields): self { return $this; }
    public function addOrderBy($field, $direction): self { return $this; }
    public function setLimit($limit): self { return $this; }
    public function setOffset($offset): self { return $this; }
    public function addWhere(...$condition): self { $this->conditions[] = $condition; return $this; }
    public function setLanguage(string $language): self { $this->language = $language; return $this; }
    public function execute(): array {
      $lang = $this->language ?? Afform::$ambientLocale;
      Afform::$requestedLocales[] = $this->language;
      $title = $lang === 'fr_FR' ? 'Titre traduit' : Afform::$baseTitle;
      $row = ['name' => 'afMl001Source', 'title' => $title, 'layout' => '<div>' . $title . '</div>'];
      foreach ($this->conditions as $condition) {
        if ($condition[0] === 'name' && $condition[2] !== $row['name']) { return []; }
      }
      return [$row];
    }
  }
}

namespace {
  $root = dirname(__DIR__, 2);
  spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'Civi\\ConfigManager\\';
    if (strpos($class, $prefix) !== 0) { return; }
    $file = $root . '/Civi/ConfigManager/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) { require_once $file; }
  });

  $handler = new \Civi\ConfigManager\Handler\GenericApi4CollectionHandler(
    'formbuilder-afforms', 'FormBuilder Afforms', 'formbuilder/afforms', 'Afform',
    ['name', 'title', 'layout'], ['name' => 'ASC'], 140, 'afforms.yml', TRUE
  );
  $handler->setDeleteMissingEnabled(FALSE);

  \Civi\Api4\Afform::$ambientLocale = 'en_US';
  $first = $handler->export();
  \Civi\Api4\Afform::$ambientLocale = 'fr_FR';
  $second = $handler->export();
  if ($first !== $second) {
    fwrite(STDERR, "FAIL: User locale changed exported Saved Config.\n");
    exit(1);
  }
  if (\Civi\Api4\Afform::$requestedLocales !== ['en_US', 'en_US']) {
    fwrite(STDERR, "FAIL: Afform get actions did not explicitly select source locale.\n");
    exit(1);
  }
  $name = (string) $first[0]['filename'];
  $data = (array) $first[0]['data'];
  $diff = $handler->diff([$name => $data]);
  if ($diff['status'] !== 'in_sync') {
    fwrite(STDERR, "FAIL: Locale-only change produced Afform drift.\n");
    exit(1);
  }
  $preview = $handler->import([$name => $data], TRUE);
  if (!empty($preview['errors']) || $preview['skip'] !== 1 || $preview['update'] !== 0) {
    fwrite(STDERR, 'FAIL: Locale-only change led to unexpected Import update. ' . json_encode($preview) . "\n");
    exit(1);
  }
  if (count(\Civi\Api4\Afform::$requestedLocales) < 4 ||
    array_unique(\Civi\Api4\Afform::$requestedLocales) !== [0 => 'en_US']) {
    fwrite(STDERR, "FAIL: All Afform reads, including Import matching, must select the source locale.\n");
    exit(1);
  }
  // Custom/third-party API4 registrations must retain their old locale behavior.
  $custom = new \Civi\ConfigManager\Handler\GenericApi4CollectionHandler(
    'qa-afform', 'QA Afform', 'qa-afform', 'Afform',
    ['name', 'title', 'layout'], ['name' => 'ASC'], 1, 'qa.yml', TRUE
  );
  if (($custom->export()[0]['data']['item']['title'] ?? NULL) !== 'Titre traduit') {
    fwrite(STDERR, "FAIL: Nonstandard Afform handlers unexpectedly changed read-locale policy.\n");
    exit(1);
  }
  \Civi\Api4\Afform::$baseTitle = 'Edited source title';
  $changed = $handler->diff([$name => $data]);
  if ($changed['status'] !== 'changed' || empty($changed['changed'])) {
    fwrite(STDERR, "FAIL: Genuine Afform source edit was suppressed.\n");
    exit(1);
  }
  \Civi\Api4\Afform::$simulateNoLanguage = TRUE;
  $refused = FALSE;
  try {
    $handler->export();
  }
  catch (\RuntimeException $e) {
    $refused = strpos($e->getMessage(), 'cannot pin the source locale') !== FALSE;
  }
  finally {
    \Civi\Api4\Afform::$simulateNoLanguage = FALSE;
  }
  if (!$refused) {
    fwrite(STDERR, "FAIL: Missing API4 setLanguage did not refuse a locale-unsafe read.\n");
    exit(1);
  }
  if (\Civi\Api4\Afform::$ambientLocale !== 'fr_FR') {
    fwrite(STDERR, "FAIL: Source-locale read changed the caller locale.\n");
    exit(1);
  }
  echo "ML-001 Afform source-locale export/diff/preview and real source edit: PASS (no writes).\n";
}
