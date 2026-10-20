<?php

use App\Enums\StudioActionStatus;
use App\Enums\StudioActionType;
use App\Models\StudioAction;
use App\Models\StudioPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One scheduled domain write of an approved plan, as a row of Content Studio's
 * business queue: its plan, its parent, its Persona, when it happens and when
 * it may run, and where it stands — the database itself holding the closed
 * sets and the references.
 */

/** A row written straight to the table, as nothing in the application writes one. */
function insertStudioActionRow(array $attributes = []): int
{
    return (int) DB::table('studio_plan_actions')->insertGetId([
        'studio_plan_id' => StudioPlan::factory()->create()->id,
        'type' => 'create_post',
        'actor_user_id' => User::factory()->persona()->create()->id,
        'payload' => '[]',
        'occurs_at' => '2026-10-20 10:00:00',
        'run_after' => '2026-10-20 10:00:00',
        'created_at' => now(),
        'updated_at' => now(),
        ...$attributes,
    ]);
}

it('has the columns its contract names', function () {
    expect(Schema::getColumnListing('studio_plan_actions'))->toEqualCanonicalizing([
        'id', 'studio_plan_id', 'parent_action_id', 'type', 'actor_user_id', 'payload', 'occurs_at', 'run_after',
        'status', 'attempts', 'dispatched_at', 'claimed_at', 'finished_at', 'result_type', 'result_id',
        'failure_code', 'failure_reason', 'created_at', 'updated_at',
    ]);
});

it('names its kinds and its states as the business queue\'s format does', function () {
    expect(array_map(fn (StudioActionType $type): string => $type->value, StudioActionType::cases()))
        ->toBe(['create_post', 'create_comment', 'create_reply', 'vote_post', 'vote_comment', 'vote_rating'])
        ->and(array_map(fn (StudioActionStatus $status): string => $status->value, StudioActionStatus::cases()))
        ->toBe(['pending', 'held', 'dispatched', 'running', 'done', 'failed', 'cancelled']);
});

it('starts pending, untried, with nothing to carry', function () {
    $action = StudioAction::query()->findOrFail(insertStudioActionRow());

    expect($action)
        ->status->toBe(StudioActionStatus::Pending)
        ->attempts->toBe(0)
        ->payload->toBe([])
        ->parent_action_id->toBeNull()
        ->dispatched_at->toBeNull()
        ->claimed_at->toBeNull()
        ->finished_at->toBeNull()
        ->result_type->toBeNull()
        ->result_id->toBeNull()
        ->failure_code->toBeNull()
        ->failure_reason->toBeNull()
        ->and(new StudioAction)
        ->status->toBe(StudioActionStatus::Pending)
        ->attempts->toBe(0)
        ->payload->toBe([]);
});

it('keeps when it happens and when it may run as written, in UTC to the second, a past date as well as a future one', function () {
    $action = StudioAction::factory()->create([
        'occurs_at' => CarbonImmutable::parse('1999-12-31 23:59:58', 'UTC'),
        'run_after' => CarbonImmutable::parse('2026-10-20 10:00:01', 'UTC'),
        'payload' => ['note' => 'kept'],
        'result_type' => 'post',
        'result_id' => 42,
    ]);

    $fresh = StudioAction::query()->findOrFail($action->id);

    expect($fresh->occurs_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($fresh->occurs_at->toDateTimeString())->toBe('1999-12-31 23:59:58')
        ->and($fresh->occurs_at->getTimezone()->getName())->toBe('UTC')
        ->and($fresh->run_after->toDateTimeString())->toBe('2026-10-20 10:00:01')
        ->and($fresh)->payload->toBe(['note' => 'kept'])->result_type->toBe('post')->result_id->toBe(42)
        ->and(DB::table('studio_plan_actions')->where('id', $action->id)->value('occurs_at'))->toStartWith('1999-12-31 23:59:58');
});

it('reaches its plan, its Persona, its parent and its children, and its plan reaches it', function () {
    $post = StudioAction::factory()->create();
    $comment = StudioAction::factory()->childOf($post)->create();

    expect($post->plan->is($comment->plan))->toBeTrue()
        ->and($post->actor->isPersona())->toBeTrue()
        ->and($comment->parent->is($post))->toBeTrue()
        ->and($post->children->modelKeys())->toBe([$comment->id])
        ->and($post->plan->actions->modelKeys())->toEqualCanonicalizing([$post->id, $comment->id]);
});

it('lets no mass assignment set anything: every column is the server\'s', function () {
    expect(fn () => (new StudioAction)->fill(['status' => StudioActionStatus::Done, 'attempts' => 3]))
        ->toThrow(MassAssignmentException::class);
});

it('lets the database itself refuse a kind or a state outside the closed sets', function (string $column, string $value) {
    expect(fn () => insertStudioActionRow([$column => $value]))->toThrow(QueryException::class);
})->with([
    'a kind' => ['type', 'publish_everything'],
    'a state' => ['status', 'paused'],
]);

it('points only at rows that exist', function (string $column) {
    expect(fn () => insertStudioActionRow([$column => 2_000_000_000]))->toThrow(QueryException::class);
})->with(['studio_plan_id', 'parent_action_id', 'actor_user_id']);

it('keeps its Persona: the account cannot be deleted from under it', function () {
    $action = StudioAction::factory()->create();

    expect(fn () => DB::table('users')->where('id', $action->actor_user_id)->delete())->toThrow(QueryException::class);
});

it('is deleted with its plan, children and all, and only then', function () {
    $post = StudioAction::factory()->create();
    $comment = StudioAction::factory()->childOf($post)->create();
    $other = StudioAction::factory()->create();

    DB::table('studio_plans')->where('id', $post->studio_plan_id)->delete();

    expect(StudioAction::query()->pluck('id')->all())->toBe([$other->id])
        ->and(StudioAction::query()->whereKey($comment->id)->exists())->toBeFalse();
});

it('indexes what is due, a plan\'s actions, an action\'s children, a Persona\'s actions, and those sent or claimed too long ago', function () {
    $indexed = collect(Schema::getIndexes('studio_plan_actions'))->pluck('columns')->all();

    expect($indexed)
        ->toContain(['status', 'run_after'])
        ->toContain(['studio_plan_id'])
        ->toContain(['parent_action_id'])
        ->toContain(['actor_user_id', 'status'])
        ->toContain(['status', 'dispatched_at'])
        ->toContain(['status', 'claimed_at']);

    $foreignKeys = collect(Schema::getForeignKeys('studio_plan_actions'))
        ->mapWithKeys(fn (array $key): array => [$key['columns'][0] => [$key['foreign_table'], strtolower((string) $key['on_delete'])]])
        ->all();

    expect($foreignKeys)->toEqual([
        'studio_plan_id' => ['studio_plans', 'cascade'],
        'parent_action_id' => ['studio_plan_actions', 'cascade'],
        'actor_user_id' => ['users', 'restrict'],
    ]);
});

it('rolls back and runs again: the table and the approval columns go and come back', function () {
    $paths = [
        'database/migrations/2026_10_20_100100_create_studio_plan_actions_table.php',
        'database/migrations/2026_10_20_100000_add_approval_to_studio_plans_table.php',
    ];

    $this->artisan('migrate:rollback', ['--path' => $paths, '--force' => true])->assertSuccessful();

    expect(Schema::hasTable('studio_plan_actions'))->toBeFalse()
        ->and(Schema::hasColumns('studio_plans', ['approved_by_user_id']))->toBeFalse()
        ->and(Schema::hasColumns('studio_plans', ['approved_at']))->toBeFalse();

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    expect(Schema::hasTable('studio_plan_actions'))->toBeTrue()
        ->and(Schema::hasColumns('studio_plans', ['approved_by_user_id', 'approved_at']))->toBeTrue();
});
