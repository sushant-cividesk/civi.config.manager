<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Handler\CustomGroupHandler;
use Civi\ConfigManager\Handler\ExtensionHandler;
use Civi\ConfigManager\Handler\FinancialTypeHandler;
use Civi\ConfigManager\Handler\MessageTemplateHandler;
use Civi\ConfigManager\Handler\OptionGroupHandler;
use Civi\ConfigManager\Handler\PaymentProcessorHandler;
use Civi\ConfigManager\Handler\SettingHandler;
use Civi\ConfigManager\Handler\SiteTokenHandler;
use PHPUnit\Framework\TestCase;

final class FixedHandlerProviderMetadataTest extends TestCase {
  /**
   * Requirement: fixed handlers must describe their provider contract
   * explicitly; inventory must not disguise unknown metadata as complete.
   */
  public function testFixedHandlersDoNotFallBackToBasicOrPartialMetadata(): void {
    $handlers = [
      new ExtensionHandler(),
      new OptionGroupHandler(),
      new CustomGroupHandler(),
      new FinancialTypeHandler(),
      new PaymentProcessorHandler(),
      new MessageTemplateHandler(),
      new SettingHandler(),
      new SiteTokenHandler(),
    ];

    foreach ($handlers as $handler) {
      $metadata = $handler->getProviderMetadata();
      self::assertNotContains($metadata['metadata_completeness'] ?? '', ['basic', 'partial'], $handler->getType());
      self::assertNotEmpty($metadata['actions'] ?? [], $handler->getType());
      self::assertNotEmpty($metadata['identity_fields'] ?? [], $handler->getType());
      self::assertArrayHasKey('reference_fields', $metadata, $handler->getType());
      self::assertArrayHasKey('sensitive_fields', $metadata, $handler->getType());
      self::assertArrayHasKey('runtime_fields', $metadata, $handler->getType());
    }
  }
  /**
   * Requirement: provider metadata must not advertise write authority that
   * the concrete handler does not actually implement.
   */
  public function testCapabilityMetadataMatchesHandlerWriteSurface(): void {
    $payment = (new PaymentProcessorHandler())->getProviderMetadata();
    self::assertSame('export_only', $payment['management_capability'] ?? NULL);
    self::assertFalse((bool) ($payment['actions']['create'] ?? TRUE));
    self::assertFalse((bool) ($payment['actions']['update'] ?? TRUE));
    self::assertFalse((bool) ($payment['actions']['delete'] ?? TRUE));

    $extensions = (new ExtensionHandler())->getProviderMetadata();
    self::assertSame('mixed', $extensions['management_capability'] ?? NULL);
    self::assertFalse((bool) ($extensions['actions']['create'] ?? TRUE));
    self::assertTrue((bool) ($extensions['actions']['update'] ?? FALSE));
    self::assertFalse((bool) ($extensions['actions']['delete'] ?? TRUE));
  }

}
