<?php

use App\Enums\StudioPlanStatus;
use App\Models\StudioDiscussionNode;
use App\Models\StudioPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The discussion a Draft prepares, as rows of their own — never a JSON column
 * of the plan, never a comment: each comment keeps its id, a reply names the
 * comment it answers, and a comment goes with its plan and takes its replies
 * with it.
 */
it('has the columns its contract names', function () {
    expect(Schema::getColumnListing('studio_plan_discussion_nodes'))->toEqualCanonicalizing([
        'id', 'studio_plan_id', 'parent_id', 'persona_user_id', 'body', 'sort_order', 'offset_seconds',
        'created_at', 'updated_at',
    ])
        // The discussion is no part of the plan's own row.
        ->and(Schema::getColumnListing('studio_plans'))->not->toContain('discussion');
});

it('relates a plan, its comments, their replies and their Personas, each level in its order', function () {
    $plan = StudioPlan::factory()->create();
    $persona = User::factory()->persona()->create();
    $second = StudioDiscussionNode::factory()->for($plan, 'plan')->create(['sort_order' => 1, 'persona_user_id' => $persona->id]);
    $first = StudioDiscussionNode::factory()->for($plan, 'plan')->create(['sort_order' => 0]);
    $later = StudioDiscussionNode::factory()->replyTo($first)->create(['sort_order' => 1]);
    $earlier = StudioDiscussionNode::factory()->replyTo($first)->create(['sort_order' => 0]);

    expect($plan->discussionNodes()->whereNull('parent_id')->pluck('id')->all())->toBe([$first->id, $second->id])
        ->and($first->replies()->pluck('id')->all())->toBe([$earlier->id, $later->id])
        ->and($later->parent->is($first))->toBeTrue()
        ->and($later->plan->is($plan))->toBeTrue()
        ->and($second->persona->is($persona))->toBeTrue()
        ->and($first->isRoot())->toBeTrue()
        ->and($later->isRoot())->toBeFalse();
});

it('orders equal places by id, so the order never depends on how the database reads them', function () {
    $plan = StudioPlan::factory()->create();
    $nodes = StudioDiscussionNode::factory()->for($plan, 'plan')->count(4)->create(['sort_order' => 0, 'offset_seconds' => 60]);

    expect($plan->discussionNodes()->pluck('id')->all())->toBe($nodes->pluck('id')->sort()->values()->all());
});

it('takes nothing by mass assignment: every column is the server\'s to write', function () {
    expect(fn () => (new StudioDiscussionNode)->fill(['body' => 'Lovely', 'studio_plan_id' => 1]))
        ->toThrow(MassAssignmentException::class);
});

it('goes with its plan, and a comment takes its replies with it', function () {
    $plan = StudioPlan::factory()->create();
    $root = StudioDiscussionNode::factory()->for($plan, 'plan')->create();
    $reply = StudioDiscussionNode::factory()->replyTo($root)->create();
    $other = StudioDiscussionNode::factory()->for($plan, 'plan')->create();

    $root->delete();

    expect(StudioDiscussionNode::query()->whereKey($reply->id)->exists())->toBeFalse()
        ->and(StudioDiscussionNode::query()->whereKey($other->id)->exists())->toBeTrue();

    $plan->delete();

    expect(StudioDiscussionNode::query()->where('studio_plan_id', $plan->id)->exists())->toBeFalse();
});

it('keeps a deleted Persona\'s comment: accounts are never deleted, only made tombstones', function () {
    $node = StudioDiscussionNode::factory()->create();

    // In a savepoint of its own: PostgreSQL aborts the test's transaction on a refused statement.
    expect(fn () => DB::transaction(fn () => DB::table('users')->where('id', $node->persona_user_id)->delete()))->toThrow(QueryException::class)
        ->and(StudioDiscussionNode::query()->whereKey($node->id)->exists())->toBeTrue();
});

it('refuses a reply to a comment that is not there', function () {
    $plan = StudioPlan::factory()->create(['status' => StudioPlanStatus::Draft]);

    expect(fn () => DB::transaction(fn () => DB::table('studio_plan_discussion_nodes')->insert([
        'studio_plan_id' => $plan->id,
        'parent_id' => 2_000_000_000,
        'persona_user_id' => User::factory()->persona()->create()->id,
        'body' => 'Lovely',
        'sort_order' => 0,
        'offset_seconds' => 60,
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(QueryException::class);
});

it('rolls back and runs again: the table goes and comes back, the plans untouched', function () {
    $plan = StudioPlan::factory()->create(['title' => 'Harbour']);
    // The prepared votes and the generations' history point at the comments,
    // so they go first and come back with them.
    $paths = [
        'database/migrations/2026_10_21_100000_create_studio_plan_votes_table.php',
        'database/migrations/2026_10_20_120000_create_studio_plan_discussion_generations_table.php',
        'database/migrations/2026_10_20_110000_create_studio_plan_discussion_nodes_table.php',
    ];

    $this->artisan('migrate:rollback', ['--path' => $paths, '--force' => true])->assertSuccessful();

    expect(Schema::hasTable('studio_plan_discussion_nodes'))->toBeFalse()
        ->and(Schema::hasTable('studio_plan_discussion_generations'))->toBeFalse()
        ->and(Schema::hasTable('studio_plan_votes'))->toBeFalse()
        ->and(StudioPlan::query()->findOrFail($plan->id)->title)->toBe('Harbour');

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    expect(Schema::hasTable('studio_plan_discussion_nodes'))->toBeTrue()
        ->and(Schema::hasTable('studio_plan_discussion_generations'))->toBeTrue()
        ->and(Schema::hasTable('studio_plan_votes'))->toBeTrue()
        ->and(Schema::getColumnListing('studio_plan_discussion_nodes'))->toContain('offset_seconds');
});
