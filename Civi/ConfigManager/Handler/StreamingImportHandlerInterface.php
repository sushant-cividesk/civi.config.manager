<?php
namespace Civi\ConfigManager\Handler;

/**
 * Optional bounded-memory import contract.
 *
 * The iterable yields filename => parsed YAML one document at a time. Handlers
 * may retain compact identity sets, but must not retain the full document set.
 */
interface StreamingImportHandlerInterface {
  /**
   * @param iterable<string,array<string,mixed>> $items
   * @return array<string,mixed>
   */
  public function importIterable(iterable $items, bool $dryRun = TRUE): array;
}
