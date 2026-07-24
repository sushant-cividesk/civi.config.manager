<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Version;
use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase {
  public function testVersionMatchesInfoXml(): void {
    $xml = simplexml_load_file(dirname(__DIR__, 3) . '/info.xml');

    self::assertNotFalse($xml);
    self::assertSame(trim((string) $xml->version), Version::get());
    self::assertSame('civi.config.manager', Version::EXTENSION_KEY);
  }
}
