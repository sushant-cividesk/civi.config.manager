#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TEMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/civicfg-audit-test.XXXXXX")"
trap 'rm -rf "${TEMP_ROOT}"' EXIT INT TERM

FAKE_BIN="${TEMP_ROOT}/bin"
FAKE_STATE_FILE="${TEMP_ROOT}/attempts"
mkdir -p "${FAKE_BIN}"

cat > "${FAKE_BIN}/composer" <<'FAKE_COMPOSER'
#!/usr/bin/env bash
set -eu

count=0
if [[ -f "${FAKE_STATE_FILE}" ]]; then
  count="$(sed -n '1p' "${FAKE_STATE_FILE}")"
fi
count="$((count + 1))"
printf '%s\n' "${count}" > "${FAKE_STATE_FILE}"

case "${FAKE_SCENARIO}" in
  success)
    echo "No security vulnerability advisories found."
    exit 0
    ;;
  advisory)
    echo "Found 1 security vulnerability advisory affecting 1 package."
    exit 1
    ;;
  transient-then-success)
    if (( count < 3 )); then
      echo 'The "https://packagist.org/api/security-advisories/" file could not be downloaded (HTTP/2 502)' >&2
      exit 1
    fi
    echo "No security vulnerability advisories found."
    exit 0
    ;;
  transient-exhausted)
    echo 'curl error 28 while downloading the advisory service: Connection timed out' >&2
    exit 1
    ;;
  *)
    echo "Unknown fake scenario." >&2
    exit 2
    ;;
esac
FAKE_COMPOSER
chmod +x "${FAKE_BIN}/composer"

run_wrapper() {
  PATH="${FAKE_BIN}:${PATH}" \
    FAKE_STATE_FILE="${FAKE_STATE_FILE}" \
    FAKE_SCENARIO="$1" \
    CIVICFG_AUDIT_MAX_ATTEMPTS=3 \
    CIVICFG_AUDIT_RETRY_DELAY=0 \
    "${SCRIPT_DIR}/composer-audit.sh"
}

assert_attempts() {
  local expected="$1"
  local actual
  actual="$(sed -n '1p' "${FAKE_STATE_FILE}")"
  if [[ "${actual}" != "${expected}" ]]; then
    echo "Expected ${expected} Composer invocation(s), got ${actual}." >&2
    exit 1
  fi
}

# Requirement-first red/green proof: the raw audit command fails on the exact
# transient class from the supplied CI log; the wrapper retries and succeeds.
rm -f "${FAKE_STATE_FILE}"
if PATH="${FAKE_BIN}:${PATH}" FAKE_STATE_FILE="${FAKE_STATE_FILE}" FAKE_SCENARIO=transient-then-success composer audit --locked --no-interaction >/dev/null 2>&1; then
  echo "The raw audit unexpectedly passed the transient failure fixture." >&2
  exit 1
fi
assert_attempts 1

rm -f "${FAKE_STATE_FILE}"
run_wrapper transient-then-success >/dev/null
assert_attempts 3

# Independent negative oracle: a genuine advisory must fail immediately and
# must never be hidden by the transport retry policy.
rm -f "${FAKE_STATE_FILE}"
if run_wrapper advisory >/dev/null 2>&1; then
  echo "The wrapper suppressed a security advisory." >&2
  exit 1
fi
assert_attempts 1

# Repeated service failure remains a blocking failure after the bounded retry.
rm -f "${FAKE_STATE_FILE}"
if run_wrapper transient-exhausted >/dev/null 2>&1; then
  echo "The wrapper suppressed an exhausted transport failure." >&2
  exit 1
fi
assert_attempts 3

rm -f "${FAKE_STATE_FILE}"
run_wrapper success >/dev/null
assert_attempts 1

echo "Composer audit retry wrapper OK (transient recovery, advisory fail-closed, exhausted failure)."
