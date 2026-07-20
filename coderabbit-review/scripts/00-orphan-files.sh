#!/usr/bin/env bash
# CodeRabbit full-project audit -- block 00: orphan files
# 90 files that sit directly inside a directory whose total
# (dir + subdirs) exceeds 50, so they cannot be targeted with --dir alone
# (--dir is recursive, and the parent directory's subdirs are already reviewed
# individually in the other block scripts). This script temporarily relocates
# them into a disposable git worktree, reviews them there, then discards the
# worktree -- nothing here touches your real working tree or branch.
#
# AGENTS.md and .coderabbit.yaml are deliberately NOT included in the batch:
# they are CodeRabbit's own instruction/config files, not application code,
# and AGENTS.md is also passed via --config below so it must stay resolvable
# at the worktree root. Give them a quick manual read instead.
set -uo pipefail
REPO_ROOT="$(git rev-parse --show-toplevel)"
cd "$REPO_ROOT"

BASE="f8d765aca3e3e789e542a2eb92e573ece77ca098"
OUT="$REPO_ROOT/coderabbit-review/results/_orphan-files"
mkdir -p "$OUT"

WT="$(mktemp -d)/rateguru-audit-orphans"
git worktree add --detach -f "$WT" HEAD >/dev/null

# project-audit.md may still be untracked on your main branch; `git worktree add`
# only materializes committed content, so copy the live config files across.
cp "$REPO_ROOT/AGENTS.md" "$WT/AGENTS.md"
cp "$REPO_ROOT/project-audit.md" "$WT/project-audit.md"
cp "$REPO_ROOT/.coderabbit.yaml" "$WT/.coderabbit.yaml"

cd "$WT"
mkdir -p _audit-batch-1 _audit-batch-2

git mv \
  ".editorconfig" \
  ".env.example" \
  ".gitattributes" \
  ".gitignore" \
  ".npmrc" \
  "CODE_OF_CONDUCT.md" \
  "CONTRIBUTING.md" \
  "PlateRate.html" \
  "README.md" \
  "SECURITY.md" \
  "app/Support/helpers.php" \
  "artisan" \
  "composer.json" \
  "composer.lock" \
  "database/.gitignore" \
  "docs/fresh-clone-verification.md" \
  "docs/phase-0-checklist.md" \
  "package-lock.json" \
  "package.json" \
  "phpstan.neon" \
  "phpunit.xml" \
  "resources/views/dashboard.blade.php" \
  "resources/views/home.blade.php" \
  "resources/views/welcome.blade.php" \
  "tests/Feature/Actions/AddCommentActionHotScoreTest.php" \
  "tests/Feature/Actions/AddCommentActionNotificationTest.php" \
  "tests/Feature/Actions/AddCommentActionTest.php" \
  "tests/Feature/Actions/AddCommentRateLimitTest.php" \
  "tests/Feature/Actions/ApplyProjectPresetActionTest.php" \
  "tests/Feature/Actions/ApprovePostActionNotificationTest.php" \
  "tests/Feature/Actions/ApprovePostActionTest.php" \
  "tests/Feature/Actions/AuthBoundaryActionsTest.php" \
  "tests/Feature/Actions/BanUserActionTest.php" \
  "tests/Feature/Actions/CreateModerationLogActionTest.php" \
  "tests/Feature/Actions/CreatePostActionTest.php" \
  "tests/Feature/Actions/CreatePostAuthorAnswersTest.php" \
  "tests/Feature/Actions/CreatePostRateLimitTest.php" \
  "tests/Feature/Actions/DeleteCommentActionTest.php" \
  "tests/Feature/Actions/DeletePostActionTest.php" \
  "tests/Feature/Actions/DeletePostInAdminActionTest.php" \
  "tests/Feature/Actions/DeleteTagActionTest.php" \
  "tests/Feature/Actions/FollowAuthorActionTest.php" \
  "tests/Feature/Actions/GenerateUniqueUsernameActionTest.php" \
  "tests/Feature/Actions/HideCommentActionTest.php" \
  "tests/Feature/Actions/HidePostActionTest.php" \
  _audit-batch-1/

git mv \
  "tests/Feature/Actions/IgnoreReportActionTest.php" \
  "tests/Feature/Actions/MarkUserTrustedActionTest.php" \
  "tests/Feature/Actions/PresentationPreferenceActionsTest.php" \
  "tests/Feature/Actions/ProfileIdentityActionsTest.php" \
  "tests/Feature/Actions/RatingOptionLifecycleActionsTest.php" \
  "tests/Feature/Actions/RecalculateCommentCountersActionTest.php" \
  "tests/Feature/Actions/RecalculatePostCountersActionTest.php" \
  "tests/Feature/Actions/RecalculatePostScoreActionSkeletonTest.php" \
  "tests/Feature/Actions/RecalculatePostScoreActionTest.php" \
  "tests/Feature/Actions/RejectPostActionTest.php" \
  "tests/Feature/Actions/ReportContentActionTest.php" \
  "tests/Feature/Actions/ReportContentRateLimitTest.php" \
  "tests/Feature/Actions/ResolveReportActionTest.php" \
  "tests/Feature/Actions/RestoreCommentActionTest.php" \
  "tests/Feature/Actions/RestorePostActionTest.php" \
  "tests/Feature/Actions/SavePostActionTest.php" \
  "tests/Feature/Actions/ShadowbanUserActionTest.php" \
  "tests/Feature/Actions/ToggleFollowAuthorActionTest.php" \
  "tests/Feature/Actions/ToggleSavedPostActionTest.php" \
  "tests/Feature/Actions/UnbanUserActionTest.php" \
  "tests/Feature/Actions/UnfollowAuthorActionTest.php" \
  "tests/Feature/Actions/UnsavePostActionTest.php" \
  "tests/Feature/Actions/VoteCacheInvalidationPlaceholderTest.php" \
  "tests/Feature/Actions/VoteCommentActionTest.php" \
  "tests/Feature/Actions/VoteCuisineActionTest.php" \
  "tests/Feature/Actions/VoteOriginActionTest.php" \
  "tests/Feature/Actions/VotePostActionHotScoreTest.php" \
  "tests/Feature/Actions/VotePostActionTest.php" \
  "tests/Feature/Actions/VoteRateLimitTest.php" \
  "tests/Feature/Actions/VoteRatingOptionActionTest.php" \
  "tests/Feature/AdminAccessTest.php" \
  "tests/Feature/ApplicationBootTest.php" \
  "tests/Feature/ApplicationShellTest.php" \
  "tests/Feature/DevUiKitRouteTest.php" \
  "tests/Feature/ExampleTest.php" \
  "tests/Feature/LivewireSmokeTest.php" \
  "tests/Feature/PostOpenGraphTest.php" \
  "tests/Feature/ProfileTest.php" \
  "tests/Feature/TestDatabaseTest.php" \
  "tests/Feature/UserFactoryTest.php" \
  "tests/Feature/UserSchemaTest.php" \
  "tests/Pest.php" \
  "tests/TestCase.php" \
  "tests/Unit/ExampleTest.php" \
  "vite.config.js" \
  _audit-batch-2/

git add -A
git commit -q -m "chore(audit): temporary relocation for CodeRabbit review (not for merge)"

echo "==> [orphan batch 1]"
coderabbit review --agent --type committed --base-commit "$BASE" --dir "_audit-batch-1" \
  --config AGENTS.md project-audit.md 2>&1 | tee "$OUT/batch-1.md"
echo

echo "==> [orphan batch 2]"
coderabbit review --agent --type committed --base-commit "$BASE" --dir "_audit-batch-2" \
  --config AGENTS.md project-audit.md 2>&1 | tee "$OUT/batch-2.md"
echo

cd "$REPO_ROOT"
git worktree remove --force "$WT"
