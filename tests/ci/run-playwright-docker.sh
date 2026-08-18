#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ARTIFACT_DIR="${QA_ARTIFACT_DIR:-${ROOT}/tests/ci/artifacts}"
HTTP_PORT="${1:-8760}"
ADMIN_USER="${2:-admin}"
ADMIN_PASS="${3:-qa-admin-password}"
PLAYWRIGHT_IMAGE="${PLAYWRIGHT_IMAGE:-mcr.microsoft.com/playwright:v1.61.1-noble}"

mkdir -p "${ARTIFACT_DIR}"

docker run --rm \
  --network "${COMPOSE_PROJECT_NAME:-civicfgqa-local-1}_isolated" \
  -v "${ROOT}:/source:ro" \
  -v "${ARTIFACT_DIR}:/qa-artifacts" \
  -e CIVICFG_BASE_URL="http://app" \
  -e CIVICFG_CANONICAL_BASE_URL="http://127.0.0.1:${HTTP_PORT}" \
  -e CIVICRM_ADMIN_USER="${ADMIN_USER}" \
  -e CIVICRM_ADMIN_PASS="${ADMIN_PASS}" \
  -e QA_ARTIFACT_DIR=/qa-artifacts \
  "${PLAYWRIGHT_IMAGE}" \
  bash -lc '
    set -euo pipefail
    rm -rf /tmp/civicfg-ui
    mkdir -p /tmp/civicfg-ui/tests/ci
    cp /source/package.json /source/playwright.config.js /tmp/civicfg-ui/
    cp -R /source/tests/playwright /tmp/civicfg-ui/tests/playwright
    ln -s /qa-artifacts /tmp/civicfg-ui/tests/ci/artifacts
    cd /tmp/civicfg-ui
    npm install --no-package-lock --no-audit --no-fund
    npm run test:ui
  '
