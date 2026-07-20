#!/usr/bin/env bash
# CodeRabbit full-project audit -- block 02: app/Support/* (support helpers)
# 19 review(s), generated from base commit f8d765aca3e3e789e542a2eb92e573ece77ca098
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

run "app/Support/AbuseGuards"  # 3 files
run "app/Support/Cache"  # 1 files
run "app/Support/Database"  # 1 files
run "app/Support/Follows"  # 3 files
run "app/Support/Import"  # 8 files
run "app/Support/Locale"  # 1 files
run "app/Support/Observability"  # 6 files
run "app/Support/Profile"  # 5 files
run "app/Support/Ranking"  # 1 files
run "app/Support/Rating"  # 3 files
run "app/Support/SavedPosts"  # 3 files
run "app/Support/Seo"  # 1 files
run "app/Support/Settings"  # 3 files
run "app/Support/Sharing"  # 3 files
run "app/Support/Theme"  # 1 files
run "app/Support/Translations"  # 1 files
run "app/Support/Urls"  # 1 files
run "app/Support/View"  # 1 files
run "app/Support/VisualRegression"  # 4 files
