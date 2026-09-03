<?php
namespace Civi\ConfigManager\Service;

/**
 * Deny-by-default admission policy for automatically discovered providers.
 *
 * This class intentionally evaluates metadata only. It must never execute a
 * provider collection read. Capability assignment remains a separate step in
 * the caller after every proof stage succeeds.
 */
final class ProviderAdmissionPolicy {

  private const BUSINESS_MARKERS = [
    'contact_id', 'activity_id', 'case_id', 'contribution_id', 'participant_id',
    'membership_id', 'membership_type_id', 'event_id', 'grant_type_id',
    'financial_type_id', 'payment_processor_id', 'recurring_contribution_id',
    'amount', 'amount_total', 'amount_requested', 'amount_granted', 'currency',
  ];

  private const RUNTIME_FIELDS = [
    'id', 'created_date', 'modified_date', 'last_modified', 'created_id',
    'modified_id', 'last_run', 'last_run_end', 'last_executed', 'last_runtime',
    'next_execution',
  ];

  /**
   * @param string[] $matchFields
   * @param array<string,array<string,mixed>> $fields
   * @return array<string,mixed>
   */
  public function assess(array $matchFields, array $fields, bool $reviewedProvider = FALSE): array {
    $stages = [
      'discover' => ['passed' => TRUE, 'reason_code' => 'provider_discovered'],
      'classify' => ['passed' => FALSE, 'reason_code' => 'not_evaluated'],
      'portable_identity' => ['passed' => FALSE, 'reason_code' => 'not_evaluated'],
      'writable_projection' => ['passed' => FALSE, 'reason_code' => 'not_evaluated'],
      'reference_mapping' => ['passed' => FALSE, 'reason_code' => 'not_evaluated'],
      'capability' => ['passed' => FALSE, 'reason_code' => 'not_assigned'],
    ];

    if ($reviewedProvider) {
      foreach (['classify', 'portable_identity', 'writable_projection', 'reference_mapping'] as $stage) {
        $stages[$stage] = ['passed' => TRUE, 'reason_code' => 'reviewed_adapter'];
      }
      return $this->admit(
        'reviewed_adapter',
        'Reviewed provider adapter supplies an explicitly reviewed portable projection and reference policy.',
        $stages,
        $this->writableFields($fields),
        $this->referenceFields($fields)
      );
    }

    foreach (self::BUSINESS_MARKERS as $field) {
      if (isset($fields[$field])) {
        $stages['classify'] = ['passed' => FALSE, 'reason_code' => 'business_data_marker'];
        return $this->deny(
          'business_data_marker',
          'API4 provider exposes business/transaction field ' . $field . '. Generic discovery will not treat it as deployable configuration; use an explicit provider definition if this is intentionally configuration.',
          $stages
        );
      }
    }
    $stages['classify'] = ['passed' => TRUE, 'reason_code' => 'configuration_candidate'];

    if (!$matchFields) {
      $stages['portable_identity'] = ['passed' => FALSE, 'reason_code' => 'missing_portable_identity'];
      return $this->deny(
        'missing_portable_identity',
        'API4 provider does not declare a non-ID match_fields identity. CRUD capability alone does not prove deployable configuration.',
        $stages
      );
    }
    foreach ($matchFields as $field) {
      $field = trim((string) $field);
      if ($field === '' || strtolower($field) === 'id' || !isset($fields[$field])) {
        $stages['portable_identity'] = ['passed' => FALSE, 'reason_code' => 'incomplete_identity_metadata'];
        return $this->deny(
          'incomplete_identity_metadata',
          'API4 provider match_fields metadata is incomplete or local-ID based for field ' . ($field !== '' ? $field : '[empty]') . '.',
          $stages
        );
      }
      if ($this->isSensitiveName($field)) {
        $stages['portable_identity'] = ['passed' => FALSE, 'reason_code' => 'sensitive_identity'];
        return $this->deny(
          'sensitive_identity',
          'API4 provider uses a sensitive-looking field as portable identity. Declare the provider explicitly so sensitive-field handling can be reviewed.',
          $stages
        );
      }
    }
    $stages['portable_identity'] = ['passed' => TRUE, 'reason_code' => 'portable_identity_proven'];

    $writableFields = $this->writableFields($fields);
    foreach ($writableFields as $name) {
      if ($this->isSensitiveName($name)) {
        $stages['writable_projection'] = ['passed' => FALSE, 'reason_code' => 'sensitive_writable_field'];
        return $this->deny(
          'sensitive_writable_field',
          'API4 provider exposes sensitive-looking writable field ' . $name . '. Generic discovery cannot safely decide how to redact/restore it; declare the provider explicitly.',
          $stages,
          $writableFields
        );
      }
    }
    $stages['writable_projection'] = ['passed' => TRUE, 'reason_code' => 'writable_projection_proven'];

    $referenceFields = $this->referenceFields($fields);
    foreach ($referenceFields as $name) {
      $metadata = (array) ($fields[$name] ?? []);
      if (!$this->hasPortableReferenceMapping($metadata)) {
        $stages['reference_mapping'] = ['passed' => FALSE, 'reason_code' => 'unmapped_reference_field'];
        return $this->deny(
          'unmapped_reference_field',
          'API4 provider exposes writable reference field ' . $name . ' without an explicit portable reference mapping. Local numeric references cannot be copied across environments safely.',
          $stages,
          $writableFields,
          $referenceFields
        );
      }
    }
    $stages['reference_mapping'] = ['passed' => TRUE, 'reason_code' => $referenceFields ? 'portable_reference_mapping_proven' : 'no_writable_references'];

    return $this->admit(
      'portable_identity_and_field_policy',
      'API4 provider passed classification, portable identity, writable projection, and portable reference-mapping proofs.',
      $stages,
      $writableFields,
      $referenceFields
    );
  }

  /** @return string[] */
  private function writableFields(array $fields): array {
    $result = [];
    foreach ($fields as $name => $metadata) {
      $name = (string) $name;
      $metadata = (array) $metadata;
      if ($name === '' || in_array($name, self::RUNTIME_FIELDS, TRUE)) {
        continue;
      }
      if (!empty($metadata['readonly']) || !empty($metadata['read_only']) || (($metadata['type'] ?? '') === 'Extra')) {
        continue;
      }
      $result[] = $name;
    }
    sort($result, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values(array_unique($result));
  }

  /** @return string[] */
  private function referenceFields(array $fields): array {
    $result = [];
    foreach ($this->writableFields($fields) as $name) {
      $metadata = (array) ($fields[$name] ?? []);
      if (substr($name, -3) === '_id'
        || !empty($metadata['fk_entity'])
        || !empty($metadata['entity'])
        || !empty($metadata['pseudoconstant'])) {
        $result[] = $name;
      }
    }
    sort($result, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values(array_unique($result));
  }

  private function hasPortableReferenceMapping(array $metadata): bool {
    $mapping = $metadata['civicfg_reference'] ?? NULL;
    if (is_array($mapping)) {
      $entity = trim((string) ($mapping['entity'] ?? ''));
      $identity = array_values(array_filter(array_map('strval', (array) ($mapping['identity_fields'] ?? [])), 'strlen'));
      return $entity !== '' && !empty($identity) && !in_array('id', array_map('strtolower', $identity), TRUE);
    }
    return FALSE;
  }

  private function isSensitiveName(string $name): bool {
    return (bool) preg_match('/(?:password|passwd|secret|token|credential|(?:^|[_.:-])key(?:$|[_.:-])|(?:api|private|access|auth|signing|encryption|consumer)[_-]?key)/i', $name);
  }

  private function deny(string $code, string $reason, array $stages, array $writable = [], array $references = []): array {
    return [
      'admitted' => FALSE,
      'reason_code' => $code,
      'reason' => $reason,
      'stages' => $stages,
      'writable_fields' => $writable,
      'reference_fields' => $references,
    ];
  }

  private function admit(string $code, string $reason, array $stages, array $writable, array $references): array {
    $stages['capability'] = ['passed' => TRUE, 'reason_code' => 'capability_assignment_allowed'];
    return [
      'admitted' => TRUE,
      'reason_code' => $code,
      'reason' => $reason,
      'stages' => $stages,
      'writable_fields' => $writable,
      'reference_fields' => $references,
    ];
  }
}
