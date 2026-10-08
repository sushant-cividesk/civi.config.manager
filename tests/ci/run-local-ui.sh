#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${ROOT}"

if ! command -v docker >/dev/null 2>&1; then
  echo "Docker is required for the isolated CiviCRM stack. If you are inside ddev ssh, exit to the host first. CiviCRM Buildkit is not required." >&2
  exit 1
fi
if command -v npm >/dev/null 2>&1; then
  if [[ ! -d node_modules/@playwright/test ]]; then
    npm install
  fi
  npx playwright install chromium
else
  echo "npm/Node.js was not found on the host; JavaScript Playwright will run in the pinned Docker browser image instead."
fi
RUN_UI_TESTS=true tests/ci/run-standalone.sh
