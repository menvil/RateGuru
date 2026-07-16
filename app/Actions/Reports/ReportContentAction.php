<?php

namespace App\Actions\Reports;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Exceptions\Abuse\RateLimitExceededException;
use App\Exceptions\Reports\CannotReportContentException;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Support\AbuseGuards\ActionRateLimiter;
use App\Support\AbuseGuards\RateLimitKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class ReportContentAction
{
    private const POST_REVIEW_REPORT_THRESHOLD = 3;

    public function __construct(
        private readonly ActionRateLimiter $rateLimiter,
    ) {}

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

        if (! $content instanceof Post && ! $content instanceof Comment && ! $content instanceof User) {
            throw CannotReportContentException::becauseUnsupportedContent();
        }

        try {
            $this->rateLimiter->hitOrFail(
                key: RateLimitKey::userAction('report', $user),
                maxAttempts: (int) config('rate_limits.report.max_attempts'),
                decaySeconds: (int) config('rate_limits.report.decay_seconds'),
                message: 'You are reporting too quickly. Please try again later.',
            );
        } catch (RateLimitExceededException $e) {
            throw CannotReportContentException::becauseRateLimited($e->getMessage());
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
            // Creation and aggregate updates must be atomic: a report must
            // never be committed without its reports_count / review flag
            // being recomputed in the same unit of work.
            return DB::transaction(function () use ($user, $content, $reason, $message) {
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

                    // Re-read post-recount state; may be null if the post was
                    // deleted concurrently, in which case there is nothing to flag.
                    $freshPost = $content->fresh();

                    if ($freshPost !== null) {
                        $this->flagPostForReviewIfThresholdReached($freshPost);
                    }
                }

                if ($content instanceof Comment) {
                    $this->refreshCommentReportsCount($content);
                }

                return $report;
            });
        } catch (UniqueConstraintViolationException) {
            // Lost a race with a concurrent identical report; the pre-check
            // passed for both requests but the unique index rejected this one.
            // The transaction has already rolled back.
            throw CannotReportContentException::becauseDuplicateReport();
        }
    }

    private function refreshCommentReportsCount(Comment $comment): void
    {
        $this->recountReports($comment);
    }

    private function refreshPostReportsCount(Post $post): void
    {
        $this->recountReports($post);
    }

    /**
     * Recompute reports_count for a single reportable row.
     *
     * The recompute (COUNT) and the write are serialized behind a row lock
     * inside a transaction so concurrent reports cannot interleave a stale
     * lower count over a newer higher one (lost update).
     */
    private function recountReports(Model $content): void
    {
        DB::transaction(function () use ($content) {
            $content->newQuery()
                ->whereKey($content->getKey())
                ->lockForUpdate()
                ->first();

            $count = Report::query()
                ->where('target_type', $content::class)
                ->where('target_id', $content->getKey())
                ->count();

            $content->newQuery()
                ->whereKey($content->getKey())
                ->update(['reports_count' => $count]);
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
