<?php
namespace Civi\ConfigManager\Service;

/**
 * Local operational state for fingerprints, accepted baselines, and renames.
 *
 * YAML remains the portable configuration source. These tables are local
 * intelligence and may be rebuilt or removed without losing deployable config.
 */
class StateStore {
  public const OBJECT_TABLE = 'civicrm_civicfg_object_state';
  public const BASELINE_TABLE = 'civicrm_civicfg_baseline';
  public const ALIAS_TABLE = 'civicrm_civicfg_identity_alias';
  public const WATCH_TABLE = 'civicrm_civicfg_watch_state';

  private static bool $schemaEnsured = FALSE;

  public function isAvailable(): bool {
    return class_exists('CRM_Core_DAO');
  }

  public function ensureSchema(): void {
    if (!$this->isAvailable() || self::$schemaEnsured) {
      return;
    }

    \CRM_Core_DAO::executeQuery('CREATE TABLE IF NOT EXISTS ' . self::OBJECT_TABLE . ' (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      provider_key VARCHAR(191) NOT NULL,
      config_key TEXT NOT NULL,
      identity_hash CHAR(64) NOT NULL,
      identity_method VARCHAR(64) NOT NULL,
      identity_confidence VARCHAR(32) NOT NULL,
      yaml_hash CHAR(64) NULL,
      active_hash CHAR(64) NULL,
      sync_state VARCHAR(40) NOT NULL,
      canonical_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
      last_scanned_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY civicfg_object_identity (provider_key, identity_hash),
      KEY civicfg_object_state (sync_state)
    ) ENGINE=InnoDB');

    \CRM_Core_DAO::executeQuery('CREATE TABLE IF NOT EXISTS ' . self::BASELINE_TABLE . ' (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      provider_key VARCHAR(191) NOT NULL,
      config_key TEXT NOT NULL,
      identity_hash CHAR(64) NOT NULL,
      baseline_hash CHAR(64) NOT NULL,
      baseline_data LONGTEXT NOT NULL,
      canonical_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
      accepted_source VARCHAR(32) NOT NULL,
      accepted_at DATETIME NOT NULL,
      accepted_by INT UNSIGNED NULL,
      PRIMARY KEY (id),
      UNIQUE KEY civicfg_baseline_identity (provider_key, identity_hash)
    ) ENGINE=InnoDB');

    \CRM_Core_DAO::executeQuery('CREATE TABLE IF NOT EXISTS ' . self::ALIAS_TABLE . ' (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      provider_key VARCHAR(191) NOT NULL,
      old_config_key TEXT NOT NULL,
      old_identity_hash CHAR(64) NOT NULL,
      new_config_key TEXT NOT NULL,
      new_identity_hash CHAR(64) NOT NULL,
      confirmed_at DATETIME NOT NULL,
      confirmed_by INT UNSIGNED NULL,
      PRIMARY KEY (id),
      UNIQUE KEY civicfg_alias_identity (provider_key, old_identity_hash),
      KEY civicfg_alias_target (provider_key, new_identity_hash)
    ) ENGINE=InnoDB');


    \CRM_Core_DAO::executeQuery('CREATE TABLE IF NOT EXISTS ' . self::WATCH_TABLE . ' (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      handler_type VARCHAR(191) NOT NULL,
      provider_key VARCHAR(191) NOT NULL,
      config_key TEXT NOT NULL,
      identity_hash CHAR(64) NOT NULL,
      filename TEXT NOT NULL,
      display_label VARCHAR(255) NOT NULL,
      active_hash CHAR(64) NULL,
      active_data LONGTEXT NULL,
      watch_status VARCHAR(32) NOT NULL,
      canonical_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
      last_scanned_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY civicfg_watch_identity (provider_key, identity_hash),
      KEY civicfg_watch_type_status (handler_type, watch_status)
    ) ENGINE=InnoDB');

    self::$schemaEnsured = TRUE;
  }

  public function dropSchema(): void {
    if (!$this->isAvailable()) {
      return;
    }
    \CRM_Core_DAO::executeQuery('DROP TABLE IF EXISTS ' . self::WATCH_TABLE);
    \CRM_Core_DAO::executeQuery('DROP TABLE IF EXISTS ' . self::ALIAS_TABLE);
    \CRM_Core_DAO::executeQuery('DROP TABLE IF EXISTS ' . self::BASELINE_TABLE);
    \CRM_Core_DAO::executeQuery('DROP TABLE IF EXISTS ' . self::OBJECT_TABLE);
    self::$schemaEnsured = FALSE;
  }

  public function clearObjectState(): void {
    if (!$this->isAvailable()) {
      return;
    }
    $this->ensureSchema();
    \CRM_Core_DAO::executeQuery('DELETE FROM ' . self::OBJECT_TABLE);
  }

  public function upsertObjectState(array $identity, ?string $yamlHash, ?string $activeHash, string $state): void {
    if (!$this->isAvailable()) {
      return;
    }
    $this->ensureSchema();
    $now = date('Y-m-d H:i:s');
    $params = [
      1 => [(string) $identity['provider_key'], 'String'],
      2 => [(string) $identity['config_key'], 'String'],
      3 => [(string) $identity['identity_hash'], 'String'],
      4 => [(string) $identity['identity_method'], 'String'],
      5 => [(string) $identity['identity_confidence'], 'String'],
      6 => [$yamlHash, 'String'],
      7 => [$activeHash, 'String'],
      8 => [$state, 'String'],
      9 => [Canonicalizer::VERSION, 'Integer'],
      10 => [$now, 'String'],
    ];
    \CRM_Core_DAO::executeQuery('INSERT INTO ' . self::OBJECT_TABLE . '
      (provider_key, config_key, identity_hash, identity_method, identity_confidence, yaml_hash, active_hash, sync_state, canonical_version, last_scanned_at)
      VALUES (%1, %2, %3, %4, %5, %6, %7, %8, %9, %10)
      ON DUPLICATE KEY UPDATE
        config_key = VALUES(config_key),
        identity_method = VALUES(identity_method),
        identity_confidence = VALUES(identity_confidence),
        yaml_hash = VALUES(yaml_hash),
        active_hash = VALUES(active_hash),
        sync_state = VALUES(sync_state),
        canonical_version = VALUES(canonical_version),
        last_scanned_at = VALUES(last_scanned_at)', $params);
  }

  public function getWatchState(string $providerKey, string $identityHash): ?array {
    if (!$this->isAvailable()) {
      return NULL;
    }
    $this->ensureSchema();
    $dao = \CRM_Core_DAO::executeQuery('SELECT * FROM ' . self::WATCH_TABLE . ' WHERE provider_key = %1 AND identity_hash = %2 LIMIT 1', [
      1 => [$providerKey, 'String'],
      2 => [$identityHash, 'String'],
    ]);
    if (!$dao->fetch()) {
      return NULL;
    }
    $data = json_decode((string) $dao->active_data, TRUE);
    return [
      'handler_type' => (string) $dao->handler_type,
      'provider_key' => (string) $dao->provider_key,
      'config_key' => (string) $dao->config_key,
      'identity_hash' => (string) $dao->identity_hash,
      'filename' => (string) $dao->filename,
      'display_label' => (string) $dao->display_label,
      'active_hash' => $dao->active_hash !== NULL ? (string) $dao->active_hash : NULL,
      'active_data' => is_array($data) ? $data : [],
      'watch_status' => (string) $dao->watch_status,
      'last_scanned_at' => (string) $dao->last_scanned_at,
    ];
  }

  public function getWatchStatesByType(string $handlerType): array {
    if (!$this->isAvailable()) {
      return [];
    }
    $this->ensureSchema();
    $dao = \CRM_Core_DAO::executeQuery('SELECT * FROM ' . self::WATCH_TABLE . ' WHERE handler_type = %1 ORDER BY id ASC', [
      1 => [$handlerType, 'String'],
    ]);
    $rows = [];
    while ($dao->fetch()) {
      $data = json_decode((string) $dao->active_data, TRUE);
      $rows[] = [
        'handler_type' => (string) $dao->handler_type,
        'provider_key' => (string) $dao->provider_key,
        'config_key' => (string) $dao->config_key,
        'identity_hash' => (string) $dao->identity_hash,
        'filename' => (string) $dao->filename,
        'display_label' => (string) $dao->display_label,
        'active_hash' => $dao->active_hash !== NULL ? (string) $dao->active_hash : NULL,
        'active_data' => is_array($data) ? $data : [],
        'watch_status' => (string) $dao->watch_status,
        'last_scanned_at' => (string) $dao->last_scanned_at,
      ];
    }
    return $rows;
  }

  public function upsertWatchState(string $handlerType, array $identity, string $filename, string $label, ?string $activeHash, array $activeData, string $status): void {
    if (!$this->isAvailable()) {
      return;
    }
    $this->ensureSchema();
    $encoded = json_encode($activeData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    if ($encoded === FALSE) {
      throw new \RuntimeException('Could not encode Configuration Manager watch state.');
    }
    \CRM_Core_DAO::executeQuery('INSERT INTO ' . self::WATCH_TABLE . '
      (handler_type, provider_key, config_key, identity_hash, filename, display_label, active_hash, active_data, watch_status, canonical_version, last_scanned_at)
      VALUES (%1, %2, %3, %4, %5, %6, %7, %8, %9, %10, %11)
      ON DUPLICATE KEY UPDATE
        handler_type = VALUES(handler_type),
        config_key = VALUES(config_key),
        filename = VALUES(filename),
        display_label = VALUES(display_label),
        active_hash = VALUES(active_hash),
        active_data = VALUES(active_data),
        watch_status = VALUES(watch_status),
        canonical_version = VALUES(canonical_version),
        last_scanned_at = VALUES(last_scanned_at)', [
      1 => [$handlerType, 'String'],
      2 => [(string) $identity['provider_key'], 'String'],
      3 => [(string) $identity['config_key'], 'String'],
      4 => [(string) $identity['identity_hash'], 'String'],
      5 => [$filename, 'String'],
      6 => [$label, 'String'],
      7 => [$activeHash, 'String'],
      8 => [$encoded, 'String'],
      9 => [$status, 'String'],
      10 => [Canonicalizer::VERSION, 'Integer'],
      11 => [date('Y-m-d H:i:s'), 'String'],
    ]);
  }

  public function deleteWatchState(string $providerKey, string $identityHash): void {
    if (!$this->isAvailable()) {
      return;
    }
    $this->ensureSchema();
    \CRM_Core_DAO::executeQuery('DELETE FROM ' . self::WATCH_TABLE . ' WHERE provider_key = %1 AND identity_hash = %2', [
      1 => [$providerKey, 'String'],
      2 => [$identityHash, 'String'],
    ]);
  }


  public function clearWatchStatesByType(string $handlerType): void {
    if (!$this->isAvailable()) {
      return;
    }
    $this->ensureSchema();
    \CRM_Core_DAO::executeQuery('DELETE FROM ' . self::WATCH_TABLE . ' WHERE handler_type = %1', [
      1 => [$handlerType, 'String'],
    ]);
  }

  public function getBaseline(string $providerKey, string $identityHash): ?array {
    if (!$this->isAvailable()) {
      return NULL;
    }
    $this->ensureSchema();
    $dao = \CRM_Core_DAO::executeQuery('SELECT * FROM ' . self::BASELINE_TABLE . ' WHERE provider_key = %1 AND identity_hash = %2 LIMIT 1', [
      1 => [$providerKey, 'String'],
      2 => [$identityHash, 'String'],
    ]);
    if (!$dao->fetch()) {
      return NULL;
    }
    $data = json_decode((string) $dao->baseline_data, TRUE);
    return [
      'provider_key' => (string) $dao->provider_key,
      'config_key' => (string) $dao->config_key,
      'identity_hash' => (string) $dao->identity_hash,
      'baseline_hash' => (string) $dao->baseline_hash,
      'baseline_data' => is_array($data) ? $data : [],
      'canonical_version' => (int) $dao->canonical_version,
      'accepted_source' => (string) $dao->accepted_source,
      'accepted_at' => (string) $dao->accepted_at,
    ];
  }

  public function acceptBaseline(array $identity, array $canonicalData, string $hash, string $source): void {
    if (!$this->isAvailable()) {
      return;
    }
    $this->ensureSchema();
    $encoded = json_encode($canonicalData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    if ($encoded === FALSE) {
      throw new \RuntimeException('Could not encode Configuration Manager baseline data.');
    }
    $params = [
      1 => [(string) $identity['provider_key'], 'String'],
      2 => [(string) $identity['config_key'], 'String'],
      3 => [(string) $identity['identity_hash'], 'String'],
      4 => [$hash, 'String'],
      5 => [$encoded, 'String'],
      6 => [Canonicalizer::VERSION, 'Integer'],
      7 => [$source, 'String'],
      8 => [date('Y-m-d H:i:s'), 'String'],
      9 => [$this->loggedInContactId(), 'Integer'],
    ];
    \CRM_Core_DAO::executeQuery('INSERT INTO ' . self::BASELINE_TABLE . '
      (provider_key, config_key, identity_hash, baseline_hash, baseline_data, canonical_version, accepted_source, accepted_at, accepted_by)
      VALUES (%1, %2, %3, %4, %5, %6, %7, %8, %9)
      ON DUPLICATE KEY UPDATE
        config_key = VALUES(config_key),
        baseline_hash = VALUES(baseline_hash),
        baseline_data = VALUES(baseline_data),
        canonical_version = VALUES(canonical_version),
        accepted_source = VALUES(accepted_source),
        accepted_at = VALUES(accepted_at),
        accepted_by = VALUES(accepted_by)', $params);
  }

  public function confirmAlias(array $oldIdentity, array $newIdentity): void {
    if (!$this->isAvailable()) {
      return;
    }
    if ((string) $oldIdentity['provider_key'] !== (string) $newIdentity['provider_key']) {
      throw new \InvalidArgumentException('Identity aliases must stay within the same configuration provider.');
    }
    $this->ensureSchema();
    \CRM_Core_DAO::executeQuery('INSERT INTO ' . self::ALIAS_TABLE . '
      (provider_key, old_config_key, old_identity_hash, new_config_key, new_identity_hash, confirmed_at, confirmed_by)
      VALUES (%1, %2, %3, %4, %5, %6, %7)
      ON DUPLICATE KEY UPDATE
        old_config_key = VALUES(old_config_key),
        new_config_key = VALUES(new_config_key),
        new_identity_hash = VALUES(new_identity_hash),
        confirmed_at = VALUES(confirmed_at),
        confirmed_by = VALUES(confirmed_by)', [
      1 => [(string) $oldIdentity['provider_key'], 'String'],
      2 => [(string) $oldIdentity['config_key'], 'String'],
      3 => [(string) $oldIdentity['identity_hash'], 'String'],
      4 => [(string) $newIdentity['config_key'], 'String'],
      5 => [(string) $newIdentity['identity_hash'], 'String'],
      6 => [date('Y-m-d H:i:s'), 'String'],
      7 => [$this->loggedInContactId(), 'Integer'],
    ]);
  }

  public function previousIdentityHash(string $providerKey, string $newIdentityHash): ?string {
    if (!$this->isAvailable()) {
      return NULL;
    }
    $this->ensureSchema();
    $dao = \CRM_Core_DAO::executeQuery('SELECT old_identity_hash FROM ' . self::ALIAS_TABLE . ' WHERE provider_key = %1 AND new_identity_hash = %2 ORDER BY id DESC LIMIT 1', [
      1 => [$providerKey, 'String'],
      2 => [$newIdentityHash, 'String'],
    ]);
    return $dao->fetch() ? (string) $dao->old_identity_hash : NULL;
  }

  public function resolveAlias(string $providerKey, string $identityHash): ?string {
    if (!$this->isAvailable()) {
      return NULL;
    }
    $this->ensureSchema();
    $dao = \CRM_Core_DAO::executeQuery('SELECT new_identity_hash FROM ' . self::ALIAS_TABLE . ' WHERE provider_key = %1 AND old_identity_hash = %2 LIMIT 1', [
      1 => [$providerKey, 'String'],
      2 => [$identityHash, 'String'],
    ]);
    return $dao->fetch() ? (string) $dao->new_identity_hash : NULL;
  }

  private function loggedInContactId(): ?int {
    if (class_exists('CRM_Core_Session') && method_exists('CRM_Core_Session', 'getLoggedInContactID')) {
      $id = \CRM_Core_Session::getLoggedInContactID();
      return $id ? (int) $id : NULL;
    }
    return NULL;
  }
}
