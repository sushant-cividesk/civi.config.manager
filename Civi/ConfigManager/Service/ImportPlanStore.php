<?php
namespace Civi\ConfigManager\Service;

/**
 * Private persistent storage for reviewed import plans.
 *
 * Plans live outside the portable Saved Config tree. They are opaque to the
 * browser, integrity checked, short lived, and safe to reload after a browser
 * refresh or PHP worker restart.
 */
final class ImportPlanStore {
  private const DEFAULT_TTL = 86400;
  private string $root;
  private int $ttl;

  public function __construct(?string $root = NULL, int $ttl = self::DEFAULT_TTL) {
    $this->root = $root !== NULL ? rtrim($root, DIRECTORY_SEPARATOR) : $this->resolveBaseDirectory();
    $this->ttl = max(1, $ttl);
    $this->ensureRoot();
  }

  /** @param array<string,mixed> $plan */
  public function create(array $plan): array {
    $this->cleanupExpired();
    $now = time();
    $plan['schema_version'] = 1;
    $plan['plan_id'] = $this->nonce();
    $plan['created_at'] = gmdate('c', $now);
    $plan['expires_at'] = gmdate('c', $now + $this->ttl);
    unset($plan['integrity_hash']);
    $plan['integrity_hash'] = $this->fingerprint($plan);
    $this->write($plan);
    return $plan;
  }

  /** @return array<string,mixed> */
  public function load(string $planId): array {
    $path = $this->path($planId);
    if (!is_file($path) || is_link($path)) {
      throw new \RuntimeException('The reviewed Import preview is no longer available. Build and review a fresh preview before importing.');
    }
    $raw = file_get_contents($path);
    if ($raw === FALSE || trim($raw) === '') {
      throw new \RuntimeException('The reviewed Import preview could not be read. Build and review a fresh preview before importing.');
    }
    $plan = json_decode($raw, TRUE);
    if (!is_array($plan)) {
      throw new \RuntimeException('The reviewed Import preview is invalid. Build and review a fresh preview before importing.');
    }
    $expected = (string) ($plan['integrity_hash'] ?? '');
    unset($plan['integrity_hash']);
    if ($expected === '' || !hash_equals($expected, $this->fingerprint($plan))) {
      throw new \RuntimeException('The reviewed Import preview failed its integrity check. Build and review a fresh preview before importing.');
    }
    $plan['integrity_hash'] = $expected;
    $expiresAt = strtotime((string) ($plan['expires_at'] ?? ''));
    if ($expiresAt === FALSE || $expiresAt < time()) {
      $this->delete($planId);
      throw new \RuntimeException('The reviewed Import preview expired. Build and review a fresh preview before importing.');
    }
    if ((int) ($plan['schema_version'] ?? 0) !== 1 || (string) ($plan['plan_id'] ?? '') !== $planId) {
      throw new \RuntimeException('The reviewed Import preview format is not supported. Build and review a fresh preview before importing.');
    }
    return $plan;
  }

  public function delete(string $planId): void {
    $path = $this->path($planId);
    if (is_file($path) && !is_link($path)) {
      @unlink($path);
    }
  }

  public function cleanupExpired(): void {
    if (!is_dir($this->root)) {
      return;
    }
    $now = time();
    foreach (glob($this->root . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
      if (!is_file($path) || is_link($path)) {
        continue;
      }
      $raw = @file_get_contents($path);
      $data = is_string($raw) ? json_decode($raw, TRUE) : NULL;
      $expiresAt = is_array($data) ? strtotime((string) ($data['expires_at'] ?? '')) : FALSE;
      if ($expiresAt === FALSE || $expiresAt < $now) {
        @unlink($path);
      }
    }
  }

  public function cleanupAll(): void {
    if (!is_dir($this->root)) {
      return;
    }
    foreach (glob($this->root . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
      if (is_file($path) && !is_link($path)) {
        @unlink($path);
      }
    }
  }

  /** @param mixed $value */
  public function fingerprint($value): string {
    $normalized = $this->normalize($value);
    $json = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    if ($json === FALSE) {
      throw new \RuntimeException('Could not fingerprint reviewed Import plan state.');
    }
    return hash('sha256', $json);
  }

  /** @param array<string,mixed> $plan */
  private function write(array $plan): void {
    $this->ensureRoot();
    $path = $this->path((string) $plan['plan_id']);
    $json = json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    if ($json === FALSE) {
      throw new \RuntimeException('Could not encode reviewed Import plan.');
    }
    $tmp = $path . '.tmp-' . $this->nonce();
    if (file_put_contents($tmp, $json, LOCK_EX) === FALSE || !@rename($tmp, $path)) {
      @unlink($tmp);
      throw new \RuntimeException('Could not persist the reviewed Import preview.');
    }
    @chmod($path, 0600);
  }

  private function path(string $planId): string {
    if (!preg_match('/^[a-f0-9]{48}$/', $planId)) {
      throw new \RuntimeException('Invalid reviewed Import plan identifier.');
    }
    return $this->root . DIRECTORY_SEPARATOR . $planId . '.json';
  }

  private function resolveBaseDirectory(): string {
    try {
      if (class_exists('CRM_Core_Config')) {
        $config = \CRM_Core_Config::singleton();
        $configAndLogDir = trim((string) ($config->configAndLogDir ?? ''));
        if ($configAndLogDir !== '') {
          $base = rtrim($configAndLogDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'civicfg-import-plans';
          if ((is_dir($base) || @mkdir($base, 0700, TRUE)) && is_writable($base)) {
            @chmod($base, 0700);
            return $base;
          }
        }
      }
    }
    catch (\Throwable $e) {
      // Unit tests and early bootstrap may not have a usable CiviCRM path.
    }

    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'civicfg-import-plans';
  }

  private function ensureRoot(): void {
    if (!is_dir($this->root) && !@mkdir($this->root, 0700, TRUE) && !is_dir($this->root)) {
      throw new \RuntimeException('Could not create reviewed Import plan storage.');
    }
    if (!is_writable($this->root)) {
      throw new \RuntimeException('Reviewed Import plan storage is not writable.');
    }
    @chmod($this->root, 0700);
  }

  private function nonce(): string {
    return bin2hex(random_bytes(24));
  }

  /** @param mixed $value @return mixed */
  private function normalize($value) {
    if (is_object($value)) {
      $value = (array) $value;
    }
    if (!is_array($value)) {
      return $value;
    }
    $keys = array_keys($value);
    $isList = $keys === [] || $keys === range(0, count($keys) - 1);
    if (!$isList) {
      ksort($value, SORT_STRING);
    }
    foreach ($value as $key => $child) {
      $value[$key] = $this->normalize($child);
    }
    return $value;
  }
}
