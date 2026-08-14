<?php

namespace App\Services\Comments;

use App\Enums\ReportStatus;
use App\Models\Comment;
use App\Models\CommentVote;
use App\Models\Report;

/**
 * The single physical deletion boundary for a standalone leaf comment
 * (docs/architecture/comment-lifecycle.md). Purely mechanical: eligibility
 * (retention windows, structural anchors, parent-post holds, open-report
 * evidence) belongs to the semantic callers — CommentRetentionPurgeService
 * for author-deleted leaves and ModerationContentPurgeService for
 * finalized moderation removals.
 *
 * Must run inside the caller's transaction with the parent post and the
 * comment row already locked (Post -> Comment order). Deletes only the
 * comment's own child data: its votes and its non-open target reports
 * (open reports were already checked as holds upstream and must never be
 * silently destroyed). Moderation logs are audit history and remain.
 */
// Deliberately not final (MediaReferenceChecker precedent): rollback
// regressions substitute a failing double through the container.
class CommentPhysicalDeletionService
{
    public function deleteLeaf(Comment $comment): void
    {
        CommentVote::query()->where('comment_id', $comment->id)->delete();

        Report::query()
            ->where('target_type', Comment::class)
            ->where('target_id', $comment->id)
            ->where('status', '!=', ReportStatus::Open)
            ->delete();

        $comment->forceDelete();
    }
}
