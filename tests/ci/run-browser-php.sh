#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
manifest_dir="${repo_root}/tests/browser-php"

if [[ -z "${CIVICRM_ADMIN_PASS:-}" ]]; then
  echo "CIVICRM_ADMIN_PASS is required for targeted Playwright-PHP QA." >&2
  exit 2
fi

if [[ -z "${CIVICFG_BASE_URL:-}" ]]; then
  cat >&2 <<'MSG'
CIVICFG_BASE_URL is required for Playwright-PHP black-box QA.
Run this test against an authorized DEV/disposable CiviCRM site, for example:
  civicfg qa-browser --base-url http://127.0.0.1:8760
MSG
  exit 2
fi

if [[ -d "${manifest_dir}/vendor" ]]; then
  cat >&2 <<'MSG'
Refusing to use tests/browser-php/vendor.
Alpha67.1 keeps a single project vendor directory in the extension tree.
Run 'civicfg qa-browser-clean --yes' (or 'civicfg qa-browser --base-url URL --clean-legacy'); browser tooling is installed outside the repository.
MSG
  exit 2
fi

command -v composer >/dev/null 2>&1 || {
  echo "Composer is required to provision the isolated Playwright-PHP QA toolchain." >&2
  exit 2
}

php_version_id="$(php -r 'echo PHP_VERSION_ID;')"
if (( php_version_id < 80200 )); then
  echo "Playwright-PHP browser QA requires PHP 8.2+; current runtime is $(php -r 'echo PHP_VERSION;')." >&2
  exit 2
fi

cache_root="${CIVICFG_BROWSER_PHP_TOOL_DIR:-${TMPDIR:-/tmp}/civicfg-browser-php-${USER:-user}}"
workspace="${cache_root}/workspace"
vendor_dir="${cache_root}/vendor"
mkdir -p "${workspace}"
cp "${manifest_dir}/composer.json" "${workspace}/composer.json"
cp "${manifest_dir}/composer.lock" "${workspace}/composer.lock"

COMPOSER_VENDOR_DIR="${vendor_dir}" composer install \
  --working-dir="${workspace}" \
  --no-interaction --prefer-dist --no-progress

if [[ ! -x "${vendor_dir}/bin/phpunit" || ! -x "${vendor_dir}/bin/playwright-install" ]]; then
  echo "Playwright-PHP QA tooling did not install correctly in ${cache_root}." >&2
  exit 1
fi

"${vendor_dir}/bin/playwright-install" --browsers

CIVICFG_BROWSER_PHP_VENDOR_AUTOLOAD="${vendor_dir}/autoload.php" \
  "${vendor_dir}/bin/phpunit" --fail-on-skipped --fail-on-risky -c "${manifest_dir}/phpunit.xml.dist"
