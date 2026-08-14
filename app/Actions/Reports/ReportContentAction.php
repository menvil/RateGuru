<?php

namespace App\Actions\Reports;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Exceptions\Abuse\RateLimitExceededException;
use App\Exceptions\Reports\CannotReportContentException;
use App\Models\Comment;
use App\Models\Concerns\LocksActorForWrite;
use App\Models\Concerns\LocksUsersInOrder;
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
    use LocksActorForWrite;
    use LocksUsersInOrder;

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

        if ($content instanceof Comment && ! $content->canReceiveReports()) {
            throw CannotReportContentException::becauseContentIsNotReportable();
        }

        if ($content instanceof Post && ! $content->canReceiveReports()) {
            throw CannotReportContentException::becauseContentIsNotReportable();
        }

        // Ownership is immutable on posts, so the policy check (which
        // denies reporting your own post) needs no locked re-read.
        if ($content instanceof Post && ! $user->can('report', $content)) {
            throw CannotReportContentException::becauseUserIsNotAllowed();
        }

        // A Deleted tombstone is not a reportable public target; living
        // users stay reportable through the existing product flow.
        if ($content instanceof User && $content->isTombstoned()) {
            throw CannotReportContentException::becauseContentIsNotReportable();
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
                // Lock order: Actor User -> Post -> Comment; for a User
                // target both user rows are locked together in ascending id
                // order — sequential reporter-then-target locking would
                // break the global User lock order shared with sanctions
                // and follows. Pre-checks ran on possibly stale instances:
                // the reporter may have been sanctioned and a User target
                // may have self-deleted into a tombstone in between.
                if ($content instanceof User) {
                    $lockedPair = $this->lockUsersInOrder((int) $user->getKey(), (int) $content->getKey());

                    $lockedReporter = $lockedPair->get($user->getKey());
                    $lockedTarget = $lockedPair->get($content->getKey());

                    if ($lockedReporter === null || ! $lockedReporter->canReport()) {
                        throw CannotReportContentException::becauseUserIsNotAllowed();
                    }

                    if ($lockedTarget === null || $lockedTarget->isTombstoned()) {
                        throw CannotReportContentException::becauseContentIsNotReportable();
                    }
                } else {
                    $lockedReporter = $this->lockActor($user);

                    if ($lockedReporter === null || ! $lockedReporter->canReport()) {
                        throw CannotReportContentException::becauseUserIsNotAllowed();
                    }
                }

                if ($content instanceof Comment) {
                    $this->assertCommentIsReportableUnderLock($content);
                }

                if ($content instanceof Post) {
                    $this->assertPostIsReportableUnderLock($content);
                }

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

    /**
     * The caller's Comment instance may be stale: a moderation hide or an
     * author delete can land between the public pre-check and this
     * transaction, and a tombstoned comment must not accumulate reports.
     * Only the rows re-read under lock are authoritative. Users are the
     * only remaining ungated target: account tombstoning is not content
     * removal.
     *
     * Lock order: parent Post row first, then the Comment
     * (docs/architecture/post-lifecycle.md). Locking the post serializes
     * comment reports against the retention purge: either this report
     * commits first and the purge's hold check sees the open report, or
     * the purge holds the post lock and this re-read finds the graph gone
     * — an open report can never be created between the purge's hold check
     * and its comment sweep. The post gate also closes the plain lifecycle
     * gap: comments of a deleted or hidden post keep their own Visible
     * status in storage but are publicly unreachable, so they must not
     * accept new reports.
     */
    private function assertCommentIsReportableUnderLock(Comment $comment): void
    {
        $lockedPost = Post::withTrashed()
            ->whereKey($comment->post_id)
            ->lockForUpdate()
            ->first();

        if ($lockedPost === null || ! $lockedPost->canReceiveReports()) {
            throw CannotReportContentException::becauseContentIsNotReportable();
        }

        $lockedComment = Comment::query()
            ->withTrashed()
            ->whereKey($comment->id)
            ->lockForUpdate()
            ->first();

        if ($lockedComment === null || ! $lockedComment->canReceiveReports()) {
            throw CannotReportContentException::becauseContentIsNotReportable();
        }
    }

    /**
     * Same stale-instance rule for posts (PR-E): an author-deleted or
     * moderation-hidden post is no longer publicly reportable — existing
     * reports remain, new ones are refused.
     */
    private function assertPostIsReportableUnderLock(Post $post): void
    {
        $lockedPost = Post::withTrashed()
            ->whereKey($post->id)
            ->lockForUpdate()
            ->first();

        if ($lockedPost === null || ! $lockedPost->canReceiveReports()) {
            throw CannotReportContentException::becauseContentIsNotReportable();
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
            $content->newQueryWithoutScopes()
                ->whereKey($content->getKey())
                ->lockForUpdate()
                ->first();

            $count = Report::query()
                ->where('target_type', $content::class)
                ->where('target_id', $content->getKey())
                ->count();

            $content->newQueryWithoutScopes()
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
