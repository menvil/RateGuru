<?php

namespace App\Actions\Reports;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Exceptions\Reports\CannotReportContentException;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class ReportContentAction
{
    private const POST_REVIEW_REPORT_THRESHOLD = 3;

    public function handle(
        ?User $user,
        Model $content,
        ReportReason $reason,
        ?string $message = null,
    ): Report {
        if ($user === null) {
            throw CannotReportContentException::becauseGuest();
        }

        if (! $user->canReport()) {
            throw CannotReportContentException::becauseUserIsNotAllowed();
        }

        if (! $content instanceof Post && ! $content instanceof Comment) {
            throw CannotReportContentException::becauseUnsupportedContent();
        }

        $alreadyReported = Report::query()
            ->where('reporter_id', $user->id)
            ->where('target_type', $content::class)
            ->where('target_id', $content->id)
            ->exists();

        if ($alreadyReported) {
            throw CannotReportContentException::becauseDuplicateReport();
        }

        $message = trim((string) $message);
        $message = $message === '' ? null : $message;

        try {
            $report = Report::create([
                'reporter_id' => $user->id,
                'target_type' => $content::class,
                'target_id' => $content->id,
                'reason' => $reason,
                'message' => $message,
                'status' => ReportStatus::Open,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Lost a race with a concurrent identical report; the pre-check
            // passed for both requests but the unique index rejected this one.
            throw CannotReportContentException::becauseDuplicateReport();
        }

        if ($content instanceof Post) {
            $this->refreshPostReportsCount($content);

            // Re-read post-recount state; may be null if the post was deleted
            // concurrently, in which case there is nothing to flag.
            $freshPost = $content->fresh();

            if ($freshPost !== null) {
                $this->flagPostForReviewIfThresholdReached($freshPost);
            }
        }

        if ($content instanceof Comment) {
            $this->refreshCommentReportsCount($content);
        }

        return $report;
    }

    private function refreshCommentReportsCount(Comment $comment): void
    {
        $this->recountReports(Comment::class, $comment->getKey(), $comment->getTable());
    }

    private function refreshPostReportsCount(Post $post): void
    {
        $this->recountReports(Post::class, $post->getKey(), $post->getTable());
    }

    /**
     * Recompute reports_count for a single reportable row.
     *
     * The recompute (COUNT) and the write are serialized behind a row lock
     * inside a transaction so concurrent reports cannot interleave a stale
     * lower count over a newer higher one (lost update).
     */
    private function recountReports(string $type, int|string $id, string $table): void
    {
        DB::transaction(function () use ($type, $id, $table) {
            DB::table($table)->where('id', $id)->lockForUpdate()->first();

            $count = Report::query()
                ->where('target_type', $type)
                ->where('target_id', $id)
                ->count();

            DB::table($table)->where('id', $id)->update(['reports_count' => $count]);
        });
    }

    private function flagPostForReviewIfThresholdReached(Post $post): void
    {
        if ($post->reports_count < self::POST_REVIEW_REPORT_THRESHOLD) {
            return;
        }

        if ($post->needs_review) {
            return;
        }

        $post->forceFill([
            'needs_review' => true,
            'flagged_at' => now(),
            'flagged_reason' => 'reports_threshold',
        ])->save();
    }
}
