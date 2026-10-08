#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
source_file="${repo_root}/Civi/ConfigManager/Service/ConfigManager.php"
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
$mutations = [
  "    if (\$checkContentState) {" => "    if (FALSE && \$checkContentState) { // MUTATION PROOF ONLY: ignore stale preview state.",
  "  private function assertReviewPlanHandlerYamlMatches(array \$plan, \$handler, YamlFileStorage \$storage): void {\n    \$type = (string) \$handler->getType();" => "  private function assertReviewPlanHandlerYamlMatches(array \$plan, \$handler, YamlFileStorage \$storage): void {\n    return; // MUTATION PROOF ONLY: bypass the last-moment Saved Config guard.\n    \$type = (string) \$handler->getType();",
];
foreach ($mutations as $needle => $replacement) {
  $source = str_replace($needle, $replacement, $source, $count);
  if ($count !== 1) {
    fwrite(STDERR, "Could not apply exactly one immutable-import-plan mutation.\n");
    exit(2);
  }
}
file_put_contents($path, $source);
' "${source_file}"

php -l "${source_file}" >/dev/null

if "${phpunit_bin}" --configuration "${repo_root}/phpunit.xml.dist" \
  --filter testSavedConfigChangeMakesReviewedPlanStaleBeforeWrite \
  "${repo_root}/tests/phpunit/Unit/ConfigManagerImportPlanTest.php" >"${failure_log}" 2>&1; then
  echo "Immutable import-plan mutation unexpectedly remained green." >&2
  cat "${failure_log}" >&2
  exit 1
fi
if ! grep -Fq 'Changed Saved Config should make the reviewed plan stale.' "${failure_log}"; then
  echo "Immutable import-plan mutation failed for an unexpected reason." >&2
  cat "${failure_log}" >&2
  exit 1
fi

cp "${backup_file}" "${source_file}"
php -l "${source_file}" >/dev/null
"${phpunit_bin}" --configuration "${repo_root}/phpunit.xml.dist" \
  --filter testSavedConfigChangeMakesReviewedPlanStaleBeforeWrite \
  "${repo_root}/tests/phpunit/Unit/ConfigManagerImportPlanTest.php"

echo "Immutable import-plan mutation proof OK (stale-plan bypass red; restored source green)."
