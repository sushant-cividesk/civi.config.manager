<?php
namespace Civi\ConfigManager\Service;

/**
 * Builds stable semantic identities for portable configuration documents.
 */
class ConfigIdentity {
  public const EXPLICIT = 'EXPLICIT';
  public const API_VERIFIED = 'API_VERIFIED';
  public const DISCOVERED_UNIQUE = 'DISCOVERED_UNIQUE';
  public const AMBIGUOUS = 'AMBIGUOUS';

  /**
   * Identify a YAML/export document without relying on database IDs.
   *
   * @return array{provider_key:string,config_key:string,identity_hash:string,identity_method:string,identity_confidence:string,write_safe:bool}
   */
  public function identify(string $handlerType, array $data, string $filename = ''): array {
    $documentType = (string) ($data['type'] ?? $handlerType);
    $provider = $this->providerKey($handlerType, $data);
    $method = 'document_type';
    $confidence = self::API_VERIFIED;
    $identity = '';

    if (!empty($data['identity_key']) && is_scalar($data['identity_key'])) {
      $identity = (string) $data['identity_key'];
      $method = 'declared_identity_key';
      $declaredConfidence = (string) ($data['identity_confidence'] ?? self::EXPLICIT);
      $confidence = in_array($declaredConfidence, [self::EXPLICIT, self::API_VERIFIED, self::DISCOVERED_UNIQUE, self::AMBIGUOUS], TRUE)
        ? $declaredConfidence
        : self::EXPLICIT;
    }
    elseif ($documentType === 'message_template') {
      $template = (array) ($data['template'] ?? []);
      if (!empty($template['workflow_name'])) {
        $identity = 'workflow_name=' . (string) $template['workflow_name'] . '|is_default=' . (!empty($template['is_default']) ? '1' : '0');
        $method = 'message_template.workflow_name+is_default';
        $confidence = self::API_VERIFIED;
      }
      elseif (!empty($template['msg_title'])) {
        $identity = 'msg_title=' . (string) $template['msg_title'];
        $method = 'message_template.msg_title';
        $declaredConfidence = (string) ($data['identity_confidence'] ?? self::DISCOVERED_UNIQUE);
        $confidence = in_array($declaredConfidence, [self::DISCOVERED_UNIQUE, self::AMBIGUOUS], TRUE)
          ? $declaredConfidence
          : self::DISCOVERED_UNIQUE;
      }
    }
    elseif ($documentType === 'extension.item') {
      $extension = (array) ($data['extension'] ?? []);
      $identity = (string) ($extension['key'] ?? ($data['key'] ?? ''));
      $method = 'extension.key';
      $confidence = self::EXPLICIT;
    }
    elseif ($documentType === 'extension_config.item') {
      $row = (array) ($data['item'] ?? []);
      $field = (string) ($data['identity_field'] ?? '');
      if ($field !== '' && array_key_exists($field, $row) && is_scalar($row[$field])) {
        $identity = $field . '=' . (string) $row[$field];
        $method = 'identity_field:' . $field;
        $declaredConfidence = (string) ($data['identity_confidence'] ?? '');
        $confidence = in_array($declaredConfidence, [self::API_VERIFIED, self::DISCOVERED_UNIQUE, self::AMBIGUOUS], TRUE)
          ? $declaredConfidence
          : $this->fieldConfidence($field);
      }
    }
    elseif (!empty($data['key_fields']) && array_key_exists('key', $data) && is_scalar($data['key'])) {
      $identity = 'key=' . (string) $data['key'];
      $method = 'declared_key_fields';
      $confidence = self::EXPLICIT;
    }
    elseif ($this->isCollectionDocument($documentType, $data)) {
      $identity = 'collection';
      $method = 'singleton_collection';
      $confidence = self::API_VERIFIED;
    }
    else {
      $row = isset($data['item']) && is_array($data['item']) ? (array) $data['item'] : $data;
      foreach ($this->strongFields() as $field) {
        if (array_key_exists($field, $row) && is_scalar($row[$field]) && (string) $row[$field] !== '') {
          $identity = $field . '=' . (string) $row[$field];
          $method = 'field:' . $field;
          $confidence = self::DISCOVERED_UNIQUE;
          break;
        }
      }
      if ($identity === '') {
        foreach (['title', 'label'] as $field) {
          if (array_key_exists($field, $row) && is_scalar($row[$field]) && (string) $row[$field] !== '') {
            $identity = $field . '=' . (string) $row[$field];
            $method = 'weak_field:' . $field;
            $confidence = self::AMBIGUOUS;
            break;
          }
        }
      }
    }

    if ($identity === '') {
      $identity = 'unresolved=' . ($filename !== '' ? $filename : $documentType);
      $method = 'unresolved_fallback';
      $confidence = self::AMBIGUOUS;
    }

    // Handlers may deliberately export a row for backup/monitor visibility
    // while declaring that its provider identity is not portable (for example,
    // duplicate CiviRules action names). Never let generic strong-field
    // discovery upgrade such a document back to write-safe.
    if ((array_key_exists('identity_portable', $data) && empty($data['identity_portable']))
      || (($data['identity_confidence'] ?? '') === self::AMBIGUOUS)) {
      $confidence = self::AMBIGUOUS;
      $method .= '+declared_ambiguous';
    }

    $configKey = $provider . '|' . $this->escapeIdentity($identity);

    return [
      'provider_key' => $provider,
      'config_key' => $configKey,
      'identity_hash' => hash('sha256', $configKey),
      'identity_method' => $method,
      'identity_confidence' => $confidence,
      'write_safe' => $this->isWriteSafeConfidence($confidence),
    ];
  }

  public function isWriteSafeConfidence(string $confidence): bool {
    return in_array($confidence, [self::EXPLICIT, self::API_VERIFIED, self::DISCOVERED_UNIQUE], TRUE);
  }

  private function providerKey(string $handlerType, array $data): string {
    $type = (string) ($data['type'] ?? $handlerType);
    if ($type === 'extension_config.item') {
      return implode(':', array_filter([
        'extension',
        (string) ($data['extension'] ?? ''),
        (string) ($data['api'] ?? ''),
        (string) ($data['entity'] ?? ''),
      ], 'strlen'));
    }
    if ($type === 'extension.item') {
      return 'extensions';
    }
    if (!empty($data['entity'])) {
      return $handlerType . ':api4:' . (string) $data['entity'];
    }
    return $handlerType . ':' . $type;
  }

  private function isCollectionDocument(string $documentType, array $data): bool {
    if (substr($documentType, -11) === '.collection') {
      return TRUE;
    }
    return isset($data['items']) && is_array($data['items']) && !isset($data['item']);
  }

  private function fieldConfidence(string $field): string {
    if (in_array(strtolower($field), $this->strongFields(), TRUE)) {
      return self::DISCOVERED_UNIQUE;
    }
    return self::AMBIGUOUS;
  }

  private function strongFields(): array {
    return ['key', 'machine_name', 'name', 'name_a_b', 'workflow_name'];
  }

  private function escapeIdentity(string $identity): string {
    return str_replace(['%', '|'], ['%25', '%7C'], $identity);
  }
}
