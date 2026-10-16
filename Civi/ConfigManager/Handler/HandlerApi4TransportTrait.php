<?php
namespace Civi\ConfigManager\Handler;


/**
 * HandlerApi4Transport methods from AbstractHandler.
 *
 * Methods run on the original owning object and retain the same state and
 * access modifiers. Do not instantiate this trait separately.
 */
trait HandlerApi4TransportTrait {

  /**
   * Describe the API4 action surface needed for safe managed CRUD.
   *
   * This is intentionally based on callable API actions rather than CiviCRM
   * version numbers. Contributed/core entities vary by installation, so a
   * handler is only advertised as full-management when the actions it uses are
   * actually present at runtime.
   *
   * @return array{available:bool,management_capability:string,reason:string,missing_actions:array<int,string>}
   */
  protected function api4ManagementAvailability(string $entity, array $requiredActions = ['get', 'create', 'update', 'delete']): array {
    $class = 'Civi\\Api4\\' . $entity;
    if (!class_exists($class)) {
      return [
        'available' => FALSE,
        'management_capability' => 'unavailable',
        'reason' => 'API4 entity ' . $entity . ' is not available on this site.',
        'missing_actions' => array_values($requiredActions),
      ];
    }

    $missing = [];
    foreach ($requiredActions as $action) {
      if (!is_callable([$class, (string) $action])) {
        $missing[] = (string) $action;
      }
    }

    if (in_array('get', $missing, TRUE)) {
      return [
        'available' => FALSE,
        'management_capability' => 'unavailable',
        'reason' => 'API4 entity ' . $entity . ' cannot be read on this site. Missing action(s): ' . implode(', ', $missing) . '.',
        'missing_actions' => $missing,
      ];
    }

    if ($missing) {
      return [
        'available' => TRUE,
        'management_capability' => 'export_only',
        'reason' => 'API4 entity ' . $entity . ' is readable but does not expose every action required for managed restore/import. Missing action(s): ' . implode(', ', $missing) . '.',
        'missing_actions' => $missing,
      ];
    }

    return [
      'available' => TRUE,
      'management_capability' => 'full',
      'reason' => '',
      'missing_actions' => [],
    ];
  }

  /**
   * Combine multiple API4 capability checks for one composite handler.
   */
  protected function combineApi4ManagementAvailability(array $checks, string $label): array {
    $reasons = [];
    $capability = 'full';
    foreach ($checks as $check) {
      $check = (array) $check;
      if (empty($check['available'])) {
        $capability = 'unavailable';
      }
      elseif (($check['management_capability'] ?? 'full') !== 'full' && $capability !== 'unavailable') {
        $capability = 'export_only';
      }
      $reason = trim((string) ($check['reason'] ?? ''));
      if ($reason !== '') {
        $reasons[] = $reason;
      }
    }

    return [
      'available' => $capability !== 'unavailable',
      'management_capability' => $capability,
      'reason' => $reasons ? ($label . ': ' . implode(' ', $reasons)) : '',
    ];
  }

  /**
   * Iterate an API4 collection in bounded pages without retaining prior pages.
   *
   * This is the primitive large-site handlers should use. api4Get() remains a
   * compatibility helper for legacy/small code paths and intentionally builds
   * an array from this iterator.
   *
   * @return \Generator<int,array<string,mixed>>
   */
  protected function api4Iterate(string $entity, array $where = [], array $select = ['*'], array $orderBy = []): \Generator {
    // Preserve the pre-alpha62 protected extension seam: a subclass may
    // override api4Get() to provide custom/test provider semantics. Route that
    // explicit override through the generator once, while guarding parent-call
    // recursion. Built-in handlers continue on the true streaming path.
    $api4GetMethod = new \ReflectionMethod($this, 'api4Get');
    if (!$this->api4GetOverrideBridgeActive && $api4GetMethod->getDeclaringClass()->getName() !== self::class) {
      $this->api4GetOverrideBridgeActive = TRUE;
      try {
        foreach ($this->api4Get($entity, $where, $select, $orderBy) as $row) {
          yield (array) $row;
        }
      }
      finally {
        $this->api4GetOverrideBridgeActive = FALSE;
      }
      return;
    }

    $class = 'Civi\\Api4\\' . $entity;
    if (!class_exists($class)) {
      throw new \RuntimeException('API4 entity not available: ' . $entity . '. Install/enable the provider before managing this configuration type.');
    }

    $offset = 0;
    $batchSize = 200;
    $seenPages = [];
    $orderFields = array_keys($orderBy);
    $firstOrderField = isset($orderFields[0]) ? (string) $orderFields[0] : '';
    $idSelected = in_array('*', $select, TRUE) || in_array('id', $select, TRUE);
    $useIdCursor = $firstOrderField === 'id'
      && strtoupper((string) ($orderBy['id'] ?? '')) === 'ASC'
      && $idSelected;
    $lastId = NULL;

    while (TRUE) {
      $action = $class::get(FALSE)->addSelect(...$select);
      foreach ($where as $condition) {
        $action->addWhere(...$condition);
      }
      if ($useIdCursor && $lastId !== NULL) {
        // Database-local IDs are execution cursors only. They never become
        // portable Configuration Manager identity. Keyset paging avoids
        // offset skips/repeats when a numeric-ID collection changes earlier
        // in the result set while it is being scanned.
        $action->addWhere('id', '>', $lastId);
      }
      foreach ($orderBy as $field => $direction) {
        $action->addOrderBy($field, $direction);
      }

      $hasLimit = method_exists($action, 'setLimit');
      $hasOffset = method_exists($action, 'setOffset');
      $paged = $hasLimit && ($useIdCursor || $hasOffset);
      if ($paged) {
        $action->setLimit($batchSize);
        if (!$useIdCursor) {
          $action->setOffset($offset);
        }
      }

      $page = array_values((array) $action->execute());
      if ($paged && $page) {
        $fingerprint = $this->collectionPageFingerprint($page);
        if (isset($seenPages[$fingerprint])) {
          throw new \RuntimeException('API4 provider pagination repeated a previous page for ' . $entity . '; refusing an incomplete or unbounded collection read.');
        }
        $seenPages[$fingerprint] = TRUE;
      }

      $pageCount = count($page);
      $nextLastId = $lastId;
      foreach ($page as $row) {
        $row = (array) $row;
        if ($useIdCursor) {
          if (!isset($row['id']) || !is_numeric($row['id'])) {
            throw new \RuntimeException('API4 provider ' . $entity . ' was configured for numeric-ID cursor paging but returned a row without a numeric id.');
          }
          $rowId = (int) $row['id'];
          if ($nextLastId !== NULL && $rowId <= $nextLastId) {
            throw new \RuntimeException('API4 provider ' . $entity . ' did not make forward progress for numeric-ID cursor paging.');
          }
          $nextLastId = $rowId;
        }
        yield $row;
      }
      unset($page, $action);

      if (!$paged || $pageCount < $batchSize) {
        break;
      }
      if ($useIdCursor) {
        $lastId = $nextLastId;
      }
      else {
        $offset += $pageCount;
      }
    }
  }

  /**
   * Backward-compatible materialized API4 collection read.
   */
  protected function api4Get(string $entity, array $where = [], array $select = ['*'], array $orderBy = []): array {
    return iterator_to_array($this->api4Iterate($entity, $where, $select, $orderBy), FALSE);
  }

  /**
   * Fingerprint a small page sample for pagination-loop protection.
   */
  private function collectionPageFingerprint(array $rows): string {
    $sample = [];
    foreach (array_slice($rows, 0, 3) as $row) {
      $row = (array) $row;
      foreach (['id', 'key', 'name', 'machine_name', 'title'] as $field) {
        if (array_key_exists($field, $row) && is_scalar($row[$field])) {
          $sample[] = $field . '=' . (string) $row[$field];
          continue 2;
        }
      }
      $sample[] = sha1(serialize($row));
    }
    return sha1(implode('|', $sample));
  }

  /**
   * Fetch one API4 row without materializing the complete matching collection.
   */
  protected function api4GetFirst(string $entity, array $where, array $select = ['*']): ?array {
    $class = 'Civi\\Api4\\' . $entity;
    if (!class_exists($class)) {
      throw new \RuntimeException('API4 entity not available: ' . $entity . '. Install/enable the provider before managing this configuration type.');
    }
    $action = $class::get(FALSE)->addSelect(...$select);
    foreach ($where as $condition) {
      $action->addWhere(...$condition);
    }
    if (method_exists($action, 'setLimit')) {
      $action->setLimit(1);
    }
    $rows = array_values((array) $action->execute());
    return isset($rows[0]) ? (array) $rows[0] : NULL;
  }

  protected function api4Create(string $entity, array $values): array {
    $class = 'Civi\\Api4\\' . $entity;
    if (!class_exists($class)) {
      throw new \RuntimeException("API4 entity not available: {$entity}");
    }
    $action = $class::create(FALSE);
    foreach ($values as $field => $value) {
      $action->addValue($field, $value);
    }
    $result = (array) $action->execute();
    return $result[0] ?? [];
  }

  protected function api4Update(string $entity, array $where, array $values): array {
    $class = 'Civi\\Api4\\' . $entity;
    if (!class_exists($class)) {
      throw new \RuntimeException("API4 entity not available: {$entity}");
    }
    $action = $class::update(FALSE);
    foreach ($where as $condition) {
      $action->addWhere(...$condition);
    }
    foreach ($values as $field => $value) {
      $action->addValue($field, $value);
    }
    return (array) $action->execute();
  }

  protected function api4Delete(string $entity, array $where): array {
    $class = 'Civi\\Api4\\' . $entity;
    if (!class_exists($class)) {
      throw new \RuntimeException("API4 entity not available: {$entity}");
    }
    $action = $class::delete(FALSE);
    foreach ($where as $condition) {
      $action->addWhere(...$condition);
    }
    return (array) $action->execute();
  }
}
