<?php

use App\Enums\StudioDiscussionGenerationError;
use App\Models\MediaAsset;
use App\Models\PersonaProfile;
use App\Models\StudioPlan;
use App\Models\StudioPlanImageAnalysis;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\File;

/*
 * Discussion generation against real concurrency, on PostgreSQL: every
 * competitor is a process of its own with its own connection
 * (lockRaceCompetitor()), the provider faked in each. Two administrators
 * asking at once get one generation; a job delivered twice makes one paid
 * request; a Persona changed while a job runs leaves its proposal unwritten;
 * and taking a proposal in races a hand save, a deletion, a new date for the
 * post and another administrator taking it in, the later one refused — never
 * a deadlock, never a hand edit written over, never a proposal taken in twice.
 */

const GENERATION_RACE_SKIP = 'PostgreSQL only: the competitors find each other through pg_blocking_pids(), and SQLite runs the suite in memory, where a second process cannot join at all. PostgreSQL is the primary runtime (docs/architecture/database-support.md).';

/**
 * A Draft a discussion may be written for, committed for the competitors to
 * see: a photo with a finished analysis and a final title; and two Personas
 * with profiles.
 *
 * @return array{0: StudioPlan, 1: User, 2: User}
 */
function committedDiscussionPlan(User $creator): array
{
    $photo = MediaAsset::factory()->postImage()->connection('committed_peer')->create(['owner_user_id' => $creator->id]);
    $plan = StudioPlan::factory()->connection('committed_peer')->create([
        'created_by_user_id' => $creator->id,
        'title' => 'A quiet harbour at dawn',
        'image_asset_id' => $photo->id,
    ]);
    StudioPlanImageAnalysis::factory()->forPlan($plan)->completed()->connection('committed_peer')->create();
    $personas = User::factory()->persona()->count(2)->connection('committed_peer')->create();

    foreach ($personas as $persona) {
        PersonaProfile::factory()->connection('committed_peer')->create(['user_id' => $persona->id]);
    }

    return [$plan, $personas[0], $personas[1]];
}

/** Removes what the competitors committed, profiles first: an account's profile holds it. */
function forgetGenerationRace(ConnectionInterface $db, array $userIds, string $directory): void
{
    $db->table('studio_plans')->whereIn('created_by_user_id', $userIds)->delete();
    $db->table('persona_profiles')->whereIn('user_id', $userIds)->delete();
    forgetStudioPlanRace($db, $userIds, $directory);
}

/** How many requests the faked provider was sent, by every competitor together. */
function providerRequests(string $directory): int
{
    return count(File::glob($directory.'/http-*'));
}

/** The provider's answer to a whole discussion of one comment by the first Persona. */
function oneCommentResponse(): array
{
    return studioDiscussionBody(['comments' => [answeredComment('node_1', null, 'persona_1', 60, 'Somebody owns this harbour now.')]]);
}

/**
 * A generation asked for and written, step by step, by competitors of their
 * own — so it is committed, and the plan's version is the one it was asked at.
 */
function committedCompletedGeneration(string $directory, User $admin, StudioPlan $plan, array $personaIds): int
{
    $request = finishedRace([lockRaceCompetitor($directory, 'a', ['action' => 'discussion-request', 'sync' => 'solo', 'admin' => $admin->id, 'plan' => $plan->id, 'version' => 1, 'personas' => $personaIds, 'disk' => $directory.'/disk'])])['results'][0];
    expect($request['status'])->toBe(0, $request['output']);
    $generation = committedConnection()->table('studio_plan_discussion_generations')->where('studio_plan_id', $plan->id)->orderByDesc('id')->first();

    $job = finishedRace([lockRaceCompetitor($directory, 'a', ['action' => 'discussion-job', 'sync' => 'solo', 'admin' => $admin->id, 'generation' => $generation->id, 'attempt' => $generation->attempt_id, 'response' => oneCommentResponse(), 'disk' => $directory.'/disk'])])['results'][0];
    expect($job['status'])->toBe(0, $job['output'])
        ->and(committedConnection()->table('studio_plan_discussion_generations')->where('id', $generation->id)->value('status'))->toBe('completed');

    return (int) $generation->id;
}

it('gives two administrators asking at once the same one generation', function () {
    $db = committedConnection();
    [$first, $second] = User::factory()->admin()->count(2)->connection('committed_peer')->create();
    [$plan, $anna, $boris] = committedDiscussionPlan($first);
    $directory = raceDirectory('studio-generation');
    $ask = fn (string $label, User $admin, string $sync) => lockRaceCompetitor($directory, $label, ['action' => 'discussion-request', 'sync' => $sync, 'admin' => $admin->id, 'plan' => $plan->id, 'version' => 1, 'personas' => [$anna->id], 'disk' => $directory.'/disk']);

    try {
        ['results' => $results, 'report' => $report] = finishedRace([$ask('a', $first, 'lead'), $ask('b', $second, 'follow')]);

        $generations = $db->table('studio_plan_discussion_generations')->where('studio_plan_id', $plan->id)->pluck('id')->all();

        expect($results[0]['status'])->toBe(0, $report)
            ->and($results[1]['status'])->toBe(0, $report)
            ->and($generations)->toHaveCount(1)
            ->and($results[0]['output'])->toContain('generation '.$generations[0])
            ->and($results[1]['output'])->toContain('generation '.$generations[0])
            ->and((int) $db->table('studio_plans')->where('id', $plan->id)->value('lock_version'))->toBe(1);
    } finally {
        forgetGenerationRace($db, [$first->id, $second->id, $anna->id, $boris->id], $directory);
    }
})->skip(fn (): bool => config('database.default') !== 'pgsql', GENERATION_RACE_SKIP);

it('makes one paid request when its job is delivered twice at once', function () {
    $db = committedConnection();
    $admin = User::factory()->admin()->connection('committed_peer')->create();
    [$plan, $anna, $boris] = committedDiscussionPlan($admin);
    $directory = raceDirectory('studio-generation');

    try {
        $request = finishedRace([lockRaceCompetitor($directory, 'a', ['action' => 'discussion-request', 'sync' => 'solo', 'admin' => $admin->id, 'plan' => $plan->id, 'version' => 1, 'personas' => [$anna->id], 'disk' => $directory.'/disk'])])['results'][0];
        expect($request['status'])->toBe(0, $request['output']);
        $generation = $db->table('studio_plan_discussion_generations')->where('studio_plan_id', $plan->id)->first();
        $job = ['action' => 'discussion-job', 'admin' => $admin->id, 'generation' => $generation->id, 'attempt' => $generation->attempt_id, 'response' => oneCommentResponse(), 'disk' => $directory.'/disk'];

        ['results' => $results, 'report' => $report] = finishedRace([
            // Claimed, and held there until the second delivery has done all it would.
            lockRaceCompetitor($directory, 'a', [...$job, 'sync' => 'lead', 'hold' => 'claim']),
            lockRaceCompetitor($directory, 'b', [...$job, 'sync' => 'follow']),
        ]);

        expect($results[0]['status'])->toBe(0, $report)
            ->and($results[1]['status'])->toBe(0, $report)
            ->and(providerRequests($directory))->toBe(1)
            ->and($db->table('studio_plan_discussion_generations')->where('id', $generation->id)->value('status'))->toBe('completed');
    } finally {
        forgetGenerationRace($db, [$admin->id, $anna->id, $boris->id], $directory);
    }
})->skip(fn (): bool => config('database.default') !== 'pgsql', GENERATION_RACE_SKIP);

it('writes no proposal, and sends nothing, when a Persona taking part is changed while its job runs', function () {
    $db = committedConnection();
    $admin = User::factory()->admin()->connection('committed_peer')->create();
    [$plan, $anna, $boris] = committedDiscussionPlan($admin);
    $directory = raceDirectory('studio-generation');

    try {
        $request = finishedRace([lockRaceCompetitor($directory, 'a', ['action' => 'discussion-request', 'sync' => 'solo', 'admin' => $admin->id, 'plan' => $plan->id, 'version' => 1, 'personas' => [$anna->id], 'disk' => $directory.'/disk'])])['results'][0];
        expect($request['status'])->toBe(0, $request['output']);
        $generation = $db->table('studio_plan_discussion_generations')->where('studio_plan_id', $plan->id)->first();

        ['results' => $results, 'report' => $report] = finishedRace([
            lockRaceCompetitor($directory, 'a', ['action' => 'discussion-job', 'sync' => 'lead', 'hold' => 'claim', 'admin' => $admin->id, 'generation' => $generation->id, 'attempt' => $generation->attempt_id, 'response' => oneCommentResponse(), 'disk' => $directory.'/disk']),
            // Her character rewritten by another administrator meanwhile.
            lockRaceCompetitor($directory, 'b', ['action' => 'edit', 'sync' => 'follow', 'admin' => $admin->id, 'persona' => $anna->id, 'disk' => $directory.'/disk']),
        ]);

        $after = $db->table('studio_plan_discussion_generations')->where('id', $generation->id)->first();

        expect($results[0]['status'])->toBe(0, $report)
            ->and($results[1]['status'])->toBe(0, $report)
            ->and($after->status)->toBe('failed')
            ->and($after->error_code)->toBe(StudioDiscussionGenerationError::StaleContext->value)
            ->and($after->result)->toBeNull()
            ->and(providerRequests($directory))->toBe(0);
    } finally {
        forgetGenerationRace($db, [$admin->id, $anna->id, $boris->id], $directory);
    }
})->skip(fn (): bool => config('database.default') !== 'pgsql', GENERATION_RACE_SKIP);

it('lets taking a proposal in and another write to the discussion or the plan take turns, the later refused, nothing written over', function (string $other, string $leader, Closure $otherJob) {
    $db = committedConnection();
    [$first, $second] = User::factory()->admin()->count(2)->connection('committed_peer')->create();
    [$plan, $anna, $boris] = committedDiscussionPlan($first);
    $directory = raceDirectory('studio-generation');

    try {
        $generationId = committedCompletedGeneration($directory, $first, $plan, [$anna->id]);
        $apply = ['action' => 'discussion-apply', 'admin' => $first->id, 'plan' => $plan->id, 'generation' => $generationId, 'version' => 1, 'disk' => $directory.'/disk'];
        $write = ['admin' => $second->id, 'plan' => $plan->id, 'version' => 1, 'disk' => $directory.'/disk', ...$otherJob($anna, $boris, $generationId)];

        ['results' => $results, 'report' => $report] = finishedRace($leader === 'apply'
            ? [lockRaceCompetitor($directory, 'a', [...$apply, 'sync' => 'lead']), lockRaceCompetitor($directory, 'b', [...$write, 'sync' => 'follow'])]
            : [lockRaceCompetitor($directory, 'a', [...$write, 'sync' => 'lead']), lockRaceCompetitor($directory, 'b', [...$apply, 'sync' => 'follow'])]);

        [$applyResult, $writeResult] = $leader === 'apply' ? $results : array_reverse($results);
        $applied = $db->table('studio_plan_discussion_generations')->where('id', $generationId)->value('applied_at') !== null;
        $after = $db->table('studio_plans')->where('id', $plan->id)->first();
        $bodies = $db->table('studio_plan_discussion_nodes')->where('studio_plan_id', $plan->id)->pluck('body')->all();

        // The first is written; the second, drawn from the version it replaced, is refused.
        expect($applyResult['status'])->toBe($leader === 'apply' ? 0 : 1, $report)
            ->and($writeResult['status'])->toBe($leader === 'apply' ? 1 : 0, $report)
            ->and($leader === 'apply' ? $writeResult['output'] : $applyResult['output'])->toContain('changed by another administrator')
            ->and($applied)->toBe($leader === 'apply')
            ->and((int) $after->lock_version)->toBe(2)
            ->and($bodies)->toBe(match (true) {
                $leader === 'apply' => ['Somebody owns this harbour now.'],
                $other === 'a hand save' => ['Written by hand.'],
                default => [],
            });

        if ($other === 'a new date' && $leader === 'apply') {
            expect($after->publish_at)->toBeNull();
        }
    } finally {
        forgetGenerationRace($db, [$first->id, $second->id, $anna->id, $boris->id], $directory);
    }
})->with([
    'a hand save, the proposal first' => ['a hand save', 'apply', fn (User $anna) => ['action' => 'discussion-save', 'nodes' => [['personaUserId' => $anna->id, 'body' => 'Written by hand.', 'offsetSeconds' => 60]]]],
    'a hand save first' => ['a hand save', 'write', fn (User $anna) => ['action' => 'discussion-save', 'nodes' => [['personaUserId' => $anna->id, 'body' => 'Written by hand.', 'offsetSeconds' => 60]]]],
    'the discussion emptied first' => ['a deletion', 'write', fn () => ['action' => 'discussion-save', 'nodes' => []]],
    'a new date, the proposal first' => ['a new date', 'apply', fn () => ['action' => 'plan-date', 'publish_at' => '2038-01-18 23:59:30']],
    'a new date first' => ['a new date', 'write', fn () => ['action' => 'plan-date', 'publish_at' => '2038-01-18 23:59:30']],
])->skip(fn (): bool => config('database.default') !== 'pgsql', GENERATION_RACE_SKIP);

it('takes a proposal in once when two administrators take it in at once', function () {
    $db = committedConnection();
    [$first, $second] = User::factory()->admin()->count(2)->connection('committed_peer')->create();
    [$plan, $anna, $boris] = committedDiscussionPlan($first);
    $directory = raceDirectory('studio-generation');

    try {
        $generationId = committedCompletedGeneration($directory, $first, $plan, [$anna->id]);
        $apply = fn (string $label, User $admin, string $sync) => lockRaceCompetitor($directory, $label, ['action' => 'discussion-apply', 'sync' => $sync, 'admin' => $admin->id, 'plan' => $plan->id, 'generation' => $generationId, 'version' => 1, 'disk' => $directory.'/disk']);

        ['results' => $results, 'report' => $report] = finishedRace([$apply('a', $first, 'lead'), $apply('b', $second, 'follow')]);

        expect($results[0]['status'])->toBe(0, $report)
            ->and($results[0]['output'])->toContain('version 2')
            ->and($results[1]['status'])->toBe(1, $report)
            ->and($results[1]['output'])->toContain('changed by another administrator')
            ->and($db->table('studio_plan_discussion_generations')->where('id', $generationId)->value('applied_at'))->not->toBeNull()
            ->and($db->table('studio_plan_discussion_nodes')->where('studio_plan_id', $plan->id)->pluck('body')->all())->toBe(['Somebody owns this harbour now.'])
            ->and((int) $db->table('studio_plans')->where('id', $plan->id)->value('lock_version'))->toBe(2);
    } finally {
        forgetGenerationRace($db, [$first->id, $second->id, $anna->id, $boris->id], $directory);
    }
})->skip(fn (): bool => config('database.default') !== 'pgsql', GENERATION_RACE_SKIP);
