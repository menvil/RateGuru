<?php

use App\Data\Studio\BuiltStudioPostVotes;
use App\Data\Studio\StudioPostVotesSettings;
use App\Data\Studio\StudioRatingVotesSettings;
use App\Data\Studio\StudioVoteData;
use App\Enums\StudioPlanPublicationMode;
use App\Enums\UserStatus;
use App\Models\StudioDiscussionNode;
use App\Models\StudioPlan;
use App\Models\StudioPlanVote;
use App\Models\User;
use App\Support\Studio\BuildStudioPostVotesData;
use App\Support\Studio\StudioPlanValidationRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Making the votes on a post and its rating questions from what the Post
 * votes editor asks for: each Persona once per target, never the post's
 * author, the counts and moments exactly as asked, the same votes for the
 * same draw — and the whole request refused, never cut, when it can't be met.
 */
beforeEach(function () {
    $this->author = User::factory()->persona()->create();
    $this->plan = StudioPlan::factory()->authoredBy($this->author)->create();
    $this->personas = User::factory()->persona()->count(6)->create()->sortBy('id')->values();
    [$this->group, $answers] = votableRatingQuestion(3);
    $this->answers = collect($answers);
});

/** The votes made of these settings, named as StudioPostVotesSettings's fields. */
function buildPostVotes(StudioPlan $plan, array $settings): BuiltStudioPostVotes
{
    return app(BuildStudioPostVotesData::class)->handle($plan->fresh(), new StudioPostVotesSettings(...$settings));
}

/** What the build refuses, by field; none when it goes through. */
function postVotesBuildErrors(StudioPlan $plan, array $settings): array
{
    try {
        buildPostVotes($plan, $settings);
    } catch (ValidationException $invalid) {
        return array_map(fn (array $messages): string => $messages[0], $invalid->errors());
    }

    return [];
}

/** @return list<array{persona: int, type: string, way: ?string, option: ?int, offset: int}> */
function builtPostVotes(BuiltStudioPostVotes $built): array
{
    return array_map(fn (StudioVoteData $vote): array => [
        'persona' => $vote->personaUserId,
        'type' => $vote->type,
        'way' => $vote->voteType,
        'option' => $vote->ratingOptionId,
        'offset' => (int) $vote->offsetSeconds,
    ], $built->votes->votes);
}

it('gives the up and down votes to different Personas, each once, and never the post\'s author', function () {
    $votes = builtPostVotes(buildPostVotes($this->plan, ['voterCount' => 6, 'up' => 4, 'down' => 2, 'seed' => 7]));

    expect($votes)->toHaveCount(6)
        ->and(array_count_values(array_column($votes, 'way')))->toEqualCanonicalizing(['up' => 4, 'down' => 2])
        ->and(array_unique(array_column($votes, 'persona')))->toHaveCount(6)
        ->and(array_column($votes, 'persona'))->not->toContain($this->author->id)
        ->and(array_unique(array_column($votes, 'type')))->toBe(['vote_post']);
});

it('leaves the author out of a draw and of a choice by hand, and counts what is left', function () {
    $everyone = [$this->author->id, ...$this->personas->pluck('id')->all()];

    expect(postVotesBuildErrors($this->plan, ['voterCount' => 7, 'up' => 1]))->toBe(['voterCount' => 'Choose from 0 to 6 personas: that many can vote on this post.'])
        ->and(builtPostVotes(buildPostVotes($this->plan, ['voterMode' => 'manual', 'voterIds' => $everyone, 'up' => 6])))->each(fn ($vote) => $vote->persona->not->toBe($this->author->id))
        ->and(postVotesBuildErrors($this->plan, ['voterMode' => 'manual', 'voterIds' => $everyone, 'up' => 7]))->toHaveKey('post');
});

it('leaves out the author picked and not saved yet as it does the saved one', function () {
    $toBe = $this->personas[0]->id;
    $everyone = [$this->author->id, ...$this->personas->pluck('id')->all()];

    expect(postVotesBuildErrors($this->plan, ['voterCount' => 6, 'up' => 1, 'authorToBe' => $toBe]))->toBe(['voterCount' => 'Choose from 0 to 5 personas: that many can vote on this post.'])
        ->and(array_column(builtPostVotes(buildPostVotes($this->plan, ['voterCount' => 5, 'up' => 5, 'authorToBe' => $toBe])), 'persona'))->not->toContain($toBe)->not->toContain($this->author->id)
        ->and(array_column(builtPostVotes(buildPostVotes($this->plan, ['voterMode' => 'manual', 'voterIds' => $everyone, 'up' => 5, 'authorToBe' => $toBe])), 'persona'))->not->toContain($toBe)
        ->and(postVotesBuildErrors($this->plan, ['voterMode' => 'manual', 'voterIds' => $everyone, 'up' => 6, 'authorToBe' => $toBe]))->toHaveKey('post');
});

it('draws the voters from the active Personas, the same ones for the same seed, others for another', function () {
    User::factory()->persona()->create(['status' => UserStatus::Limited]);
    User::factory()->create();
    $draw = fn (int $seed): array => buildPostVotes($this->plan, ['voterCount' => 3, 'up' => 3, 'seed' => $seed])->voters;
    $draws = array_map(fn (int $seed): string => implode(',', $draw($seed)), range(1, 20));

    expect($draw(11))->toBe($draw(11))
        ->and($draw(11))->toHaveCount(3)
        ->and(array_diff($draw(11), $this->personas->pluck('id')->all()))->toBe([])
        ->and(array_unique($draws))->not->toHaveCount(1);
});

it('takes only the Personas chosen by hand', function () {
    $chosen = $this->personas->take(3)->pluck('id')->all();

    $votes = builtPostVotes(buildPostVotes($this->plan, ['voterMode' => 'manual', 'voterIds' => $chosen, 'up' => 2, 'down' => 1]));

    expect(array_column($votes, 'persona'))->toEqualCanonicalizing($chosen);
});

it('refuses too few Personas for what is asked, saying exactly how many short, and cuts nothing', function () {
    $errors = postVotesBuildErrors($this->plan, [
        'voterMode' => 'manual', 'voterIds' => $this->personas->take(5)->pluck('id')->all(), 'up' => 4, 'down' => 3,
        'ratings' => [$this->group->id => new StudioRatingVotesSettings($this->group->id, [$this->answers[0]->id => 6])],
    ]);

    expect($errors)->toBe([
        'post' => 'Up and down votes need 7 personas who can vote; 5 are chosen — 2 short. Choose more personas, or ask for fewer.',
        "ratings.{$this->group->id}" => $this->group->translatedLabel().' answers need 6 personas who can vote; 5 are chosen — 1 short. Choose more personas, or ask for fewer.',
    ]);
});

it('gives each option exactly the answers asked for, one answer per Persona in each question', function () {
    [$second, $others] = votableRatingQuestion(2);
    // The questions in the order they are configured: this one, then the second.
    $this->group->update(['sort_order' => 1]);
    $second->update(['sort_order' => 2]);
    $votes = builtPostVotes(buildPostVotes($this->plan, ['voterCount' => 6, 'seed' => 3, 'ratings' => [
        $this->group->id => new StudioRatingVotesSettings($this->group->id, [$this->answers[0]->id => 3, $this->answers[1]->id => 1, $this->answers[2]->id => 0]),
        $second->id => new StudioRatingVotesSettings($second->id, [$others[0]->id => 2, $others[1]->id => 4]),
    ]]));
    $first = array_values(array_filter($votes, fn (array $vote): bool => in_array($vote['option'], $this->answers->pluck('id')->all(), true)));
    $next = array_values(array_filter($votes, fn (array $vote): bool => in_array($vote['option'], [$others[0]->id, $others[1]->id], true)));

    expect(array_count_values(array_column($first, 'option')))->toEqualCanonicalizing([$this->answers[0]->id => 3, $this->answers[1]->id => 1])
        ->and(array_unique(array_column($first, 'persona')))->toHaveCount(4)
        ->and(array_count_values(array_column($next, 'option')))->toEqualCanonicalizing([$others[0]->id => 2, $others[1]->id => 4])
        ->and(array_unique(array_column($next, 'persona')))->toHaveCount(6);
});

it('lets one Persona vote on the post and answer each question, once each', function () {
    [$second, $others] = votableRatingQuestion(2);
    $voters = $this->personas->take(3)->pluck('id')->all();

    $votes = builtPostVotes(buildPostVotes($this->plan, ['voterMode' => 'manual', 'voterIds' => $voters, 'up' => 2, 'down' => 1, 'ratings' => [
        $this->group->id => new StudioRatingVotesSettings($this->group->id, [$this->answers[1]->id => 3]),
        $second->id => new StudioRatingVotesSettings($second->id, [$others[0]->id => 3]),
    ]]));

    expect(array_count_values(array_column($votes, 'persona')))->toEqualCanonicalizing(array_fill_keys($voters, 3));
});

it('spreads the voters from the first moment to the last, one after another', function (int $count, array $offsets) {
    $votes = builtPostVotes(buildPostVotes($this->plan, ['voterCount' => 6, 'up' => $count, 'firstSeconds' => 300, 'spreadSeconds' => 3600]));

    expect(array_column($votes, 'offset'))->toBe($offsets);
})->with([
    'none' => [0, []],
    'one, at the first moment' => [1, [300]],
    'two, at the first and the last' => [2, [300, 3900]],
    'five, evenly' => [5, [300, 1200, 2100, 3000, 3900]],
    'three, rounded down' => [3, [300, 2100, 3900]],
]);

it('brings each voter\'s votes in one visit: on the post first, then the answers in the questions\' order, a little apart', function () {
    [$second, $others] = votableRatingQuestion(2);
    // The questions in the order they are configured: this one, then the second.
    $this->group->update(['sort_order' => 1]);
    $second->update(['sort_order' => 2]);
    $votes = builtPostVotes(buildPostVotes($this->plan, ['voterCount' => 6, 'up' => 1, 'down' => 1, 'firstSeconds' => 300, 'spreadSeconds' => 3600, 'seed' => 3, 'ratings' => [
        $this->group->id => new StudioRatingVotesSettings($this->group->id, [$this->answers[0]->id => 2]),
        $second->id => new StudioRatingVotesSettings($second->id, [$others[1]->id => 2]),
    ]]));
    $visits = collect($votes)->groupBy('persona');

    expect($visits)->toHaveCount(2)
        ->and($visits->map(fn ($visit): int => $visit->min('offset'))->sort()->values()->all())->toBe([300, 3900]);

    foreach ($visits as $persona => $visit) {
        $steps = BuildStudioPostVotesData::visitSteps($persona, 3);

        expect($visit->map(fn (array $vote): array => [$vote['type'], $vote['option'], $vote['offset']])->all())->toBe([
            ['vote_post', null, $visit[0]['offset']],
            ['vote_rating', $this->answers[0]->id, $visit[0]['offset'] + $steps[1]],
            ['vote_rating', $others[1]->id, $visit[0]['offset'] + $steps[2]],
        ])
            ->and($steps[1])->toBeIn([60, 120, 180])
            ->and($steps[2] - $steps[1])->toBeIn([60, 120, 180])
            ->and(BuildStudioPostVotesData::visitSteps($persona, 3))->toBe($steps);
    }
});

it('brings a vote given a moment of its own then, the rest keeping their places in the spread', function () {
    $settings = ['voterCount' => 6, 'up' => 3, 'firstSeconds' => 300, 'spreadSeconds' => 3600, 'seed' => 7];
    $spread = builtPostVotes(buildPostVotes($this->plan, $settings));
    $moved = builtPostVotes(buildPostVotes($this->plan, [...$settings, 'postMoments' => [$spread[1]['persona'] => 7200]]));

    expect(array_column($spread, 'offset'))->toBe([300, 2100, 3900])
        // The same Personas, the one moved now last in time, the others where they were.
        ->and(array_map(fn (array $vote): array => [$vote['persona'], $vote['offset']], $moved))
        ->toBe([[$spread[0]['persona'], 300], [$spread[2]['persona'], 3900], [$spread[1]['persona'], 7200]]);

    // An answer's own moment the same; one past thirty days, or before the post, is refused.
    $rating = fn (array $moments): array => ['voterCount' => 6, 'seed' => 7, 'ratings' => [$this->group->id => new StudioRatingVotesSettings($this->group->id, [$this->answers[0]->id => 1], $moments)]];
    $persona = builtPostVotes(buildPostVotes($this->plan, $rating([])))[0]['persona'];

    expect(builtPostVotes(buildPostVotes($this->plan, $rating([$persona => 90])))[0]['offset'])->toBe(90)
        ->and(postVotesBuildErrors($this->plan, $rating([$persona => 2_592_000 + 60])))
        ->toBe(['timing' => 'The votes and answers would end more than 30 days after the post. Start sooner, or spread them over less time.'])
        ->and(postVotesBuildErrors($this->plan, $rating([$persona => -60])))->toBe(['timing' => 'Nothing can arrive before the post.']);
});

it('keeps the votes of one moment in one order, the same every time', function () {
    $settings = ['voterCount' => 6, 'up' => 3, 'down' => 2, 'firstSeconds' => 600, 'spreadSeconds' => 0, 'seed' => 5];
    $first = buildPostVotes($this->plan, $settings)->votes->votes;
    $again = buildPostVotes($this->plan, $settings)->votes->votes;

    expect(array_map(fn (StudioVoteData $vote): array => [$vote->personaUserId, $vote->sortOrder, $vote->offsetSeconds], $first))
        ->toBe(array_map(fn (StudioVoteData $vote): array => [$vote->personaUserId, $vote->sortOrder, $vote->offsetSeconds], $again))
        ->and(array_map(fn (StudioVoteData $vote): int|float => $vote->sortOrder, $first))->toBe([0, 1, 2, 3, 4]);
});

it('refuses moments past thirty days, or past the dates a post may carry, cutting nothing', function () {
    expect(postVotesBuildErrors($this->plan, ['voterCount' => 6, 'up' => 2, 'firstSeconds' => 2_592_000 - 60, 'spreadSeconds' => 3600]))
        ->toBe(['timing' => 'The votes and answers would end more than 30 days after the post. Start sooner, or spread them over less time.'])
        // One voter comes at the first moment.
        ->and(postVotesBuildErrors($this->plan, ['voterCount' => 6, 'up' => 1, 'firstSeconds' => 2_592_000 - 60, 'spreadSeconds' => 3600]))->toBe([]);

    $this->plan->forceFill(['publication_mode' => StudioPlanPublicationMode::AtDate, 'publish_at' => CarbonImmutable::parse(StudioPlanValidationRules::LATEST_PUBLISH_AT)->subHour()])->save();

    expect(postVotesBuildErrors($this->plan, ['voterCount' => 6, 'ratings' => [$this->group->id => new StudioRatingVotesSettings($this->group->id, [$this->answers[0]->id => 2])]]))
        ->toBe(['timing' => 'The votes and answers would end after 2038, past the latest date a post may carry. Start sooner, or spread them over less time.']);
});

it('refuses more votes than a plan may prepare, counting the discussion\'s', function () {
    $voters = User::factory()->persona()->count(25)->create();
    $nodes = StudioDiscussionNode::factory()->for($this->plan, 'plan')->count(40)->create(['persona_user_id' => $this->author->id, 'offset_seconds' => 60]);
    $rows = [];

    foreach ($nodes as $node) {
        foreach ($voters as $voter) {
            $rows[] = ['studio_plan_id' => $this->plan->id, 'persona_user_id' => $voter->id, 'type' => 'vote_comment', 'vote_type' => 'up', 'discussion_node_id' => $node->id, 'target_key' => "comment:{$node->id}", 'offset_seconds' => 120, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()];
        }
    }

    foreach (array_chunk($rows, 250) as $chunk) {
        DB::table('studio_plan_votes')->insert($chunk);
    }

    expect(postVotesBuildErrors($this->plan, ['voterCount' => 1, 'up' => 1]))->toBe(['votes' => 'A plan prepares at most 1,000 votes; these and the discussion’s come to 1,001. Ask for fewer.'])
        ->and(postVotesBuildErrors($this->plan, ['voterCount' => 1]))->toBe([]);
});

it('keeps the id of each saved vote a Persona casts on the same target again, its way or moment changed', function () {
    $built = buildPostVotes($this->plan, ['voterCount' => 6, 'up' => 3, 'down' => 1, 'seed' => 9, 'ratings' => [
        $this->group->id => new StudioRatingVotesSettings($this->group->id, [$this->answers[0]->id => 2]),
    ]]);
    $saved = saveStudioVotes(User::factory()->admin()->create(), $this->plan, $built->votes->votes);
    $ids = $saved->votes->mapWithKeys(fn (StudioPlanVote $vote): array => [$vote->persona_user_id.'|'.$vote->target_key => $vote->id])->all();

    // The same voters, other ways and moments, and the answers moved to another option.
    $again = buildPostVotes($this->plan, ['voterMode' => 'manual', 'voterIds' => $built->voters, 'up' => 1, 'down' => 3, 'firstSeconds' => 3600, 'seed' => 4, 'ratings' => [
        $this->group->id => new StudioRatingVotesSettings($this->group->id, [$this->answers[1]->id => 2]),
    ]])->votes->votes;

    foreach ($again as $vote) {
        $key = $vote->personaUserId.'|'.($vote->type === 'vote_post' ? 'post' : 'rating:'.$vote->ratingGroupId);

        expect($vote->id)->toBe($ids[$key] ?? null);
    }

    expect(array_filter(array_map(fn (StudioVoteData $vote): ?int => $vote->id, $again)))->toHaveCount(6);
});

it('draws everything again once a new draw is asked for, the saved votes no longer preferred', function () {
    $saved = saveStudioVotes(User::factory()->admin()->create(), $this->plan, buildPostVotes($this->plan, ['voterCount' => 6, 'up' => 1, 'seed' => 1])->votes->votes);
    $kept = $saved->votes->first()->persona_user_id;
    $redrawn = array_map(fn (int $seed): int => buildPostVotes($this->plan, ['voterCount' => 6, 'up' => 1, 'seed' => $seed, 'keepSaved' => false])->votes->votes[0]->personaUserId, range(2, 20));

    expect(buildPostVotes($this->plan, ['voterCount' => 6, 'up' => 1, 'seed' => 99])->votes->votes[0]->personaUserId)->toBe($kept)
        ->and(array_unique($redrawn))->not->toBe([$kept]);
});

it('keeps answers saved on an option no longer open, never more of them and never another option\'s', function () {
    $admin = User::factory()->admin()->create();
    $voters = $this->personas->take(3)->pluck('id')->all();
    saveStudioVotes($admin, $this->plan, buildPostVotes($this->plan, ['voterMode' => 'manual', 'voterIds' => $voters, 'ratings' => [
        $this->group->id => new StudioRatingVotesSettings($this->group->id, [$this->answers[2]->id => 2]),
    ]])->votes->votes);
    $this->answers[2]->update(['is_active' => false, 'archived_at' => now()]);
    $settings = fn (array $counts): array => ['voterMode' => 'manual', 'voterIds' => $voters, 'keepSaved' => false, 'seed' => 8, 'ratings' => [$this->group->id => new StudioRatingVotesSettings($this->group->id, $counts)]];

    $kept = builtPostVotes(buildPostVotes($this->plan, $settings([$this->answers[2]->id => 1, $this->answers[0]->id => 2])));

    expect(array_count_values(array_column($kept, 'option')))->toEqualCanonicalizing([$this->answers[2]->id => 1, $this->answers[0]->id => 2])
        ->and(postVotesBuildErrors($this->plan, $settings([$this->answers[2]->id => 3])))->toBe(["ratings.{$this->group->id}" => 'An answer no longer open keeps at most the 2 it had. Move the others to an open answer.'])
        ->and(postVotesBuildErrors($this->plan, $settings([$this->answers[0]->id => 1, 2_000_000_000 => 1])))->toBe(["ratings.{$this->group->id}" => 'Answer with the options this question has now.']);
});

it('lets a Persona restricted since keep the vote it has, and gives it no new one; a deleted one keeps none', function () {
    $admin = User::factory()->admin()->create();
    [$anna, $boris, $carl] = $this->personas->take(3)->all();
    saveStudioVotes($admin, $this->plan, buildPostVotes($this->plan, ['voterMode' => 'manual', 'voterIds' => [$anna->id, $boris->id], 'up' => 2])->votes->votes);
    $anna->forceFill(['status' => UserStatus::Banned])->save();
    $boris->forceFill(['status' => UserStatus::Deleted])->save();
    $settings = fn (int $up, int $down): array => ['voterMode' => 'manual', 'voterIds' => [$anna->id, $boris->id, $carl->id], 'up' => $up, 'down' => $down];

    expect(builtPostVotes(buildPostVotes($this->plan, $settings(2, 0))))->toHaveCount(2)
        ->and(array_column(builtPostVotes(buildPostVotes($this->plan, $settings(2, 0))), 'persona'))->toEqualCanonicalizing([$anna->id, $carl->id])
        ->and(postVotesBuildErrors($this->plan, $settings(1, 1)))->toBe([])
        // A restricted Persona takes no new vote, not even another way.
        ->and(postVotesBuildErrors($this->plan, $settings(0, 2)))->toHaveKey('post');
});

it('only reads: nothing is written', function () {
    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    buildPostVotes($this->plan, ['voterCount' => 6, 'up' => 3, 'down' => 2, 'ratings' => [$this->group->id => new StudioRatingVotesSettings($this->group->id, [$this->answers[0]->id => 4])]]);

    expect($writes)->toBe([])
        ->and(StudioPlanVote::query()->exists())->toBeFalse();
});
