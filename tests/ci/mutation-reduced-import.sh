#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
source_file="${repo_root}/Civi/ConfigManager/Service/ImportDependencyPlanner.php"
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
$needle = "\$component[\x27can_exclude\x27] = \$excludedTypes !== [] && \$remainingRequested !== [];";
$replacement = "\$component[\x27can_exclude\x27] = TRUE; // MUTATION PROOF ONLY: allow unsafe whole-import exclusion.";
$source = str_replace($needle, $replacement, $source, $count);
if ($count !== 1) {
  fwrite(STDERR, "Could not apply exactly one reduced-import safety mutation.\n");
  exit(2);
}
file_put_contents($path, $source);
' "${source_file}"

php -l "${source_file}" >/dev/null

if "${phpunit_bin}" --configuration "${repo_root}/phpunit.xml.dist" \
  --filter testExclusionFailsClosedWhenItWouldRemoveWholeImport \
  "${repo_root}/tests/phpunit/Unit/ImportDependencyPlannerTest.php" >"${failure_log}" 2>&1; then
  echo "Reduced-import mutation unexpectedly remained green." >&2
  cat "${failure_log}" >&2
  exit 1
fi

if ! grep -Fq 'Failed asserting that true is false' "${failure_log}"; then
  echo "Reduced-import mutation failed for an unexpected reason." >&2
  cat "${failure_log}" >&2
  exit 1
fi

cp "${backup_file}" "${source_file}"
php -l "${source_file}" >/dev/null
"${phpunit_bin}" --configuration "${repo_root}/phpunit.xml.dist" \
  --filter testExclusionFailsClosedWhenItWouldRemoveWholeImport \
  "${repo_root}/tests/phpunit/Unit/ImportDependencyPlannerTest.php"

echo "Reduced import mutation proof OK (unsafe whole-import exclusion red; restored source green)."
