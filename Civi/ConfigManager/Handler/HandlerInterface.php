<?php
namespace Civi\ConfigManager\Handler;

interface HandlerInterface {

  public function getType(): string;

  public function getLabel(): string;

  public function getDirectory(): string;

  public function export(): array;

  public function import(array $items, bool $dryRun = TRUE): array;

  public function diff(array $items): array;

  public function validate(array $items): array;

  public function getWeight(): int;

}
