#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${ROOT}"

if [[ -d "${ROOT}/tests/browser-php/vendor" ]]; then
  cat >&2 <<'MSG'
Legacy tests/browser-php/vendor exists from the old manual workflow.
Preview it with: ./bin/civicfg qa-browser-clean
Remove it explicitly with: ./bin/civicfg qa-browser-clean --yes
MSG
  exit 2
fi

if ! command -v docker >/dev/null 2>&1; then
  cat >&2 <<'MSG'
Docker is required for self-contained Playwright-PHP QA.
Run this command from the host repository checkout, not from inside ddev ssh.
The test creates its own disposable CiviCRM stack; no existing CiviCRM site is required.
MSG
  exit 2
fi

RUN_UI_TESTS=false \
RUN_JS_UI_TESTS=false \
RUN_PHP_UI_TESTS=true \
  bash tests/ci/run-standalone.sh
