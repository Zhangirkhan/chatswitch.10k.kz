#!/usr/bin/env bash
# Cursor stop hook: commit any remaining workspace changes the agent left uncommitted.
set -euo pipefail

# Required by hook protocol — consume stdin JSON.
if ! [ -t 0 ]; then
  cat >/dev/null || true
fi

ROOT="$(git rev-parse --show-toplevel 2>/dev/null)" || exit 0
cd "$ROOT" || exit 0

git rev-parse --git-dir >/dev/null 2>&1 || exit 0

should_exclude() {
  case "$1" in
    .cursor/debug*.log|.cursor/debug-*|.cursor/*.log)
      return 0
      ;;
  esac
  return 1
}

unstage_excluded() {
  local path
  while IFS= read -r path; do
    [ -n "$path" ] || continue
    if should_exclude "$path"; then
      git reset -q -- "$path" 2>/dev/null || true
    fi
  done < <(git diff --cached --name-only 2>/dev/null || true)
}

has_worktree_changes() {
  ! git diff --quiet 2>/dev/null && return 0
  ! git diff --cached --quiet 2>/dev/null && return 0
  [ -n "$(git ls-files --others --exclude-standard 2>/dev/null)" ] && return 0
  return 1
}

if ! has_worktree_changes; then
  exit 0
fi

git add -A
unstage_excluded

if git diff --cached --quiet; then
  exit 0
fi

mapfile -t staged < <(git diff --cached --name-only)
count=${#staged[@]}
if [ "$count" -le 3 ]; then
  summary=$(printf '%s, ' "${staged[@]}")
  summary="${summary%, }"
else
  summary="${staged[0]}, ${staged[1]} (+$((count - 2)) more)"
fi

git commit -m "$(cat <<EOF
Auto-commit remaining agent changes: ${summary}

EOF
)"

exit 0
