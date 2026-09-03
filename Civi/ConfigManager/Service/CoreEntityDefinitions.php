<?php
namespace Civi\ConfigManager\Service;

/**
 * Reviewed metadata definitions for CiviCRM configuration entities.
 *
 * These definitions deliberately use the same metadata-driven handler used by
 * contributed/custom providers. Keeping the policy declarative avoids adding
 * family-specific branches to ConfigManager or the Settings UI.
 */
final class CoreEntityDefinitions {

  /**
   * @return array<string,array<string,mixed>>
   */
  public static function tag(): array {
    return self::definitions()['tags'];
  }

  /**
   * Definitions which can use the generic metadata-driven importer directly.
   *
   * @return array<string,array<string,mixed>>
   */
  public static function metadataDriven(): array {
    $definitions = self::definitions();
    unset($definitions['tags']);
    return $definitions;
  }

  /**
   * @return array<string,array<string,mixed>>
   */
  private static function definitions(): array {
    return [
      'tags' => [
        'provider' => 'civicrm-core',
        'label' => 'Tags',
        'api_version' => 4,
        'entity' => 'Tag',
        'path' => 'tags',
        'key_fields' => ['name'],
        'export_fields' => [
          'name', 'label', 'description', 'parent_id', 'is_selectable',
          'is_reserved', 'is_tagset', 'used_for', 'color',
        ],
        'runtime_fields' => ['created_id', 'created_date'],
        'reference_fields' => [
          'parent_id' => [
            'entity' => 'Tag',
            'id_field' => 'id',
            'key_fields' => ['name'],
            'dependency_type' => 'tags',
          ],
        ],
        'order_by' => ['name' => 'ASC'],
        'split_files' => TRUE,
        'can_create' => TRUE,
        'can_update' => TRUE,
        // Tag deletion can remove assignments to business records. Until a
        // real-runtime preservation proof exists, YAML must never imply it.
        'can_delete' => FALSE,
        'delete_missing' => FALSE,
        'weight' => 33,
      ],
      'profiles' => [
        'provider' => 'civicrm-core',
        'label' => 'Profiles',
        'api_version' => 4,
        'entity' => 'UFGroup',
        'path' => 'profiles/groups',
        'key_fields' => ['name'],
        'export_fields' => [
          'name', 'is_active', 'group_type', 'title', 'frontend_title',
          'description', 'help_pre', 'help_post', 'limit_listings_group_id',
          'post_url', 'add_to_group_id', 'add_captcha', 'is_map',
          'is_edit_link', 'is_uf_link', 'is_update_dupe', 'cancel_url',
          'is_cms_user', 'notify', 'is_reserved', 'is_proximity_search',
          'cancel_button_text', 'submit_button_text', 'add_cancel_button',
        ],
        'runtime_fields' => ['created_id', 'created_date'],
        'reference_fields' => [
          'limit_listings_group_id' => [
            'entity' => 'Group',
            'id_field' => 'id',
            'key_fields' => ['name'],
            'dependency_type' => 'groups',
          ],
          'add_to_group_id' => [
            'entity' => 'Group',
            'id_field' => 'id',
            'key_fields' => ['name'],
            'dependency_type' => 'groups',
          ],
        ],
        'order_by' => ['name' => 'ASC'],
        'split_files' => TRUE,
        'can_create' => TRUE,
        'can_update' => TRUE,
        'can_delete' => FALSE,
        'delete_missing' => FALSE,
        'weight' => 40,
      ],
      'profile-fields' => [
        'provider' => 'civicrm-core',
        'label' => 'Profile Fields',
        'api_version' => 4,
        'entity' => 'UFField',
        'path' => 'profiles/fields',
        // field_name is only unique within a profile. The joined machine name
        // keeps matching portable when local UFGroup IDs differ by site.
        'key_fields' => ['uf_group_id.name', 'field_name'],
        'export_fields' => [
          'uf_group_id', 'uf_group_id.name', 'field_name', 'is_active',
          'is_view', 'is_required', 'weight', 'help_post', 'help_pre',
          'visibility', 'in_selector', 'is_searchable', 'location_type_id',
          'label', 'field_type', 'is_reserved', 'is_multi_summary',
        ],
        'reference_fields' => [
          'uf_group_id' => [
            'entity' => 'UFGroup',
            'id_field' => 'id',
            'key_fields' => ['name'],
            'dependency_type' => 'profiles',
          ],
          'location_type_id' => [
            'entity' => 'LocationType',
            'id_field' => 'id',
            'key_fields' => ['name'],
            'dependency_type' => 'location-types',
          ],
        ],
        'order_by' => ['uf_group_id.name' => 'ASC', 'weight' => 'ASC', 'field_name' => 'ASC'],
        'split_files' => TRUE,
        'can_create' => TRUE,
        'can_update' => TRUE,
        'can_delete' => FALSE,
        'delete_missing' => FALSE,
        'weight' => 41,
      ],
    ];
  }
}
