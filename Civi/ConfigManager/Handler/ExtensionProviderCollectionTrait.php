<?php
namespace Civi\ConfigManager\Handler;

/**
 * ExtensionProviderCollection methods from ExtensionHandler.
 *
 * Methods run on the original owning object and retain the same state and
 * access modifiers. Do not instantiate this trait separately.
 */
trait ExtensionProviderCollectionTrait {

  private function fetchEntityRows(array $definition): array {
    $api = (string) ($definition['api'] ?? '');
    $entity = (string) ($definition['entity'] ?? '');

    if ($api === 'api4') {
      $class = (string) ($definition['class'] ?? '');
      if ($class === '' || !class_exists($class)) {
        throw new \RuntimeException('Contributed configuration provider is unavailable: API4 ' . $entity . '.');
      }
      try {
        // Use AbstractHandler's bounded API4 reader. Large contributed
        // providers must not ask API4 to materialize the complete collection
        // in one request.
        return $this->api4Get($entity, [], ['*']);
      }
      catch (\Throwable $e) {
        throw new \RuntimeException('Could not read contributed configuration provider API4 ' . $entity . ': ' . $e->getMessage(), 0, $e);
      }
    }

    $readAdapter = trim((string) ($definition['read_adapter'] ?? ''));
    if ($readAdapter !== '') {
      return $this->fetchApi3ReadAdapterRows($definition, $readAdapter);
    }

    try {
      $action = (string) ($definition['list_action'] ?? 'get');
      if ($action === 'get') {
        $rows = $this->fetchApi3GetRowsPaged($entity);
      }
      else {
        // Contributed get-all actions are not guaranteed to implement offset
        // semantics. Ask for a bounded page once; if the provider honors API3
        // options we page it, otherwise preserve the returned collection and
        // never risk an infinite pagination loop.
        $rows = $this->fetchApi3CollectionRowsConservatively($entity, $action);
      }

      // A custom get-all action may intentionally return a lightweight list.
      // When the entity also has get and rows expose IDs, hydrate each row so
      // export receives the complete configuration exposed by the provider.
      if ($action !== 'get' && $this->api3EntityHasAction($entity, 'get')) {
        foreach ($rows as $index => $row) {
          $row = (array) $row;
          if (empty($row['id']) || !is_scalar($row['id'])) {
            continue;
          }
          try {
            $detail = civicrm_api3($entity, 'get', [
              'sequential' => 1,
              'id' => $row['id'],
              'options' => ['limit' => 1],
            ]);
            $detailRows = $this->normalizeApi3Rows((array) $detail);
            if (!empty($detailRows[0])) {
              $rows[$index] = (array) $detailRows[0];
            }
          }
          catch (\Throwable $e) {
            throw new \RuntimeException('Could not hydrate contributed configuration provider API3 ' . $entity . '.' . $action . ' row ' . (string) $row['id'] . ' through get: ' . $e->getMessage(), 0, $e);
          }
        }
      }
      return array_values($rows);
    }
    catch (\Throwable $e) {
      if ($e instanceof \RuntimeException && strpos($e->getMessage(), 'Could not hydrate contributed configuration provider') === 0) {
        throw $e;
      }
      throw new \RuntimeException('Could not read contributed configuration provider API3 ' . $entity . '.' . (string) ($definition['list_action'] ?? 'get') . ': ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Read a normal API3 get collection in bounded pages.
   */
  private function fetchApi3GetRowsPaged(string $entity): array {
    $rows = [];
    $offset = 0;
    $batchSize = 200;
    while (TRUE) {
      $result = civicrm_api3($entity, 'get', [
        'sequential' => 1,
        'options' => ['limit' => $batchSize, 'offset' => $offset],
      ]);
      $page = $this->normalizeApi3Rows((array) $result);
      foreach ($page as $row) {
        $rows[] = $row;
      }
      $count = count($page);
      unset($page, $result);
      if ($count < $batchSize) {
        break;
      }
      $offset += $count;
    }
    return $rows;
  }

  /**
   * Page a contributed read action only when it demonstrably honors offsets.
   *
   * Custom API3 get-all actions vary widely. The first call is bounded through
   * API3 options. If a full page is returned, a second page is requested. A
   * repeated page means the provider ignored offset; in that case fail closed
   * rather than silently truncating or looping forever.
   */
  private function fetchApi3CollectionRowsConservatively(string $entity, string $action): array {
    $batchSize = 200;
    $result = civicrm_api3($entity, $action, [
      'sequential' => 1,
      'options' => ['limit' => $batchSize, 'offset' => 0],
    ]);
    $rows = $this->normalizeApi3Rows((array) $result);
    unset($result);
    if (count($rows) < $batchSize) {
      return array_values($rows);
    }

    $offset = count($rows);
    $seenPages = [$this->api3PageFingerprint($rows) => TRUE];
    while (TRUE) {
      $result = civicrm_api3($entity, $action, [
        'sequential' => 1,
        'options' => ['limit' => $batchSize, 'offset' => $offset],
      ]);
      $page = $this->normalizeApi3Rows((array) $result);
      unset($result);
      if (!$page) {
        break;
      }
      $fingerprint = $this->api3PageFingerprint($page);
      if (isset($seenPages[$fingerprint])) {
        throw new \RuntimeException('Provider collection action does not honor bounded offset pagination; refusing an incomplete or unbounded export.');
      }
      $seenPages[$fingerprint] = TRUE;
      foreach ($page as $row) {
        $rows[] = $row;
      }
      $count = count($page);
      unset($page);
      if ($count < $batchSize) {
        break;
      }
      $offset += $count;
    }
    return array_values($rows);
  }

  private function api3PageFingerprint(array $rows): string {
    if (!$rows) {
      return '';
    }
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
   * Read rows through one reviewed provider-owned adapter.
   */
  private function fetchApi3ReadAdapterRows(array $definition, string $adapterName): array {
    $entity = (string) ($definition['entity'] ?? '');
    $adapter = $this->api3ReadAdapterDefinition($entity);
    if ($adapter === NULL || (string) ($adapter['name'] ?? '') !== $adapterName) {
      throw new \RuntimeException('Unknown API3 contributed-provider read adapter: ' . $adapterName . '.');
    }

    $class = (string) ($adapter['class'] ?? '');
    $collectionMethod = (string) ($adapter['collection_method'] ?? '');
    $rowMethod = (string) ($adapter['row_method'] ?? '');
    $basePath = (string) ($definition['base_path'] ?? '');
    if ($class === '' || !$this->loadApi3ReadAdapterClass($adapter, $basePath) || !method_exists($class, $collectionMethod) || !method_exists($class, $rowMethod)) {
      // SQLTasks 3.x ships native API4 SqlTask as well as its older API3/BAO
      // surface. An old YAML export can still reference API3 Sqltask, while a
      // target site's classloader may not expose the BAO in this request. Use
      // the native API4 collection as the safe read fallback instead of
      // blocking preview/import solely because the legacy adapter is absent.
      if (strtolower($entity) === 'sqltask') {
        $nativeApi4File = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'Civi' . DIRECTORY_SEPARATOR . 'Api4' . DIRECTORY_SEPARATOR . 'SqlTask.php';
        if ($this->loadApi4ClassFromProvider('SqlTask', $nativeApi4File)) {
          try {
            return $this->api4Get('SqlTask', [], ['*']);
          }
          catch (\Throwable $e) {
            throw new \RuntimeException('Could not read SQLTasks through native API4 fallback: ' . $e->getMessage(), 0, $e);
          }
        }

        // SQLTasks 2.2.x exposes Sqltask.getalltasks as its supported
        // collection API. Old YAML/provider metadata may still request the
        // later BAO adapter, so transparently fall back to the public API3
        // collection instead of blocking export/diff/import.
        $getAllFile = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'v3' . DIRECTORY_SEPARATOR . 'Sqltask' . DIRECTORY_SEPARATOR . 'Getalltasks.php';
        if (is_file($getAllFile)) {
          $fallbackDefinition = $definition;
          $fallbackDefinition['read_adapter'] = '';
          $fallbackDefinition['list_action'] = 'getalltasks';
          return $this->fetchEntityRows($fallbackDefinition);
        }
      }
      throw new \RuntimeException('API3 contributed-provider read adapter is unavailable for ' . $entity . '.');
    }

    try {
      $generatorMethod = new \ReflectionMethod($class, $collectionMethod);
      $rowExportMethod = new \ReflectionMethod($class, $rowMethod);
      $items = $generatorMethod->invoke(NULL, []);
      if (!is_iterable($items)) {
        throw new \RuntimeException('Provider collection adapter did not return an iterable result.');
      }

      $rows = [];
      foreach ($items as $item) {
        if (!is_object($item)) {
          throw new \RuntimeException('Provider collection adapter returned a non-object row.');
        }
        $row = $rowExportMethod->invoke($item);
        if (!is_array($row)) {
          throw new \RuntimeException('Provider row adapter did not return an array.');
        }
        $rows[] = $row;
      }
      return $rows;
    }
    catch (\Throwable $e) {
      throw new \RuntimeException('Could not read contributed configuration provider API3 ' . $entity . ' through ' . $adapterName . ': ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Normalize both standard API3 get results and custom single-record results.
   */
  private function normalizeApi3Rows(array $result): array {
    $values = (array) ($result['values'] ?? []);
    if (!$values) {
      return [];
    }
    foreach ($values as $value) {
      if (!is_array($value)) {
        return [$values];
      }
    }
    return array_values($values);
  }

  private function cleanEntityRowForExport(array $row, array $definition): array {
    $row = $this->stripRuntime($row);
    if ($definition['api'] === 'api4') {
      $row = $this->stripReadOnlyFields($row, (array) ($definition['fields'] ?? []));
    }
    elseif ($definition['api'] === 'api3') {
      $row = $this->stripApi3NonWritableFields($row, $definition);
    }
    ksort($row, SORT_NATURAL | SORT_FLAG_CASE);
    return $row;
  }

  private function cleanEntityRowForImport(array $row, array $definition): array {
    $row = $this->stripRuntime($row);
    if ($definition['api'] === 'api4') {
      $row = $this->stripReadOnlyFields($row, (array) ($definition['fields'] ?? []));
    }
    elseif ($definition['api'] === 'api3') {
      $row = $this->stripApi3NonWritableFields($row, $definition);
    }
    return $row;
  }

  /**
   * Keep only fields accepted by an API3 provider's create action when the
   * provider exposes a usable getfields specification.
   *
   * Collection/read APIs often return calculated runtime fields which cannot
   * be passed back to create/update. SQLTasks is a concrete example: its read
   * result contains fields such as last_executed, last_runtime, next_execution,
   * schedule_label, short_desc, and archive state, while Sqltask.create accepts
   * only the task's writable configuration fields. Nested provider-owned data
   * such as config.actions is deliberately preserved unchanged.
   */
  private function stripApi3NonWritableFields(array $row, array $definition): array {
    $fields = $this->api3WritableFields($definition);
    if (!$fields) {
      // Fail open for portability when a third-party API does not expose a
      // trustworthy create spec. Existing identity/write-safety checks still
      // decide whether the provider can be managed automatically.
      return $row;
    }
    return array_intersect_key($row, array_fill_keys($fields, TRUE));
  }

  private function api3WritableFields(array $definition): array {
    if (!empty($definition['write_fields'])) {
      return $this->normaliseApi3WritableFields((array) $definition['write_fields']);
    }

    $entity = (string) ($definition['entity'] ?? '');
    if ($entity === '' || !function_exists('civicrm_api3')) {
      return [];
    }
    $cacheKey = strtolower($entity);
    if (array_key_exists($cacheKey, self::$api3WritableFieldsByEntity)) {
      return self::$api3WritableFieldsByEntity[$cacheKey];
    }

    try {
      $result = civicrm_api3($entity, 'getfields', [
        'sequential' => 1,
        'action' => 'create',
      ]);
      self::$api3WritableFieldsByEntity[$cacheKey] = $this->normaliseApi3WritableFields((array) ($result['values'] ?? []));
    }
    catch (\Throwable $e) {
      self::$api3WritableFieldsByEntity[$cacheKey] = [];
    }

    return self::$api3WritableFieldsByEntity[$cacheKey];
  }

  /**
   * @return string[]
   */
  private function normaliseApi3WritableFields(array $fields): array {
    $names = [];
    foreach ($fields as $key => $field) {
      if (is_string($field)) {
        $name = $field;
      }
      else {
        $field = (array) $field;
        $name = (string) ($field['name'] ?? (is_string($key) ? $key : ''));
      }
      $name = trim($name);
      if ($name === '' || $name === 'id') {
        continue;
      }
      $names[$name] = TRUE;
    }
    $names = array_keys($names);
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    return $names;
  }

}
