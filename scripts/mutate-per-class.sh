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
# A file with no executable code (an interface, a plain enum) yields no
# mutation and passes through --ignore-min-score-on-zero-mutations; every
# other file must reach the minimum.
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
    ;;
  *)
    echo "::error::MUTATE_SCOPE must be all or changed."
    exit 1
    ;;
esac

minimum="${MUTATE_MIN:-95}"
shard="${MUTATE_SHARD:-1}"
shards="${MUTATE_SHARDS:-1}"
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
  vendor/bin/pest --mutate --min="${minimum}" --path="${file}" --ignore-min-score-on-zero-mutations --parallel | tee "${output}"
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
