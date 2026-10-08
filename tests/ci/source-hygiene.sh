#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${ROOT}"

# A source ZIP has no .git directory, so every .DS_Store in an extracted ZIP
# remains a hard failure. In a Git checkout, Finder may create ignored,
# untracked .DS_Store files after extraction. They are not part of the source
# tracked by Git and must not stop functional QA. The local-QA allowance is
# opt-in; strict direct hygiene and release packaging still reject every such
# file. Never delete user files.
git_root=''
if command -v git >/dev/null 2>&1; then
  git_root="$(git rev-parse --show-toplevel 2>/dev/null || true)"
fi
if [[ -n "$git_root" ]]; then
  git_root="$(cd "$git_root" && pwd -P)"
  source_root="$(pwd -P)"
  # A Git repository may contain the extension as a subdirectory. A containing
  # worktree is still safe when the file itself is explicitly Git-ignored.
  case "$source_root" in
    "$git_root"|"$git_root"/*) ;;
    *) git_root='' ;;
  esac
fi

bad=()
local_finder_files=()
while IFS= read -r -d '' path; do
  if [[ "${CIVICFG_LOCAL_QA:-0}" == '1' && -n "$git_root" && "${path##*/}" == '.DS_Store' && -f "$path" && ! -L "$path" ]] \
    && git check-ignore -q -- "$path" \
    && ! git ls-files --error-unmatch -- "$path" >/dev/null 2>&1; then
    local_finder_files+=("$path")
  else
    bad+=("$path")
  fi
done < <(find . \
  -path './.git' -prune -o \
  -path './vendor' -prune -o \
  -path './node_modules' -prune -o \
  \( -name '.DS_Store' -o -name '__MACOSX' -o -path './tests/browser-php' -o -name '.phpunit.result.cache' -o -name '.phpunit.cache' \) \
  -print0)

if (( ${#bad[@]} > 0 )); then
  echo 'Generated/source-package debris detected:' >&2
  printf '  %s\n' "${bad[@]}" >&2
  echo 'Remove only the listed generated files. The release builder rejects them too.' >&2
  exit 1
fi

if (( ${#local_finder_files[@]} > 0 )); then
  echo "Source hygiene: ignoring ${#local_finder_files[@]} untracked, Git-ignored local Finder metadata file(s)."
  echo 'They remain on disk; tracked metadata and debris inside source ZIPs are rejected.'
fi

# A source ZIP must preserve the executable bits of every shipped shell tool.
# Missing modes cause downstream `Permission denied` failures even when the
# PHP and shell logic is sound. The CLI launcher has the same requirement.
missing_executable=()
if [[ ! -x bin/civicfg ]]; then
  missing_executable+=(bin/civicfg)
fi
while IFS= read -r -d '' script; do
  if [[ ! -x "$script" ]]; then
    missing_executable+=("${script#./}")
  fi
done < <(find scripts tests/ci -type f -name '*.sh' -print0)

if (( ${#missing_executable[@]} > 0 )); then
  echo 'Source-package error: executable permission bits are missing:' >&2
  printf '  %s\n' "${missing_executable[@]}" >&2
  echo 'Re-extract an archive that preserves Unix executable permissions.' >&2
  echo 'Do not mark this as a Composer dependency or CiviCRM application failure.' >&2
  exit 1
fi

echo 'Source hygiene OK (including executable permissions).'
