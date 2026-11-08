<?php

use App\Actions\Comments\AddCommentAction;
use App\Actions\Comments\DeleteCommentAction;
use App\Actions\Comments\HideCommentAction;
use App\Actions\Comments\RestoreCommentAction;
use App\Actions\Reports\ReportContentAction;
use App\Actions\Votes\VotePostAction;
use App\Enums\CommentStatus;
use App\Enums\ReportReason;
use App\Enums\VoteType;
use App\Models\Comment;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/*
 * A vote, a comment or a report on a post — and a comment deleted by its
 * member, or hidden or restored by a moderator — locks its writer, then the
 * post (docs/architecture/user-lifecycle.md), and never the post's author while
 * it holds the post. PostgreSQL would lock the author all the same, unasked,
 * were the post updated twice in that transaction: it checks the second
 * update's foreign keys, the author's among them, with FOR KEY SHARE on the
 * author's row. A two-user operation locks both users in ascending id order —
 * a follow, here, of the writer by the post's author, the lower id: holding the
 * author and waiting for the writer, while the write holds the writer and the
 * post and waits for that check, each would wait for the other.
 *
 * So each such write updates the post once, which every engine shows; the race
 * itself runs on PostgreSQL. There the write leads (lockRaceCompetitor()): it
 * holds its vote, comment, comment change or report, written, until the follow
 * is seen waiting for it.
 */

const POST_AUTHOR_RACE_SKIP = 'PostgreSQL only: the competitors find each other through pg_blocking_pids(), and SQLite runs the suite in memory, where a second process cannot join at all. PostgreSQL is the primary runtime (docs/architecture/database-support.md).';

/**
 * Removes what a race of a write on a post and a follow committed, for real:
 * the post and all written on it, the follow, the notifications, the
 * moderation logged, the settings row and the users.
 *
 * @param  list<int>  $userIds
 */
function forgetPostAuthorRace(ConnectionInterface $db, array $userIds, string $directory): void
{
    $postIds = $db->table('posts')->whereIn('user_id', $userIds)->pluck('id');

    $db->table('reports')->where('target_type', Post::class)->whereIn('target_id', $postIds)->delete();
    $db->table('comments')->whereIn('post_id', $postIds)->delete();
    $db->table('post_votes')->whereIn('post_id', $postIds)->delete();
    $db->table('posts')->whereIn('id', $postIds)->delete();
    $db->table('follows')->whereIn('follower_id', $userIds)->delete();
    $db->table('notifications')->whereIn('notifiable_id', $userIds)->delete();
    $db->table('moderation_logs')->whereIn('moderator_id', $userIds)->delete();
    $db->table('project_settings')->where('id', 1)->delete();
    $db->table('users')->whereIn('id', $userIds)->delete();
    File::deleteDirectory($directory);
}

it('updates the post once for a vote, a comment, a comment deleted, hidden or restored, and a report that flags it', function (Closure $write) {
    $member = User::factory()->create();
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create(['reports_count' => 2]);
    // Two reports already there: the member's is the one that flags the post for review.
    Report::factory()->count(2)->create(['target_type' => Post::class, 'target_id' => $post->id]);
    [$comment, $hidden] = Comment::factory()->count(2)->create(['post_id' => $post->id, 'user_id' => $member->id])->all();
    app(HideCommentAction::class)->handle($moderator, $hidden);

    $updates = 0;

    DB::listen(function (QueryExecuted $query) use (&$updates): void {
        // Identifier quoting is stripped rather than matched: PostgreSQL quotes "posts", MariaDB `posts`.
        if (preg_match('/^\s*update posts\s/i', str_replace(['"', '`'], '', $query->sql)) === 1) {
            $updates++;
        }
    });

    $write($member, $moderator, $post->fresh(), $comment->fresh(), $hidden->fresh());

    expect($updates)->toBe(1);
})->with([
    'a vote' => [fn (User $member, User $moderator, Post $post) => app(VotePostAction::class)->handle($member, $post, VoteType::Up)],
    'a comment' => [fn (User $member, User $moderator, Post $post) => app(AddCommentAction::class)->handle($member, $post, 'Another comment.')],
    'a comment deleted' => [fn (User $member, User $moderator, Post $post, Comment $comment) => app(DeleteCommentAction::class)->handle($member, $comment)],
    'a comment hidden' => [fn (User $member, User $moderator, Post $post, Comment $comment) => app(HideCommentAction::class)->handle($moderator, $comment)],
    'a comment restored' => [fn (User $member, User $moderator, Post $post, Comment $comment, Comment $hidden) => app(RestoreCommentAction::class)->handle($moderator, $hidden)],
    'a report that flags the post' => [fn (User $member, User $moderator, Post $post) => app(ReportContentAction::class)->handle($member, $post, ReportReason::Spam)],
]);

it('lets a write on a post and its author following the writer take turns rather than deadlocking', function (string $write, string $hold, bool $moderates, array $after) {
    $db = committedConnection();
    // The author first, so its id is the lower: the follow locks the author before the writer.
    $author = User::factory()->connection('committed_peer')->create();
    $member = User::factory()->connection('committed_peer')->create();
    $moderator = User::factory()->moderator()->connection('committed_peer')->create();
    $writer = $moderates ? $moderator : $member;
    // Two reports already there: the member's is the one that flags the post for review.
    $reporters = User::factory()->count(2)->connection('committed_peer')->create();
    // Its one visible comment counted, so each change to its comments changes the count.
    $post = Post::factory()->published()->connection('committed_peer')->create(['user_id' => $author->id, 'reports_count' => 2, 'comments_count' => 1]);
    $reporters->each(fn (User $reporter) => Report::factory()->connection('committed_peer')->create(['reporter_id' => $reporter->id, 'target_type' => Post::class, 'target_id' => $post->id]));
    // The member's own comment, and one a moderator has hidden.
    $comment = Comment::factory()->connection('committed_peer')->create(['post_id' => $post->id, 'user_id' => $member->id]);
    $hidden = Comment::factory()->connection('committed_peer')->create(['post_id' => $post->id, 'user_id' => $member->id, 'status' => CommentStatus::Hidden]);
    $db->table('project_settings')->where('id', 1)->delete();
    ProjectSettings::factory()->connection('committed_peer')->create(['id' => 1, 'feature_flags' => ['show_follow_buttons' => true]]);
    expect($author->id)->toBeLessThan($writer->id);

    $directory = raceDirectory('post-author');
    $race = fn (string $label, array $job): array => lockRaceCompetitor($directory, $label, [...$job, 'disk' => $directory.'/disk', 'config' => ['cache.default' => 'array']]);

    try {
        ['results' => $results, 'report' => $report] = finishedRace([
            $race('a', ['action' => $write, 'sync' => 'lead', 'hold' => $hold, 'admin' => $writer->id, 'post' => $post->id, 'comment' => $write === 'restore-comment' ? $hidden->id : $comment->id]),
            $race('b', ['action' => 'follow', 'sync' => 'follow', 'admin' => $author->id, 'target' => $writer->id]),
        ]);

        expect($report)->not->toContain('eadlock')
            ->and($results[0]['status'])->toBe(0, $report)
            ->and($results[1]['status'])->toBe(0, $report)
            ->and($db->table('follows')->where('follower_id', $author->id)->where('author_id', $writer->id)->count())->toBe(1)
            ->and((array) $db->table('posts')->where('id', $post->id)->first(array_keys($after)))->toEqual($after);
    } finally {
        forgetPostAuthorRace($db, [$author->id, $member->id, $moderator->id, ...$reporters->modelKeys()], $directory);
    }
})->with([
    'a member\'s vote' => ['vote-post', 'vote', false, ['upvotes_count' => 1]],
    // Visible then: the member's comment and the new one.
    'a member\'s comment' => ['comment', 'comment', false, ['comments_count' => 2]],
    'a member\'s comment deleted' => ['delete-comment', 'comment-change', false, ['comments_count' => 0]],
    'a comment a moderator hides' => ['hide-comment', 'comment-change', true, ['comments_count' => 0]],
    'a comment a moderator restores' => ['restore-comment', 'comment-change', true, ['comments_count' => 2]],
    'a member\'s report that flags the post' => ['report-post', 'report', false, ['reports_count' => 3, 'needs_review' => true]],
])->skip(fn (): bool => config('database.default') !== 'pgsql', POST_AUTHOR_RACE_SKIP);
