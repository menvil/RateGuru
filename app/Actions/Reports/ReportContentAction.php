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

        $report = Report::create([
            'reporter_id' => $user->id,
            'target_type' => $content::class,
            'target_id' => $content->id,
            'reason' => $reason,
            'message' => $message,
            'status' => ReportStatus::Open,
        ]);

        if ($content instanceof Post) {
            $this->refreshPostReportsCount($content);
            $this->flagPostForReviewIfThresholdReached($content->fresh());
        }

        if ($content instanceof Comment) {
            $this->refreshCommentReportsCount($content);
        }

        return $report;
    }

    private function refreshCommentReportsCount(Comment $comment): void
    {
        $count = Report::query()
            ->where('target_type', Comment::class)
            ->where('target_id', $comment->id)
            ->count();

        $comment->forceFill([
            'reports_count' => $count,
        ])->save();
    }

    private function refreshPostReportsCount(Post $post): void
    {
        $count = Report::query()
            ->where('target_type', Post::class)
            ->where('target_id', $post->id)
            ->count();

        $post->forceFill([
            'reports_count' => $count,
        ])->save();
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
