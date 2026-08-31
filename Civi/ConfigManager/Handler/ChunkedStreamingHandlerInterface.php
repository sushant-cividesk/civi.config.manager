<?php
namespace Civi\ConfigManager\Handler;

/**
 * Optional persistent-queue export contract.
 *
 * Each unit is independently stage-safe: it reads active configuration and
 * writes only to the temporary export workspace. A failed/retried unit must not
 * mutate active CiviCRM or live YAML.
 */
interface ChunkedStreamingHandlerInterface extends StreamingHandlerInterface {

  /**
   * @return array<int,array{key:string,label:string,path_prefix?:string}>
   */
  public function getExportUnits(): array;

  /**
   * @return iterable<int,array{filename:string,data:array,source_id?:int|null}>
   */
  public function iterateExportUnit(string $unitKey, ?callable $progress = NULL): iterable;
}
