#!/usr/bin/env bash
set -Eeuo pipefail

# Install latest civi.config.manager release and create first YAML baseline.
#
# This script intentionally does only the agreed install/baseline steps:
#   1. Download latest release ZIP from GitHub.
#   2. Install the extension package into the CiviCRM extension directory.
#   3. Enable civi.config.manager.
#   4. Clear caches.
#   5. Confirm API/menu registration.
#   6. Confirm CLI wrapper.
#   7. Run initial validation.
#   8. Run first export to create baseline YAML.
#
# It does NOT:
#   - import config
#   - commit YAML
#   - push to Git
#   - resolve conflicts
#   - send alerts
#   - take DB/files backups
#
# Expected environment variables:
#   CIVICRM_ROOT=/var/www/html/web/wp-content/plugins/civicrm/civicrm/
#   CIVICRM_SETTINGS=/var/www/html/web/wp-content/uploads/civicrm/civicrm.settings.php
#   WEBROOT=/var/www/html/web
#   ENV=prod
#   APP_URL=cividesk.com,cividesk.ca
#   APP=cividesk
#
# Optional:
#   REPORT_BASE=/tmp/civicfg-install
#   CIVICFG_RELEASE_TAG=0.1.0-beta2
#
# Notes:
#   - No GitHub token is required.
#   - By default, the script installs the newest non-draft release asset matching:
#       civi.config.manager-*.zip
#   - If CIVICFG_RELEASE_TAG is set, the script installs that exact release tag.

APP="${APP:-unknown-app}"
ENV="${ENV:-unknown-env}"
WEBROOT="${WEBROOT:-/var/www/html/web}"
CIVICRM_ROOT="${CIVICRM_ROOT:-}"
CIVICRM_SETTINGS="${CIVICRM_SETTINGS:-}"
APP_URL="${APP_URL:-}"

REPO="sushant-cividesk/civi.config.manager"
EXT_KEY="civi.config.manager"
TS="$(date +%Y%m%d-%H%M%S)"

REPORT_BASE="${REPORT_BASE:-/tmp/civicfg-install}"
REPORT_DIR="${REPORT_BASE}/${APP}-${ENV}-${TS}"
TMP_DIR="$(mktemp -d)"

cleanup() {
  rm -rf "$TMP_DIR"
}
trap cleanup EXIT

log() {
  printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"
}

fail() {
  log "ERROR: $*"
  log "Reports saved at: ${REPORT_DIR}"
  exit 1
}

need_cmd() {
  command -v "$1" >/dev/null 2>&1 || fail "Missing required command: $1"
}

run_report() {
  name="$1"
  shift

  log "Running: $*"

  if "$@" > "${REPORT_DIR}/${name}.json" 2> "${REPORT_DIR}/${name}.err"; then
    log "OK: ${name}"
    return 0
  fi

  cat "${REPORT_DIR}/${name}.err" >&2 || true
  fail "Command failed: $*"
}

github_api_get() {
  url="$1"

  curl -fsSL \
    -H "Accept: application/vnd.github+json" \
    "$url"
}

latest_release_asset_info() {
  php -r '
    $releases = json_decode(file_get_contents($argv[1]), true);
    $wantedTag = $argv[2] ?? "";

    if (!is_array($releases)) {
      exit(1);
    }

    foreach ($releases as $release) {
      if (!empty($release["draft"])) {
        continue;
      }

      $tag = $release["tag_name"] ?? "";

      if ($wantedTag !== "" && $tag !== $wantedTag) {
        continue;
      }

      foreach (($release["assets"] ?? []) as $asset) {
        $name = $asset["name"] ?? "";
        $url = $asset["browser_download_url"] ?? "";

        if (preg_match("/^civi\.config\.manager-[0-9].*\.zip$/", $name) && $url) {
          echo $tag . "\n" . $url . "\n" . $name . "\n";
          exit(0);
        }
      }
    }

    exit(1);
  ' "$1" "${CIVICFG_RELEASE_TAG:-}"
}

first_app_url_host() {
  printf '%s' "$APP_URL" \
    | awk -F',' '{print $1}' \
    | sed 's#^https\?://##' \
    | sed 's#/$##'
}

json_sync_dir_from_status() {
  php -r '
    $json = json_decode(file_get_contents($argv[1]), true);
    if (!is_array($json)) {
      exit(1);
    }

    $row = $json[0] ?? $json;
    echo $row["sync_dir"] ?? "";
  ' "$1"
}

log "Starting Configuration Manager install"
log "APP=${APP}"
log "ENV=${ENV}"
log "WEBROOT=${WEBROOT}"
log "CIVICRM_ROOT=${CIVICRM_ROOT}"
log "CIVICRM_SETTINGS=${CIVICRM_SETTINGS}"
log "APP_URL=${APP_URL}"

mkdir -p "$REPORT_DIR"

need_cmd curl
need_cmd unzip
need_cmd php
need_cmd cv

[ -d "$WEBROOT" ] || fail "WEBROOT does not exist: $WEBROOT"

if [ -n "$CIVICRM_ROOT" ]; then
  [ -d "$CIVICRM_ROOT" ] || fail "CIVICRM_ROOT does not exist: $CIVICRM_ROOT"
  export CIVICRM_ROOT
fi

if [ -n "$CIVICRM_SETTINGS" ]; then
  [ -f "$CIVICRM_SETTINGS" ] || fail "CIVICRM_SETTINGS does not exist: $CIVICRM_SETTINGS"
  export CIVICRM_SETTINGS
fi

cd "$WEBROOT"

log "Checking CiviCRM bootstrap"
cv core:check-req >/dev/null || fail "CiviCRM bootstrap check failed"

log "Detecting CiviCRM extension directory"
EXT_DIR="$(cv ev 'echo CRM_Core_Config::singleton()->extensionsDir;' | tr -d '\r')"
EXT_DIR="${EXT_DIR%/}"

[ -n "$EXT_DIR" ] || fail "Could not detect CiviCRM extensions directory"

mkdir -p "$EXT_DIR"

INSTALL_DIR="${EXT_DIR}/${EXT_KEY}"

log "Extension directory: ${EXT_DIR}"
log "Install directory: ${INSTALL_DIR}"

log "Fetching GitHub release metadata"
RELEASE_JSON="${TMP_DIR}/releases.json"

github_api_get "https://api.github.com/repos/${REPO}/releases?per_page=20" > "$RELEASE_JSON"

RELEASE_INFO="$(latest_release_asset_info "$RELEASE_JSON")" || fail "Could not find a matching release ZIP asset"

TAG_NAME="$(printf '%s\n' "$RELEASE_INFO" | sed -n '1p')"
ASSET_URL="$(printf '%s\n' "$RELEASE_INFO" | sed -n '2p')"
ASSET_NAME="$(printf '%s\n' "$RELEASE_INFO" | sed -n '3p')"

[ -n "$TAG_NAME" ] || fail "Could not detect release tag"
[ -n "$ASSET_URL" ] || fail "Could not detect release asset URL"
[ -n "$ASSET_NAME" ] || fail "Could not detect release asset name"

log "Selected release tag: ${TAG_NAME}"
log "Selected release asset: ${ASSET_NAME}"

log "Downloading release asset"
curl -fL "$ASSET_URL" -o "${TMP_DIR}/${EXT_KEY}.zip"

log "Extracting release package"
mkdir -p "${TMP_DIR}/extract"
unzip -q "${TMP_DIR}/${EXT_KEY}.zip" -d "${TMP_DIR}/extract"

SRC_INFO="$(find "${TMP_DIR}/extract" -maxdepth 4 -type f -name 'info.xml' -path "*/${EXT_KEY}/info.xml" -print -quit)"

[ -n "$SRC_INFO" ] || fail "Could not find ${EXT_KEY}/info.xml in release ZIP"

SRC_DIR="$(dirname "$SRC_INFO")"

log "Installing extension package"
if [ -e "$INSTALL_DIR" ] || [ -L "$INSTALL_DIR" ]; then
  BACKUP_DIR="${INSTALL_DIR}.backup-${TS}"
  log "Existing extension found. Moving to: ${BACKUP_DIR}"
  mv "$INSTALL_DIR" "$BACKUP_DIR"
fi

cp -a "$SRC_DIR" "$INSTALL_DIR"

[ -f "${INSTALL_DIR}/info.xml" ] || fail "Installed extension is missing info.xml"
[ -x "${INSTALL_DIR}/bin/civicfg" ] || fail "Installed extension is missing executable bin/civicfg"

log "Installed ${EXT_KEY} ${TAG_NAME}"

log "Enabling extension"
cv ext:enable "$EXT_KEY"

log "Clearing CiviCRM caches"
cv flush

if command -v drush >/dev/null 2>&1; then
  log "Clearing Drupal cache if available"
  drush cr >/dev/null 2>&1 || true
fi

if command -v wp >/dev/null 2>&1; then
  log "Clearing WordPress cache if available"
  wp cache flush --path="$WEBROOT" --allow-root >/dev/null 2>&1 || true
fi

log "Confirming Configuration Manager API registration"
run_report "status-api4" cv api4 ConfigManager.status --out=json

log "Checking menu/page registration"
cv ev '
$matches = [];
$dao = CRM_Core_DAO::executeQuery(
  "SELECT path, title FROM civicrm_menu WHERE path LIKE %1 OR title LIKE %2",
  [
    1 => ["%config%manager%", "String"],
    2 => ["%Configuration Manager%", "String"],
  ]
);
while ($dao->fetch()) {
  $matches[] = $dao->path . " :: " . $dao->title;
}
echo implode(PHP_EOL, $matches);
' > "${REPORT_DIR}/menu-check.txt" || true

if [ -s "${REPORT_DIR}/menu-check.txt" ]; then
  log "Menu/page entry found:"
  cat "${REPORT_DIR}/menu-check.txt"
else
  log "WARNING: Menu entry was not found by DB check. API is available, so continuing."
fi

log "Confirming CLI wrapper"
CIVICFG_BIN=""

if command -v civicfg >/dev/null 2>&1; then
  CIVICFG_BIN="$(command -v civicfg)"
elif [ -x "${INSTALL_DIR}/bin/civicfg" ]; then
  CIVICFG_BIN="${INSTALL_DIR}/bin/civicfg"
fi

[ -n "$CIVICFG_BIN" ] || fail "Could not find civicfg CLI wrapper"

log "Using civicfg: ${CIVICFG_BIN}"

run_report "civicfg-status" "$CIVICFG_BIN" status --json

log "Running initial config validation"
run_report "civicfg-validate" "$CIVICFG_BIN" cval --json

log "Running first export to create baseline YAML config"
run_report "civicfg-export" "$CIVICFG_BIN" ce --write --json

SYNC_DIR="$(json_sync_dir_from_status "${REPORT_DIR}/civicfg-status.json" || true)"

if [ -n "$SYNC_DIR" ] && [ -d "$SYNC_DIR" ]; then
  YAML_COUNT="$(find "$SYNC_DIR" -type f \( -name "*.yml" -o -name "*.yaml" \) | wc -l | tr -d " ")"
  log "Baseline YAML directory: ${SYNC_DIR}"
  log "Baseline YAML file count: ${YAML_COUNT}"
else
  log "WARNING: Could not confirm sync directory from status output"
fi

HOST="$(first_app_url_host)"
if [ -n "$HOST" ]; then
  log "Primary app URL: https://${HOST}"
fi

log "Done."
log "Reports saved at: ${REPORT_DIR}"
