<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Service\ConfigManager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ConfigManagerCrossSiteTest extends TestCase {
  protected function setUp(): void {
    parent::setUp();
    \Civi::settings()->reset();
  }

  protected function tearDown(): void {
    \Civi::settings()->reset();
    parent::tearDown();
  }

  public function testDifferentSiteIsBlockedByDefault(): void {
    $manager = new CrossSiteFixtureConfigManager('local-site');
    $result = ['ok' => TRUE, 'errors' => []];

    $this->validateManifest($manager, $result, ['site_id' => 'foreign-site']);

    self::assertFalse($result['ok']);
    self::assertCount(1, $result['errors']);
    self::assertStringContainsString('does not match this site_id', (string) $result['errors'][0]['message']);
  }

  public function testDifferentSiteIsAllowedOnlyWhenExplicitlyEnabled(): void {
    $manager = new CrossSiteFixtureConfigManager('local-site');

    $enabled = $manager->setCrossSiteImportAllowed(TRUE);
    self::assertTrue($enabled['allowed']);
    self::assertSame('local-site', $enabled['site_id']);

    $result = ['ok' => TRUE, 'errors' => []];
    $this->validateManifest($manager, $result, ['site_id' => 'foreign-site']);
    self::assertTrue($result['ok']);
    self::assertSame([], $result['errors']);

    $disabled = $manager->setCrossSiteImportAllowed(FALSE);
    self::assertFalse($disabled['allowed']);
    $result = ['ok' => TRUE, 'errors' => []];
    $this->validateManifest($manager, $result, ['site_id' => 'foreign-site']);
    self::assertFalse($result['ok']);
  }

  public function testSameSiteDoesNotNeedCrossSiteOverride(): void {
    $manager = new CrossSiteFixtureConfigManager('shared-site');
    $result = ['ok' => TRUE, 'errors' => []];

    $this->validateManifest($manager, $result, ['site_id' => 'shared-site']);

    self::assertTrue($result['ok']);
    self::assertSame([], $result['errors']);
  }

  public function testLegacyManifestWithoutSiteIdentifierDoesNotFalseBlock(): void {
    $manager = new CrossSiteFixtureConfigManager('local-site');
    $result = ['ok' => TRUE, 'errors' => []];

    $this->validateManifest($manager, $result, ['schema_version' => 1]);

    self::assertTrue($result['ok']);
    self::assertSame([], $result['errors']);
  }

  private function validateManifest(ConfigManager $manager, array &$result, array $manifest): void {
    $method = new ReflectionMethod(ConfigManager::class, 'addManifestValidation');
    $method->setAccessible(TRUE);
    $method->invokeArgs($manager, [&$result, $manifest]);
  }
}

final class CrossSiteFixtureConfigManager extends ConfigManager {
  private string $siteId;

  public function __construct(string $siteId) {
    $this->siteId = $siteId;
  }

  public function getSiteIdentifier(): string {
    return $this->siteId;
  }
}
