#!/usr/bin/env bash
# CodeRabbit full-project audit -- block 06: docs/*
# 20 review(s), generated from base commit f8d765aca3e3e789e542a2eb92e573ece77ca098
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

run "docs/admin"  # 3 files
run "docs/api"  # 4 files
run "docs/architecture"  # 2 files
run "docs/deployment"  # 9 files
run "docs/design"  # 17 files
run "docs/dev"  # 1 files
run "docs/domain"  # 4 files
run "docs/follows"  # 2 files
run "docs/i18n"  # 3 files
run "docs/import"  # 3 files
run "docs/mobile"  # 4 files
run "docs/observability"  # 4 files
run "docs/performance"  # 2 files
run "docs/profile"  # 4 files
run "docs/ranking"  # 1 files
run "docs/rating"  # 3 files
run "docs/saved-posts"  # 2 files
run "docs/security"  # 3 files
run "docs/sharing"  # 3 files
run "docs/testing"  # 2 files
