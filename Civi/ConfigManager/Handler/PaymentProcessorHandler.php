<?php
namespace Civi\ConfigManager\Handler;

class PaymentProcessorHandler extends AbstractHandler {
  public function getType(): string { return 'payment-processors'; }
  public function getLabel(): string { return 'Payment Processors'; }
  public function getDirectory(): string { return 'payment-processors'; }
  public function getWeight(): int { return 50; }

  public function getRuntimeAvailability(): array {
    if (class_exists('Civi\\Api4\\PaymentProcessor')) {
      return ['available' => TRUE, 'reason' => ''];
    }
    return [
      'available' => FALSE,
      'reason' => 'PaymentProcessor API4 is unavailable. Install/enable CiviContribute before managing payment processors.',
    ];
  }

  public function export(): array {
    $rows = $this->api4Get('PaymentProcessor', [], ['id', 'name', 'title', 'description', 'payment_processor_type_id', 'is_active', 'is_default', 'is_test', 'user_name', 'url_site', 'url_api', 'url_recur', 'url_button', 'class_name', 'billing_mode', 'financial_account_id', 'payment_instrument_id'], ['name' => 'ASC']);
    $files = [];
    foreach ($rows as $row) {
      $row = (array) $row;
      $sourceId = isset($row['id']) && is_scalar($row['id']) ? (int) $row['id'] : NULL;
      unset($row['id']);
      foreach (['password', 'signature', 'subject'] as $secret) {
        if (array_key_exists($secret, $row)) {
          unset($row[$secret]);
        }
      }
      $row['secrets_exported'] = FALSE;
      $name = trim((string) ($row['name'] ?? ''));
      if ($name === '') {
        continue;
      }
      $files[] = [
        'filename' => $this->safeName($name) . '.yml',
        'source_id' => $sourceId,
        'data' => [
          'schema_version' => 1,
          'type' => 'payment_processor.item',
          'entity' => 'PaymentProcessor',
          'name' => $name,
          'identity_field' => 'name',
          'identity_confidence' => 'DISCOVERED_UNIQUE',
          'dependencies' => [
            'option_groups' => ['payment_instrument'],
            'financial_types' => [],
          ],
          'item' => $row,
        ],
      ];
    }
    return $files;
  }

  private function safeName(string $name): string {
    $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name);
    return trim((string) $safe, '-') ?: sha1($name);
  }
}
