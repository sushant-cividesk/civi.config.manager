#!/usr/bin/env bash

set -Eeuo pipefail

MAX_ATTEMPTS="${CIVICFG_AUDIT_MAX_ATTEMPTS:-3}"
RETRY_DELAY="${CIVICFG_AUDIT_RETRY_DELAY:-5}"

if [[ ! "${MAX_ATTEMPTS}" =~ ^[1-9][0-9]*$ ]]; then
  echo "CIVICFG_AUDIT_MAX_ATTEMPTS must be a positive integer." >&2
  exit 2
fi
if [[ ! "${RETRY_DELAY}" =~ ^[0-9]+$ ]]; then
  echo "CIVICFG_AUDIT_RETRY_DELAY must be a non-negative integer." >&2
  exit 2
fi

is_transient_transport_failure() {
  grep -Eiq \
    'could not be downloaded|curl error|could not resolve host|failed to connect|connection (timed out|reset)|TLS connection|SSL connection|HTTP(/[0-9.]+)?[[:space:]]+(408|425|429|5[0-9]{2})' \
    <<< "$1"
}

attempt=1
while (( attempt <= MAX_ATTEMPTS )); do
  set +e
  output="$(composer audit --locked --no-interaction 2>&1)"
  status="$?"
  set -e

  printf '%s\n' "${output}"
  if (( status == 0 )); then
    exit 0
  fi

  # Advisory findings, invalid metadata, authentication failures, and unknown
  # errors are security-gate failures. Retry only recognizable transport/service
  # failures, and never convert exhausted retries into success.
  if ! is_transient_transport_failure "${output}"; then
    exit "${status}"
  fi
  if (( attempt == MAX_ATTEMPTS )); then
    echo "Composer security audit failed after ${MAX_ATTEMPTS} transport attempts." >&2
    exit "${status}"
  fi

  delay="$((RETRY_DELAY * attempt))"
  echo "Composer advisory service was temporarily unavailable; retrying audit ($((attempt + 1))/${MAX_ATTEMPTS}) in ${delay}s." >&2
  if (( delay > 0 )); then
    sleep "${delay}"
  fi
  attempt="$((attempt + 1))"
done

exit 1
