<?php
namespace Civi\ConfigManager\Handler;

interface ScopePickerHintProviderInterface {

  /**
   * Return optional UI hints keyed by exported filename.
   *
   * @param array $exported
   *   Files returned by the handler export.
   *
   * @return array
   *   Picker-only metadata. Nothing returned here is written to YAML.
   */
  public function getScopePickerHints(array $exported): array;
}
