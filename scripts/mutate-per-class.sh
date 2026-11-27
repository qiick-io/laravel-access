#!/usr/bin/env bash
# Per-class mutation gate (1.0 checklist item 3): every class in src/ scores
# at least 95% on its own, so a weak class cannot hide behind a high overall
# score. No --covered-only, never `|| true`.
#
# MUTATE_SCOPE=all (push, nightly) mutates every file in src/, the test
# helpers in src/Testing/ included: products run them as conformance checks.
# MUTATE_SCOPE=changed (pull requests) mutates the files in MUTATE_CLASSES,
# the JSON list the shared package matrix exports. MUTATE_SHARD of
# MUTATE_SHARDS splits the files across parallel jobs.
#
# Only the files listed in DECLARATION_ONLY_FILES (interfaces, plain enums,
# data-only events and exceptions) may yield no mutation; any other file
# with no mutation fails, so a class cannot escape the gate by accident.
# On a pull request the shared plan leaves src/Testing/ out; MUTATE_DIFF_BASE
# (the base commit) adds the test helpers the pull request changed.
set -euo pipefail

cd "$(dirname "$0")/.."

case "${MUTATE_SCOPE:-all}" in
  all)
    files=$(find src -type f -name '*.php' | LC_ALL=C sort)
    ;;
  changed)
    files=$(node -e '
      const files = JSON.parse(process.env.MUTATE_CLASSES ?? "[]");
      if (!Array.isArray(files) || files.some(file => typeof file !== "string" || !/^src\/[\w/]+\.php$/.test(file))) {
        throw new Error("MUTATE_CLASSES must be an array of package source paths");
      }
      for (const file of files) {
        console.log(file);
      }
    ')
    if [[ -n "${MUTATE_DIFF_BASE:-}" ]]; then
      files="$(printf '%s\n%s\n' "${files}" "$(git diff --name-only --diff-filter=ACMR "${MUTATE_DIFF_BASE}...HEAD" -- 'src/Testing/*.php')" | sed '/^$/d' | LC_ALL=C sort -u)"
    fi
    ;;
  *)
    echo "::error::MUTATE_SCOPE must be all or changed."
    exit 1
    ;;
esac

DECLARATION_ONLY_FILES=(
  src/Contracts/ActiveUsers.php
  src/Contracts/ActorKind.php
  src/Contracts/ActorResolver.php
  src/Contracts/ApiActorScopeCheck.php
  src/Contracts/AuthorizationResponder.php
  src/Contracts/GroupRoleSource.php
  src/Contracts/LegacyGateBefore.php
  src/Contracts/PermissionVocabulary.php
  src/Contracts/RoleCatalog.php
  src/Contracts/TeamContext.php
  src/Contracts/TwoFactorPolicy.php
  src/Contracts/TwoFactorResponder.php
  src/Events/PrincipalCreatedEvent.php
  src/Events/PrincipalDeactivatedEvent.php
  src/Events/SubjectErasedEvent.php
  src/Laravel/NoTeamContext.php
  src/Principals/DeactivationReason.php
  src/Principals/PrincipalDirectory.php
  src/Principals/UserHasPrincipalsException.php
)

minimum="${MUTATE_MIN:-95}"
shard="${MUTATE_SHARD:-1}"
shards="${MUTATE_SHARDS:-1}"

if ! [[ "${shard}" =~ ^[0-9]+$ && "${shards}" =~ ^[0-9]+$ ]] || (( shards < 1 || shard < 1 || shard > shards )); then
  echo "::error::MUTATE_SHARD must be between 1 and MUTATE_SHARDS."
  exit 1
fi
position=0
failed=0
summary_rows=()

while IFS= read -r file; do
  # The pull request plan lists deleted paths too; only surviving files are mutated.
  if [[ -z "$file" || ! -f "$file" ]]; then
    continue
  fi

  position=$((position + 1))
  if (( (position - 1) % shards != shard - 1 )); then
    continue
  fi

  echo "::group::Mutate ${file}"
  output="$(mktemp)"
  set +e
  zero_mutations_flag=()
  for declaration_only_file in "${DECLARATION_ONLY_FILES[@]}"; do
    if [[ "${file}" == "${declaration_only_file}" ]]; then
      zero_mutations_flag=(--ignore-min-score-on-zero-mutations)
    fi
  done

  vendor/bin/pest --mutate --min="${minimum}" --path="${file}" "${zero_mutations_flag[@]+"${zero_mutations_flag[@]}"}" --parallel | tee "${output}"
  status=${PIPESTATUS[0]}
  set -e
  echo "::endgroup::"

  # Strip ANSI before parsing: Pest colours the score line.
  plain="$(sed $'s/\x1b\\[[0-9;]*m//g' "${output}")"
  rm -f "${output}"
  score="$(printf '%s\n' "${plain}" | grep -oE 'Score:[[:space:]]+[0-9.]+%' | tail -1 | grep -oE '[0-9.]+' || true)"

  if printf '%s\n' "${plain}" | grep -q 'No mutations created'; then
    score='no mutations'
  fi

  summary_rows+=("| \`${file}\` | ${score:-?} |")

  if [[ "${status}" -ne 0 ]]; then
    echo "::error file=${file}::Mutation score below ${minimum}% (or the run failed) for ${file}"
    failed=1
  fi
done <<< "${files}"

{
  echo "## Per-class mutation scores (shard ${shard}/${shards})"
  echo "| File | Score |"
  echo "| --- | --- |"
  printf '%s\n' "${summary_rows[@]+"${summary_rows[@]}"}"
} | tee -a "${GITHUB_STEP_SUMMARY:-/dev/null}"

exit "${failed}"
