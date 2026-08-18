#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${ROOT}"

if ! command -v docker >/dev/null 2>&1; then
  echo "Docker is required for the isolated CiviCRM stack. CiviCRM Buildkit is not required." >&2
  exit 1
fi
if ! command -v npm >/dev/null 2>&1; then
  echo "npm/Node.js is required for Playwright UI tests." >&2
  exit 1
fi

if [[ ! -d node_modules/@playwright/test ]]; then
  npm install
fi
npx playwright install chromium
RUN_UI_TESTS=true tests/ci/run-standalone.sh
