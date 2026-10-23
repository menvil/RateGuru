<?php

use App\Data\Studio\BuiltStudioDiscussionVotes;
use App\Data\Studio\StudioDiscussionVotesSettings;
use App\Data\Studio\StudioDiscussionVoteTargetSettings;
use App\Data\Studio\StudioVoteData;
use App\Enums\StudioPlanPublicationMode;
use App\Enums\UserStatus;
use App\Models\StudioDiscussionNode;
use App\Models\StudioPlan;
use App\Models\StudioPlanVote;
use App\Models\User;
use App\Support\Studio\BuildStudioDiscussionVotesData;
use App\Support\Studio\StudioPlanValidationRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Making the votes on a plan's prepared comments and replies from what the
 * Comment votes editor asks for: each Persona once per comment, never on its
 * own, the counts and moments exactly as asked, after each comment, the same
 * votes for the same draw — and the whole request refused, never cut, when it
 * can't be met.
 */
beforeEach(function () {
    [$this->anna, $this->boris, $this->carl, $this->dana, $this->eli] = collect(['Anna', 'Boris', 'Carl', 'Dana', 'Eli'])
        ->map(fn (string $name): User => User::factory()->persona()->create(['display_name' => $name]))
        ->all();
    $this->plan = StudioPlan::factory()->create();
    $this->comment = StudioDiscussionNode::factory()->for($this->plan, 'plan')->create(['persona_user_id' => $this->anna->id, 'offset_seconds' => 300]);
    $this->reply = StudioDiscussionNode::factory()->replyTo($this->comment)->create(['persona_user_id' => $this->boris->id, 'offset_seconds' => 600]);
    $this->other = StudioDiscussionNode::factory()->for($this->plan, 'plan')->create(['persona_user_id' => $this->carl->id, 'offset_seconds' => 900, 'sort_order' => 1]);
});

/**
 * The votes made of these settings, a comment's votes as `node => [up, down, first, spread]`.
 *
 * @param  array<int, array{0: int, 1: int, 2?: int, 3?: int}>  $targets
 */
function buildCommentVotes(StudioPlan $plan, array $targets, array $settings = []): BuiltStudioDiscussionVotes
{
    $made = [];

    foreach ($targets as $node => [$up, $down]) {
        $made[$node] = new StudioDiscussionVoteTargetSettings($node, $up, $down, 'normal', $targets[$node][2] ?? 300, $targets[$node][3] ?? 3600);
    }

    return app(BuildStudioDiscussionVotesData::class)->handle($plan->fresh(), new StudioDiscussionVotesSettings(...['voterCount' => 5, 'seed' => 3, ...$settings, 'targets' => $made]));
}

/** What the build refuses, by field; none when it goes through. */
function commentVotesBuildErrors(StudioPlan $plan, array $targets, array $settings = []): array
{
    try {
        buildCommentVotes($plan, $targets, $settings);
    } catch (ValidationException $invalid) {
        return array_map(fn (array $messages): string => $messages[0], $invalid->errors());
    }

    return [];
}

/** @return list<array{persona: int, node: int, way: string, offset: int}> */
function builtCommentVotes(BuiltStudioDiscussionVotes $built): array
{
    return array_map(fn (StudioVoteData $vote): array => [
        'persona' => $vote->personaUserId,
        'node' => $vote->discussionNodeId,
        'way' => $vote->voteType,
        'offset' => (int) $vote->offsetSeconds,
    ], $built->votes->votes);
}

it('votes a comment up and down by different Personas, never its own', function () {
    $votes = builtCommentVotes(buildCommentVotes($this->plan, [$this->comment->id => [3, 1]]));

    expect($votes)->toHaveCount(4)
        ->and(array_count_values(array_column($votes, 'way')))->toEqualCanonicalizing(['up' => 3, 'down' => 1])
        ->and(array_unique(array_column($votes, 'persona')))->toHaveCount(4)
        ->and(array_column($votes, 'persona'))->not->toContain($this->anna->id)
        ->and(array_unique(array_column($votes, 'node')))->toBe([$this->comment->id]);
});

it('votes a reply, its own Persona left out and the comment it answers\' Persona not', function () {
    $votes = builtCommentVotes(buildCommentVotes($this->plan, [$this->reply->id => [4, 0]]));

    expect(array_column($votes, 'persona'))->toEqualCanonicalizing([$this->anna->id, $this->carl->id, $this->dana->id, $this->eli->id]);
});

it('lets one Persona vote on several comments, once each', function () {
    $votes = builtCommentVotes(buildCommentVotes($this->plan, [
        $this->comment->id => [2, 0], $this->reply->id => [1, 1], $this->other->id => [0, 2],
    ], ['voterMode' => 'manual', 'voterIds' => [$this->dana->id, $this->eli->id]]));

    expect(array_count_values(array_column($votes, 'persona')))->toEqualCanonicalizing([$this->dana->id => 3, $this->eli->id => 3])
        ->and(collect($votes)->groupBy('node')->map(fn ($on) => $on->pluck('persona')->unique()->count())->all())
        ->toEqualCanonicalizing([$this->comment->id => 2, $this->reply->id => 2, $this->other->id => 2]);
});

it('shares the votes among the voters, each comment drawing its own rather than the same few on every comment', function () {
    $fay = User::factory()->persona()->create(['display_name' => 'Fay']);
    $comments = collect(range(2, 4))->map(fn (int $order): int => StudioDiscussionNode::factory()->for($this->plan, 'plan')->create(['persona_user_id' => $fay->id, 'offset_seconds' => 300 * $order, 'sort_order' => $order])->id);
    $build = fn (int $seed): array => builtCommentVotes(buildCommentVotes($this->plan, $comments->mapWithKeys(fn (int $id): array => [$id => [1, 1]])->all(), [
        'voterMode' => 'manual', 'voterIds' => [$this->anna->id, $this->boris->id, $this->carl->id, $this->dana->id, $this->eli->id], 'seed' => $seed,
    ]));

    foreach (range(1, 5) as $seed) {
        expect(collect($build($seed))->pluck('persona')->unique()->count())->toBeGreaterThan(2);
    }

    expect($build(3))->toBe($build(3));
});

it('refuses a comment more votes than its voters but its own Persona can give, saying how many short', function () {
    $chosen = ['voterMode' => 'manual', 'voterIds' => [$this->anna->id, $this->dana->id]];

    expect(commentVotesBuildErrors($this->plan, [$this->comment->id => [2, 0]], $chosen))
        ->toBe(["targets.{$this->comment->id}" => 'Its 2 votes need 2 personas who can vote on it; 1 can — 1 short. Choose more personas, or ask for fewer.'])
        // One Persona never votes a comment both ways.
        ->and(commentVotesBuildErrors($this->plan, [$this->other->id => [1, 1]], ['voterMode' => 'manual', 'voterIds' => [$this->dana->id]]))
        ->toBe(["targets.{$this->other->id}" => 'Its 2 votes need 2 personas who can vote on it; 1 can — 1 short. Choose more personas, or ask for fewer.'])
        // Anna writes the comment, so votes on the other one.
        ->and(builtCommentVotes(buildCommentVotes($this->plan, [$this->other->id => [2, 0]], $chosen)))->toHaveCount(2);
});

it('draws the voters from the active Personas, the same for the same seed and others for another', function () {
    User::factory()->persona()->create(['status' => UserStatus::Banned]);
    $draw = fn (int $seed): array => buildCommentVotes($this->plan, [$this->other->id => [2, 0]], ['voterCount' => 3, 'seed' => $seed])->voters;
    $draws = array_map(fn (int $seed): string => implode(',', $draw($seed)), range(1, 20));

    expect($draw(9))->toBe($draw(9))->toHaveCount(3)
        ->and(array_unique($draws))->not->toHaveCount(1)
        ->and(commentVotesBuildErrors($this->plan, [], ['voterCount' => 6]))->toBe(['voterCount' => 'Choose from 0 to 5 personas: that many can vote.']);
});

it('shares a total among the comments by priority, the remainders by the largest fractions and then in the tree\'s order', function (array $priorities, int $total, array $shares) {
    expect(BuildStudioDiscussionVotesData::distribute($priorities, $total))->toBe($shares);
})->with([
    'low, normal and high' => [[11 => 'low', 12 => 'normal', 13 => 'high'], 10, [11 => 2, 12 => 3, 13 => 5]],
    'equal remainders to the first in order' => [[21 => 'normal', 22 => 'normal', 23 => 'normal'], 4, [21 => 2, 22 => 1, 23 => 1]],
    'the order given, not the ids' => [[33 => 'normal', 31 => 'normal'], 1, [33 => 1, 31 => 0]],
    'none' => [[41 => 'high', 42 => 'low'], 0, [41 => 0, 42 => 0]],
    'more than the weights' => [[51 => 'high', 52 => 'low'], 7, [51 => 5, 52 => 2]],
]);

it('dates each comment\'s votes from the comment, the first after it and the last after the spread', function (int $count, array $offsets) {
    $votes = builtCommentVotes(buildCommentVotes($this->plan, [$this->comment->id => [$count, 0, 300, 3600]]));

    expect(array_column($votes, 'offset'))->toBe($offsets);
})->with([
    'none' => [0, []],
    'one, at its first moment' => [1, [600]],
    'three, evenly' => [3, [600, 2400, 4200]],
    'four, rounded down' => [4, [600, 1800, 3000, 4200]],
]);

it('brings a vote set to a moment of its own then, the others at their places, and never one before its comment', function () {
    $spread = buildCommentVotes($this->plan, [$this->comment->id => [3, 0, 300, 3600]]);
    $middle = builtCommentVotes($spread)[1]['persona'];
    $own = fn (int $seconds): array => [$this->comment->id => new StudioDiscussionVoteTargetSettings($this->comment->id, 3, 0, 'normal', 300, 3600, [$middle => $seconds])];
    $build = fn (int $seconds) => app(BuildStudioDiscussionVotesData::class)->handle($this->plan->fresh(), new StudioDiscussionVotesSettings(voterCount: 5, seed: 3, targets: $own($seconds)));

    expect(array_map(fn (array $vote): array => [$vote['persona'], $vote['offset']], builtCommentVotes($build(7200))))
        ->toBe([[builtCommentVotes($spread)[0]['persona'], 600], [builtCommentVotes($spread)[2]['persona'], 4200], [$middle, 7500]]);

    $refusal = studioRefusal(fn () => $build(-1));

    expect($refusal->errors())->toBe(["timing.{$this->comment->id}" => ['No vote can come before the comment it is on.']]);
});

it('keeps the votes of one moment in one order, the same every time', function () {
    $first = buildCommentVotes($this->plan, [$this->other->id => [2, 1, 60, 0]])->votes->votes;
    $again = buildCommentVotes($this->plan, [$this->other->id => [2, 1, 60, 0]])->votes->votes;

    expect(array_map(fn (StudioVoteData $vote): array => [$vote->personaUserId, $vote->sortOrder, $vote->offsetSeconds], $first))
        ->toBe(array_map(fn (StudioVoteData $vote): array => [$vote->personaUserId, $vote->sortOrder, $vote->offsetSeconds], $again))
        ->and(array_column(builtCommentVotes(buildCommentVotes($this->plan, [$this->other->id => [2, 1, 60, 0]])), 'offset'))->toBe([960, 960, 960]);
});

it('takes a vote thirty days after the post and refuses one a second later, or past the dates a post may carry', function () {
    $last = 2_592_000 - 300 - 600;

    expect(commentVotesBuildErrors($this->plan, [$this->comment->id => [2, 0, 600, $last]]))->toBe([])
        ->and(commentVotesBuildErrors($this->plan, [$this->comment->id => [2, 0, 600, $last + 1]]))
        ->toBe(["timing.{$this->comment->id}" => 'Its votes would end more than 30 days after the post. Start sooner, or spread them over less time.']);

    $this->plan->forceFill(['publication_mode' => StudioPlanPublicationMode::AtDate, 'publish_at' => CarbonImmutable::parse(StudioPlanValidationRules::LATEST_PUBLISH_AT)->subHour()])->save();

    expect(commentVotesBuildErrors($this->plan, [$this->other->id => [1, 0, 3600, 0]]))
        ->toBe(["timing.{$this->other->id}" => 'Its votes would end after 2038, past the latest date a post may carry. Start sooner, or spread them over less time.']);
});

it('refuses more votes than a plan may prepare, counting the votes on the post and its questions', function () {
    $voters = User::factory()->persona()->count(25)->create();
    $rows = [];

    foreach ($voters as $voter) {
        foreach (range(1, 40) as $group) {
            $rows[] = ['studio_plan_id' => $this->plan->id, 'persona_user_id' => $voter->id, 'type' => 'vote_rating', 'vote_type' => null, 'rating_group_id' => $group, 'rating_option_id' => $group, 'target_key' => "rating:{$group}", 'offset_seconds' => 60, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()];
        }
    }

    foreach (array_chunk(array_slice($rows, 1), 250) as $chunk) {
        DB::table('studio_plan_votes')->insert($chunk);
    }

    expect(commentVotesBuildErrors($this->plan, [$this->other->id => [1, 0]]))->toBe([])
        ->and(commentVotesBuildErrors($this->plan, [$this->other->id => [2, 0]]))
        ->toBe(['votes' => 'A plan prepares at most 1,000 votes; these and the post’s come to 1,001. Ask for fewer.']);
});

it('keeps the id of a saved vote its Persona casts on the same comment again, its way and moment changed', function () {
    $built = buildCommentVotes($this->plan, [$this->other->id => [2, 1]]);
    saveStudioVotes(User::factory()->admin()->create(), $this->plan, $built->votes->votes);
    $ids = StudioPlanVote::query()->pluck('id', 'persona_user_id')->all();

    $again = buildCommentVotes($this->plan, [$this->other->id => [1, 2, 1800, 600]], ['voterMode' => 'manual', 'voterIds' => array_keys($ids), 'seed' => 77])->votes->votes;

    expect(array_map(fn (StudioVoteData $vote): ?int => $vote->id, $again))->toEqualCanonicalizing(array_values($ids))
        ->and(collect($again)->every(fn (StudioVoteData $vote): bool => $vote->id === $ids[$vote->personaUserId]))->toBeTrue();
});

it('lets a Persona restricted since keep the vote it has and gives it no new one; a deleted one keeps none', function () {
    saveStudioVotes(User::factory()->admin()->create(), $this->plan, buildCommentVotes($this->plan, [$this->other->id => [2, 0]], ['voterMode' => 'manual', 'voterIds' => [$this->dana->id, $this->eli->id]])->votes->votes);
    $this->dana->forceFill(['status' => UserStatus::Limited])->save();
    $this->eli->forceFill(['status' => UserStatus::Deleted])->save();
    $chosen = ['voterMode' => 'manual', 'voterIds' => [$this->dana->id, $this->eli->id, $this->anna->id]];

    expect(array_column(builtCommentVotes(buildCommentVotes($this->plan, [$this->other->id => [2, 0]], $chosen)), 'persona'))->toEqualCanonicalizing([$this->dana->id, $this->anna->id])
        ->and(commentVotesBuildErrors($this->plan, [$this->comment->id => [1, 0]], [...$chosen, 'voterIds' => [$this->dana->id, $this->eli->id]]))->toHaveKey("targets.{$this->comment->id}");
});

it('refuses a comment that is not this plan\'s, or no longer there', function () {
    $theirs = StudioDiscussionNode::factory()->create();

    expect(commentVotesBuildErrors($this->plan, [$theirs->id => [1, 0], 2_000_000_000 => [1, 0]]))->toBe([
        "targets.{$theirs->id}" => 'This comment is no longer in the discussion. Reload the plan.',
        'targets.2000000000' => 'This comment is no longer in the discussion. Reload the plan.',
    ]);
});

it('only reads: nothing is written', function () {
    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    buildCommentVotes($this->plan, [$this->comment->id => [2, 1], $this->other->id => [1, 0]]);

    expect($writes)->toBe([])->and(StudioPlanVote::query()->exists())->toBeFalse();
});
