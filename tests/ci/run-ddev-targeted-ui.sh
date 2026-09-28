#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BASE_URL="${CIVICFG_BASE_URL:-https://dcivi-dev.civicrm.ddev.site}"
ADMIN_USER="${CIVICRM_ADMIN_USER:-admin}"
ADMIN_PASS="${CIVICRM_ADMIN_PASS:-}"
DDEV_ROOT="${CIVICFG_DDEV_EXTENSION_ROOT:-/var/www/html/ext/civi.config.manager}"

case "${BASE_URL}" in
  https://*.ddev.site|http://*.ddev.site) ;;
  *)
    echo "Targeted DDEV browser QA is restricted to explicit DDEV targets (*.ddev.site)." >&2
    exit 2
    ;;
esac

if ! command -v ddev >/dev/null 2>&1; then
  echo "ddev is required for targeted DDEV browser QA." >&2
  exit 2
fi

CIVICFG_DDEV_EXTENSION_ROOT="${DDEV_ROOT}" "${ROOT}/tests/ci/ensure-ddev-playwright.sh"

printf -v q_root '%q' "${DDEV_ROOT}"
printf -v q_base_url '%q' "${BASE_URL}"
printf -v q_admin_user '%q' "${ADMIN_USER}"
printf -v q_admin_pass '%q' "${ADMIN_PASS}"

echo "Running read-only targeted Playwright QA against ${BASE_URL}..."
ddev exec bash -lc "cd ${q_root} && CIVICFG_BASE_URL=${q_base_url} CIVICRM_ADMIN_USER=${q_admin_user} CIVICRM_ADMIN_PASS=${q_admin_pass} npm run test:ui"
