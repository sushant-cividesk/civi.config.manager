#!/usr/bin/env bash
set -euo pipefail

mapfile -d '' files < <(find CRM Civi settings tests -type f -name '*.php' -print0; printf '%s\0' configmanager.php)
for file in "${files[@]}"; do
  php -l "$file" >/dev/null
done

echo "PHP syntax valid for ${#files[@]} files."
