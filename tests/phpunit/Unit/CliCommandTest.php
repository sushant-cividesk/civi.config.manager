<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CliCommandTest extends TestCase {
  private string $sandbox;
  private string $fakeCv;
  private string $cli;

  protected function setUp(): void {
    parent::setUp();
    $this->sandbox = sys_get_temp_dir() . '/civicfg-command-test-' . bin2hex(random_bytes(5));
    mkdir($this->sandbox, 0775, TRUE);
    $this->fakeCv = $this->sandbox . '/cv';
    file_put_contents($this->fakeCv, "#!/bin/sh\nprintf '%s\\n' \"\$@\"\n");
    chmod($this->fakeCv, 0755);
    $this->cli = dirname(__DIR__, 3) . '/bin/civicfg';
  }

  protected function tearDown(): void {
    @unlink($this->fakeCv);
    @rmdir($this->sandbox);
    parent::tearDown();
  }

  /**
   * @dataProvider simpleCommandProvider
   */
  public function testSimpleCommandsMapToApiActions(array $args, string $expectedAction): void {
    [$exit, $lines] = $this->runCli($args);

    self::assertSame(0, $exit, implode("\n", $lines));
    self::assertContains('api4', $lines);
    self::assertContains($expectedAction, $lines);
  }

  public function simpleCommandProvider(): array {
    return [
      'status' => [['status'], 'ConfigManager.status'],
      'scope' => [['scope'], 'ConfigManager.scopeGet'],
      'watch' => [['watch', '--type', 'message-templates'], 'ConfigManager.watch'],
      'validate' => [['validate', '--type', 'custom-data'], 'ConfigManager.validate'],
      'diff' => [['diff'], 'ConfigManager.diff'],
    ];
  }

  public function testScopeItemsRequiresOneTypeAndMapsCorrectly(): void {
    [$exit, $lines] = $this->runCli(['scope-items', '--type', 'message-templates']);

    self::assertSame(0, $exit, implode("\n", $lines));
    self::assertContains('ConfigManager.scopeItems', $lines);
    self::assertContains('type=message-templates', $lines);
  }

  public function testScopeSetPassesPortableSelectorsAndWatchFlag(): void {
    [$exit, $lines] = $this->runCli([
      'scope-set',
      '--type', 'message-templates',
      '--mode', 'selected',
      '--selector', 'key:message-templates|workflow_name=receipt|is_default=0',
      '--selector', 'key:message-templates|msg_title=Custom Notice',
      '--watch-unmanaged',
    ]);

    self::assertSame(0, $exit, implode("\n", $lines));
    self::assertContains('ConfigManager.scopeSet', $lines);
    self::assertContains('type=message-templates', $lines);
    self::assertContains('mode=selected', $lines);
    self::assertContains('watchUnmanaged=1', $lines);
    self::assertTrue((bool) array_filter($lines, static function(string $line): bool {
      return strpos($line, 'selectors=[') === 0
        && strpos($line, 'workflow_name=receipt') !== FALSE
        && strpos($line, 'Custom Notice') !== FALSE;
    }));
  }

  public function testScopeSetRejectsSelectorsOutsideSelectedMode(): void {
    [$exit, $lines] = $this->runCli([
      'scope-set', '--type', 'scheduled-jobs', '--mode', 'watch', '--selector', 'job_one',
    ]);

    self::assertSame(2, $exit);
    self::assertStringContainsString('require --mode selected', implode("\n", $lines));
  }

  public function testScopeAndCrossSiteCommandsRejectUnrelatedImportExportOptions(): void {
    foreach ([
      ['scope', '--dry-run'],
      ['scope-items', '--type', 'message-templates', '--yes'],
      ['scope-set', '--type', 'message-templates', '--mode', 'all', '--write'],
      ['cross-site-import', '--dry-run'],
    ] as $args) {
      [$exit] = $this->runCli($args);
      self::assertSame(2, $exit, 'Expected invalid option combination to be rejected: ' . implode(' ', $args));
    }
  }

  public function testCrossSiteImportStatusAllowAndDenyMapToApiActions(): void {
    [$statusExit, $status] = $this->runCli(['cross-site-import']);
    self::assertSame(0, $statusExit);
    self::assertContains('ConfigManager.crossSiteStatus', $status);

    [$allowExit, $allow] = $this->runCli(['cross-site-import', '--allow']);
    self::assertSame(0, $allowExit);
    self::assertContains('ConfigManager.crossSiteSet', $allow);
    self::assertContains('allowed=1', $allow);

    [$denyExit, $deny] = $this->runCli(['cross-site-import', '--deny']);
    self::assertSame(0, $denyExit);
    self::assertContains('ConfigManager.crossSiteSet', $deny);
    self::assertContains('allowed=0', $deny);
  }

  public function testCrossSiteImportRejectsAllowAndDenyTogether(): void {
    [$exit, $lines] = $this->runCli(['cross-site-import', '--allow', '--deny']);

    self::assertSame(2, $exit);
    self::assertStringContainsString('either --allow or --deny', implode("\n", $lines));
  }


  /**
   * Requirement: browser QA is a first-class civicfg command that can run
   * from the extension CLI without cv and passes the real-site boundary to
   * the canonical browser runner.
   */
  public function testQaBrowserRunsCanonicalRunnerWithoutCv(): void {
    $root = $this->createCliFixtureRoot();
    $runner = $root . '/tests/ci/run-browser-php.sh';
    file_put_contents($runner, "#!/bin/sh\nprintf 'url=%s\nuser=%s\npass=%s\n' \"\$CIVICFG_BASE_URL\" \"\$CIVICRM_ADMIN_USER\" \"\${CIVICRM_ADMIN_PASS:-}\"\n");
    chmod($runner, 0755);

    [$exit, $lines] = $this->runCliPath($root . '/bin/civicfg', [
      'qa-browser', '--base-url', 'https://dev.example.test', '--admin-user', 'qa-admin',
    ], ['CIVICRM_ADMIN_PASS' => 'secret-from-env', 'CIVICFG_CV' => '']);

    self::assertSame(0, $exit, implode("\n", $lines));
    self::assertContains('url=https://dev.example.test', $lines);
    self::assertContains('user=qa-admin', $lines);
    self::assertContains('pass=secret-from-env', $lines);
    $this->removeTree($root);
  }

  /** Requirement: generated pre-Alpha67.1 nested vendor can be removed only by explicit CLI consent. */
  public function testQaBrowserCleanPreviewsThenRemovesLegacyVendor(): void {
    $root = $this->createCliFixtureRoot();
    $legacy = $root . '/tests/browser-php/vendor';
    mkdir($legacy, 0775, TRUE);
    file_put_contents($legacy . '/generated.txt', 'generated');

    [$previewExit, $preview] = $this->runCliPath($root . '/bin/civicfg', ['qa-browser-clean']);
    self::assertSame(0, $previewExit, implode("\n", $preview));
    self::assertDirectoryExists($legacy);
    self::assertStringContainsString('Preview only', implode("\n", $preview));

    [$cleanExit, $clean] = $this->runCliPath($root . '/bin/civicfg', ['qa-browser-clean', '--yes']);
    self::assertSame(0, $cleanExit, implode("\n", $clean));
    self::assertDirectoryDoesNotExist($legacy);
    self::assertStringContainsString('removed', strtolower(implode("\n", $clean)));
    $this->removeTree($root);
  }

  /** Requirement: browser passwords must not be accepted as process-list-visible CLI arguments. */
  public function testQaBrowserRejectsPasswordArgument(): void {
    $root = $this->createCliFixtureRoot();
    [$exit, $lines] = $this->runCliPath($root . '/bin/civicfg', [
      'qa-browser', '--base-url', 'https://dev.example.test', '--admin-pass', 'secret',
    ]);
    self::assertSame(2, $exit);
    self::assertStringContainsString('CIVICRM_ADMIN_PASS', implode("\n", $lines));
    $this->removeTree($root);
  }


  private function createCliFixtureRoot(): string {
    $root = $this->sandbox . '/extension-' . bin2hex(random_bytes(3));
    mkdir($root . '/bin', 0775, TRUE);
    mkdir($root . '/tests/ci', 0775, TRUE);
    mkdir($root . '/tests/browser-php', 0775, TRUE);
    copy($this->cli, $root . '/bin/civicfg');
    chmod($root . '/bin/civicfg', 0755);
    return $root;
  }

  /** @return array{0:int,1:array<int,string>} */
  private function runCliPath(string $cli, array $args, array $env = []): array {
    $parts = [];
    foreach ($env as $name => $value) {
      $parts[] = $name . '=' . escapeshellarg((string) $value);
    }
    $parts[] = 'bash';
    $parts[] = escapeshellarg($cli);
    foreach ($args as $arg) {
      $parts[] = escapeshellarg((string) $arg);
    }
    $output = [];
    $exit = 0;
    exec(implode(' ', $parts) . ' 2>&1', $output, $exit);
    return [$exit, array_values(array_map('strval', $output))];
  }

  private function removeTree(string $path): void {
    if (!is_dir($path)) {
      return;
    }
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
      if ($item->isDir() && !$item->isLink()) {
        @rmdir($item->getPathname());
      }
      else {
        @unlink($item->getPathname());
      }
    }
    @rmdir($path);
  }

  /**
   * @return array{0:int,1:array<int,string>}
   */
  private function runCli(array $args): array {
    $parts = [
      'CIVICFG_CV=' . escapeshellarg($this->fakeCv),
      'bash',
      escapeshellarg($this->cli),
    ];
    foreach ($args as $arg) {
      $parts[] = escapeshellarg((string) $arg);
    }
    $output = [];
    $exit = 0;
    exec(implode(' ', $parts) . ' 2>&1', $output, $exit);
    return [$exit, array_values(array_map('strval', $output))];
  }
}
