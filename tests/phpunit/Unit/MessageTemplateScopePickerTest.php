<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Handler\MessageTemplateHandler;
use Civi\ConfigManager\Service\ConfigManager;
use Civi\ConfigManager\Service\HandlerRegistry;
use Civi\ConfigManager\Service\ConfigIdentity;
use Civi\ConfigManager\Tests\Support\TemporaryDirectoryTrait;
use PHPUnit\Framework\TestCase;

final class MessageTemplateScopePickerTest extends TestCase {
  use TemporaryDirectoryTrait;

  protected function setUp(): void {
    parent::setUp();
    \Civi::settings()->reset();
    $GLOBALS['civicrm_setting'] = [];
  }

  protected function tearDown(): void {
    \Civi::settings()->reset();
    $GLOBALS['civicrm_setting'] = [];
    $this->removeTemporaryDirectories();
    parent::tearDown();
  }

  public function testCustomizedWorkflowTemplateMatchesCoreRevertModel(): void {
    $handler = new MessageTemplateScopePickerFixture($this->fixtureFiles());
    $hints = $handler->getScopePickerHints($handler->export());

    self::assertArrayHasKey('system/contribution_invoice_default.yml', $hints);
    self::assertTrue($hints['system/contribution_invoice_default.yml']['recommended']);
    self::assertStringContainsString('CiviCRM shows Revert', $hints['system/contribution_invoice_default.yml']['recommendation']);
    self::assertArrayHasKey('system/contribution_invoice_custom.yml', $hints);
    self::assertTrue($hints['system/contribution_invoice_custom.yml']['reference']);
    self::assertStringContainsString('System reference', $hints['system/contribution_invoice_custom.yml']['recommendation']);
    self::assertArrayNotHasKey('system/event_confirmation_default.yml', $hints);
    self::assertArrayNotHasKey('user/newsletter.yml', $hints);
  }

  public function testScopePickerSurfacesCustomizedTemplateFirstWithoutChangingPortableSelector(): void {
    $root = $this->createTemporaryDirectory();
    \Civi::settings()->set('civicfg_sync_dir', $root);
    \Civi::settings()->set('civicfg_scope_default_mode', 'ignore');

    $handler = new MessageTemplateScopePickerFixture($this->fixtureFiles());
    $manager = new ConfigManager(new MessageTemplateScopePickerRegistry([$handler]));
    $picker = $manager->getScopePickerItems('message-templates');

    self::assertTrue($picker['available']);
    self::assertSame('ignore', $picker['policy']['mode']);
    self::assertSame('Contributions - Invoice', $picker['items'][0]['label']);
    self::assertTrue($picker['items'][0]['recommended']);
    $firstFile = $handler->export()[0];
    $expectedIdentity = (new ConfigIdentity())->identify('message-templates', (array) $firstFile['data'], (string) $firstFile['filename']);
    self::assertSame('key:' . $expectedIdentity['config_key'], $picker['items'][0]['selector']);
    self::assertStringContainsString('CiviCRM shows Revert', $picker['items'][0]['recommendation']);
    self::assertTrue($picker['items'][count($picker['items']) - 1]['reference']);
  }

  public function testPickerJavascriptOnlyAutoSelectsRecommendationsBeforeSelectedPolicyIsSaved(): void {
    $javascript = (string) file_get_contents(dirname(__DIR__, 3) . '/js/configmanager.js');

    self::assertStringContainsString("String(savedPolicy.mode || '') !== 'selected'", $javascript);
    self::assertStringContainsString('autoSelectRecommended && !!item.recommended', $javascript);
    self::assertStringContainsString('Customized in CiviCRM', $javascript);
    self::assertStringContainsString('System reference', $javascript);
    self::assertStringContainsString('Existing saved selections are kept', $javascript);
    self::assertStringContainsString('item.recommendation', $javascript);
  }

  private function fixtureFiles(): array {
    return [
      $this->workflowFile(10, 'contribution_invoice', 'Contributions - Invoice', TRUE, FALSE, 'Invoice subject', 'Invoice text', '<p>Customized invoice</p>'),
      $this->workflowFile(11, 'contribution_invoice', 'Contributions - Invoice', FALSE, TRUE, 'Invoice subject', 'Invoice text', '<p>Original invoice</p>'),
      $this->workflowFile(20, 'event_confirmation', 'Events - Registration Confirmation', TRUE, FALSE, 'Event subject', 'Event text', '<p>Event body</p>'),
      $this->workflowFile(21, 'event_confirmation', 'Events - Registration Confirmation', FALSE, TRUE, 'Event subject', 'Event text', '<p>Event body</p>'),
      [
        'filename' => 'user/newsletter.yml',
        'source_id' => 30,
        'data' => [
          'schema_version' => 1,
          'type' => 'message_template',
          'name' => 'Newsletter',
          'identity_key' => 'msg_title=Newsletter',
          'identity_confidence' => 'DISCOVERED_UNIQUE',
          'dependencies' => [],
          'template' => [
            'msg_title' => 'Newsletter',
            'msg_subject' => 'Newsletter',
            'msg_text' => 'Body',
            'msg_html' => '<p>Body</p>',
            'workflow_name' => NULL,
            'is_default' => FALSE,
            'is_reserved' => FALSE,
            'is_active' => TRUE,
          ],
        ],
      ],
    ];
  }

  private function workflowFile(int $id, string $workflow, string $title, bool $isDefault, bool $isReserved, string $subject, string $text, string $html): array {
    return [
      'filename' => 'system/' . $workflow . ($isDefault ? '_default' : '_custom') . '.yml',
      'source_id' => $id,
      'data' => [
        'schema_version' => 1,
        'type' => 'message_template',
        'name' => $workflow,
        'identity_key' => 'workflow_name=' . $workflow . '|is_default=' . ($isDefault ? '1' : '0'),
        'identity_confidence' => 'API_VERIFIED',
        'dependencies' => [],
        'template' => [
          'msg_title' => $title,
          'msg_subject' => $subject,
          'msg_text' => $text,
          'msg_html' => $html,
          'workflow_name' => $workflow,
          'is_default' => $isDefault,
          'is_reserved' => $isReserved,
          'is_active' => TRUE,
        ],
      ],
    ];
  }
}

final class MessageTemplateScopePickerFixture extends MessageTemplateHandler {
  private array $files;

  public function __construct(array $files) {
    $this->files = $files;
  }

  public function export(): array {
    return $this->files;
  }

  public function getRuntimeAvailability(): array {
    return [
      'available' => TRUE,
      'management_capability' => 'full',
      'reason' => '',
    ];
  }
}

final class MessageTemplateScopePickerRegistry extends HandlerRegistry {
  private array $handlers;

  public function __construct(array $handlers) {
    $this->handlers = $handlers;
  }

  public function getHandlers(): array {
    return $this->handlers;
  }
}
