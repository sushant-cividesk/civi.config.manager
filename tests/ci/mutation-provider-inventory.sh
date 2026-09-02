#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
source_file="${repo_root}/Civi/ConfigManager/Handler/ExtensionHandler.php"
phpunit_bin="${repo_root}/vendor/bin/phpunit"
backup_file="$(mktemp)"
failure_log="$(mktemp)"

cp "${source_file}" "${backup_file}"
cleanup() {
  cp "${backup_file}" "${source_file}"
  rm -f "${backup_file}" "${failure_log}"
}
trap cleanup EXIT HUP INT TERM

needle=$'      $definition = (array) $definition;\n      $api = (string) ($definition['"'"'api'"'"'] ?? '"'"''"'"');'
replacement=$'      $definition = (array) $definition;\n      // MUTATION PROOF ONLY: simulate the forbidden provider collection read.\n      if (!empty($definition['"'"'class'"'"']) && is_callable([$definition['"'"'class'"'"'], '"'"'get'"'"'])) {\n        call_user_func([$definition['"'"'class'"'"'], '"'"'get'"'"'], FALSE);\n      }\n      $api = (string) ($definition['"'"'api'"'"'] ?? '"'"''"'"');'

php -r '
$path = $argv[1];
$source = file_get_contents($path);
$mutated = str_replace($argv[2], $argv[3], $source, $count);
if ($count !== 1) {
  fwrite(STDERR, "Could not apply exactly one provider-inventory mutation.\n");
  exit(2);
}
file_put_contents($path, $mutated);
' "${source_file}" "${needle}" "${replacement}"

php -l "${source_file}" >/dev/null

if "${phpunit_bin}" --configuration "${repo_root}/phpunit.xml.dist" \
  --filter testProviderInventoryDoesNotExecuteDiscoveredCollection >"${failure_log}" 2>&1; then
  echo "Provider inventory mutation unexpectedly remained green." >&2
  cat "${failure_log}" >&2
  exit 1
fi
if ! grep -Fq 'Inventory executed the provider collection action.' "${failure_log}"; then
  echo "Provider inventory mutation failed for an unexpected reason." >&2
  cat "${failure_log}" >&2
  exit 1
fi

# Restore before proving the unmodified behavior is green.
cp "${backup_file}" "${source_file}"
php -l "${source_file}" >/dev/null
"${phpunit_bin}" --configuration "${repo_root}/phpunit.xml.dist" \
  --filter testProviderInventoryDoesNotExecuteDiscoveredCollection

echo "Provider inventory mutation proof OK (forbidden collection read red; restored source green)."
