#!/usr/bin/env bash
# CodeRabbit full-project audit -- block 01: app/Actions/* (business mutation classes)
# 16 review(s), generated from base commit f8d765aca3e3e789e542a2eb92e573ece77ca098
set -uo pipefail
cd "$(git rev-parse --show-toplevel)"

BASE="f8d765aca3e3e789e542a2eb92e573ece77ca098"
OUT="coderabbit-review/results"

run() {
  local dir="$1"
  local out="$OUT/$dir.md"
  mkdir -p "$(dirname "$out")"
  echo "==> [$dir]"
  coderabbit review --agent --type committed --base-commit "$BASE" --dir "$dir" \
    --config AGENTS.md project-audit.md 2>&1 | tee "$out"
  echo
}

run "app/Actions/Auth"  # 9 files
run "app/Actions/Comments"  # 5 files
run "app/Actions/Counters"  # 2 files
run "app/Actions/Follows"  # 4 files
run "app/Actions/Import"  # 2 files
run "app/Actions/Locale"  # 1 files
run "app/Actions/Moderation"  # 9 files
run "app/Actions/Posts"  # 7 files
run "app/Actions/Profile"  # 3 files
run "app/Actions/Ranking"  # 1 files
run "app/Actions/Rating"  # 6 files
run "app/Actions/Reports"  # 3 files
run "app/Actions/Settings"  # 2 files
run "app/Actions/Tags"  # 1 files
run "app/Actions/Users"  # 4 files
run "app/Actions/Votes"  # 4 files
