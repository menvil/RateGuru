#!/usr/bin/env bash
# CodeRabbit full-project audit -- block 11: tests/Feature/* (part B, alphabetical)
# 17 review(s), generated from base commit f8d765aca3e3e789e542a2eb92e573ece77ca098
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

run "tests/Feature/Observability"  # 16 files
run "tests/Feature/Policies"  # 7 files
run "tests/Feature/Profile"  # 1 files
run "tests/Feature/Queries"  # 8 files
run "tests/Feature/Relationships"  # 10 files
run "tests/Feature/Requests"  # 2 files
run "tests/Feature/Routes"  # 3 files
run "tests/Feature/Scopes"  # 6 files
run "tests/Feature/Security"  # 2 files
run "tests/Feature/Seeders"  # 13 files
run "tests/Feature/Services"  # 3 files
run "tests/Feature/Settings"  # 3 files
run "tests/Feature/Sharing"  # 16 files
run "tests/Feature/Support"  # 9 files
run "tests/Feature/Theme"  # 19 files
run "tests/Feature/ViewComponents"  # 17 files
run "tests/Feature/Visual"  # 1 files
