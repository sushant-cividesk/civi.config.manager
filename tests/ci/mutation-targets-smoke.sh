#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

verify_mutation_target() {
  local script="$1"
  local path="$2"
  local before after

  before="$(sha256sum "${repo_root}/${path}" | cut -d' ' -f1)"
  bash "${repo_root}/tests/ci/${script}" --check-target
  after="$(sha256sum "${repo_root}/${path}" | cut -d' ' -f1)"
  if [[ "${before}" != "${after}" ]]; then
    echo "Mutation target smoke changed source: ${path}" >&2
    exit 1
  fi
}

verify_mutation_target 'mutation-provider-inventory.sh' 'Civi/ConfigManager/Handler/ExtensionProviderInventoryTrait.php'
verify_mutation_target 'mutation-import-plan.sh' 'Civi/ConfigManager/Service/ConfigManagerImportReviewTrait.php'
echo 'Mutation target smoke OK (both extracted concerns restored byte-for-byte).'
