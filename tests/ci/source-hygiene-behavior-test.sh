#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/civicfg-source-hygiene.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT
FIXTURE="$TMP/project"
mkdir -p "$FIXTURE/tests/ci" "$FIXTURE/scripts" "$FIXTURE/bin"
cp "$ROOT/tests/ci/source-hygiene.sh" "$FIXTURE/tests/ci/source-hygiene.sh"
printf '#!/bin/sh\n' > "$FIXTURE/scripts/check.sh"
printf '#!/bin/sh\n' > "$FIXTURE/bin/civicfg"
chmod 755 "$FIXTURE/tests/ci/source-hygiene.sh" "$FIXTURE/scripts/check.sh" "$FIXTURE/bin/civicfg"
printf '.DS_Store\n' > "$FIXTURE/.gitignore"
# Mirror all six Finder paths from the maintainer's DDEV report.
for dir in . Civi Civi/ConfigManager tests tests/phpunit docs; do
  mkdir -p "$FIXTURE/$dir"
  printf 'Finder view metadata' > "$FIXTURE/$dir/.DS_Store"
done

# A downloaded/source archive without Git metadata must reject included debris.
if (cd "$FIXTURE" && bash tests/ci/source-hygiene.sh >"$TMP/no-git.log" 2>&1); then
  echo 'ERROR: source ZIP with Finder debris was incorrectly accepted.' >&2
  exit 1
fi
grep -Fq 'Generated/source-package debris detected' "$TMP/no-git.log"

# A checkout generated .DS_Store after extraction; it is ignored/untracked
# and must not block QA or be automatically deleted.
git -C "$FIXTURE" init --quiet
git -C "$FIXTURE" add -- .gitignore
# Strict direct invocation still rejects any Finder file, even if ignored.
if (cd "$FIXTURE" && bash tests/ci/source-hygiene.sh >"$TMP/git-strict.log" 2>&1); then
  echo 'ERROR: strict source-archive check incorrectly accepted Finder debris.' >&2
  exit 1
fi
grep -Fq 'Generated/source-package debris detected' "$TMP/git-strict.log"
(cd "$FIXTURE" && CIVICFG_LOCAL_QA=1 bash tests/ci/source-hygiene.sh >"$TMP/ignored.log" 2>&1)
grep -Fq 'ignoring 6 untracked, Git-ignored local Finder metadata' "$TMP/ignored.log"
for dir in . Civi Civi/ConfigManager tests tests/phpunit docs; do
  test -f "$FIXTURE/$dir/.DS_Store"
done

# A tracked/force-added Finder file really would enter a source export: fail.
git -C "$FIXTURE" add -f -- .DS_Store
if (cd "$FIXTURE" && CIVICFG_LOCAL_QA=1 bash tests/ci/source-hygiene.sh >"$TMP/tracked.log" 2>&1); then
  echo 'ERROR: tracked Finder debris was incorrectly accepted.' >&2
  exit 1
fi
grep -Fq 'Generated/source-package debris detected' "$TMP/tracked.log"
git -C "$FIXTURE" rm --cached --quiet -- .DS_Store

# Real packaging debris and lost executable bits remain hard QA failures.
mkdir -p "$FIXTURE/__MACOSX"
if (cd "$FIXTURE" && CIVICFG_LOCAL_QA=1 bash tests/ci/source-hygiene.sh >"$TMP/macosx.log" 2>&1); then
  echo 'ERROR: __MACOSX archive debris was incorrectly accepted.' >&2
  exit 1
fi
grep -Fq 'Generated/source-package debris detected' "$TMP/macosx.log"
rmdir "$FIXTURE/__MACOSX"
chmod 644 "$FIXTURE/bin/civicfg"
if (cd "$FIXTURE" && CIVICFG_LOCAL_QA=1 bash tests/ci/source-hygiene.sh >"$TMP/mode.log" 2>&1); then
  echo 'ERROR: CLI missing executable bit was incorrectly accepted.' >&2
  exit 1
fi
grep -Fq 'bin/civicfg' "$TMP/mode.log"
chmod 755 "$FIXTURE/bin/civicfg"
(cd "$FIXTURE" && CIVICFG_LOCAL_QA=1 bash tests/ci/source-hygiene.sh >"$TMP/clean.log" 2>&1)
grep -Fq 'Source hygiene OK' "$TMP/clean.log"

# Parent Git worktrees that contain the extension are valid DDEV layouts too.
mv "$FIXTURE/.git" "$TMP/.git"
(cd "$FIXTURE" && CIVICFG_LOCAL_QA=1 bash tests/ci/source-hygiene.sh >"$TMP/parent-worktree.log" 2>&1)
grep -Fq 'ignoring 6 untracked, Git-ignored local Finder metadata' "$TMP/parent-worktree.log"

echo 'Source hygiene behavior OK (local and parent Git worktrees, tracked debris, source ZIP, executable permissions).'
