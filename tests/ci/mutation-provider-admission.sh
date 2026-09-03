#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
source_file="${repo_root}/Civi/ConfigManager/Service/ProviderAdmissionPolicy.php"
phpunit_bin="${repo_root}/vendor/bin/phpunit"
backup_file="$(mktemp)"
failure_log="$(mktemp)"

cp "${source_file}" "${backup_file}"
cleanup() {
  cp "${backup_file}" "${source_file}"
  rm -f "${backup_file}" "${failure_log}"
}
trap cleanup EXIT HUP INT TERM

needle="$(cat <<'EOF'
  private function hasPortableReferenceMapping(array $metadata): bool {
    $mapping = $metadata['civicfg_reference'] ?? NULL;
EOF
)"
replacement="$(cat <<'EOF'
  private function hasPortableReferenceMapping(array $metadata): bool {
    // MUTATION PROOF ONLY: incorrectly trust every reference as portable.
    return TRUE;
    $mapping = $metadata['civicfg_reference'] ?? NULL;
EOF
)"

php -r '
$path = $argv[1];
$source = file_get_contents($path);
$mutated = str_replace($argv[2], $argv[3], $source, $count);
if ($count !== 1) {
  fwrite(STDERR, "Could not apply exactly one provider-admission mutation.\n");
  exit(2);
}
file_put_contents($path, $mutated);
' "${source_file}" "${needle}" "${replacement}"

php -l "${source_file}" >/dev/null

if "${phpunit_bin}" --configuration "${repo_root}/phpunit.xml.dist" \
  --filter testUnmappedWritableReferenceFailsClosed >"${failure_log}" 2>&1; then
  echo "Provider admission mutation unexpectedly remained green." >&2
  cat "${failure_log}" >&2
  exit 1
fi
if ! grep -Fq 'Failed asserting that true is false' "${failure_log}"; then
  echo "Provider admission mutation failed for an unexpected reason." >&2
  cat "${failure_log}" >&2
  exit 1
fi

cp "${backup_file}" "${source_file}"
php -l "${source_file}" >/dev/null
"${phpunit_bin}" --configuration "${repo_root}/phpunit.xml.dist" \
  --filter testUnmappedWritableReferenceFailsClosed

echo "Provider admission mutation proof OK (unmapped-reference bypass red; restored source green)."
