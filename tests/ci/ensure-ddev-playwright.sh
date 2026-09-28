#!/usr/bin/env bash
set -euo pipefail

DDEV_ROOT="${CIVICFG_DDEV_EXTENSION_ROOT:-/var/www/html/ext/civi.config.manager}"

if ! command -v ddev >/dev/null 2>&1; then
  echo "ddev is required for DDEV browser QA." >&2
  exit 2
fi

printf -v q_root '%q' "${DDEV_ROOT}"

echo "Ensuring Playwright system dependencies in the current DDEV container..."
ddev exec -u root bash -lc "cd ${q_root} && ./node_modules/.bin/playwright install-deps chromium"

echo "Ensuring the repository-pinned Chromium build is installed for the DDEV web user..."
ddev exec bash -lc "cd ${q_root} && ./node_modules/.bin/playwright install chromium"

echo "DDEV Playwright prerequisites are ready."
