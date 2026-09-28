#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BASE_URL="${CIVICFG_BASE_URL:-https://dcivi-dev.civicrm.ddev.site}"
ADMIN_USER="${CIVICRM_ADMIN_USER:-admin}"
ADMIN_PASS="${CIVICRM_ADMIN_PASS:-}"
DDEV_ROOT="${CIVICFG_DDEV_EXTENSION_ROOT:-/var/www/html/ext/civi.config.manager}"
DDEV_ARTIFACT_DIR="${DDEV_ROOT}/tests/ci/artifacts"
STATE_FILE="${ROOT}/tests/ci/artifacts/ui-fixture-state.json"
RUN_ID="${CIVICFG_QA_RUN_ID:-ddev-a68-09-$(date +%Y%m%d%H%M%S)}"

case "${BASE_URL}" in
  https://*.ddev.site|http://*.ddev.site) ;;
  *)
    echo "Stateful browser QA is restricted to explicit DDEV targets (*.ddev.site)." >&2
    exit 2
    ;;
esac

if ! command -v ddev >/dev/null 2>&1; then
  echo "ddev is required for stateful DDEV browser QA." >&2
  exit 2
fi

mkdir -p "${ROOT}/tests/ci/artifacts"
if [[ -f "${STATE_FILE}" ]]; then
  echo "Refusing to overwrite an existing UI fixture state: ${STATE_FILE}" >&2
  echo "Clean the prior fixture before starting another stateful run." >&2
  exit 2
fi

printf -v q_root '%q' "${DDEV_ROOT}"
printf -v q_artifacts '%q' "${DDEV_ARTIFACT_DIR}"
printf -v q_run_id '%q' "${RUN_ID}"
printf -v q_base_url '%q' "${BASE_URL}"
printf -v q_admin_user '%q' "${ADMIN_USER}"
printf -v q_admin_pass '%q' "${ADMIN_PASS}"

seeded=0
cleanup() {
  local status=$?
  if [[ "${seeded}" -eq 1 ]]; then
    echo "Cleaning disposable Configuration Manager browser fixture..."
    ddev exec bash -lc "cd ${q_root} && CIVICFG_QA_RUN_ID=${q_run_id} CIVICFG_QA_ARTIFACTS=${q_artifacts} cv scr tests/integration/UiFixture.php cleanup" \
      || echo "WARNING: automatic UI fixture cleanup failed; inspect ${STATE_FILE}." >&2
  fi
  exit "${status}"
}
trap cleanup EXIT HUP INT TERM

echo "Seeding disposable Configuration Manager browser fixture (${RUN_ID})..."
ddev exec bash -lc "cd ${q_root} && CIVICFG_QA_RUN_ID=${q_run_id} CIVICFG_QA_ARTIFACTS=${q_artifacts} cv scr tests/integration/UiFixture.php seed"
seeded=1

echo "Running stateful Playwright QA against ${BASE_URL}..."
ddev exec bash -lc "cd ${q_root} && CIVICFG_BASE_URL=${q_base_url} CIVICRM_ADMIN_USER=${q_admin_user} CIVICRM_ADMIN_PASS=${q_admin_pass} QA_ARTIFACT_DIR=${q_artifacts} npm run test:ui"

echo "Stateful DDEV browser QA passed."
