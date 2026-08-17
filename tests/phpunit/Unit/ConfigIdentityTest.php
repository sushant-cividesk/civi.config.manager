<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Service\ConfigIdentity;
use PHPUnit\Framework\TestCase;

final class ConfigIdentityTest extends TestCase {
  public function testDeclaredCompositeKeyIsStableAcrossDatabaseIdsAndFilenames(): void {
    $service = new ConfigIdentity();
    $first = $service->identify('myext_templates', [
      'type' => 'myext_templates.item',
      'entity' => 'Template',
      'key_fields' => ['category', 'name'],
      'key' => 'category=member|name=welcome',
      'item' => ['id' => 7, 'category' => 'member', 'name' => 'welcome'],
    ], 'old-name.yml');
    $second = $service->identify('myext_templates', [
      'type' => 'myext_templates.item',
      'entity' => 'Template',
      'key_fields' => ['category', 'name'],
      'key' => 'category=member|name=welcome',
      'item' => ['id' => 99, 'category' => 'member', 'name' => 'welcome'],
    ], 'renamed.yml');

    self::assertSame($first['config_key'], $second['config_key']);
    self::assertSame($first['identity_hash'], $second['identity_hash']);
    self::assertSame(ConfigIdentity::EXPLICIT, $first['identity_confidence']);
    self::assertTrue($first['write_safe']);
    self::assertSame(64, strlen($first['identity_hash']));
  }

  public function testExtensionConfigStrongIdentityIsWriteSafe(): void {
    $identity = (new ConfigIdentity())->identify('extensions', [
      'type' => 'extension_config.item',
      'extension' => 'de.systopia.sqltasks',
      'api' => 'api3',
      'entity' => 'Sqltask',
      'identity_field' => 'name',
      'item' => ['id' => 4, 'name' => 'monthly_report', 'label' => 'Monthly report'],
    ], 'monthly_report.yml');

    self::assertSame('extension:de.systopia.sqltasks:api3:Sqltask|name=monthly_report', $identity['config_key']);
    self::assertSame(ConfigIdentity::DISCOVERED_UNIQUE, $identity['identity_confidence']);
    self::assertTrue($identity['write_safe']);
  }

  public function testWeakLabelIdentityIsVisibleButNotWriteSafe(): void {
    $identity = (new ConfigIdentity())->identify('extensions', [
      'type' => 'extension_config.item',
      'extension' => 'example.ext',
      'api' => 'api3',
      'entity' => 'Thing',
      'identity_field' => 'label',
      'item' => ['label' => 'Default'],
    ], 'default.yml');

    self::assertSame(ConfigIdentity::AMBIGUOUS, $identity['identity_confidence']);
    self::assertFalse($identity['write_safe']);
  }

  public function testSystemMessageTemplateUsesWorkflowAndDefaultVariantIdentity(): void {
    $identity = (new ConfigIdentity())->identify('message-templates', [
      'type' => 'message_template',
      'template' => [
        'workflow_name' => 'contribution_online_receipt',
        'msg_title' => 'Contribution Receipt',
        'is_default' => FALSE,
      ],
    ], 'system/contribution_online_receipt_custom.yml');

    self::assertStringContainsString('workflow_name=contribution_online_receipt', $identity['config_key']);
    self::assertStringContainsString('is_default=0', $identity['config_key']);
    self::assertSame(ConfigIdentity::API_VERIFIED, $identity['identity_confidence']);
    self::assertTrue($identity['write_safe']);
  }

  public function testAmbiguousUserMessageTemplateTitleIsNotWriteSafe(): void {
    $identity = (new ConfigIdentity())->identify('message-templates', [
      'type' => 'message_template',
      'identity_key' => 'msg_title=Reminder',
      'identity_confidence' => ConfigIdentity::AMBIGUOUS,
      'template' => ['msg_title' => 'Reminder'],
    ], 'user/reminder.yml');

    self::assertSame(ConfigIdentity::AMBIGUOUS, $identity['identity_confidence']);
    self::assertFalse($identity['write_safe']);
  }

  public function testUnresolvedIdentityUsesFilenameOnlyAsAmbiguousReadFallback(): void {
    $identity = (new ConfigIdentity())->identify('example', ['value' => 123], 'one.yml');

    self::assertSame(ConfigIdentity::AMBIGUOUS, $identity['identity_confidence']);
    self::assertSame('unresolved_fallback', $identity['identity_method']);
    self::assertFalse($identity['write_safe']);
    self::assertStringContainsString('one.yml', $identity['config_key']);
  }
}
