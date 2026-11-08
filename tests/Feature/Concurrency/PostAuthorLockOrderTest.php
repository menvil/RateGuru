<?php

use App\Actions\Comments\AddCommentAction;
use App\Actions\Comments\DeleteCommentAction;
use App\Actions\Comments\HideCommentAction;
use App\Actions\Comments\RestoreCommentAction;
use App\Actions\Reports\ReportContentAction;
use App\Actions\Votes\VotePostAction;
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
 * A member's vote, comment or report on a post locks the member, then the post
 * (docs/architecture/user-lifecycle.md), and never the post's author after it.
 * PostgreSQL would lock the author all the same, unasked, were the post updated
 * twice in that transaction: it checks the second update's foreign keys, the
 * author's among them, with FOR KEY SHARE on the author's row. A two-user
 * operation locks both users in ascending id order — a follow, here, of the
 * member by the post's author, the lower id: holding the author and waiting for
 * the member, while the member's write holds the member and the post and waits
 * for that check, each would wait for the other.
 *
 * So each such write updates the post once — and so does a comment deleted,
 * hidden or restored, which recounts the post's comments as a new one does —
 * which every engine shows; the race itself runs on PostgreSQL. There the
 * member's write leads
 * (lockRaceCompetitor()): it holds its vote, comment or report, written, until
 * the follow is seen waiting for it.
 */

const POST_AUTHOR_RACE_SKIP = 'PostgreSQL only: the competitors find each other through pg_blocking_pids(), and SQLite runs the suite in memory, where a second process cannot join at all. PostgreSQL is the primary runtime (docs/architecture/database-support.md).';

/**
 * Removes what a race of a member's write and a follow committed, for real:
 * the post and all written on it, the follow, the notifications, the settings
 * row and the users.
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

it('lets a member\'s write on a post and its author following the member take turns rather than deadlocking', function (string $write, string $hold) {
    $db = committedConnection();
    // The author first, so its id is the lower: the follow locks the author before the member.
    $author = User::factory()->connection('committed_peer')->create();
    $member = User::factory()->connection('committed_peer')->create();
    // Two reports already there: the member's is the one that flags the post for review.
    $reporters = User::factory()->count(2)->connection('committed_peer')->create();
    $post = Post::factory()->published()->connection('committed_peer')->create(['user_id' => $author->id, 'reports_count' => 2]);
    $reporters->each(fn (User $reporter) => Report::factory()->connection('committed_peer')->create(['reporter_id' => $reporter->id, 'target_type' => Post::class, 'target_id' => $post->id]));
    $db->table('project_settings')->where('id', 1)->delete();
    ProjectSettings::factory()->connection('committed_peer')->create(['id' => 1, 'feature_flags' => ['show_follow_buttons' => true]]);
    expect($author->id)->toBeLessThan($member->id);

    $directory = raceDirectory('post-author');
    $race = fn (string $label, array $job): array => lockRaceCompetitor($directory, $label, [...$job, 'disk' => $directory.'/disk', 'config' => ['cache.default' => 'array']]);

    try {
        ['results' => $results, 'report' => $report] = finishedRace([
            $race('a', ['action' => $write, 'sync' => 'lead', 'hold' => $hold, 'admin' => $member->id, 'post' => $post->id]),
            $race('b', ['action' => 'follow', 'sync' => 'follow', 'admin' => $author->id, 'target' => $member->id]),
        ]);

        expect($report)->not->toContain('eadlock')
            ->and($results[0]['status'])->toBe(0, $report)
            ->and($results[1]['status'])->toBe(0, $report)
            ->and($db->table('follows')->where('follower_id', $author->id)->where('author_id', $member->id)->count())->toBe(1);

        $written = $db->table('posts')->where('id', $post->id)->first();

        match ($write) {
            'vote-post' => expect($written)->upvotes_count->toBe(1),
            'comment' => expect($written)->comments_count->toBe(1),
            'report-post' => expect($written)->reports_count->toBe(3)->needs_review->toBeTrue(),
        };
    } finally {
        forgetPostAuthorRace($db, [$author->id, $member->id, ...$reporters->modelKeys()], $directory);
    }
})->with([
    'a vote' => ['vote-post', 'vote'],
    'a comment' => ['comment', 'comment'],
    'a report that flags the post' => ['report-post', 'report'],
])->skip(fn (): bool => config('database.default') !== 'pgsql', POST_AUTHOR_RACE_SKIP);
