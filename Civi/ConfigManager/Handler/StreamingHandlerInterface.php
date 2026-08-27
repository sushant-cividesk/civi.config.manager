<?php
namespace Civi\ConfigManager\Handler;

/**
 * Optional low-memory export contract.
 *
 * Existing third-party handlers may continue implementing HandlerInterface.
 * Core/high-volume handlers implement this interface so Configuration Manager
 * can consume one export document at a time instead of materializing a full
 * site collection in PHP memory.
 */
interface StreamingHandlerInterface {

  /**
   * @return iterable<int,array{filename:string,data:array,source_id?:int|null}>
   */
  public function iterateExport(): iterable;
}
