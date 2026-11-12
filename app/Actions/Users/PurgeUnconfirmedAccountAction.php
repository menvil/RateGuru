<?php

namespace App\Actions\Users;

use App\Actions\Counters\RecalculatePostCountersAction;
use App\Actions\Profile\AnonymizeUserAccountAction;
use App\Actions\Ranking\RecalculatePostScoreAction;
use App\Enums\CommentStatus;
use App\Enums\UnconfirmedAccountPurgeOutcome;
use App\Enums\VoteType;
use App\Models\Comment;
use App\Models\CommentVote;
use App\Models\GameAnswer;
use App\Models\GameAssignment;
use App\Models\GameAttempt;
use App\Models\GameEmailCapture;
use App\Models\GameEvent;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\RatingVote;
use App\Models\Report;
use App\Models\User;
use App\Queries\Users\UnconfirmedAccountsQuery;
use App\Services\Comments\CommentPhysicalDeletionService;
use App\Services\Posts\PostGraphDeletionService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Removes an account that signed up with an email address and never
 * confirmed one, and everything it did (docs/architecture/user-lifecycle.md,
 * "Unconfirmed accounts"): its posts with all that hangs on them, its
 * comments, its votes, its reports, the games it played. What is left is the
 * tombstone account deletion leaves, through the same AnonymizeUserAccountAction.
 *
 * One of its comments that other people answered stays in the thread as
 * "[comment deleted]", without its text, so their replies keep their place.
 * Counts on what others wrote — votes, comments, reports, the hot score — are
 * counted again.
 *
 * The account is asked again on its locked row, and then the posts and
 * comments it touched are locked in ascending id order after it: the lock
 * order every participation takes (docs/architecture/user-lifecycle.md).
 * Each row another person owns is written once, so PostgreSQL never checks
 * its foreign keys out of that order.
 */
final class PurgeUnconfirmedAccountAction
{
    public function __construct(
        private readonly UnconfirmedAccountsQuery $accounts,
        private readonly PostGraphDeletionService $postGraph,
        private readonly CommentPhysicalDeletionService $commentDeletion,
        private readonly RecalculatePostCountersAction $postCounters,
        private readonly RecalculatePostScoreAction $postScore,
        private readonly AnonymizeUserAccountAction $anonymize,
    ) {}

    public function handle(int $userId, CarbonInterface $signedUpBefore, bool $dryRun = false): UnconfirmedAccountPurgeOutcome
    {
        return DB::transaction(function () use ($userId, $signedUpBefore, $dryRun): UnconfirmedAccountPurgeOutcome {
            $query = User::query()->whereKey($userId);
            $user = $dryRun ? $query->first() : $query->lockForUpdate()->first();

            if ($user === null) {
                return UnconfirmedAccountPurgeOutcome::AlreadyGone;
            }

            if (! $this->accounts->isUnconfirmed($user) || $user->created_at === null || $user->created_at->gt($signedUpBefore)) {
                return UnconfirmedAccountPurgeOutcome::NotEligible;
            }

            if ($dryRun) {
                return UnconfirmedAccountPurgeOutcome::WouldPurge;
            }

            $this->erase((int) $user->id);
            $this->anonymize->execute($user);

            return UnconfirmedAccountPurgeOutcome::Purged;
        });
    }

    private function erase(int $id): void
    {
        $posts = $this->lockTouchedPosts($id);

        foreach ($posts->where('user_id', $id) as $post) {
            $this->postGraph->deleteGraph($post);
        }

        $others = $posts->reject(fn (Post $post): bool => (int) $post->user_id === $id);
        $comments = $this->lockTouchedComments($id);

        $this->eraseComments($id);

        CommentVote::query()->where('user_id', $id)->delete();
        PostVote::query()->where('user_id', $id)->delete();
        RatingVote::query()->where('user_id', $id)->delete();
        Report::query()->where('reporter_id', $id)->delete();
        Report::query()->where('target_type', User::class)->where('target_id', $id)->delete();

        foreach ($comments as $comment) {
            if ((int) $comment->user_id !== $id && Comment::withTrashed()->whereKey($comment->id)->exists()) {
                $this->countComment($comment);
            }
        }

        foreach ($others as $post) {
            $this->countPost($post);
        }

        $this->eraseGames($id);
    }

    /**
     * The posts the account wrote, commented on, voted on or reported, or
     * whose comments it voted on or reported, locked in ascending id.
     *
     * @return Collection<int, Post>
     */
    private function lockTouchedPosts(int $id): Collection
    {
        return Post::withTrashed()
            ->where(fn ($posts) => $posts
                ->where('user_id', $id)
                ->orWhereIn('id', Comment::withTrashed()->select('post_id')->whereIn('id', $this->touchedComments($id)))
                ->orWhereIn('id', PostVote::query()->select('post_id')->where('user_id', $id))
                ->orWhereIn('id', Report::query()->select('target_id')->where('reporter_id', $id)->where('target_type', Post::class)))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->toBase();
    }

    /**
     * The comments the account wrote, voted on or reported that are still
     * there once its posts are gone, locked in ascending id after the posts.
     *
     * @return Collection<int, Comment>
     */
    private function lockTouchedComments(int $id): Collection
    {
        $ids = $this->touchedComments($id)->pluck('id');

        return Comment::withTrashed()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->toBase();
    }

    /** @return Builder<Comment> */
    private function touchedComments(int $id)
    {
        return Comment::withTrashed()
            ->select('id')
            ->where(fn ($comments) => $comments
                ->where('user_id', $id)
                ->orWhereIn('id', CommentVote::query()->select('comment_id')->where('user_id', $id))
                ->orWhereIn('id', Report::query()->select('target_id')->where('reporter_id', $id)->where('target_type', Comment::class)));
    }

    /**
     * Its comments on other people's posts, leaves first: a reply, then a
     * comment nobody else answered. What remains was answered by others and
     * becomes the thread's "[comment deleted]" — removed, without its text,
     * its votes or its reports.
     */
    private function eraseComments(int $id): void
    {
        while (true) {
            $leaves = Comment::withTrashed()
                ->where('user_id', $id)
                ->whereNotIn('id', Comment::withTrashed()->whereNotNull('parent_id')->select('parent_id'))
                ->pluck('id');

            if ($leaves->isEmpty()) {
                break;
            }

            foreach ($leaves as $leafId) {
                // The physical deletion keeps open reports as evidence; the
                // comment and its author are going, so they go too.
                Report::query()->where('target_type', Comment::class)->where('target_id', $leafId)->delete();
                $this->commentDeletion->deleteLeaf(Comment::withTrashed()->findOrFail($leafId));
            }
        }

        foreach (Comment::withTrashed()->where('user_id', $id)->get() as $anchor) {
            CommentVote::query()->where('comment_id', $anchor->id)->delete();
            Report::query()->where('target_type', Comment::class)->where('target_id', $anchor->id)->delete();

            $anchor->forceFill([
                'body' => '',
                'upvotes_count' => 0,
                'downvotes_count' => 0,
                'reports_count' => 0,
                'deleted_at' => $anchor->deleted_at ?? now(),
            ])->save();
        }
    }

    /** Another person's comment, counted again without the account's votes and reports. */
    private function countComment(Comment $comment): void
    {
        $votes = CommentVote::query()->where('comment_id', $comment->id);

        $comment->forceFill([
            'upvotes_count' => (clone $votes)->where('type', VoteType::Up->value)->count(),
            'downvotes_count' => (clone $votes)->where('type', VoteType::Down->value)->count(),
            'reports_count' => Report::query()->where('target_type', Comment::class)->where('target_id', $comment->id)->count(),
        ])->save();
    }

    /**
     * Another person's post, counted again — votes, comments, reports and
     * the hot score — in one update.
     */
    private function countPost(Post $post): void
    {
        $this->postCounters->fill($post);

        $post->forceFill([
            'comments_count' => Comment::query()->where('post_id', $post->id)->where('status', CommentStatus::Visible)->count(),
            'reports_count' => Report::query()->where('target_type', Post::class)->where('target_id', $post->id)->count(),
        ]);

        $this->postScore->saveWithCounts($post);
    }

    /**
     * The games it played: its attempts, their answers, emails and events,
     * and the versions it was assigned.
     */
    private function eraseGames(int $id): void
    {
        $assignments = GameAssignment::query()->where('user_id', $id)->pluck('id');
        $attempts = GameAttempt::query()
            ->where(fn ($attempt) => $attempt->where('user_id', $id)->orWhereIn('assignment_id', $assignments))
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');

        GameEvent::query()
            ->where(fn ($event) => $event
                ->where('user_id', $id)
                ->orWhereIn('attempt_id', $attempts)
                ->orWhereIn('assignment_id', $assignments))
            ->delete();
        GameAnswer::query()->whereIn('attempt_id', $attempts)->delete();
        GameEmailCapture::query()->whereIn('attempt_id', $attempts)->delete();
        GameAttempt::query()->whereIn('id', $attempts)->delete();
        GameAssignment::query()->whereIn('id', $assignments)->delete();
    }
}
