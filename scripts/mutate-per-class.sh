#!/usr/bin/env bash
# Enforce pest --mutate --min=95 per design §11 class (no --covered-only).
# One invocation per class so a weak class cannot hide behind a high overall score.
# Pest's --class is a prefix match and --ignore takes comma-separated filesystem
# paths (repeated --ignore flags keep only the last), so we ignore every other
# target file when mutating one class.
# Never `|| true`.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

# Parallel arrays: class FQCN and its source file.
# Populated as feature PRs land (design §11 mutation targets).
CLASSES=(
  'Qiick\Access\Contracts\ActorContext'
  'Qiick\Access\Laravel\AccessContext'
  'Qiick\Access\Laravel\ContainerActorResolver'
  'Qiick\Access\Laravel\CarriesActorContext'
  'Qiick\Access\Laravel\ActorPayloadStamper'
)

CLASS_FILES=(
  'src/Contracts/ActorContext.php'
  'src/Laravel/AccessContext.php'
  'src/Laravel/ContainerActorResolver.php'
  'src/Laravel/CarriesActorContext.php'
  'src/Laravel/ActorPayloadStamper.php'
)

# Other mutation-target siblings ignored while mutating one class.
SIBLING_FILES=(
  'src/Contracts/ActorKind.php'
  'src/Contracts/ActorResolver.php'
  'src/Contracts/AuthorizationResponder.php'
  'src/Contracts/GroupRoleSource.php'
  'src/Contracts/PermissionVocabulary.php'
  'src/Contracts/RoleCatalog.php'
  'src/Contracts/TeamContext.php'
  'src/Contracts/TwoFactorPolicy.php'
  'src/Contracts/TwoFactorResponder.php'
  'src/Laravel/AccessServiceProvider.php'
)

MIN="${MUTATE_MIN:-95}"

if [[ "${#CLASSES[@]}" -eq 0 ]]; then
  echo "No mutation target classes yet (scaffold). Skipping."
  exit 0
fi

FAILED=0
declare -a TABLE_ROWS=()

for index in "${!CLASSES[@]}"; do
  class="${CLASSES[$index]}"
  target_file="${CLASS_FILES[$index]}"

  echo "::group::mutate ${class}"
  echo "==> mutate --class=${class} (min=${MIN}, no covered-only)"

  ignore_files=()
  for other_index in "${!CLASS_FILES[@]}"; do
    other_file="${CLASS_FILES[$other_index]}"
    if [[ "$other_file" != "$target_file" ]]; then
      ignore_files+=("${other_file}")
    fi
  done
  for sibling in "${SIBLING_FILES[@]}"; do
    ignore_files+=("${sibling}")
  done
  ignore_csv="$(IFS=','; echo "${ignore_files[*]}")"

  output="$(mktemp)"
  set +e
  vendor/bin/pest --mutate --min="${MIN}" --class="${class}" --ignore="${ignore_csv}" | tee "${output}"
  status=${PIPESTATUS[0]}
  set -e

  # Strip ANSI before parsing — pest colours the Score line.
  score="$(sed $'s/\x1b\\[[0-9;]*m//g' "${output}" | grep -oE 'Score:[[:space:]]+[0-9.]+%' | tail -1 | grep -oE '[0-9.]+' | head -1 || true)"
  if [[ -z "${score}" ]]; then
    score='?'
  fi
  TABLE_ROWS+=("| \`${class}\` | ${score}% |")

  if [[ "${status}" -ne 0 ]]; then
    echo "::error::Mutation score below ${MIN}% for ${class}"
    FAILED=1
  fi
  rm -f "${output}"
  echo "::endgroup::"
done

echo "## Per-class mutation scores"
echo "| Class | Score |"
echo "| --- | --- |"
for row in "${TABLE_ROWS[@]}"; do
  echo "${row}"
done

if [[ "${FAILED}" -ne 0 ]]; then
  echo "One or more classes scored below ${MIN}%."
  exit 1
fi

echo "All ${#CLASSES[@]} classes scored >= ${MIN}%."
