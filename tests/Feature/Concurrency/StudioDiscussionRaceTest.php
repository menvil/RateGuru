<?php

use App\Enums\UserStatus;
use App\Models\StudioPlan;
use App\Models\User;

/*
 * Saving a Draft's discussion against real concurrency, on PostgreSQL: every
 * competitor is a process of its own with its own connection
 * (lockRaceCompetitor()). Two administrators saving one discussion take turns
 * on the plan's row, and the later save, drawn from the version the earlier
 * one replaced, is refused rather than written over it. A save and a change
 * to a Persona it names lock the same `users` row in the same order, so they
 * take turns rather than deadlocking — and a Persona deleted first is refused.
 */

const DISCUSSION_RACE_SKIP = 'PostgreSQL only: the competitors find each other through pg_blocking_pids(), and SQLite runs the suite in memory, where a second process cannot join at all. The lock order itself is asserted on every engine by SaveStudioDiscussionTreeActionTest. PostgreSQL is the primary runtime (docs/architecture/database-support.md).';

/** The discussion a competitor saves: one comment by the Persona. */
function racedDiscussion(User $persona, string $body): array
{
    return [['personaUserId' => $persona->id, 'body' => $body, 'offsetSeconds' => 60]];
}

it('lets two administrators save one discussion in turn, refusing the save drawn from the version the first replaced', function () {
    $db = committedConnection();
    [$first, $second] = User::factory()->admin()->count(2)->connection('committed_peer')->create();
    $persona = User::factory()->persona()->connection('committed_peer')->create();
    $plan = StudioPlan::factory()->connection('committed_peer')->create(['created_by_user_id' => $first->id]);
    $directory = raceDirectory('studio-discussion');

    try {
        ['results' => $results, 'report' => $report] = finishedRace([
            lockRaceCompetitor($directory, 'a', ['action' => 'discussion-save', 'sync' => 'lead', 'admin' => $first->id, 'plan' => $plan->id, 'version' => 1, 'nodes' => racedDiscussion($persona, 'First.'), 'disk' => $directory.'/disk']),
            lockRaceCompetitor($directory, 'b', ['action' => 'discussion-save', 'sync' => 'follow', 'admin' => $second->id, 'plan' => $plan->id, 'version' => 1, 'nodes' => racedDiscussion($persona, 'Second.'), 'disk' => $directory.'/disk']),
        ]);

        expect($results[0]['status'])->toBe(0, $report)
            ->and($results[0]['output'])->toContain('version 2')
            ->and($results[1]['status'])->toBe(1, $report)
            ->and($results[1]['output'])->toContain('changed by another administrator')
            ->and($db->table('studio_plan_discussion_nodes')->where('studio_plan_id', $plan->id)->pluck('body')->all())->toBe(['First.'])
            ->and((int) $db->table('studio_plans')->where('id', $plan->id)->value('lock_version'))->toBe(2);
    } finally {
        forgetStudioPlanRace($db, [$first->id, $second->id, $persona->id], $directory);
    }
})->skip(fn (): bool => config('database.default') !== 'pgsql', DISCUSSION_RACE_SKIP);

it('lets a save and the deletion of a Persona it names take turns: saved first, kept; deleted first, refused', function (string $leader, bool $saved) {
    $db = committedConnection();
    $persona = User::factory()->persona()->connection('committed_peer')->create();
    $admin = User::factory()->admin()->connection('committed_peer')->create();
    $plan = StudioPlan::factory()->connection('committed_peer')->create(['created_by_user_id' => $admin->id]);
    $directory = raceDirectory('studio-discussion');
    $save = ['action' => 'discussion-save', 'admin' => $admin->id, 'plan' => $plan->id, 'version' => 1, 'nodes' => racedDiscussion($persona, 'Lovely.'), 'disk' => $directory.'/disk'];
    // The deletion holds the Persona's row, written, until the save waits for it.
    $tombstone = ['action' => 'tombstone', 'admin' => $admin->id, 'target' => $persona->id, 'hold' => 'user', 'disk' => $directory.'/disk'];

    try {
        ['results' => $results, 'report' => $report] = finishedRace($leader === 'save'
            ? [lockRaceCompetitor($directory, 'a', [...$save, 'sync' => 'lead']), lockRaceCompetitor($directory, 'b', [...$tombstone, 'sync' => 'follow'])]
            : [lockRaceCompetitor($directory, 'a', [...$tombstone, 'sync' => 'lead']), lockRaceCompetitor($directory, 'b', [...$save, 'sync' => 'follow'])]);

        [$saveResult, $tombstoneResult] = $leader === 'save' ? $results : array_reverse($results);

        expect($tombstoneResult['status'])->toBe(0, $report)
            ->and($saveResult['status'])->toBe($saved ? 0 : 1, $report)
            ->and($db->table('users')->where('id', $persona->id)->value('status'))->toBe(UserStatus::Deleted->value)
            ->and($db->table('studio_plan_discussion_nodes')->where('studio_plan_id', $plan->id)->count())->toBe($saved ? 1 : 0)
            ->and((int) $db->table('studio_plans')->where('id', $plan->id)->value('lock_version'))->toBe($saved ? 2 : 1);

        if (! $saved) {
            expect($saveResult['output'])->toContain('Choose a persona who is not deleted.');
        }
    } finally {
        forgetStudioPlanRace($db, [$persona->id, $admin->id], $directory);
    }
})->with([
    'the save first: the comment kept, the Persona deleted after it' => ['save', true],
    'the deletion first: the save refused, nothing written' => ['tombstone', false],
])->skip(fn (): bool => config('database.default') !== 'pgsql', DISCUSSION_RACE_SKIP);

it('lets a save and a ban of a Persona it names meet at their first user lock and take turns, both done', function () {
    $db = committedConnection();
    // The Persona first, so its id is the lower: the ban locks it before its
    // administrator, the order the save has to agree with.
    $persona = User::factory()->persona()->connection('committed_peer')->create();
    $admin = User::factory()->admin()->connection('committed_peer')->create();
    $plan = StudioPlan::factory()->connection('committed_peer')->create(['created_by_user_id' => $admin->id]);
    $directory = raceDirectory('studio-discussion');

    try {
        ['results' => $results, 'report' => $report] = finishedRace([
            lockRaceCompetitor($directory, 'a', ['action' => 'discussion-save', 'admin' => $admin->id, 'plan' => $plan->id, 'version' => 1, 'nodes' => racedDiscussion($persona, 'Lovely.'), 'disk' => $directory.'/disk']),
            lockRaceCompetitor($directory, 'b', ['action' => 'ban', 'admin' => $admin->id, 'persona' => $persona->id, 'disk' => $directory.'/disk']),
        ]);

        foreach ($results as $index => $result) {
            expect($result['status'])->toBe(0, "competitor {$index} must finish, not deadlock:\n{$report}");
        }

        // A banned Persona may stay in a draft: whether it can write is asked before anything is published.
        expect($db->table('users')->where('id', $persona->id)->value('status'))->toBe(UserStatus::Banned->value)
            ->and((int) $db->table('studio_plan_discussion_nodes')->where('studio_plan_id', $plan->id)->value('persona_user_id'))->toBe($persona->id);
    } finally {
        forgetStudioPlanRace($db, [$persona->id, $admin->id], $directory);
    }
})->skip(fn (): bool => config('database.default') !== 'pgsql', DISCUSSION_RACE_SKIP);
