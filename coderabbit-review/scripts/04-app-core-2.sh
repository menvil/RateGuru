#!/usr/bin/env bash
# CodeRabbit full-project audit -- block 04: app/{Livewire,Models,Notifications,Policies,Providers,Queries,Rules,Services,View}
# 9 review(s), generated from base commit f8d765aca3e3e789e542a2eb92e573ece77ca098
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

run "app/Livewire"  # 29 files
run "app/Models"  # 17 files
run "app/Notifications"  # 3 files
run "app/Policies"  # 9 files
run "app/Providers"  # 2 files
run "app/Queries"  # 7 files
run "app/Rules"  # 1 files
run "app/Services"  # 11 files
run "app/View"  # 7 files
