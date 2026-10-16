<?php
namespace Civi\ConfigManager\Handler;

// Include stateful concerns for direct CiviCRM/legacy bootstrap loaders too.
require_once __DIR__ . '/ExtensionProviderCollectionTrait.php';
require_once __DIR__ . '/ExtensionProviderDiscoveryTrait.php';
require_once __DIR__ . '/ExtensionProviderExportTrait.php';
require_once __DIR__ . '/ExtensionProviderFiltersTrait.php';
require_once __DIR__ . '/ExtensionProviderIdentityAccessTrait.php';
require_once __DIR__ . '/ExtensionProviderImportSafetyTrait.php';
require_once __DIR__ . '/ExtensionProviderImportTrait.php';
require_once __DIR__ . '/ExtensionProviderInventoryTrait.php';
require_once __DIR__ . '/ExtensionProviderMetadataTrait.php';
require_once __DIR__ . '/ExtensionProviderSettingsTrait.php';
require_once __DIR__ . '/ExtensionProviderUtilityTrait.php';

class ExtensionHandler extends AbstractHandler implements StreamingHandlerInterface, StreamingImportHandlerInterface, ChunkedStreamingHandlerInterface {
  use ExtensionProviderCollectionTrait;
  use ExtensionProviderDiscoveryTrait;
  use ExtensionProviderExportTrait;
  use ExtensionProviderFiltersTrait;
  use ExtensionProviderIdentityAccessTrait;
  use ExtensionProviderImportSafetyTrait;
  use ExtensionProviderImportTrait;
  use ExtensionProviderInventoryTrait;
  use ExtensionProviderMetadataTrait;
  use ExtensionProviderSettingsTrait;
  use ExtensionProviderUtilityTrait;


  /**
   * @var ExtensionProviderIdentity|null
   */
  private ?ExtensionProviderIdentity $providerIdentityRules = NULL;
  /**
   * @var bool
   */
  private bool $importWritesEnabled = TRUE;
  /**
   * @var bool
   */
  private bool $deleteMissingEnabled = TRUE;
  /**
   * @var array|null
   */
  private ?array $discoveredEntityDefinitions = NULL;
  /**
   * @var array|null
   */
  private ?array $inventoryEntityDefinitions = NULL;
  /**
   * @var array
   */
  private array $runtimeTypeFilters = [];
  /**
   * @var array
   */
  private array $identityRowsByDefinition = [];
  /**
   * @var array
   */
  private static array $api3ActionsByEntity = [];
  /**
   * @var array
   */
  private static array $api3ListActionByEntity = [];
  /**
   * @var array
   */
  private static array $api3WritableFieldsByEntity = [];
  /**
   * @var array
   */
  private static array $api3DeleteActionByEntity = [];
  /**
   * @var array
   */
  private array $exportErrors = [];

  private function providerIdentityRules(): ExtensionProviderIdentity {
    if ($this->providerIdentityRules === NULL) {
      $this->providerIdentityRules = new ExtensionProviderIdentity();
    }
    return $this->providerIdentityRules;
  }

  public function getType(): string {
    return 'extensions';
  }

  public function getLabel(): string {
    return 'Extensions';
  }

  public function getDirectory(): string {
    return 'extensions';
  }

  public function getWeight(): int {
    return 10;
  }

  public function getProviderMetadata(): array {
    return [
      'owner' => 'civi.config.manager',
      'api_version' => 'handler',
      'entity' => 'ExtensionStatusAndSettings',
      'actions' => ['read' => TRUE, 'create' => FALSE, 'update' => TRUE, 'delete' => FALSE],
      'field_names' => ['extension', 'status', 'settings'],
      'identity_fields' => ['extension'],
      'reference_fields' => [],
      'sensitive_fields' => ['settings.*secret', 'settings.*token', 'settings.*key'],
      'runtime_fields' => [],
      'management_capability' => 'mixed',
      'identity_evidence' => 'handler_policy',
      'metadata_completeness' => 'declared',
    ];
  }

  public function setImportWriteEnabled(bool $enabled): self {
    $this->importWritesEnabled = $enabled;
    return $this;
  }

  public function setDeleteMissingEnabled(bool $enabled): self {
    $this->deleteMissingEnabled = $enabled;
    return $this;
  }

  public function setRuntimeTypeFilters(array $filters): self {
    $this->runtimeTypeFilters = array_values(array_unique(array_filter(array_map('strval', $filters))));
    return $this;
  }

}
