#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
EXTENSION_ROOT="${EXTENSION_ROOT:-$(cd "${SCRIPT_DIR}/../.." && pwd)}"
FIXTURE_EXTENSION_DIR="${FIXTURE_EXTENSION_DIR:-${EXTENSION_ROOT}/tests/ci/artifacts/fixture-extensions}"
ALLOW_MISSING="${CIVICFG_ALLOW_MISSING_FIXTURE_EXTENSIONS:-false}"

mkdir -p "${FIXTURE_EXTENSION_DIR}"

fetch_git_extension() {
  local key="$1"
  local url="$2"
  local ref="${3:-}"
  local target="${FIXTURE_EXTENSION_DIR}/${key}"

  if [[ -f "${target}/info.xml" ]]; then
    echo "Fixture extension already present: ${key}"
    return 0
  fi

  rm -rf "${target}.tmp" "${target}"
  echo "Fetching fixture extension ${key} from ${url} ${ref}"
  if [[ -n "${ref}" ]]; then
    if git clone --depth 1 --branch "${ref}" "${url}" "${target}.tmp"; then
      mv "${target}.tmp" "${target}"
      return 0
    fi
    rm -rf "${target}.tmp"
  fi

  if git clone --depth 1 "${url}" "${target}.tmp"; then
    mv "${target}.tmp" "${target}"
    return 0
  fi

  rm -rf "${target}.tmp"
  if [[ "${ALLOW_MISSING}" == "true" ]]; then
    echo "WARNING: Could not fetch optional fixture extension ${key}." >&2
    return 0
  fi
  echo "ERROR: Could not fetch required fixture extension ${key}." >&2
  return 1
}

# These are intentionally fetched on the GitHub runner/host. The CiviCRM
# container stays on an internal Docker network so the application under test
# cannot reach the public internet during QA.
fetch_git_extension "uk.co.vedaconsulting.mosaico" "${MOSAICO_REPO:-https://github.com/veda-consulting/uk.co.vedaconsulting.mosaico.git}" "${MOSAICO_REF:-}"
fetch_git_extension "de.systopia.sqltasks" "${SQLTASKS_REPO:-https://github.com/systopia/de.systopia.sqltasks.git}" "${SQLTASKS_REF:-}"
fetch_git_extension "org.civicrm.contactlayout" "${CONTACTLAYOUT_REPO:-https://github.com/civicrm/org.civicrm.contactlayout.git}" "${CONTACTLAYOUT_REF:-}"
fetch_git_extension "org.civicoop.civirules" "${CIVIRULES_REPO:-https://lab.civicrm.org/extensions/civirules.git}" "${CIVIRULES_REF:-}"

find "${FIXTURE_EXTENSION_DIR}" -maxdepth 2 -name info.xml -print | sort
