<?php

use App\Enums\CommentPurgeOutcome;
use App\Enums\CommentStatus;
use App\Enums\PostPurgeOutcome;
use App\Enums\ReportStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Services\Comments\CommentRetentionPurgeService;
use App\Services\Posts\PostRetentionPurgeService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * A retention purge reads whether an open report holds the content, then
 * deletes it. A moderator reopening a closed report on that content in
 * between must not end with an open report on a comment that is gone, nor
 * with the reopened report deleted along with its post. The purge locks the
 * reports it reads, so the reopen has to wait for it; here the moderator's
 * session gives up after a moment instead of waiting, which is what makes
 * the lock observable from a single test process.
 */
beforeEach(function () {
    config([
        'content_lifecycle.comments.author_delete_retention_days' => 30,
        'posts.author_delete_retention_days' => 30,
    ]);
});

/**
 * Runs $purge on a session that commits, while a moderator in a second one
 * tries to reopen $reportId right after the purge has read the reports.
 *
 * @return array{outcome: mixed, reopened: bool|null}
 */
function purgeWhileTheReportIsReopened(int $reportId, Closure $purge): array
{
    $moderator = committedConnection('committed_moderator');
    $moderator->statement(match ($moderator->getDriverName()) {
        'pgsql' => "set lock_timeout = '300ms'",
        default => 'set session innodb_lock_wait_timeout = 1',
    });

    $reopened = null;
    DB::listen(function (QueryExecuted $query) use (&$reopened, $moderator, $reportId): void {
        if ($reopened !== null || $query->connectionName !== 'committed_peer') {
            return;
        }

        if (! str_contains(strtolower(str_replace(['"', '`'], '', $query->sql)), 'from reports')) {
            return;
        }

        try {
            $reopened = $moderator->table('reports')
                ->where('id', $reportId)
                ->where('status', '!=', ReportStatus::Open->value)
                ->update(['status' => ReportStatus::Open->value, 'resolved_by' => null, 'resolved_at' => null, 'resolution_note' => null]) > 0;
        } catch (QueryException $exception) {
            // It waited on the purge's lock and gave up: PostgreSQL's lock
            // timeout, or MySQL's and MariaDB's. Any other failure is not the
            // race this shows, and fails the test.
            if ($exception->getCode() !== '55P03' && ($exception->errorInfo[1] ?? null) !== 1205) {
                throw $exception;
            }

            $reopened = false;
        }
    });

    $default = DB::getDefaultConnection();
    DB::setDefaultConnection('committed_peer');

    try {
        return ['outcome' => $purge(), 'reopened' => $reopened];
    } finally {
        DB::setDefaultConnection($default);
    }
}

/** Removes what the race committed: whatever the purge left of it, and the accounts. */
function forgetReportReopenRace(ConnectionInterface $db, array $userIds, int $postId): void
{
    $commentIds = $db->table('comments')->where('post_id', $postId)->pluck('id')->all();

    $db->table('reports')->whereIn('reporter_id', $userIds)->delete();
    $db->table('comments')->whereIn('id', $commentIds)->delete();
    $db->table('posts')->where('id', $postId)->delete();
    $db->table('users')->whereIn('id', $userIds)->delete();
}

it('leaves no open report on a comment the retention purge deleted', function () {
    $db = committedConnection();
    $author = User::factory()->connection('committed_peer')->create();
    $reporter = User::factory()->connection('committed_peer')->create();
    $closedBy = User::factory()->connection('committed_peer')->moderator()->create();
    $post = Post::factory()->connection('committed_peer')->published()->create(['user_id' => $author->id]);
    $comment = Comment::factory()->connection('committed_peer')->create([
        'post_id' => $post->id,
        'user_id' => $author->id,
        'status' => CommentStatus::Visible,
        'deleted_at' => now()->subDays(31),
    ]);
    $report = Report::factory()->connection('committed_peer')->create([
        'reporter_id' => $reporter->id,
        'target_type' => Comment::class,
        'target_id' => $comment->id,
        'status' => ReportStatus::Resolved,
        'resolved_by' => $closedBy->id,
        'resolved_at' => now()->subDay(),
    ]);

    try {
        ['outcome' => $outcome, 'reopened' => $reopened] = purgeWhileTheReportIsReopened(
            $report->id,
            fn () => app(CommentRetentionPurgeService::class)->purge($comment->id),
        );

        expect($outcome)->toBe(CommentPurgeOutcome::Purged)
            ->and($reopened)->toBeFalse()
            ->and($db->table('comments')->where('id', $comment->id)->exists())->toBeFalse()
            ->and($db->table('reports')->where('id', $report->id)->exists())->toBeFalse();
    } finally {
        forgetReportReopenRace($db, [$author->id, $reporter->id, $closedBy->id], $post->id);
    }
})->skip(
    fn (): bool => config('database.default') === 'sqlite',
    'SQLite takes no row locks, and one writer holds the whole database at a time.',
);

it('does not delete a report reopened on a post while the retention purge deletes it', function () {
    $db = committedConnection();
    $author = User::factory()->connection('committed_peer')->create();
    $reporter = User::factory()->connection('committed_peer')->create();
    $closedBy = User::factory()->connection('committed_peer')->moderator()->create();
    $post = Post::factory()->connection('committed_peer')->authorDeleted()->create([
        'user_id' => $author->id,
        'deleted_at' => now()->subDays(31),
    ]);
    $report = Report::factory()->connection('committed_peer')->create([
        'reporter_id' => $reporter->id,
        'target_type' => Post::class,
        'target_id' => $post->id,
        'status' => ReportStatus::Ignored,
        'resolved_by' => $closedBy->id,
        'resolved_at' => now()->subDay(),
    ]);

    try {
        ['outcome' => $outcome, 'reopened' => $reopened] = purgeWhileTheReportIsReopened(
            $report->id,
            fn () => app(PostRetentionPurgeService::class)->purge($post->id),
        );

        // The moderator's reopen waited for the purge instead of landing in
        // the middle of it, so nobody was told a report was open that the
        // purge then deleted.
        expect($outcome)->toBe(PostPurgeOutcome::Purged)
            ->and($reopened)->toBeFalse()
            ->and($db->table('posts')->where('id', $post->id)->exists())->toBeFalse();
    } finally {
        forgetReportReopenRace($db, [$author->id, $reporter->id, $closedBy->id], $post->id);
    }
})->skip(
    fn (): bool => config('database.default') === 'sqlite',
    'SQLite takes no row locks, and one writer holds the whole database at a time.',
);
