#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${ROOT}"

bad=()
while IFS= read -r path; do
  [[ -z "$path" ]] || bad+=("$path")
done < <(find . \
  -path './.git' -prune -o \
  -path './vendor' -prune -o \
  -path './node_modules' -prune -o \
  \( -name '.DS_Store' -o -name '__MACOSX' -o -path './tests/browser-php' -o -name '.phpunit.result.cache' -o -name '.phpunit.cache' \) \
  -print)

if [[ ${#bad[@]} -gt 0 ]]; then
  echo 'Generated/source-package debris detected:' >&2
  printf '  %s\n' "${bad[@]}" >&2
  exit 1
fi

echo 'Source hygiene OK.'
