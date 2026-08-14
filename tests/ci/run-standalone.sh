#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
EXTENSION_ROOT="${EXTENSION_ROOT:-$(cd "${SCRIPT_DIR}/../.." && pwd)}"
QA_ARTIFACT_DIR="${QA_ARTIFACT_DIR:-${EXTENSION_ROOT}/tests/ci/artifacts}"
PHP_QA_INI="${PHP_QA_INI:-${SCRIPT_DIR}/php-qa.ini}"
FIXTURE_EXTENSION_DIR="${FIXTURE_EXTENSION_DIR:-${QA_ARTIFACT_DIR}/fixture-extensions}"
COMPOSE_FILE="${SCRIPT_DIR}/compose.standalone.yml"
CIVICRM_HTTP_PORT="${CIVICRM_HTTP_PORT:-8760}"
MAILPIT_HTTP_PORT="${MAILPIT_HTTP_PORT:-8025}"
CIVICFG_QA_RUN_ID="${CIVICFG_QA_RUN_ID:-github-${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-1}}"
COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-civicfgqa-${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-1}}"

export EXTENSION_ROOT QA_ARTIFACT_DIR PHP_QA_INI FIXTURE_EXTENSION_DIR CIVICRM_HTTP_PORT MAILPIT_HTTP_PORT
export CIVICFG_QA_RUN_ID COMPOSE_PROJECT_NAME

source_state() {
  if git -C "${EXTENSION_ROOT}" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    {
      git -C "${EXTENSION_ROOT}" diff --binary
      git -C "${EXTENSION_ROOT}" diff --cached --binary
      git -C "${EXTENSION_ROOT}" status --porcelain=v1 --untracked-files=no
    } | sha256sum | awk '{print $1}'
    return
  fi

  find "${EXTENSION_ROOT}" \
    -path "${EXTENSION_ROOT}/.git" -prune -o \
    -path "${EXTENSION_ROOT}/vendor" -prune -o \
    -path "${EXTENSION_ROOT}/node_modules" -prune -o \
    -path "${EXTENSION_ROOT}/tests/ci/artifacts" -prune -o \
    -type f -print0 \
    | sort -z \
    | xargs -0 sha256sum \
    | sha256sum \
    | awk '{print $1}'
}

SOURCE_STATE_BEFORE="$(source_state)"

mkdir -p "${QA_ARTIFACT_DIR}" "${FIXTURE_EXTENSION_DIR}"
find "${QA_ARTIFACT_DIR}" -mindepth 1 -depth ! -name '.gitkeep' -delete
mkdir -p "${FIXTURE_EXTENSION_DIR}"
chmod 0777 "${QA_ARTIFACT_DIR}"
cd "${EXTENSION_ROOT}"

compose() {
  docker compose -f "${COMPOSE_FILE}" "$@"
}

collect_diagnostics() {
  local exit_code="$?"
  local cleanup_status=0
  local down_status=0
  set +e
  compose ps > "${QA_ARTIFACT_DIR}/docker-compose-ps.txt" 2>&1
  compose logs --no-color > "${QA_ARTIFACT_DIR}/docker-compose.log" 2>&1
  if compose ps --services --status running | grep -qx app; then
    compose exec -T -u www-data \
      -e CIVICFG_QA_RUN_ID="${CIVICFG_QA_RUN_ID}" \
      -e CIVICFG_QA_ARTIFACTS=/qa-artifacts \
      app cv scr /var/www/html/ext/civi.config.manager/tests/integration/UiFixture.php cleanup \
      > "${QA_ARTIFACT_DIR}/ui-fixture-cleanup.log" 2>&1 || cleanup_status=$?
    compose exec -T app sh -lc 'php -v; cv --version || true; cv status || true' \
      > "${QA_ARTIFACT_DIR}/runtime-status.txt" 2>&1
  fi
  compose down -v --remove-orphans > "${QA_ARTIFACT_DIR}/docker-compose-down.log" 2>&1 || down_status=$?

  if [[ "${exit_code}" -eq 0 && "${cleanup_status}" -ne 0 ]]; then
    echo "UI fixture cleanup failed with exit code ${cleanup_status}." >&2
    exit_code="${cleanup_status}"
  fi
  if [[ "${exit_code}" -eq 0 && "${down_status}" -ne 0 ]]; then
    echo "Docker cleanup failed with exit code ${down_status}." >&2
    exit_code="${down_status}"
  fi
  if [[ "${exit_code}" -ne 0 ]]; then
    rm -f "${QA_ARTIFACT_DIR}/READY"
  fi
  exit "${exit_code}"
}
trap collect_diagnostics EXIT

wait_for_http() {
  local url="$1"
  local attempts="${2:-90}"
  local delay="${3:-2}"
  local count
  for count in $(seq 1 "${attempts}"); do
    if curl --fail --silent --show-error --max-time 5 "${url}" >/dev/null 2>&1; then
      return 0
    fi
    sleep "${delay}"
  done
  echo "Timed out waiting for ${url}" >&2
  return 1
}

printf 'QA run ID: %s\n' "${CIVICFG_QA_RUN_ID}" | tee "${QA_ARTIFACT_DIR}/run-metadata.txt"
printf 'Git SHA: %s\n' "${GITHUB_SHA:-$(git -C "${EXTENSION_ROOT}" rev-parse HEAD 2>/dev/null || echo unknown)}" \
  | tee -a "${QA_ARTIFACT_DIR}/run-metadata.txt"
printf 'CiviCRM image: %s\n' "${CIVICRM_IMAGE:-civicrm/civicrm:6.16-php8.3}" \
  | tee -a "${QA_ARTIFACT_DIR}/run-metadata.txt"
printf 'Real extension fixtures: %s\n' "${RUN_REAL_EXTENSION_FIXTURES:-true}" \
  | tee -a "${QA_ARTIFACT_DIR}/run-metadata.txt"

if [[ "${RUN_REAL_EXTENSION_FIXTURES:-true}" == "true" ]]; then
  "${SCRIPT_DIR}/fetch-fixture-extensions.sh" | tee "${QA_ARTIFACT_DIR}/fixture-extension-fetch.log"
fi

compose pull
compose up -d

compose exec -T -u www-data \
  -e CIVICRM_ADMIN_USER="${CIVICRM_ADMIN_USER:-admin}" \
  -e CIVICRM_ADMIN_PASS="${CIVICRM_ADMIN_PASS:-qa-admin-password}" \
  app civicrm-docker-install

wait_for_http "http://127.0.0.1:${CIVICRM_HTTP_PORT}/"

compose exec -T -u www-data app sh -lc '
  set -eu
  cd /var/www/html
  cv api Extension.install keys=civi.config.manager
  cv flush
  cv api Extension.get key=civi.config.manager return=status --out=json
  cv api Extension.disable keys=civi.config.manager
  cv api Extension.enable keys=civi.config.manager
  cv flush
  cv api Extension.get key=civi.config.manager return=status --out=json
' | tee "${QA_ARTIFACT_DIR}/extension-lifecycle.log"

if [[ "${RUN_REAL_EXTENSION_FIXTURES:-true}" == "true" ]]; then
  compose exec -T -u www-data \
    -e FIXTURE_EXTENSION_DIR=/var/www/html/ext-fixtures \
    -e CIVICFG_QA_FIXTURE_EXTENSION_KEYS="${CIVICFG_QA_FIXTURE_EXTENSION_KEYS:-uk.co.vedaconsulting.mosaico de.systopia.sqltasks org.civicrm.contactlayout org.civicoop.civirules}" \
    -e CIVICFG_ALLOW_MISSING_FIXTURE_EXTENSIONS="${CIVICFG_ALLOW_MISSING_FIXTURE_EXTENSIONS:-false}" \
    app bash /var/www/html/ext/civi.config.manager/tests/ci/install-fixture-extensions.sh \
    | tee "${QA_ARTIFACT_DIR}/fixture-extension-install.log"
fi

compose exec -T -u www-data \
  -e CIVICFG_QA_RUN_ID="${CIVICFG_QA_RUN_ID}" \
  -e CIVICFG_QA_ROOT=/tmp/civicfg-qa \
  -e CIVICFG_QA_ARTIFACTS=/qa-artifacts \
  app cv scr /var/www/html/ext/civi.config.manager/tests/integration/StandaloneRoundTrip.php \
  | tee "${QA_ARTIFACT_DIR}/standalone-round-trip.log"

if [[ "${RUN_FULL_REAL_FIXTURE_SUITE:-true}" == "true" ]]; then
  compose exec -T -u www-data \
    -e CIVICFG_QA_RUN_ID="${CIVICFG_QA_RUN_ID}" \
    -e CIVICFG_QA_ROOT=/tmp/civicfg-qa \
    -e CIVICFG_QA_ARTIFACTS=/qa-artifacts \
    -e CIVICFG_QA_FIXTURE_EXTENSION_KEYS="${CIVICFG_QA_FIXTURE_EXTENSION_KEYS:-uk.co.vedaconsulting.mosaico de.systopia.sqltasks org.civicrm.contactlayout org.civicoop.civirules}" \
    -e CIVICFG_ALLOW_MISSING_FIXTURE_EXTENSIONS="${CIVICFG_ALLOW_MISSING_FIXTURE_EXTENSIONS:-false}" \
    app cv scr /var/www/html/ext/civi.config.manager/tests/integration/FullRealFixtures.php \
    | tee "${QA_ARTIFACT_DIR}/full-real-fixtures.log"
fi

compose exec -T -u www-data app sh -lc '
  set -eu
  cd /var/www/html
  cv api4 ConfigManager.status --out=json
  cv api4 ConfigManager.listTypes --out=json
  cv api4 ConfigManager.validate --out=json
  /var/www/html/ext/civi.config.manager/bin/civicfg validate
' | tee "${QA_ARTIFACT_DIR}/api-cli-smoke.log"

compose logs --no-color app > "${QA_ARTIFACT_DIR}/app-runtime.log"
if grep -E 'PHP (Fatal error|Parse error)|Uncaught (Error|Exception)|Allowed memory size.*exhausted' \
  "${QA_ARTIFACT_DIR}/app-runtime.log"; then
  echo "Fatal PHP runtime errors were detected during QA." >&2
  exit 1
fi

if [[ "${RUN_UI_TESTS:-false}" == "true" ]]; then
  compose exec -T -u www-data \
    -e CIVICFG_QA_RUN_ID="${CIVICFG_QA_RUN_ID}" \
    -e CIVICFG_QA_ARTIFACTS=/qa-artifacts \
    app cv scr /var/www/html/ext/civi.config.manager/tests/integration/UiFixture.php seed \
    | tee "${QA_ARTIFACT_DIR}/ui-fixture-seed.log"

  CIVICFG_BASE_URL="http://127.0.0.1:${CIVICRM_HTTP_PORT}" \
  CIVICRM_ADMIN_USER="${CIVICRM_ADMIN_USER:-admin}" \
  CIVICRM_ADMIN_PASS="${CIVICRM_ADMIN_PASS:-qa-admin-password}" \
  QA_ARTIFACT_DIR="${QA_ARTIFACT_DIR}" \
  npm run test:ui

  compose exec -T -u www-data \
    -e CIVICFG_QA_RUN_ID="${CIVICFG_QA_RUN_ID}" \
    -e CIVICFG_QA_ARTIFACTS=/qa-artifacts \
    app cv scr /var/www/html/ext/civi.config.manager/tests/integration/UiFixture.php cleanup \
    | tee "${QA_ARTIFACT_DIR}/ui-fixture-cleanup.log"
fi

compose logs --no-color app > "${QA_ARTIFACT_DIR}/app-runtime.log"
if grep -Ei 'PHP (Fatal error|Parse error)|Uncaught (Error|Exception)|Allowed memory size.*exhausted' \
  "${QA_ARTIFACT_DIR}/app-runtime.log"; then
  echo "Fatal PHP runtime errors were detected during QA." >&2
  exit 1
fi

if [[ -s "${QA_ARTIFACT_DIR}/mail-attempts.log" ]]; then
  echo "Email isolation failed: application code attempted to invoke PHP mail." >&2
  exit 1
fi

mail_total="$(curl --fail --silent --show-error "http://127.0.0.1:${MAILPIT_HTTP_PORT}/api/v1/messages" \
  | php -r '$data = json_decode(stream_get_contents(STDIN), true); echo (int) ($data["total"] ?? -1);')"
if [[ "${mail_total}" != "0" ]]; then
  echo "Email isolation failed: Mailpit captured ${mail_total} message(s)." >&2
  exit 1
fi
printf '{"mailpit_messages":%s,"status":"passed"}\n' "${mail_total}" \
  > "${QA_ARTIFACT_DIR}/mail-isolation.json"

SOURCE_STATE_AFTER="$(source_state)"
if [[ "${SOURCE_STATE_BEFORE}" != "${SOURCE_STATE_AFTER}" ]]; then
  echo "The QA run modified tracked extension source files." >&2
  git -C "${EXTENSION_ROOT}" status --short >&2 || true
  exit 1
fi

touch "${QA_ARTIFACT_DIR}/READY"
echo "Standalone integration suite passed."
