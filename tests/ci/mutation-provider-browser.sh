#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
source_file="${repo_root}/js/settings-provider-browser.js"
test_file="${repo_root}/tests/ci/provider-browser-test.js"
backup_file="$(mktemp)"
failure_log="$(mktemp)"

cp "${source_file}" "${backup_file}"
cleanup() {
  cp "${backup_file}" "${source_file}"
  rm -f "${backup_file}" "${failure_log}"
}
trap cleanup EXIT HUP INT TERM

needle="      return !represented[normalize(provider.type)];"
replacement="      return false; // MUTATION PROOF ONLY: hide every additional detected provider."

php -r '
$path = $argv[1];
$source = file_get_contents($path);
$mutated = str_replace($argv[2], $argv[3], $source, $count);
if ($count !== 1) {
  fwrite(STDERR, "Could not apply exactly one provider-browser mutation.\n");
  exit(2);
}
file_put_contents($path, $mutated);
' "${source_file}" "${needle}" "${replacement}"

node --check "${source_file}"
if node "${test_file}" >"${failure_log}" 2>&1; then
  echo "Provider browser mutation unexpectedly remained green." >&2
  cat "${failure_log}" >&2
  exit 1
fi
if ! grep -Fq 'only non-card, non-rejected detected providers should appear in progressive disclosure' "${failure_log}"; then
  echo "Provider browser mutation failed for an unexpected reason." >&2
  cat "${failure_log}" >&2
  exit 1
fi

cp "${backup_file}" "${source_file}"
node --check "${source_file}"
node "${test_file}"

echo "Provider browser mutation proof OK (hidden detected providers red; restored source green)."
