#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_INPUT="${1:-$ROOT/dist}"
mkdir -p "$DIST_INPUT"
DIST_DIR="$(cd "$DIST_INPUT" && pwd)"

for command_name in php composer zip unzip; do
  if ! command -v "$command_name" >/dev/null 2>&1; then
    echo "ERROR: required release-build command is missing: $command_name" >&2
    exit 1
  fi
done

if [ ! -f "$ROOT/composer.lock" ]; then
  echo "ERROR: composer.lock is required for a reproducible release package." >&2
  exit 1
fi
if [ ! -f "$ROOT/composer.json" ]; then
  echo "ERROR: composer.json is required to build bundled runtime dependencies." >&2
  exit 1
fi

VERSION="$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$ROOT/info.xml" | head -n 1)"
if [ -z "$VERSION" ]; then
  echo "ERROR: could not read the extension version from info.xml." >&2
  exit 1
fi

TMP="$(mktemp -d "${TMPDIR:-/tmp}/civicfg-release.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT INT TERM
PACKAGE_ROOT="$TMP/civi.config.manager"
mkdir -p "$PACKAGE_ROOT"

# Production package allowlist. Anything not listed here is development/source
# material and must never appear in the installable release ZIP.
RUNTIME_DIRS=(
  CRM
  Civi
  bin
  css
  js
  settings
  templates
  xml
)
RUNTIME_FILES=(
  configmanager.php
  info.xml
)

for path in "${RUNTIME_DIRS[@]}"; do
  if [ ! -d "$ROOT/$path" ]; then
    echo "ERROR: required runtime directory is missing: $path" >&2
    exit 1
  fi
  cp -a "$ROOT/$path" "$PACKAGE_ROOT/$path"
done

for path in "${RUNTIME_FILES[@]}"; do
  if [ ! -f "$ROOT/$path" ]; then
    echo "ERROR: required runtime file is missing: $path" >&2
    exit 1
  fi
  cp -a "$ROOT/$path" "$PACKAGE_ROOT/$path"
done

# Composer metadata is copied only into the temporary build tree so Composer
# can reproduce the locked production dependencies. It is removed before ZIP.
cp "$ROOT/composer.json" "$ROOT/composer.lock" "$PACKAGE_ROOT/"

(
  cd "$PACKAGE_ROOT"
  composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --classmap-authoritative

  test -f vendor/autoload.php
  php -r '
    require "vendor/autoload.php";
    if (!class_exists("Symfony\\Component\\Yaml\\Yaml")) {
      fwrite(STDERR, "Bundled Symfony YAML runtime is missing.\n");
      exit(1);
    }
    $yaml = Symfony\Component\Yaml\Yaml::dump(["probe" => ["multiline" => "one\ntwo"]], 8, 2);
    $roundTrip = Symfony\Component\Yaml\Yaml::parse($yaml);
    if (($roundTrip["probe"]["multiline"] ?? NULL) !== "one\ntwo") {
      fwrite(STDERR, "Bundled Symfony YAML round-trip check failed.\n");
      exit(1);
    }
  '

  # Development Composer metadata is not needed at runtime because the
  # extension carries its production vendor tree and its own CiviCRM loader.
  rm -f composer.json composer.lock
)

# Refuse to ship source/QA/build material even if the allowlist changes later.
FORBIDDEN_TOP_LEVEL=(
  .git
  .github
  .gitignore
  .gitattributes
  CHANGELOG.md
  README.md
  composer.json
  composer.lock
  docs
  dist
  node_modules
  package.json
  phpcs.xml.dist
  phpstan.neon.dist
  phpunit.xml.dist
  playwright.config.js
  scripts
  tests
)
for path in "${FORBIDDEN_TOP_LEVEL[@]}"; do
  if [ -e "$PACKAGE_ROOT/$path" ]; then
    echo "ERROR: development-only path leaked into release package: $path" >&2
    exit 1
  fi
done

# Explicitly reject common generated/log/cache artifacts anywhere in package.
if find "$PACKAGE_ROOT" \
  \( -name '*.log' -o -name '.DS_Store' -o -name '.phpstan-cache' -o -name 'coverage' -o -name '.phpunit.result.cache' \) \
  -print -quit | grep -q .; then
  echo "ERROR: generated/log/cache material leaked into release package." >&2
  find "$PACKAGE_ROOT" \
    \( -name '*.log' -o -name '.DS_Store' -o -name '.phpstan-cache' -o -name 'coverage' -o -name '.phpunit.result.cache' \) \
    -print >&2
  exit 1
fi

ZIP="$DIST_DIR/civi.config.manager-$VERSION.zip"
rm -f "$ZIP" "$ZIP.sha256"
(
  cd "$TMP"
  zip -qr "$ZIP" civi.config.manager
)

# Validate the archive itself, not only the staging tree.
unzip -tqq "$ZIP"
ARCHIVE_LIST="$TMP/archive-list.txt"
unzip -Z1 "$ZIP" > "$ARCHIVE_LIST"

for forbidden in \
  'civi.config.manager/.github/' \
  'civi.config.manager/docs/' \
  'civi.config.manager/scripts/' \
  'civi.config.manager/tests/' \
  'civi.config.manager/node_modules/' \
  'civi.config.manager/CHANGELOG.md' \
  'civi.config.manager/README.md' \
  'civi.config.manager/composer.json' \
  'civi.config.manager/composer.lock' \
  'civi.config.manager/package.json' \
  'civi.config.manager/phpcs.xml.dist' \
  'civi.config.manager/phpstan.neon.dist' \
  'civi.config.manager/phpunit.xml.dist' \
  'civi.config.manager/playwright.config.js'; do
  if grep -Fq "$forbidden" "$ARCHIVE_LIST"; then
    echo "ERROR: forbidden development path present in final ZIP: $forbidden" >&2
    exit 1
  fi
done

for required in \
  'civi.config.manager/info.xml' \
  'civi.config.manager/configmanager.php' \
  'civi.config.manager/bin/civicfg' \
  'civi.config.manager/vendor/autoload.php'; do
  if ! grep -Fxq "$required" "$ARCHIVE_LIST"; then
    echo "ERROR: required runtime file missing from final ZIP: $required" >&2
    exit 1
  fi
done

if command -v sha256sum >/dev/null 2>&1; then
  sha256sum "$ZIP" > "$ZIP.sha256"
else
  shasum -a 256 "$ZIP" > "$ZIP.sha256"
fi

printf 'Production runtime release: %s\n' "$ZIP"
printf 'Checksum: %s\n' "$ZIP.sha256"
printf 'Release contains runtime code/assets + production vendor dependencies only.\n'
