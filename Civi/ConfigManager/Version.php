<?php
namespace Civi\ConfigManager;

class Version {
  public const EXTENSION_KEY = 'civi.config.manager';
  /**
   * @var string|null
   */
  private static ?string $version = NULL;

  public static function get(): string {
    if (self::$version !== NULL) {
      return self::$version;
    }

    $infoFile = dirname(__DIR__, 2) . '/info.xml';
    if (is_file($infoFile)) {
      $xml = @simplexml_load_file($infoFile);
      if ($xml && isset($xml->version)) {
        self::$version = trim((string) $xml->version);
        if (self::$version !== '') {
          return self::$version;
        }
      }
    }

    self::$version = 'unknown';
    return self::$version;
  }

}
