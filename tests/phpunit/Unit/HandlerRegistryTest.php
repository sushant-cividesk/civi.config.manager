<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Handler\AbstractHandler;
use Civi\ConfigManager\Handler\EntityDefinitionHandler;
use Civi\ConfigManager\Service\HandlerRegistry;
use PHPUnit\Framework\TestCase;

final class HandlerRegistryTest extends TestCase {
  protected function tearDown(): void {
    \CRM_Utils_Hook::resetCallbacks();
  }

  public function testBaseHandlerTypesAreUniqueAndWeightOrdered(): void {
    $handlers = (new HandlerRegistry())->getHandlers();
    $types = array_map(static fn($handler): string => $handler->getType(), $handlers);
    $weights = array_map(static fn($handler): int => $handler->getWeight(), $handlers);

    self::assertSame($types, array_values(array_unique($types)));
    self::assertSame($weights, array_values($weights));

    $sorted = $weights;
    sort($sorted);
    self::assertSame($sorted, $weights);
    self::assertContains('option-groups', $types);
    self::assertContains('message-templates', $types);
    self::assertContains('searchkit-saved-searches', $types);
    self::assertContains('formbuilder-afforms', $types);
  }

  public function testEntityDefinitionHookRegistersCustomDevelopmentHandler(): void {
    \CRM_Utils_Hook::setCallback('civicfg_entityDefinitions', static function (array &$definitions): void {
      $definitions['custom_widget_config'] = [
        'label' => 'Custom Widget Config',
        'api_version' => 4,
        'entity' => 'CivicfgHookFixture',
        'path' => 'extensions/custom-widget/config',
        'key_fields' => ['name'],
        'export_fields' => ['name', 'label'],
        'weight' => 515,
      ];
    });

    $handlers = (new HandlerRegistry())->getHandlers();
    $matches = array_values(array_filter($handlers, static fn($handler): bool => $handler->getType() === 'custom_widget_config'));

    self::assertCount(1, $matches);
    self::assertInstanceOf(EntityDefinitionHandler::class, $matches[0]);
    self::assertSame('Custom Widget Config', $matches[0]->getLabel());
    self::assertSame('extensions/custom-widget/config', $matches[0]->getDirectory());
  }

  public function testAdvancedConfigTypesHookRegistersCustomHandler(): void {
    \CRM_Utils_Hook::setCallback('civicfg_configTypes', static function (array &$handlers): void {
      $handlers[] = new class extends AbstractHandler {
        public function getType(): string {
          return 'custom_private_config';
        }

        public function getLabel(): string {
          return 'Custom Private Config';
        }

        public function getDirectory(): string {
          return 'extensions/custom-private';
        }

        public function getWeight(): int {
          return 516;
        }

        public function export(): array {
          return [];
        }
      };
    });

    $handlers = (new HandlerRegistry())->getHandlers();
    $matches = array_values(array_filter($handlers, static fn($handler): bool => $handler->getType() === 'custom_private_config'));

    self::assertCount(1, $matches);
    self::assertSame('Custom Private Config', $matches[0]->getLabel());
    self::assertSame('extensions/custom-private', $matches[0]->getDirectory());
  }

  public function testRegistrationSourcesIdentifyCoreAndBothPublicHooks(): void {
    \CRM_Utils_Hook::setCallback('civicfg_entityDefinitions', static function (array &$definitions): void {
      $definitions['custom_inventory_definition'] = [
        'entity' => 'CivicfgInventoryDefinition',
        'key_fields' => ['name'],
      ];
    });
    \CRM_Utils_Hook::setCallback('civicfg_configTypes', static function (array &$handlers): void {
      $handlers[] = new class extends AbstractHandler {
        public function getType(): string { return 'custom_inventory_handler'; }
        public function getLabel(): string { return 'Custom inventory handler'; }
        public function getDirectory(): string { return 'extensions/custom-inventory'; }
        public function getWeight(): int { return 517; }
        public function export(): array { return []; }
      };
    });

    $byType = [];
    foreach ((new HandlerRegistry())->getHandlerRegistrations() as $registration) {
      $byType[$registration['handler']->getType()] = $registration['registration_source'];
    }

    self::assertSame('core_handler', $byType['option-groups']);
    self::assertSame('entity_definition_hook', $byType['custom_inventory_definition']);
    self::assertSame('config_types_hook', $byType['custom_inventory_handler']);
  }

  /**
   * Requirement: a contributed/custom hook must not shadow an already
   * registered configuration type. The original provider remains active and
   * the collision is reported as unavailable metadata instead of creating an
   * ambiguous handler order.
   */
  public function testDuplicateHookTypeIsRejectedWithoutReplacingCoreHandler(): void {
    \CRM_Utils_Hook::setCallback('civicfg_entityDefinitions', static function (array &$definitions): void {
      $definitions['option-groups'] = [
        'provider' => 'example.collision',
        'entity' => 'OptionGroup',
        'key_fields' => ['name'],
        'export_fields' => ['name', 'title'],
      ];
    });

    $registry = new HandlerRegistry();
    $registrations = $registry->getHandlerRegistrations();
    $matches = array_values(array_filter($registrations, static function(array $registration): bool {
      return $registration['handler']->getType() === 'option-groups';
    }));

    self::assertCount(1, $matches);
    self::assertSame('core_handler', $matches[0]['registration_source']);
    $diagnostics = $registry->getRegistrationDiagnostics();
    self::assertCount(1, $diagnostics);
    self::assertSame([
      'type' => 'option-groups',
      'registration_source' => 'entity_definition_hook',
      'reason_code' => 'duplicate_handler_type',
      'reason' => 'Configuration type "option-groups" is already registered by core_handler; the later entity_definition_hook registration was rejected.',
    ], $diagnostics[0]);
  }

  /** Requirement: malformed advanced-hook values cannot break unrelated providers. */
  public function testInvalidAdvancedHookRegistrationIsRejectedWithoutBreakingRegistry(): void {
    \CRM_Utils_Hook::setCallback('civicfg_configTypes', static function (array &$handlers): void {
      $handlers[] = 'not-a-handler';
    });

    $registry = new HandlerRegistry();
    $registrations = $registry->getHandlerRegistrations();
    $types = array_map(static fn(array $registration): string => $registration['handler']->getType(), $registrations);

    self::assertContains('option-groups', $types);
    self::assertSame('invalid_handler_registration', $registry->getRegistrationDiagnostics()[0]['reason_code']);
  }

  /** Requirement: malformed metadata-hook definitions are visible and do not break core providers. */
  public function testMalformedEntityDefinitionIsRejectedWithDiagnostic(): void {
    \CRM_Utils_Hook::setCallback('civicfg_entityDefinitions', static function (array &$definitions): void {
      $definitions['broken_definition'] = 'not-an-array';
    });

    $registry = new HandlerRegistry();
    $registrations = $registry->getHandlerRegistrations();
    $types = array_map(static fn(array $registration): string => $registration['handler']->getType(), $registrations);

    self::assertContains('option-groups', $types);
    self::assertNotContains('broken_definition', $types);
    $diagnostics = $registry->getRegistrationDiagnostics();
    self::assertCount(1, $diagnostics);
    self::assertSame([
      'type' => 'broken_definition',
      'registration_source' => 'entity_definition_hook',
      'reason_code' => 'invalid_entity_definition',
      'reason' => 'A civicfg_entityDefinitions hook must register a non-empty string type with an array definition.',
    ], $diagnostics[0]);
  }
}
