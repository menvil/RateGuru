#!/usr/bin/env bash
# CodeRabbit full-project audit -- block 12: tests/Unit/*
# 11 review(s), generated from base commit f8d765aca3e3e789e542a2eb92e573ece77ca098
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

run "tests/Unit/Actions"  # 15 files
run "tests/Unit/Data"  # 1 files
run "tests/Unit/Enums"  # 7 files
run "tests/Unit/Filament"  # 1 files
run "tests/Unit/Http"  # 3 files
run "tests/Unit/Jobs"  # 1 files
run "tests/Unit/Models"  # 8 files
run "tests/Unit/PHPStan"  # 36 files
run "tests/Unit/Queries"  # 1 files
run "tests/Unit/Services"  # 4 files
run "tests/Unit/Support"  # 2 files
