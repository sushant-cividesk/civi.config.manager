#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
EXTENSION_ROOT="${EXTENSION_ROOT:-$(cd "${SCRIPT_DIR}/../.." && pwd)}"
QA_ARTIFACT_DIR="${QA_ARTIFACT_DIR:-${EXTENSION_ROOT}/tests/ci/artifacts}"
PHP_QA_INI="${PHP_QA_INI:-${SCRIPT_DIR}/php-qa.ini}"
FIXTURE_EXTENSION_DIR="${FIXTURE_EXTENSION_DIR:-${QA_ARTIFACT_DIR}/fixture-extensions}"
COMPOSE_FILE="${SCRIPT_DIR}/compose.standalone.yml"
CIVICRM_HTTP_PORT="${CIVICRM_HTTP_PORT:-8760}"
CIVICFG_QA_RUN_ID="${CIVICFG_QA_RUN_ID:-github-${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-1}}"
COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-civicfgqa-${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-1}}"
CURRENT_QA_STAGE="bootstrap"

export EXTENSION_ROOT QA_ARTIFACT_DIR PHP_QA_INI FIXTURE_EXTENSION_DIR CIVICRM_HTTP_PORT
export CIVICFG_QA_RUN_ID COMPOSE_PROJECT_NAME

if ! command -v docker >/dev/null 2>&1; then
  echo "Docker is required for standalone Configuration Manager QA." >&2
  echo "If you are inside 'ddev ssh' or another container, exit to the host and run this command from the host repository checkout." >&2
  echo "CiviCRM Buildkit is not required; this suite starts its own isolated CiviCRM stack with Docker Compose." >&2
  exit 2
fi
if ! docker compose version >/dev/null 2>&1; then
  echo "Docker Compose v2 ('docker compose') is required for standalone Configuration Manager QA." >&2
  exit 2
fi
if ! docker info >/dev/null 2>&1; then
  echo "Docker is installed, but the Docker daemon is not available." >&2
  echo "Start Docker on the host, then rerun the QA command." >&2
  exit 2
fi

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
    -path "${EXTENSION_ROOT}/tests/browser-php/vendor" -prune -o \
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
    printf 'FAILED QA STAGE: %s\n' "${CURRENT_QA_STAGE}" | tee "${QA_ARTIFACT_DIR}/FAILED_STAGE.txt" >&2
  fi
  exit "${exit_code}"
}
trap collect_diagnostics EXIT

qa_stage() {
  CURRENT_QA_STAGE="$1"
  printf '%s\n' "${CURRENT_QA_STAGE}" > "${QA_ARTIFACT_DIR}/current-stage.txt"
  printf '\n============================================================\n'
  printf 'QA STAGE: %s\n' "${CURRENT_QA_STAGE}"
  printf '============================================================\n'
}

wait_for_app_http() {
  local attempts="${1:-90}"
  local delay="${2:-2}"
  local count

  # The application deliberately runs only on an internal Docker network.
  # A host-side curl to a published port is therefore not a reliable readiness
  # check on all Docker implementations/runners. Probe Apache from inside the
  # app container instead; this verifies the service we actually use for the
  # CLI/integration tests without weakening outbound isolation.
  for count in $(seq 1 "${attempts}"); do
    if compose exec -T app php -r '
      $errno = 0;
      $errstr = "";
      $socket = @fsockopen("127.0.0.1", 80, $errno, $errstr, 2.0);
      if (!is_resource($socket)) {
        exit(1);
      }
      stream_set_timeout($socket, 2);
      fwrite($socket, "GET / HTTP/1.0\r\nHost: localhost\r\nConnection: close\r\n\r\n");
      $status = fgets($socket);
      fclose($socket);
      if (!is_string($status) || !preg_match("~^HTTP/[0-9.]+ [1-5][0-9][0-9]~", trim($status))) {
        exit(1);
      }
    ' >/dev/null 2>&1; then
      return 0
    fi
    sleep "${delay}"
  done

  echo "Timed out waiting for the CiviCRM HTTP service inside the app container." >&2
  echo "Container status:" >&2
  compose ps >&2 || true
  echo "Recent app logs:" >&2
  compose logs --no-color --tail=120 app >&2 || true
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
  qa_stage "Fetch pinned real extension fixtures"
  "${SCRIPT_DIR}/fetch-fixture-extensions.sh" | tee "${QA_ARTIFACT_DIR}/fixture-extension-fetch.log"
fi

qa_stage "Pull and start isolated CiviCRM stack"
compose pull
compose up -d

qa_stage "Install isolated CiviCRM"
compose exec -T -u www-data \
  -e CIVICRM_ADMIN_USER="${CIVICRM_ADMIN_USER:-admin}" \
  -e CIVICRM_ADMIN_PASS="${CIVICRM_ADMIN_PASS:-qa-admin-password}" \
  app civicrm-docker-install

wait_for_app_http

qa_stage "Verify extension lifecycle"
qa_stage "Run API and CLI smoke tests"
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
  command -v civicfg
  test "$(command -v civicfg)" = "/var/www/html/.local/bin/civicfg"
  civicfg status --json
' | tee "${QA_ARTIFACT_DIR}/extension-lifecycle.log"

if [[ "${RUN_REAL_EXTENSION_FIXTURES:-true}" == "true" ]]; then
  qa_stage "Install pinned real extension fixtures"
  compose exec -T -u www-data \
    -e FIXTURE_EXTENSION_DIR=/var/www/html/ext-fixtures \
    -e CIVICFG_QA_FIXTURE_EXTENSION_KEYS="${CIVICFG_QA_FIXTURE_EXTENSION_KEYS:-uk.co.vedaconsulting.mosaico de.systopia.sqltasks org.civicrm.contactlayout org.civicoop.civirules}" \
    -e CIVICFG_ALLOW_MISSING_FIXTURE_EXTENSIONS="${CIVICFG_ALLOW_MISSING_FIXTURE_EXTENSIONS:-false}" \
    app bash /var/www/html/ext/civi.config.manager/tests/ci/install-fixture-extensions.sh \
    | tee "${QA_ARTIFACT_DIR}/fixture-extension-install.log"
fi

qa_stage "Prove import blockers prevent real CiviCRM writes"
compose exec -T -u www-data \
  -e CIVICFG_QA_RUN_ID="${CIVICFG_QA_RUN_ID}" \
  -e CIVICFG_QA_ROOT=/tmp/civicfg-qa \
  -e CIVICFG_QA_ARTIFACTS=/qa-artifacts \
  app cv scr /var/www/html/ext/civi.config.manager/tests/integration/ImportBlockerSafety.php \
  | tee "${QA_ARTIFACT_DIR}/import-blocker-safety.log"

qa_stage "Run standalone round-trip integration"
compose exec -T -u www-data \
  -e CIVICFG_QA_RUN_ID="${CIVICFG_QA_RUN_ID}" \
  -e CIVICFG_QA_ROOT=/tmp/civicfg-qa \
  -e CIVICFG_QA_ARTIFACTS=/qa-artifacts \
  app cv scr /var/www/html/ext/civi.config.manager/tests/integration/StandaloneRoundTrip.php \
  | tee "${QA_ARTIFACT_DIR}/standalone-round-trip.log"

if [[ "${RUN_FULL_REAL_FIXTURE_SUITE:-true}" == "true" ]]; then
  qa_stage "Run full real-fixture integration"
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
  cv api4 ConfigManager.providerInventory --out=json
  cv api4 ConfigManager.validate --out=json
  /var/www/html/ext/civi.config.manager/bin/civicfg validate
' | tee "${QA_ARTIFACT_DIR}/api-cli-smoke.log"

qa_stage "Check application runtime logs"
compose logs --no-color app > "${QA_ARTIFACT_DIR}/app-runtime.log"
if grep -E 'PHP (Fatal error|Parse error)|Uncaught (Error|Exception)|Allowed memory size.*exhausted' \
  "${QA_ARTIFACT_DIR}/app-runtime.log"; then
  echo "Fatal PHP runtime errors were detected during QA." >&2
  exit 1
fi

if [[ "${RUN_UI_TESTS:-false}" == "true" ]]; then
  qa_stage "Run Playwright UI tests and screenshots"
  compose exec -T -u www-data \
    -e CIVICFG_QA_RUN_ID="${CIVICFG_QA_RUN_ID}" \
    -e CIVICFG_QA_ARTIFACTS=/qa-artifacts \
    app cv scr /var/www/html/ext/civi.config.manager/tests/integration/UiFixture.php seed \
    | tee "${QA_ARTIFACT_DIR}/ui-fixture-seed.log"

  if command -v npm >/dev/null 2>&1; then
    CIVICFG_BASE_URL="http://127.0.0.1:${CIVICRM_HTTP_PORT}" \
    CIVICRM_ADMIN_USER="${CIVICRM_ADMIN_USER:-admin}" \
    CIVICRM_ADMIN_PASS="${CIVICRM_ADMIN_PASS:-qa-admin-password}" \
    QA_ARTIFACT_DIR="${QA_ARTIFACT_DIR}" \
    npm run test:ui
  else
    "${SCRIPT_DIR}/run-playwright-docker.sh" \
      "${CIVICRM_HTTP_PORT}" \
      "${CIVICRM_ADMIN_USER:-admin}" \
      "${CIVICRM_ADMIN_PASS:-qa-admin-password}"
  fi

  if [[ "${RUN_PHP_UI_TESTS:-false}" == "true" ]]; then
    qa_stage "Run isolated Playwright PHP black-box UI test"
    if [[ ! -x "${EXTENSION_ROOT}/tests/browser-php/vendor/bin/phpunit" ]]; then
      echo "PHP Playwright QA requested but tests/browser-php dependencies are not installed." >&2
      exit 1
    fi
    CIVICFG_BASE_URL="http://127.0.0.1:${CIVICRM_HTTP_PORT}" \
    CIVICRM_ADMIN_USER="${CIVICRM_ADMIN_USER:-admin}" \
    CIVICRM_ADMIN_PASS="${CIVICRM_ADMIN_PASS:-qa-admin-password}" \
    QA_ARTIFACT_DIR="${QA_ARTIFACT_DIR}" \
      "${EXTENSION_ROOT}/tests/browser-php/vendor/bin/phpunit" \
        -c "${EXTENSION_ROOT}/tests/browser-php/phpunit.xml.dist" \
      | tee "${QA_ARTIFACT_DIR}/playwright-php.log"
  fi

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

qa_stage "Verify outbound isolation and source immutability"
if [[ -s "${QA_ARTIFACT_DIR}/mail-attempts.log" ]]; then
  echo "Email isolation failed: application code attempted to invoke PHP mail." >&2
  exit 1
fi

# The QA application is attached only to an internal Docker network. Prove
# that direct TCP egress is unavailable as well, so SMTP/HTTP cannot escape
# even if application code bypasses PHP mail().
if compose exec -T app php -r '
  $errno = 0;
  $errstr = "";
  $socket = @fsockopen("1.1.1.1", 443, $errno, $errstr, 2.0);
  if (is_resource($socket)) {
    fclose($socket);
    exit(0);
  }
  exit(1);
'; then
  echo "Network isolation failed: QA application can reach the public internet." >&2
  exit 1
fi
printf '{"php_mail_attempts":0,"external_network":"blocked","status":"passed"}\n' \
  > "${QA_ARTIFACT_DIR}/mail-isolation.json"

SOURCE_STATE_AFTER="$(source_state)"
if [[ "${SOURCE_STATE_BEFORE}" != "${SOURCE_STATE_AFTER}" ]]; then
  echo "The QA run modified tracked extension source files." >&2
  git -C "${EXTENSION_ROOT}" status --short >&2 || true
  exit 1
fi

touch "${QA_ARTIFACT_DIR}/READY"
qa_stage "PASS - standalone integration suite"
echo "Standalone integration suite passed."
