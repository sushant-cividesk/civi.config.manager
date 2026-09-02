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
}
