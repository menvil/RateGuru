#!/usr/bin/env bash
# CodeRabbit full-project audit -- block 07: resources/*
# 12 review(s), generated from base commit f8d765aca3e3e789e542a2eb92e573ece77ca098
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

run "resources/css"  # 2 files
run "resources/js"  # 3 files
run "resources/views/auth"  # 6 files
run "resources/views/components"  # 46 files
run "resources/views/dev"  # 10 files
run "resources/views/errors"  # 1 files
run "resources/views/feed"  # 1 files
run "resources/views/filament"  # 3 files
run "resources/views/layouts"  # 5 files
run "resources/views/livewire"  # 31 files
run "resources/views/profile"  # 4 files
run "resources/views/vendor"  # 2 files
