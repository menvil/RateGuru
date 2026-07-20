#!/usr/bin/env bash
# CodeRabbit full-project audit -- block 10: tests/Feature/* (part A, alphabetical)
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

run "tests/Feature/Admin"  # 2 files
run "tests/Feature/Api"  # 1 files
run "tests/Feature/Architecture"  # 5 files
run "tests/Feature/Auth"  # 11 files
run "tests/Feature/Console"  # 5 files
run "tests/Feature/Database"  # 18 files
run "tests/Feature/Docs"  # 32 files
run "tests/Feature/Domain"  # 13 files
run "tests/Feature/Factories"  # 9 files
run "tests/Feature/Filament"  # 21 files
run "tests/Feature/I18n"  # 25 files
run "tests/Feature/Import"  # 10 files
run "tests/Feature/Jobs"  # 1 files
run "tests/Feature/Livewire"  # 46 files
run "tests/Feature/Mobile"  # 14 files
run "tests/Feature/Models"  # 3 files
run "tests/Feature/Notifications"  # 6 files
