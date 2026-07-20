#!/usr/bin/env bash
# CodeRabbit full-project audit -- block 03: app/{Console,Contracts,Data,Enums,Exceptions,Filament,Http,Jobs}
# 8 review(s), generated from base commit f8d765aca3e3e789e542a2eb92e573ece77ca098
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

run "app/Console"  # 6 files
run "app/Contracts"  # 3 files
run "app/Data"  # 3 files
run "app/Enums"  # 14 files
run "app/Exceptions"  # 30 files
run "app/Filament"  # 35 files
run "app/Http"  # 29 files
run "app/Jobs"  # 2 files
