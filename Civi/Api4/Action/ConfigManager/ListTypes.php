<?php
namespace Civi\Api4\Action\ConfigManager;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\ConfigManager\Service\ConfigManager;

class ListTypes extends AbstractAction {

  public function _run(Result $result) {
    $items = [];
    foreach ((new ConfigManager())->getManagedTypeOptions() as $row) {
      $items[] = [
        'type' => (string) $row['type'],
        'base_type' => (string) ($row['base_type'] ?? $row['type']),
        'label' => (string) $row['label'],
        'directory' => (string) ($row['directory'] ?? ''),
        'weight' => (int) ($row['weight'] ?? 0),
        'virtual' => !empty($row['virtual']),
      ];
    }
    $result[] = ['ok' => TRUE, 'types' => $items];
  }

}
