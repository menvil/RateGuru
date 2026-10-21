<?php

use App\Enums\StudioActionStatus;
use App\Enums\StudioActionType;
use App\Enums\StudioPlanStatus;
use App\Models\Comment;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\StudioAction;
use App\Models\StudioPlan;
use App\Models\User;
use App\Support\Studio\StudioExecutionSwitch;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\File;

/*
 * Content Studio's executor against real concurrency, on PostgreSQL: every
 * competitor is a process of its own with its own connection
 * (lockRaceCompetitor()). Whatever runs beside a run — a second delivery of
 * its job, a second dispatcher, recovery, a sanction, a demotion, another
 * post wanting its photo, moderation and voting — the action is sent and
 * applied once, never at an attempt recovery has moved on from, and nobody
 * deadlocks. A worker killed in the middle leaves nothing written, and
 * recovery finishes the action once.
 */

const EXECUTOR_RACE_SKIP = 'PostgreSQL only: the competitors find each other through pg_blocking_pids(), and SQLite runs the suite in memory, where a second process cannot join at all. PostgreSQL is the primary runtime (docs/architecture/database-support.md).';

/**
 * A plan approved and its CreatePost sent — committed, for the competitors to
 * see — with the executor switched on.
 *
 * @return array{0: StudioAction, 1: StudioPlan}
 */
function committedSentStudioAction(User $admin, User $persona): array
{
    $photo = MediaAsset::factory()->postImage()->connection('committed_peer')->create(['owner_user_id' => $admin->id]);
    $plan = StudioPlan::factory()->connection('committed_peer')->create([
        'status' => StudioPlanStatus::Approved,
        'created_by_user_id' => $admin->id,
        'author_user_id' => $persona->id,
        'approved_by_user_id' => $admin->id,
        'approved_at' => now(),
        'title' => 'A quiet harbour at dawn',
        'image_asset_id' => $photo->id,
        'lock_version' => 2,
    ]);
    $action = StudioAction::factory()->connection('committed_peer')->create([
        'studio_plan_id' => $plan->id,
        'actor_user_id' => $persona->id,
        'status' => StudioActionStatus::Dispatched,
        'attempts' => 1,
        'dispatched_at' => now(),
    ]);

    committedConnection()->table('project_settings')->where('id', 1)->delete();
    ProjectSettings::factory()->connection('committed_peer')->create(['id' => 1, 'feature_flags' => [StudioExecutionSwitch::FLAG => true]]);

    return [$action, $plan];
}

/**
 * A plan running, its post published by the author, and a comment of its
 * discussion by the commenter sent — or, for a reply, a comment of it
 * published as well and the reply to it sent — committed for the competitors
 * to see, with the executor switched on.
 *
 * @return array{action: StudioAction, post: Post, comment: Comment|null}
 */
function committedSentStudioComment(User $admin, User $author, User $commenter, bool $reply = false): array
{
    [$post, $plan] = committedSentStudioAction($admin, $author);
    $published = Post::factory()->published()->connection('committed_peer')->create(['user_id' => $author->id, 'image_asset_id' => $plan->image_asset_id, 'title' => 'A quiet harbour at dawn']);
    $plan->forceFill(['status' => StudioPlanStatus::Running])->save();
    $post->forceFill(['status' => StudioActionStatus::Done, 'result_type' => Post::class, 'result_id' => $published->id, 'finished_at' => now()])->save();
    $parent = $post;
    $comment = null;

    if ($reply) {
        $comment = Comment::factory()->connection('committed_peer')->create(['post_id' => $published->id, 'user_id' => $commenter->id, 'body' => 'An earlier comment.']);
        $parent = StudioAction::factory()->childOf($post, StudioActionType::CreateComment)->connection('committed_peer')->create([
            'actor_user_id' => $commenter->id,
            'payload' => ['discussion_node_id' => 1, 'body' => 'An earlier comment.'],
            'status' => StudioActionStatus::Done,
            'attempts' => 1,
            'result_type' => Comment::class,
            'result_id' => $comment->id,
            'finished_at' => now(),
        ]);
    }

    $action = StudioAction::factory()->childOf($parent, $reply ? StudioActionType::CreateReply : StudioActionType::CreateComment)->connection('committed_peer')->create([
        'actor_user_id' => $commenter->id,
        'payload' => ['discussion_node_id' => 2, 'body' => 'Raced at the harbour.'],
        'occurs_at' => now()->subDay(),
        'run_after' => now()->subDay(),
        'status' => StudioActionStatus::Dispatched,
        'attempts' => 1,
        'dispatched_at' => now(),
    ]);

    return ['action' => $action, 'post' => $published, 'comment' => $comment];
}

/** Removes what the competitors committed, as they committed it: for real. */
function forgetExecutorRace(ConnectionInterface $db, array $userIds, string $directory): void
{
    $postIds = $db->table('posts')->whereIn('user_id', $userIds)->pluck('id');

    $db->table('comments')->whereIn('post_id', $postIds)->whereNotNull('parent_id')->delete();
    $db->table('comments')->whereIn('post_id', $postIds)->delete();
    $db->table('notifications')->whereIn('notifiable_id', $userIds)->delete();
    $db->table('moderation_logs')->whereIn('moderator_id', $userIds)->delete();
    $db->table('post_votes')->whereIn('post_id', $postIds)->delete();
    $db->table('posts')->whereIn('id', $postIds)->delete();
    $db->table('studio_plans')->whereIn('created_by_user_id', $userIds)->delete();
    $db->table('media_assets')->whereIn('owner_user_id', $userIds)->delete();
    $db->table('project_settings')->where('id', 1)->delete();
    $db->table('users')->whereIn('id', $userIds)->delete();
    File::deleteDirectory($directory);
}

/**
 * One competitor of an executor race, its disk the race's own, and its cache
 * too: a Persona's posting limit counted in a cache the competitors shared
 * would carry over from one race to the next.
 */
function executorCompetitor(string $directory, string $label, array $job): array
{
    return lockRaceCompetitor($directory, $label, [
        ...$job,
        'disk' => $directory.'/disk',
        'config' => ['cache.default' => 'array', ...($job['config'] ?? [])],
    ]);
}

it('applies an action once when two workers run its job at once', function () {
    $db = committedConnection();
    $persona = User::factory()->persona()->connection('committed_peer')->create();
    $admin = User::factory()->admin()->connection('committed_peer')->create();
    [$action] = committedSentStudioAction($admin, $persona);
    $directory = raceDirectory('studio-executor');

    try {
        ['results' => $results, 'report' => $report] = finishedRace([
            executorCompetitor($directory, 'a', ['action' => 'execute', 'sync' => 'lead', 'hold' => 'post', 'admin' => $admin->id, 'studio_action' => $action->id]),
            executorCompetitor($directory, 'b', ['action' => 'execute', 'sync' => 'follow', 'admin' => $admin->id, 'studio_action' => $action->id]),
        ]);

        expect($results[0]['status'])->toBe(0, $report)
            ->and($results[0]['output'])->toContain('became done')
            ->and($results[1]['status'])->toBe(0, $report)
            ->and($results[1]['output'])->toContain('became nothing')
            ->and($db->table('posts')->where('user_id', $persona->id)->count())->toBe(1)
            ->and($db->table('studio_plan_actions')->where('id', $action->id)->first())
            ->status->toBe('done')
            ->attempts->toBe(1);
    } finally {
        forgetExecutorRace($db, [$persona->id, $admin->id], $directory);
    }
})->skip(fn (): bool => config('database.default') !== 'pgsql', EXECUTOR_RACE_SKIP);

it('sends a due action once when two dispatchers run at once: one attempt, one send', function () {
    $db = committedConnection();
    $persona = User::factory()->persona()->connection('committed_peer')->create();
    $admin = User::factory()->admin()->connection('committed_peer')->create();
    [$action] = committedSentStudioAction($admin, $persona);
    // Due and never sent yet.
    $db->table('studio_plan_actions')->where('id', $action->id)->update(['status' => 'pending', 'attempts' => 0, 'dispatched_at' => null]);
    $directory = raceDirectory('studio-executor');

    try {
        ['results' => $results, 'report' => $report] = finishedRace([
            // Holds the action's row, its send not yet committed, until the other waits for it.
            executorCompetitor($directory, 'a', ['action' => 'dispatch', 'sync' => 'lead', 'hold' => 'action', 'admin' => $admin->id]),
            executorCompetitor($directory, 'b', ['action' => 'dispatch', 'sync' => 'follow', 'admin' => $admin->id]),
        ]);

        expect($results[0]['status'])->toBe(0, $report)
            ->and($results[0]['output'])->toContain('"sent":1')
            ->and($results[1]['status'])->toBe(0, $report)
            ->and($results[1]['output'])->toContain('"sent":0')
            ->and($db->table('studio_plan_actions')->where('id', $action->id)->first())
            ->status->toBe('dispatched')
            ->attempts->toBe(1);
    } finally {
        forgetExecutorRace($db, [$persona->id, $admin->id], $directory);
    }
})->skip(fn (): bool => config('database.default') !== 'pgsql', EXECUTOR_RACE_SKIP);

it('lets recovery wait for a run still alive, and find it done: no second post', function () {
    $db = committedConnection();
    $persona = User::factory()->persona()->connection('committed_peer')->create();
    $admin = User::factory()->admin()->connection('committed_peer')->create();
    [$action] = committedSentStudioAction($admin, $persona);
    $directory = raceDirectory('studio-executor');

    try {
        ['results' => $results, 'report' => $report] = finishedRace([
            executorCompetitor($directory, 'a', ['action' => 'execute', 'sync' => 'lead', 'hold' => 'post', 'admin' => $admin->id, 'studio_action' => $action->id]),
            // Recovery that takes any running action as left behind.
            executorCompetitor($directory, 'b', ['action' => 'dispatch', 'sync' => 'follow', 'admin' => $admin->id, 'config' => ['content_studio.actions.running_stale_after_seconds' => 0]]),
        ]);

        expect($results[0]['output'])->toContain('became done')
            ->and($results[1]['status'])->toBe(0, $report)
            ->and($results[1]['output'])->toContain('"resent":0')
            ->and($db->table('posts')->where('user_id', $persona->id)->count())->toBe(1)
            ->and($db->table('studio_plan_actions')->where('id', $action->id)->first())
            ->status->toBe('done')
            ->attempts->toBe(1);
    } finally {
        forgetExecutorRace($db, [$persona->id, $admin->id], $directory);
    }
})->skip(fn (): bool => config('database.default') !== 'pgsql', EXECUTOR_RACE_SKIP);

it('leaves nothing of a worker killed with its post written, and finishes the action once after recovery, at its second attempt', function () {
    $db = committedConnection();
    $persona = User::factory()->persona()->connection('committed_peer')->create();
    $admin = User::factory()->admin()->connection('committed_peer')->create();
    [$action] = committedSentStudioAction($admin, $persona);
    $directory = raceDirectory('studio-executor');

    try {
        // The worker dies with the post inserted and its transaction open.
        $killed = finishedRace([executorCompetitor($directory, 'a', ['action' => 'execute', 'sync' => 'lead', 'hold' => 'post', 'die' => true, 'admin' => $admin->id, 'studio_action' => $action->id])])['results'][0];

        expect($killed['status'])->toBe(137, $killed['output'].$killed['stderr'])
            ->and($db->table('posts')->where('user_id', $persona->id)->exists())->toBeFalse()
            ->and($db->table('studio_plan_actions')->where('id', $action->id)->first())
            ->status->toBe('running')
            ->attempts->toBe(1);

        // Recovery, then the job it sent.
        $recovered = finishedRace([executorCompetitor($directory, 'b', ['action' => 'dispatch-execute', 'sync' => 'solo', 'admin' => $admin->id, 'studio_action' => $action->id, 'config' => ['content_studio.actions.running_stale_after_seconds' => 0]])])['results'][0];

        expect($recovered['status'])->toBe(0, $recovered['output'].$recovered['stderr'])
            ->and($recovered['output'])->toContain('"resent":1')->toContain('became done')
            ->and($db->table('posts')->where('user_id', $persona->id)->count())->toBe(1)
            ->and($db->table('studio_plan_actions')->where('id', $action->id)->first())
            ->status->toBe('done')
            ->attempts->toBe(2);
    } finally {
        forgetExecutorRace($db, [$persona->id, $admin->id], $directory);
    }
})->skip(fn (): bool => config('database.default') !== 'pgsql', EXECUTOR_RACE_SKIP);

it('lets a run that comes late, after recovery sent its action again and that attempt finished, write nothing', function () {
    $db = committedConnection();
    $persona = User::factory()->persona()->connection('committed_peer')->create();
    $admin = User::factory()->admin()->connection('committed_peer')->create();
    [$action] = committedSentStudioAction($admin, $persona);
    $directory = raceDirectory('studio-executor');

    try {
        ['results' => $results, 'report' => $report] = finishedRace([
            // Claimed, then stalled before its run.
            executorCompetitor($directory, 'a', ['action' => 'execute', 'sync' => 'lead', 'hold' => 'run', 'admin' => $admin->id, 'studio_action' => $action->id]),
            executorCompetitor($directory, 'b', ['action' => 'dispatch-execute', 'sync' => 'follow', 'admin' => $admin->id, 'studio_action' => $action->id, 'config' => ['content_studio.actions.running_stale_after_seconds' => 0]]),
        ]);

        expect($results[1]['output'])->toContain('"resent":1')->toContain('became done')
            ->and($results[0]['status'])->toBe(0, $report)
            ->and($results[0]['output'])->toContain('became nothing')
            ->and($db->table('posts')->where('user_id', $persona->id)->count())->toBe(1)
            ->and($db->table('studio_plan_actions')->where('id', $action->id)->first())
            ->status->toBe('done')
            ->attempts->toBe(2);
    } finally {
        forgetExecutorRace($db, [$persona->id, $admin->id], $directory);
    }
})->skip(fn (): bool => config('database.default') !== 'pgsql', EXECUTOR_RACE_SKIP);

it('lets a run and a ban of its Persona, or a demotion of its approver, take turns rather than deadlocking', function (string $sanction, string $refused) {
    $db = committedConnection();
    // The Persona first, so its id is the lowest: both lock it before anyone else.
    $persona = User::factory()->persona()->connection('committed_peer')->create();
    [$admin, $other] = User::factory()->admin()->count(2)->connection('committed_peer')->create();
    [$action] = committedSentStudioAction($admin, $persona);
    $directory = raceDirectory('studio-executor');

    try {
        ['results' => $results, 'report' => $report] = finishedRace([
            executorCompetitor($directory, 'a', ['action' => 'execute', 'admin' => $admin->id, 'studio_action' => $action->id]),
            executorCompetitor($directory, 'b', ['action' => $sanction, 'admin' => $other->id, 'persona' => $persona->id, 'target' => $sanction === 'demote' ? $admin->id : $persona->id]),
        ]);

        expect($report)->not->toContain('eadlock')
            ->and($results[0]['status'])->toBe(0, $report)
            ->and($results[1]['status'])->toBe(0, $report);

        $after = $db->table('studio_plan_actions')->where('id', $action->id)->first();
        $posts = $db->table('posts')->where('user_id', $persona->id)->count();

        // Whichever took the shared row first: published before, or refused after.
        if ($after->status === 'done') {
            expect($posts)->toBe(1);
        } else {
            expect($after->failure_code)->toBe($refused)
                ->and($posts)->toBe(0);
        }
    } finally {
        forgetExecutorRace($db, [$persona->id, $admin->id, $other->id], $directory);
    }
})->with([
    'the Persona banned' => ['ban', 'author_banned'],
    'the approver demoted' => ['demote', 'approver_not_allowed'],
])->skip(fn (): bool => config('database.default') !== 'pgsql', EXECUTOR_RACE_SKIP);

it('gives one stored photo to one post when two would use it at once', function () {
    $db = committedConnection();
    [$first, $second] = User::factory()->persona()->count(2)->connection('committed_peer')->create();
    $admin = User::factory()->admin()->connection('committed_peer')->create();
    $photo = MediaAsset::factory()->postImage()->connection('committed_peer')->create(['owner_user_id' => $admin->id]);
    $directory = raceDirectory('studio-executor');

    try {
        ['results' => $results, 'report' => $report] = finishedRace([
            executorCompetitor($directory, 'a', ['action' => 'post-from-asset', 'sync' => 'lead', 'hold' => 'post', 'admin' => $admin->id, 'persona' => $first->id, 'asset' => $photo->id, 'title' => 'First harbour']),
            executorCompetitor($directory, 'b', ['action' => 'post-from-asset', 'sync' => 'follow', 'admin' => $admin->id, 'persona' => $second->id, 'asset' => $photo->id, 'title' => 'Second harbour']),
        ]);

        expect($results[0]['status'])->toBe(0, $report)
            ->and($results[1]['status'])->toBe(1, $report)
            ->and($results[1]['output'])->toContain('not a ready post image no post uses')
            ->and($db->table('posts')->where('image_asset_id', $photo->id)->pluck('user_id')->all())->toBe([$first->id]);
    } finally {
        forgetExecutorRace($db, [$first->id, $second->id, $admin->id], $directory);
    }
})->skip(fn (): bool => config('database.default') !== 'pgsql', EXECUTOR_RACE_SKIP);

it('runs beside a moderator hiding and a member voting on the Persona\'s other post, without deadlocking', function (string $follower) {
    $db = committedConnection();
    $persona = User::factory()->persona()->connection('committed_peer')->create();
    [$admin] = User::factory()->admin()->count(1)->connection('committed_peer')->create();
    $member = User::factory()->connection('committed_peer')->create();
    [$action] = committedSentStudioAction($admin, $persona);
    $other = Post::factory()->published()->connection('committed_peer')->create(['user_id' => $persona->id, 'title' => 'An older harbour']);
    $directory = raceDirectory('studio-executor');

    try {
        ['results' => $results, 'report' => $report] = finishedRace([
            executorCompetitor($directory, 'a', ['action' => 'execute', 'sync' => 'lead', 'hold' => 'post', 'admin' => $admin->id, 'studio_action' => $action->id]),
            executorCompetitor($directory, 'b', ['action' => $follower, 'sync' => 'follow', 'admin' => $follower === 'hide-post' ? $admin->id : $member->id, 'post' => $other->id]),
        ]);

        expect($report)->not->toContain('eadlock')
            ->and($results[0]['output'])->toContain('became done')
            ->and($results[1]['status'])->toBe(0, $report)
            ->and($db->table('posts')->where('user_id', $persona->id)->count())->toBe(2);
    } finally {
        forgetExecutorRace($db, [$persona->id, $admin->id, $member->id], $directory);
    }
})->with(['hide-post', 'vote-post'])->skip(fn (): bool => config('database.default') !== 'pgsql', EXECUTOR_RACE_SKIP);

it('applies a comment once when two workers run its job at once', function () {
    $db = committedConnection();
    [$author, $commenter] = User::factory()->persona()->count(2)->connection('committed_peer')->create();
    $admin = User::factory()->admin()->connection('committed_peer')->create();
    ['action' => $action, 'post' => $post] = committedSentStudioComment($admin, $author, $commenter);
    $directory = raceDirectory('studio-executor');

    try {
        ['results' => $results, 'report' => $report] = finishedRace([
            executorCompetitor($directory, 'a', ['action' => 'execute', 'sync' => 'lead', 'hold' => 'comment', 'admin' => $admin->id, 'studio_action' => $action->id]),
            executorCompetitor($directory, 'b', ['action' => 'execute', 'sync' => 'follow', 'admin' => $admin->id, 'studio_action' => $action->id]),
        ]);

        expect($results[0]['status'])->toBe(0, $report)
            ->and($results[0]['output'])->toContain('became done')
            ->and($results[1]['output'])->toContain('became nothing')
            ->and($db->table('comments')->where('post_id', $post->id)->count())->toBe(1)
            ->and((int) $db->table('posts')->where('id', $post->id)->value('comments_count'))->toBe(1)
            ->and($db->table('notifications')->where('notifiable_id', $author->id)->count())->toBe(1)
            ->and($db->table('studio_plan_actions')->where('id', $action->id)->first())->status->toBe('done')->attempts->toBe(1);
    } finally {
        forgetExecutorRace($db, [$author->id, $commenter->id, $admin->id], $directory);
    }
})->skip(fn (): bool => config('database.default') !== 'pgsql', EXECUTOR_RACE_SKIP);

it('lets a comment run and a ban of its Persona, or of the post\'s author it notifies, take turns rather than deadlocking', function (string $banned) {
    $db = committedConnection();
    [$author, $commenter] = User::factory()->persona()->count(2)->connection('committed_peer')->create();
    [$admin, $other] = User::factory()->admin()->count(2)->connection('committed_peer')->create();
    ['action' => $action, 'post' => $post] = committedSentStudioComment($admin, $author, $commenter);
    $target = $banned === 'commenter' ? $commenter : $author;
    $directory = raceDirectory('studio-executor');

    try {
        ['results' => $results, 'report' => $report] = finishedRace([
            executorCompetitor($directory, 'a', ['action' => 'execute', 'admin' => $admin->id, 'studio_action' => $action->id]),
            executorCompetitor($directory, 'b', ['action' => 'ban', 'admin' => $other->id, 'persona' => $target->id]),
        ]);

        expect($report)->not->toContain('eadlock')
            ->and($results[0]['status'])->toBe(0, $report)
            ->and($results[1]['status'])->toBe(0, $report)
            ->and($db->table('users')->where('id', $target->id)->value('status'))->toBe('banned');

        $after = $db->table('studio_plan_actions')->where('id', $action->id)->first();
        $comments = $db->table('comments')->where('post_id', $post->id)->count();

        // Whichever took the shared row first: written before, or refused after
        // — and a ban of the post's author never stops another Persona's comment.
        if ($banned === 'author' || $after->status === 'done') {
            expect($after->status)->toBe('done')->and($comments)->toBe(1);
        } else {
            expect($after->failure_code)->toBe('author_banned')->and($comments)->toBe(0);
        }
    } finally {
        forgetExecutorRace($db, [$author->id, $commenter->id, $admin->id, $other->id], $directory);
    }
})->with(['commenter', 'author'])->skip(fn (): bool => config('database.default') !== 'pgsql', EXECUTOR_RACE_SKIP);

it('lets a comment run and a moderator hiding its post, or a reply run and a moderator hiding its comment, take turns rather than deadlocking', function (bool $reply) {
    $db = committedConnection();
    [$author, $commenter] = User::factory()->persona()->count(2)->connection('committed_peer')->create();
    $admin = User::factory()->admin()->connection('committed_peer')->create();
    ['action' => $action, 'post' => $post, 'comment' => $comment] = committedSentStudioComment($admin, $author, $commenter, $reply);
    $directory = raceDirectory('studio-executor');

    try {
        ['results' => $results, 'report' => $report] = finishedRace([
            executorCompetitor($directory, 'a', ['action' => 'execute', 'admin' => $admin->id, 'studio_action' => $action->id]),
            executorCompetitor($directory, 'b', $reply
                ? ['action' => 'hide-comment', 'admin' => $admin->id, 'comment' => $comment->id]
                : ['action' => 'hide-post', 'admin' => $admin->id, 'post' => $post->id]),
        ]);

        expect($report)->not->toContain('eadlock')
            ->and($results[0]['status'])->toBe(0, $report)
            ->and($results[1]['status'])->toBe(0, $report);

        $after = $db->table('studio_plan_actions')->where('id', $action->id)->first();
        $written = $db->table('comments')->where('post_id', $post->id)->where('body', 'Raced at the harbour.')->count();

        // Written before the hide, or refused after it — never under what was hidden first.
        if ($after->status === 'done') {
            expect($written)->toBe(1);
        } else {
            expect($after->status)->toBe('cancelled')
                ->and($after->failure_code)->toBe($reply ? 'reply_target_unavailable' : 'post_unavailable')
                ->and($written)->toBe(0);
        }
    } finally {
        forgetExecutorRace($db, [$author->id, $commenter->id, $admin->id], $directory);
    }
})->with(['a comment' => false, 'a reply' => true])->skip(fn (): bool => config('database.default') !== 'pgsql', EXECUTOR_RACE_SKIP);

it('writes a Persona\'s comment beside a member\'s own comment or vote on the same post, without deadlocking', function (string $beside) {
    $db = committedConnection();
    [$author, $commenter] = User::factory()->persona()->count(2)->connection('committed_peer')->create();
    $admin = User::factory()->admin()->connection('committed_peer')->create();
    $member = User::factory()->connection('committed_peer')->create();
    ['action' => $action, 'post' => $post] = committedSentStudioComment($admin, $author, $commenter);
    $directory = raceDirectory('studio-executor');

    try {
        ['results' => $results, 'report' => $report] = finishedRace([
            executorCompetitor($directory, 'a', ['action' => 'execute', 'admin' => $admin->id, 'studio_action' => $action->id]),
            executorCompetitor($directory, 'b', ['action' => $beside === 'comment' ? 'comment' : 'vote-post', 'admin' => $member->id, 'post' => $post->id]),
        ]);

        // Both update the post twice in one transaction — counts, then score.
        expect($report)->not->toContain('eadlock')
            ->and($results[0]['status'])->toBe(0, $report)
            ->and($results[0]['output'])->toContain('became done')
            ->and($results[1]['status'])->toBe(0, $report)
            ->and($db->table('comments')->where('post_id', $post->id)->count())->toBe($beside === 'comment' ? 2 : 1)
            ->and((int) $db->table('posts')->where('id', $post->id)->value('comments_count'))->toBe($beside === 'comment' ? 2 : 1);
    } finally {
        $db->table('comments')->where('user_id', $member->id)->delete();
        forgetExecutorRace($db, [$author->id, $commenter->id, $admin->id, $member->id], $directory);
    }
})->with(['comment', 'vote'])->skip(fn (): bool => config('database.default') !== 'pgsql', EXECUTOR_RACE_SKIP);
