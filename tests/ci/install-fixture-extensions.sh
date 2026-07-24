#!/usr/bin/env bash
set -euo pipefail

FIXTURE_EXTENSION_DIR="${FIXTURE_EXTENSION_DIR:-/var/www/html/ext-fixtures}"
REQUIRED_KEYS="${CIVICFG_QA_FIXTURE_EXTENSION_KEYS:-uk.co.vedaconsulting.mosaico de.systopia.sqltasks org.civicrm.contactlayout org.civicoop.civirules}"
ALLOW_MISSING="${CIVICFG_ALLOW_MISSING_FIXTURE_EXTENSIONS:-false}"

cd /var/www/html
EXT_DIR="$(cv ev 'echo CRM_Core_Config::singleton()->extensionsDir;')"
mkdir -p "${EXT_DIR}"

if [[ ! -d "${FIXTURE_EXTENSION_DIR}" ]]; then
  if [[ "${ALLOW_MISSING}" == "true" ]]; then
    echo "WARNING: Fixture extension directory does not exist: ${FIXTURE_EXTENSION_DIR}" >&2
    exit 0
  fi
  echo "ERROR: Fixture extension directory does not exist: ${FIXTURE_EXTENSION_DIR}" >&2
  exit 1
fi

installed_keys=()
for source in "${FIXTURE_EXTENSION_DIR}"/*; do
  [[ -d "${source}" && -f "${source}/info.xml" ]] || continue
  key="$(php -r '$xml=simplexml_load_file($argv[1]); if (!$xml) exit(2); echo (string) $xml["key"];' "${source}/info.xml")"
  if [[ -z "${key}" ]]; then
    echo "Skipping extension without info.xml key: ${source}" >&2
    continue
  fi
  target="${EXT_DIR%/}/${key}"
  rm -rf "${target}"
  cp -a "${source}" "${target}"
  installed_keys+=("${key}")
  echo "Copied fixture extension ${key} to ${target}"
done

cv flush
cv ev 'CRM_Extension_System::singleton()->getManager()->refresh();' || true
cv flush

# Enable core dependencies first when available. Some CiviCRM versions ship these
# as core extensions and some do not; optional failures are harmless here.
for key in org.civicrm.flexmailer org.civicrm.search_kit org.civicrm.afform; do
  cv api Extension.install keys="${key}" >/dev/null 2>&1 || cv api Extension.enable keys="${key}" >/dev/null 2>&1 || true
done

for key in ${REQUIRED_KEYS}; do
  if [[ ! -d "${EXT_DIR%/}/${key}" ]]; then
    if [[ "${ALLOW_MISSING}" == "true" ]]; then
      echo "WARNING: Required fixture extension ${key} was not downloaded/copied." >&2
      continue
    fi
    echo "ERROR: Required fixture extension ${key} was not downloaded/copied." >&2
    exit 1
  fi

  echo "Installing fixture extension ${key}"
  if ! cv api Extension.install keys="${key}"; then
    if ! cv api Extension.enable keys="${key}"; then
      if [[ "${ALLOW_MISSING}" == "true" ]]; then
        echo "WARNING: Could not install fixture extension ${key}." >&2
        continue
      fi
      echo "ERROR: Could not install fixture extension ${key}." >&2
      exit 1
    fi
  fi
done

cv updb || true
cv flush
cv api Extension.get return=key,status --out=json
