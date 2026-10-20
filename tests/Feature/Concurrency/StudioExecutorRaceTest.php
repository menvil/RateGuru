<?php

use App\Enums\StudioActionStatus;
use App\Enums\StudioPlanStatus;
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

/** Removes what the competitors committed, as they committed it: for real. */
function forgetExecutorRace(ConnectionInterface $db, array $userIds, string $directory): void
{
    $postIds = $db->table('posts')->whereIn('user_id', $userIds)->pluck('id');

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
