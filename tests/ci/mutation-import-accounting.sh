#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
source_file="${repo_root}/Civi/ConfigManager/Service/ImportResultAccounting.php"
phpunit_bin="${repo_root}/vendor/bin/phpunit"
backup_file="$(mktemp)"
failure_log="$(mktemp)"

cp "${source_file}" "${backup_file}"
cleanup() {
  cp "${backup_file}" "${source_file}"
  rm -f "${backup_file}" "${failure_log}"
}
trap cleanup EXIT HUP INT TERM

php -r '
$path = $argv[1];
$source = file_get_contents($path);
$needle = "return \$planned > 0 ? \$planned : (\$files ? count(\$files) : count(\$types));";
$replacement = "return 0; // MUTATION PROOF ONLY: erase excluded-result accounting.";
$source = str_replace($needle, $replacement, $source, $count);
if ($count !== 1) {
  fwrite(STDERR, "Could not apply exactly one import-accounting mutation.\n");
  exit(2);
}
file_put_contents($path, $source);
' "${source_file}"

php -l "${source_file}" >/dev/null

if "${phpunit_bin}" --configuration "${repo_root}/phpunit.xml.dist" \
  --filter testExcludedFilesAreDeduplicated \
  "${repo_root}/tests/phpunit/Unit/ImportResultAccountingTest.php" >"${failure_log}" 2>&1; then
  echo "Import-accounting mutation unexpectedly remained green." >&2
  cat "${failure_log}" >&2
  exit 1
fi

if ! grep -Fq 'Failed asserting that 0 is identical to 3' "${failure_log}"; then
  echo "Import-accounting mutation failed for an unexpected reason." >&2
  cat "${failure_log}" >&2
  exit 1
fi

cp "${backup_file}" "${source_file}"
php -l "${source_file}" >/dev/null
"${phpunit_bin}" --configuration "${repo_root}/phpunit.xml.dist" \
  --filter testExcludedFilesAreDeduplicated \
  "${repo_root}/tests/phpunit/Unit/ImportResultAccountingTest.php"

echo "Import accounting mutation proof OK (excluded-count erasure red; restored source green)."
