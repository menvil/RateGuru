<?php

use App\Actions\Rating\ArchiveRatingOptionAction;
use App\Actions\Rating\DeleteRatingOptionAction;
use App\Enums\StudioActionType;
use App\Enums\VoteType;
use App\Models\RatingOption;
use App\Models\StudioDiscussionNode;
use App\Models\StudioPlan;
use App\Models\StudioPlanVote;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The votes a Draft prepares, as rows of their own: one Persona's intention
 * to vote on the post, on one prepared comment or for one rating option —
 * never a real vote, never a post's or a comment's id. The database holds
 * each kind to its own fields, one vote per Persona and target whichever way
 * it goes, and takes a vote away only with its plan or its comment.
 */
beforeEach(function () {
    $this->plan = StudioPlan::factory()->create();
    $this->persona = User::factory()->persona()->create();
    $this->node = StudioDiscussionNode::factory()->for($this->plan, 'plan')->create(['offset_seconds' => 120]);
    [$this->group, [$this->option, $this->other]] = votableRatingQuestion();
});

/**
 * A row as it would be written straight into the table, past every rule:
 * a valid up vote on the post unless the fields say otherwise.
 *
 * @param  array<string, mixed>  $fields
 * @return array<string, mixed>
 */
function rawStudioVote(array $fields = []): array
{
    return [
        'studio_plan_id' => test()->plan->id,
        'persona_user_id' => test()->persona->id,
        'type' => 'vote_post',
        'vote_type' => 'up',
        'discussion_node_id' => null,
        'rating_group_id' => null,
        'rating_option_id' => null,
        'target_key' => 'post',
        'offset_seconds' => 60,
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
        ...$fields,
    ];
}

/** Whether the database takes the row; refused in a savepoint of its own, as PostgreSQL aborts a transaction on a refused statement. */
function studioVoteRowTaken(array $row): bool
{
    try {
        DB::transaction(fn () => DB::table('studio_plan_votes')->insert($row));
    } catch (QueryException) {
        return false;
    }

    return true;
}

it('has the columns its contract names, and no post or comment of the site', function () {
    expect(Schema::getColumnListing('studio_plan_votes'))->toEqualCanonicalizing([
        'id', 'studio_plan_id', 'persona_user_id', 'type', 'vote_type', 'discussion_node_id',
        'rating_group_id', 'rating_option_id', 'target_key', 'offset_seconds', 'sort_order',
        'created_at', 'updated_at',
    ]);
});

it('relates its plan, its Persona, its comment and its rating option, each plan\'s votes in the order they are cast', function () {
    $onComment = StudioPlanVote::factory()->onComment($this->node)->create(['persona_user_id' => $this->persona->id, 'offset_seconds' => 300]);
    $rating = StudioPlanVote::factory()->forRating($this->option)->for($this->plan, 'plan')->create(['offset_seconds' => 60, 'sort_order' => 1]);
    $post = StudioPlanVote::factory()->for($this->plan, 'plan')->create(['offset_seconds' => 60]);
    $tied = StudioPlanVote::factory()->for($this->plan, 'plan')->create(['offset_seconds' => 60, 'sort_order' => 1]);

    expect($this->plan->votes()->pluck('id')->all())->toBe([$post->id, $rating->id, $tied->id, $onComment->id])
        ->and($onComment->plan->is($this->plan))->toBeTrue()
        ->and($onComment->persona->is($this->persona))->toBeTrue()
        ->and($onComment->discussionNode->is($this->node))->toBeTrue()
        ->and($this->node->votes()->pluck('id')->all())->toBe([$onComment->id])
        ->and($rating->ratingGroup->is($this->group))->toBeTrue()
        ->and($rating->ratingOption->is($this->option))->toBeTrue()
        ->and($onComment->type)->toBe(StudioActionType::VoteComment)
        ->and($onComment->vote_type)->toBe(VoteType::Up)
        ->and($rating->vote_type)->toBeNull();
});

it('takes nothing by mass assignment: every column is the server\'s to write', function () {
    expect(fn () => (new StudioPlanVote)->fill(['target_key' => 'post', 'studio_plan_id' => 1]))
        ->toThrow(MassAssignmentException::class);
});

it('computes its target key from its target every time it is saved, whatever a caller set', function () {
    $post = StudioPlanVote::factory()->for($this->plan, 'plan')->create(['target_key' => 'rating:1']);
    $comment = StudioPlanVote::factory()->onComment($this->node)->create(['target_key' => 'post']);
    $rating = StudioPlanVote::factory()->forRating($this->option)->for($this->plan, 'plan')->create(['target_key' => 'comment:1']);

    expect($post->fresh()->target_key)->toBe('post')
        ->and($comment->fresh()->target_key)->toBe("comment:{$this->node->id}")
        ->and($rating->fresh()->target_key)->toBe("rating:{$this->group->id}");

    $rating->forceFill(['target_key' => 'post', 'rating_option_id' => $this->other->id])->save();

    expect($rating->fresh()->target_key)->toBe("rating:{$this->group->id}");
});

it('refuses a Persona\'s second vote on one target, whichever way it goes', function (Closure $first, Closure $second) {
    $first($this);

    expect(fn () => DB::transaction(fn () => $second($this)))->toThrow(QueryException::class)
        ->and(StudioPlanVote::query()->count())->toBe(1);
})->with([
    'up and down on the post' => [
        fn ($test) => StudioPlanVote::factory()->for($test->plan, 'plan')->create(['persona_user_id' => $test->persona->id]),
        fn ($test) => StudioPlanVote::factory()->down()->for($test->plan, 'plan')->create(['persona_user_id' => $test->persona->id]),
    ],
    'up and down on one comment' => [
        fn ($test) => StudioPlanVote::factory()->onComment($test->node)->create(['persona_user_id' => $test->persona->id]),
        fn ($test) => StudioPlanVote::factory()->down()->onComment($test->node)->create(['persona_user_id' => $test->persona->id]),
    ],
    'two options of one rating question' => [
        fn ($test) => StudioPlanVote::factory()->forRating($test->option)->for($test->plan, 'plan')->create(['persona_user_id' => $test->persona->id]),
        fn ($test) => StudioPlanVote::factory()->forRating($test->other)->for($test->plan, 'plan')->create(['persona_user_id' => $test->persona->id]),
    ],
]);

it('takes one vote of each Persona on each target: the post, every comment and every rating question', function () {
    $second = User::factory()->persona()->create();
    $reply = StudioDiscussionNode::factory()->replyTo($this->node)->create();
    [, [$elsewhere]] = votableRatingQuestion();

    foreach ([$this->persona, $second] as $persona) {
        StudioPlanVote::factory()->for($this->plan, 'plan')->create(['persona_user_id' => $persona->id]);
        StudioPlanVote::factory()->onComment($this->node)->create(['persona_user_id' => $persona->id]);
        StudioPlanVote::factory()->down()->onComment($reply)->create(['persona_user_id' => $persona->id]);
        StudioPlanVote::factory()->forRating($this->option)->for($this->plan, 'plan')->create(['persona_user_id' => $persona->id]);
        StudioPlanVote::factory()->forRating($elsewhere)->for($this->plan, 'plan')->create(['persona_user_id' => $persona->id]);
    }

    // The same Persona's vote on another plan's post is another vote.
    StudioPlanVote::factory()->create(['persona_user_id' => $this->persona->id]);

    expect(StudioPlanVote::query()->where('studio_plan_id', $this->plan->id)->count())->toBe(10)
        ->and(StudioPlanVote::query()->count())->toBe(11);
});

it('holds each kind of vote to its own fields, its target key and its bounds, on every engine', function (Closure $row) {
    expect(studioVoteRowTaken(rawStudioVote()))->toBeTrue()
        ->and(studioVoteRowTaken(rawStudioVote($row($this))))->toBeFalse();
})->with([
    'a vote of no known kind' => [fn ($test) => ['type' => 'vote_profile', 'persona_user_id' => User::factory()->persona()->create()->id]],
    'a post, comment or reply' => [fn ($test) => ['type' => 'create_post', 'persona_user_id' => User::factory()->persona()->create()->id]],
    'a direction of no known kind' => [fn ($test) => ['vote_type' => 'sideways', 'persona_user_id' => User::factory()->persona()->create()->id]],
    'a vote on the post with no direction' => [fn ($test) => ['vote_type' => null, 'persona_user_id' => User::factory()->persona()->create()->id]],
    'a vote on the post naming a comment' => [fn ($test) => ['discussion_node_id' => $test->node->id, 'persona_user_id' => User::factory()->persona()->create()->id]],
    'a vote on the post naming a rating option' => [fn ($test) => ['rating_group_id' => $test->group->id, 'rating_option_id' => $test->option->id, 'persona_user_id' => User::factory()->persona()->create()->id]],
    'a vote on the post keyed as a comment' => [fn ($test) => ['target_key' => 'comment:1']],
    'a vote on a comment naming none' => [fn ($test) => ['type' => 'vote_comment', 'target_key' => 'comment:1']],
    'a vote on a comment naming a rating option too' => [fn ($test) => ['type' => 'vote_comment', 'discussion_node_id' => $test->node->id, 'target_key' => "comment:{$test->node->id}", 'rating_group_id' => $test->group->id, 'rating_option_id' => $test->option->id]],
    'a vote on a comment keyed as the post' => [fn ($test) => ['type' => 'vote_comment', 'discussion_node_id' => $test->node->id, 'target_key' => 'post', 'persona_user_id' => User::factory()->persona()->create()->id]],
    'a rating vote with a direction' => [fn ($test) => ['type' => 'vote_rating', 'rating_group_id' => $test->group->id, 'rating_option_id' => $test->option->id, 'target_key' => "rating:{$test->group->id}"]],
    'a rating vote naming no option' => [fn ($test) => ['type' => 'vote_rating', 'vote_type' => null, 'rating_group_id' => $test->group->id, 'target_key' => "rating:{$test->group->id}"]],
    'a rating vote naming no question' => [fn ($test) => ['type' => 'vote_rating', 'vote_type' => null, 'rating_option_id' => $test->option->id, 'target_key' => 'rating:1']],
    'a rating vote naming a comment' => [fn ($test) => ['type' => 'vote_rating', 'vote_type' => null, 'discussion_node_id' => $test->node->id, 'rating_group_id' => $test->group->id, 'rating_option_id' => $test->option->id, 'target_key' => "rating:{$test->group->id}"]],
    'a rating vote keyed as the post' => [fn ($test) => ['type' => 'vote_rating', 'vote_type' => null, 'rating_group_id' => $test->group->id, 'rating_option_id' => $test->option->id, 'target_key' => 'post', 'persona_user_id' => User::factory()->persona()->create()->id]],
    'a moment before the post' => [fn ($test) => ['offset_seconds' => -1, 'persona_user_id' => User::factory()->persona()->create()->id]],
    'a moment past thirty days' => [fn ($test) => ['offset_seconds' => 2_592_001, 'persona_user_id' => User::factory()->persona()->create()->id]],
    'a place past the last' => [fn ($test) => ['sort_order' => 65_536, 'persona_user_id' => User::factory()->persona()->create()->id]],
    'a plan that is not there' => [fn ($test) => ['studio_plan_id' => 2_000_000_000, 'persona_user_id' => User::factory()->persona()->create()->id]],
    'a Persona who is not there' => [fn ($test) => ['persona_user_id' => 2_000_000_000]],
]);

it('takes each kind of vote with its own fields, and the bounds themselves', function () {
    $node = $this->node->id;

    expect(studioVoteRowTaken(rawStudioVote(['vote_type' => 'down', 'offset_seconds' => 0, 'sort_order' => 65_535])))->toBeTrue()
        ->and(studioVoteRowTaken(rawStudioVote(['type' => 'vote_comment', 'discussion_node_id' => $node, 'target_key' => "comment:{$node}", 'offset_seconds' => 2_592_000])))->toBeTrue()
        ->and(studioVoteRowTaken(rawStudioVote(['type' => 'vote_rating', 'vote_type' => null, 'rating_group_id' => $this->group->id, 'rating_option_id' => $this->option->id, 'target_key' => "rating:{$this->group->id}"])))->toBeTrue();
});

it('never reaches another plan\'s comment, nor one that is not there', function () {
    $theirs = StudioDiscussionNode::factory()->create();

    expect(studioVoteRowTaken(rawStudioVote(['type' => 'vote_comment', 'discussion_node_id' => $theirs->id, 'target_key' => "comment:{$theirs->id}"])))->toBeFalse()
        ->and(studioVoteRowTaken(rawStudioVote(['type' => 'vote_comment', 'discussion_node_id' => 2_000_000_000, 'target_key' => 'comment:2000000000'])))->toBeFalse()
        ->and(studioVoteRowTaken(rawStudioVote(['type' => 'vote_comment', 'discussion_node_id' => $this->node->id, 'target_key' => "comment:{$this->node->id}"])))->toBeTrue();
});

it('goes with its comment — a comment taking its replies\' votes along — and with its plan, every other vote kept', function () {
    $reply = StudioDiscussionNode::factory()->replyTo($this->node)->create();
    $other = StudioDiscussionNode::factory()->for($this->plan, 'plan')->create();
    $onRoot = StudioPlanVote::factory()->onComment($this->node)->create();
    $onReply = StudioPlanVote::factory()->onComment($reply)->create();
    $onOther = StudioPlanVote::factory()->onComment($other)->create();
    $onPost = StudioPlanVote::factory()->for($this->plan, 'plan')->create();
    $rating = StudioPlanVote::factory()->forRating($this->option)->for($this->plan, 'plan')->create();
    $elsewhere = StudioPlanVote::factory()->create();

    $this->node->delete();

    expect(StudioPlanVote::query()->whereKey([$onRoot->id, $onReply->id])->exists())->toBeFalse()
        ->and(StudioPlanVote::query()->orderBy('id')->pluck('id')->all())->toBe([$onOther->id, $onPost->id, $rating->id, $elsewhere->id]);

    $this->plan->delete();

    expect(StudioPlanVote::query()->pluck('id')->all())->toBe([$elsewhere->id]);
});

it('keeps a deleted Persona\'s vote: accounts are never deleted, only made tombstones', function () {
    $vote = StudioPlanVote::factory()->for($this->plan, 'plan')->create(['persona_user_id' => $this->persona->id]);

    expect(fn () => DB::transaction(fn () => DB::table('users')->where('id', $this->persona->id)->delete()))->toThrow(QueryException::class)
        ->and(StudioPlanVote::query()->findOrFail($vote->id)->persona_user_id)->toBe($this->persona->id);
});

it('leaves the rating configuration its own: an option archived or deleted stays the one chosen, never another', function () {
    $admin = User::factory()->admin()->create();
    // Four active options, so the question keeps its two once one is deleted and one archived.
    [$third] = RatingOption::factory()->count(2)->create(['rating_group_id' => $this->group->id])->all();
    $archived = StudioPlanVote::factory()->forRating($this->option)->for($this->plan, 'plan')->create();
    $deleted = StudioPlanVote::factory()->forRating($third)->for($this->plan, 'plan')->create();

    app(DeleteRatingOptionAction::class)->handle($admin, $third->fresh());
    app(ArchiveRatingOptionAction::class)->handle($admin, $this->option);

    expect(RatingOption::query()->whereKey($third->id)->exists())->toBeFalse()
        ->and($this->option->fresh()->is_active)->toBeFalse()
        ->and(StudioPlanVote::query()->findOrFail($archived->id))
        ->rating_option_id->toBe($this->option->id)
        ->rating_group_id->toBe($this->group->id)
        ->and(StudioPlanVote::query()->findOrFail($deleted->id))
        ->rating_option_id->toBe($third->id)
        ->rating_group_id->toBe($this->group->id)
        ->ratingOption->toBeNull();
});

it('rolls back and runs again: the table goes and comes back, the plans and their discussions untouched', function () {
    $this->plan->forceFill(['title' => 'Harbour'])->save();
    StudioPlanVote::factory()->onComment($this->node)->create();
    $path = 'database/migrations/2026_10_21_100000_create_studio_plan_votes_table.php';

    $this->artisan('migrate:rollback', ['--path' => $path, '--force' => true])->assertSuccessful();

    expect(Schema::hasTable('studio_plan_votes'))->toBeFalse()
        ->and(Schema::hasIndex('studio_plan_discussion_nodes', 'studio_discussion_nodes_id_plan_unique'))->toBeFalse()
        ->and(StudioPlan::query()->findOrFail($this->plan->id)->title)->toBe('Harbour')
        ->and(StudioDiscussionNode::query()->whereKey($this->node->id)->exists())->toBeTrue();

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    expect(Schema::hasTable('studio_plan_votes'))->toBeTrue()
        ->and(Schema::hasIndex('studio_plan_discussion_nodes', 'studio_discussion_nodes_id_plan_unique'))->toBeTrue()
        ->and(Schema::getColumnListing('studio_plan_votes'))->toContain('target_key');
});
