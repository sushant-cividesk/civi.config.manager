<?php
namespace Civi\ConfigManager\Service;

use Civi\ConfigManager\Handler\ExtensionHandler;
use Civi\ConfigManager\Handler\OptionGroupHandler;
use Civi\ConfigManager\Handler\CustomGroupHandler;
use Civi\ConfigManager\Handler\FinancialTypeHandler;
use Civi\ConfigManager\Handler\PaymentProcessorHandler;
use Civi\ConfigManager\Handler\MessageTemplateHandler;
use Civi\ConfigManager\Handler\SettingHandler;
use Civi\ConfigManager\Handler\SiteTokenHandler;
use Civi\ConfigManager\Handler\CiviRulesHandler;
use Civi\ConfigManager\Handler\GenericApi4CollectionHandler;
use Civi\ConfigManager\Handler\EntityDefinitionHandler;

class HandlerRegistry {
  public function getHandlers(): array {
    $handlers = [
      new ExtensionHandler(),
      new OptionGroupHandler(),
      new GenericApi4CollectionHandler('contact-types', 'Contact Types', 'contact-types', 'ContactType', ['name', 'label', 'parent_id', 'is_active', 'is_reserved', 'description'], ['name' => 'ASC'], 30, 'contact-types.yml'),
      new GenericApi4CollectionHandler('relationship-types', 'Relationship Types', 'relationship-types', 'RelationshipType', ['name_a_b', 'label_a_b', 'name_b_a', 'label_b_a', 'contact_type_a', 'contact_type_b', 'contact_sub_type_a', 'contact_sub_type_b', 'is_active', 'is_reserved'], ['name_a_b' => 'ASC'], 31, 'relationship-types.yml'),
      new GenericApi4CollectionHandler('location-types', 'Location Types', 'location-types', 'LocationType', ['name', 'display_name', 'vcard_name', 'description', 'is_reserved', 'is_active', 'is_default'], ['name' => 'ASC'], 32, 'location-types.yml'),
      new FinancialTypeHandler(),
      new PaymentProcessorHandler(),
      new CustomGroupHandler(),
      new SettingHandler(),
      new SiteTokenHandler(),
      new MessageTemplateHandler(),
      new GenericApi4CollectionHandler('dedupe-rules', 'Dedupe Rules', 'dedupe-rules', 'DedupeRuleGroup', ['name', 'title', 'contact_type', 'threshold', 'used', 'is_reserved', 'is_active'], ['name' => 'ASC'], 100, 'dedupe-rules.yml'),
      new GenericApi4CollectionHandler('scheduled-jobs', 'Scheduled Jobs', 'scheduled-jobs', 'Job', ['name', 'description', 'api_entity', 'api_action', 'parameters', 'run_frequency', 'scheduled_run_date', 'is_active'], ['name' => 'ASC'], 110, 'jobs.yml', TRUE),
      new GenericApi4CollectionHandler('searchkit-saved-searches', 'SearchKit Saved Searches', 'searchkit/saved-searches', 'SavedSearch', ['name', 'label', 'api_entity', 'api_params', 'description', 'mapping_id', 'is_template', 'is_active'], ['name' => 'ASC'], 120, 'saved-searches.yml', TRUE),
      new GenericApi4CollectionHandler('searchkit-displays', 'SearchKit Displays', 'searchkit/displays', 'SearchDisplay', ['name', 'label', 'saved_search_id', 'saved_search_id.name', 'type', 'settings', 'acl_bypass', 'is_active'], ['name' => 'ASC'], 130, 'displays.yml', TRUE),
      new GenericApi4CollectionHandler('formbuilder-afforms', 'FormBuilder Afforms', 'formbuilder/afforms', 'Afform', ['name', 'title', 'type', 'server_route', 'permission', 'permission_operator', 'is_public', 'is_token', 'is_dashlet', 'is_active', 'layout'], ['name' => 'ASC'], 140, 'afforms.yml', TRUE),
      new CiviRulesHandler(),
    ];

    $definitions = [];
    $dummy = NULL;
    \CRM_Utils_Hook::singleton()->invoke(['definitions'], $definitions, $dummy, $dummy, $dummy, $dummy, $dummy, 'civicfg_entityDefinitions');
    foreach ($this->buildHandlersFromDefinitions((array) $definitions) as $handler) {
      $handlers[] = $handler;
    }

    \CRM_Utils_Hook::singleton()->invoke(['handlers'], $handlers, $dummy, $dummy, $dummy, $dummy, $dummy, 'civicfg_configTypes');

    usort($handlers, fn($a, $b) => $a->getWeight() <=> $b->getWeight());
    return $handlers;
  }

  /**
   * Convert metadata supplied by civicfg_entityDefinitions() into handlers.
   *
   * The preferred extension integration path is metadata only. The older
   * civicfg_configTypes() hook remains available for advanced custom handlers.
   */
  private function buildHandlersFromDefinitions(array $definitions): array {
    $handlers = [];
    foreach ($definitions as $type => $definition) {
      if (!is_string($type) || !is_array($definition)) {
        continue;
      }
      $handlers[] = new EntityDefinitionHandler($type, $definition);
    }
    return $handlers;
  }
}
