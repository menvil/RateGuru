<?php

use App\Data\Studio\StudioVoteData;
use App\Data\Studio\StudioVotesData;
use App\Enums\StudioPlanPublicationMode;
use App\Enums\UserStatus;
use App\Models\RatingOption;
use App\Models\StudioDiscussionNode;
use App\Models\StudioPlan;
use App\Models\StudioPlanVote;
use App\Models\User;
use App\Support\Studio\StudioPlanValidationRules;
use App\Support\Studio\StudioVoteValidationRules;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * The one set of rules the votes a Draft prepares are held to, whoever wrote
 * them: three kinds of vote, each with its own fields; a Persona voting on
 * each target once, never on its own post or comment, never before the
 * comment it is on; and options of the rating questions as they are now —
 * apart from what the votes need before they may be cast.
 */
beforeEach(function () {
    [$this->author, $this->anna, $this->boris] = User::factory()->persona()->count(3)->create()->all();
    $this->plan = StudioPlan::factory()->authoredBy($this->author)->create();
    $this->comment = StudioDiscussionNode::factory()->for($this->plan, 'plan')->create(['persona_user_id' => $this->anna->id, 'offset_seconds' => 300]);
    $this->reply = StudioDiscussionNode::factory()->replyTo($this->comment)->create(['persona_user_id' => $this->boris->id, 'offset_seconds' => 600]);
    [$this->group, [$this->option, $this->other]] = votableRatingQuestion();
});

/**
 * The fields the votes fail, each with its message; none when they pass.
 * Asked of the plan as well when one is given, on the users as they are now.
 *
 * @param  list<StudioVoteData>  $votes
 * @return array<string, list<string>>
 */
function voteErrors(array $votes, ?StudioPlan $plan = null): array
{
    $rules = app(StudioVoteValidationRules::class);

    try {
        $data = $rules->validated(new StudioVotesData($votes));

        if ($plan !== null) {
            $rules->validatedAgainst($data, $plan->fresh(), User::query()->whereKey($data->personaIds())->get()->keyBy('id'));
        }
    } catch (ValidationException $invalid) {
        return $invalid->errors();
    }

    return [];
}

/** A vote on the plan's post by this Persona, up unless said. */
function postVote(User $persona, array $fields = []): StudioVoteData
{
    return studioVote(['type' => 'vote_post', 'personaUserId' => $persona->id, 'voteType' => 'up', 'offsetSeconds' => 60, ...$fields]);
}

it('takes no votes, and every kind of vote: up and down, on the post, a comment and a reply, and a rating option', function () {
    expect(voteErrors([], $this->plan))->toBe([])
        ->and(voteErrors([
            postVote($this->anna),
            postVote($this->boris, ['voteType' => 'down', 'offsetSeconds' => 0]),
            studioVote(['type' => 'vote_comment', 'personaUserId' => $this->boris->id, 'voteType' => 'up', 'discussionNodeId' => $this->comment->id, 'offsetSeconds' => 300]),
            studioVote(['type' => 'vote_comment', 'personaUserId' => $this->anna->id, 'voteType' => 'down', 'discussionNodeId' => $this->reply->id, 'offsetSeconds' => 2_592_000]),
            // The post's author may vote on the comments under it.
            studioVote(['type' => 'vote_comment', 'personaUserId' => $this->author->id, 'voteType' => 'up', 'discussionNodeId' => $this->reply->id, 'offsetSeconds' => 900, 'sortOrder' => 65_535]),
            studioVote(['type' => 'vote_rating', 'personaUserId' => $this->anna->id, 'ratingOptionId' => $this->option->id, 'offsetSeconds' => 120]),
            studioVote(['type' => 'vote_rating', 'personaUserId' => $this->boris->id, 'ratingGroupId' => $this->group->id, 'ratingOptionId' => $this->other->id, 'offsetSeconds' => 120]),
        ], $this->plan))->toBe([]);
});

it('holds each vote\'s fields to its kind, keyed as the editor\'s fields', function (array $vote, array $fields) {
    $vote = ['personaUserId' => $this->anna->id, 'offsetSeconds' => 60, ...$vote];

    expect(array_keys(voteErrors([postVote($this->boris), studioVote($vote)])))->toBe($fields);
})->with([
    'no kind' => [['voteType' => 'up'], ['votes.1.type']],
    'a kind of vote that is none of the three' => [['type' => 'vote_profile', 'voteType' => 'up'], ['votes.1.type']],
    'a post rather than a vote' => [['type' => 'create_post', 'voteType' => 'up'], ['votes.1.type']],
    'a vote on the post with no direction' => [['type' => 'vote_post'], ['votes.1.voteType']],
    'a vote on a comment with no direction' => [['type' => 'vote_comment', 'discussionNodeId' => 1], ['votes.1.voteType']],
    'a direction that is neither up nor down' => [['type' => 'vote_post', 'voteType' => 'sideways'], ['votes.1.voteType']],
    'a rating vote with a direction' => [['type' => 'vote_rating', 'voteType' => 'up', 'ratingOptionId' => 1], ['votes.1.voteType']],
    'a vote on a comment naming none' => [['type' => 'vote_comment', 'voteType' => 'up'], ['votes.1.discussionNodeId']],
    'a vote on the post naming a comment' => [['type' => 'vote_post', 'voteType' => 'up', 'discussionNodeId' => 1], ['votes.1.discussionNodeId']],
    'a rating vote naming a comment' => [['type' => 'vote_rating', 'ratingOptionId' => 1, 'discussionNodeId' => 1], ['votes.1.discussionNodeId']],
    'a rating vote naming no option' => [['type' => 'vote_rating'], ['votes.1.ratingOptionId']],
    'a vote on the post naming a rating option' => [['type' => 'vote_post', 'voteType' => 'up', 'ratingOptionId' => 1], ['votes.1.ratingOptionId']],
    'a vote on a comment naming a rating question' => [['type' => 'vote_comment', 'voteType' => 'up', 'discussionNodeId' => 1, 'ratingGroupId' => 1], ['votes.1.ratingGroupId']],
    'no Persona' => [['type' => 'vote_post', 'voteType' => 'up', 'personaUserId' => null], ['votes.1.personaUserId']],
    'a moment before the post' => [['type' => 'vote_post', 'voteType' => 'up', 'offsetSeconds' => -1], ['votes.1.offsetSeconds']],
    'a moment past thirty days' => [['type' => 'vote_post', 'voteType' => 'up', 'offsetSeconds' => 2_592_001], ['votes.1.offsetSeconds']],
    'a moment in part of a second' => [['type' => 'vote_post', 'voteType' => 'up', 'offsetSeconds' => 60.5], ['votes.1.offsetSeconds']],
    'a whole moment given as a fraction' => [['type' => 'vote_post', 'voteType' => 'up', 'offsetSeconds' => 60.0], ['votes.1.offsetSeconds']],
    'a place before the first' => [['type' => 'vote_post', 'voteType' => 'up', 'sortOrder' => -1], ['votes.1.sortOrder']],
    'a place past the last' => [['type' => 'vote_post', 'voteType' => 'up', 'sortOrder' => 65_536], ['votes.1.sortOrder']],
    'a place in part of a step' => [['type' => 'vote_post', 'voteType' => 'up', 'sortOrder' => 0.5], ['votes.1.sortOrder']],
    'a key past its length' => [['type' => 'vote_post', 'voteType' => 'up', 'key' => str_repeat('k', 65)], ['votes.1.key']],
]);

it('names each vote saved before once, and each new one by a key of its own', function () {
    expect(array_keys(voteErrors([postVote($this->anna, ['id' => 7]), postVote($this->boris, ['id' => 7])])))->toBe(['votes.0.id', 'votes.1.id'])
        ->and(array_keys(voteErrors([postVote($this->anna, ['key' => 'a']), postVote($this->boris, ['key' => ' a '])])))->toBe(['votes.0.key', 'votes.1.key'])
        // An empty key is no key.
        ->and(voteErrors([postVote($this->anna, ['key' => '']), postVote($this->boris, ['key' => ' '])]))->toBe([]);
});

it('takes at most a thousand votes', function () {
    $persona = $this->anna->id;
    $votes = fn (int $count): array => array_map(
        fn (int $index): StudioVoteData => studioVote(['type' => 'vote_comment', 'personaUserId' => $persona, 'voteType' => 'up', 'discussionNodeId' => $index + 1, 'offsetSeconds' => 60]),
        range(0, $count - 1),
    );

    expect(voteErrors($votes(StudioVoteValidationRules::MOST_VOTES)))->toBe([])
        ->and(voteErrors($votes(StudioVoteValidationRules::MOST_VOTES + 1)))->toBe(['votes' => ['Keep the votes to 1,000 or fewer.']]);
});

it('refuses a vote on the Persona\'s own post or comment', function (Closure $vote, string $field) {
    expect(array_keys(voteErrors([$vote($this)], $this->plan)))->toBe([$field]);
})->with([
    'the post\'s author on its post' => [fn ($test) => postVote($test->author), 'votes.0.personaUserId'],
    'the post\'s author for a rating option' => [fn ($test) => studioVote(['type' => 'vote_rating', 'personaUserId' => $test->author->id, 'ratingOptionId' => $test->option->id, 'offsetSeconds' => 60]), 'votes.0.personaUserId'],
    'a comment\'s Persona on it' => [fn ($test) => studioVote(['type' => 'vote_comment', 'personaUserId' => $test->anna->id, 'voteType' => 'up', 'discussionNodeId' => $test->comment->id, 'offsetSeconds' => 300]), 'votes.0.personaUserId'],
    'a reply\'s Persona on it' => [fn ($test) => studioVote(['type' => 'vote_comment', 'personaUserId' => $test->boris->id, 'voteType' => 'down', 'discussionNodeId' => $test->reply->id, 'offsetSeconds' => 600]), 'votes.0.personaUserId'],
]);

it('refuses a Persona\'s second vote on one target, even the other way, keyed at the second', function (Closure $votes) {
    expect(voteErrors($votes($this), $this->plan))->toHaveKey('votes.1.personaUserId')->toHaveCount(1);
})->with([
    'up and down on the post' => [fn ($test) => [postVote($test->anna), postVote($test->anna, ['voteType' => 'down', 'offsetSeconds' => 900])]],
    'up twice on one comment' => [fn ($test) => [
        studioVote(['type' => 'vote_comment', 'personaUserId' => $test->boris->id, 'voteType' => 'up', 'discussionNodeId' => $test->comment->id, 'offsetSeconds' => 300]),
        studioVote(['type' => 'vote_comment', 'personaUserId' => $test->boris->id, 'voteType' => 'up', 'discussionNodeId' => $test->comment->id, 'offsetSeconds' => 400]),
    ]],
    'up and down on one reply' => [fn ($test) => [
        studioVote(['type' => 'vote_comment', 'personaUserId' => $test->anna->id, 'voteType' => 'up', 'discussionNodeId' => $test->reply->id, 'offsetSeconds' => 600]),
        studioVote(['type' => 'vote_comment', 'personaUserId' => $test->anna->id, 'voteType' => 'down', 'discussionNodeId' => $test->reply->id, 'offsetSeconds' => 600]),
    ]],
    'two options of one rating question' => [fn ($test) => [
        studioVote(['type' => 'vote_rating', 'personaUserId' => $test->anna->id, 'ratingOptionId' => $test->option->id, 'offsetSeconds' => 60]),
        studioVote(['type' => 'vote_rating', 'personaUserId' => $test->anna->id, 'ratingOptionId' => $test->other->id, 'offsetSeconds' => 60]),
    ]],
    'one option twice' => [fn ($test) => [
        studioVote(['type' => 'vote_rating', 'personaUserId' => $test->anna->id, 'ratingOptionId' => $test->option->id, 'offsetSeconds' => 60]),
        studioVote(['type' => 'vote_rating', 'personaUserId' => $test->anna->id, 'ratingGroupId' => $test->group->id, 'ratingOptionId' => $test->option->id, 'offsetSeconds' => 90]),
    ]],
]);

it('lets one Persona vote on the post, on each comment and for each rating question', function () {
    [, [$elsewhere]] = votableRatingQuestion();

    expect(voteErrors([
        postVote($this->anna),
        studioVote(['type' => 'vote_comment', 'personaUserId' => $this->anna->id, 'voteType' => 'up', 'discussionNodeId' => $this->reply->id, 'offsetSeconds' => 600]),
        studioVote(['type' => 'vote_rating', 'personaUserId' => $this->anna->id, 'ratingOptionId' => $this->option->id, 'offsetSeconds' => 60]),
        studioVote(['type' => 'vote_rating', 'personaUserId' => $this->anna->id, 'ratingOptionId' => $elsewhere->id, 'offsetSeconds' => 60]),
    ], $this->plan))->toBe([]);
});

it('votes on a comment no earlier than the comment comes', function () {
    $vote = fn (int $offset): StudioVoteData => studioVote(['type' => 'vote_comment', 'personaUserId' => $this->author->id, 'voteType' => 'up', 'discussionNodeId' => $this->reply->id, 'offsetSeconds' => $offset]);

    expect(voteErrors([$vote(600)], $this->plan))->toBe([])
        ->and(voteErrors([$vote(599)], $this->plan))->toBe(['votes.0.offsetSeconds' => ['A vote cannot come before the comment it is on.']]);
});

it('votes only on this plan\'s comments as they are now', function () {
    $theirs = StudioDiscussionNode::factory()->create();
    $gone = StudioDiscussionNode::factory()->for($this->plan, 'plan')->create();
    $goneId = $gone->id;
    $gone->delete();
    $vote = fn (int $node): StudioVoteData => studioVote(['type' => 'vote_comment', 'personaUserId' => $this->author->id, 'voteType' => 'up', 'discussionNodeId' => $node, 'offsetSeconds' => 900]);

    foreach ([$theirs->id, $goneId, 2_000_000_000] as $node) {
        expect(voteErrors([$vote($node)], $this->plan))->toBe(['votes.0.discussionNodeId' => ['The comment it votes on is not in this plan’s discussion. Reload the plan.']]);
    }
});

it('takes a Persona that is one and not deleted, limited, shadowbanned and banned ones too', function () {
    [$limited, $shadowbanned, $banned] = collect([UserStatus::Limited, UserStatus::Shadowbanned, UserStatus::Banned])
        ->map(fn (UserStatus $status): User => User::factory()->persona()->create(['status' => $status]))
        ->all();
    $member = User::factory()->create();
    $deleted = User::factory()->persona()->create(['status' => UserStatus::Deleted]);

    expect(voteErrors([postVote($limited), postVote($shadowbanned), postVote($banned)], $this->plan))->toBe([])
        ->and(voteErrors([postVote($member), postVote($deleted), postVote($this->anna, ['personaUserId' => 2_000_000_000])], $this->plan))->toBe([
            'votes.0.personaUserId' => ['Choose a persona who is not deleted.'],
            'votes.1.personaUserId' => ['Choose a persona who is not deleted.'],
            'votes.2.personaUserId' => ['Choose a persona who is not deleted.'],
        ]);
});

it('chooses an active option of an active rating question, of the question named', function (Closure $option, ?string $message) {
    $chosen = $option($this);
    $votes = [studioVote(['type' => 'vote_rating', 'personaUserId' => $this->anna->id, 'ratingOptionId' => $chosen['option'], 'ratingGroupId' => $chosen['group'] ?? null, 'offsetSeconds' => 60])];

    expect(voteErrors($votes, $this->plan))->toBe($message === null ? [] : ['votes.0.ratingOptionId' => [$message]]);
})->with([
    'an active option' => [fn ($test) => ['option' => $test->option->id], null],
    'with its own question named' => [fn ($test) => ['option' => $test->option->id, 'group' => $test->group->id], null],
    'an option of another question than the one named' => [fn ($test) => ['option' => $test->option->id, 'group' => votableRatingQuestion()[0]->id], 'This option is not one of that rating question’s. Choose one of its own.'],
    'an inactive option' => [fn ($test) => ['option' => RatingOption::factory()->create(['rating_group_id' => $test->group->id, 'is_active' => false])->id], 'This option is archived, or its rating question is not active. Choose an option of an active rating question.'],
    'an archived option' => [fn ($test) => ['option' => RatingOption::factory()->create(['rating_group_id' => $test->group->id, 'is_active' => false, 'archived_at' => now()])->id], 'This option is archived, or its rating question is not active. Choose an option of an active rating question.'],
    'an option of an inactive question' => [fn ($test) => ['option' => tap(votableRatingQuestion(), fn (array $question) => $question[0]->update(['is_active' => false]))[1][0]->id], 'This option is archived, or its rating question is not active. Choose an option of an active rating question.'],
    'an option that is not there' => [fn ($test) => ['option' => 2_000_000_000], 'This rating option no longer exists. Choose another one.'],
]);

it('gives a rating vote its option\'s own question, whatever question it named', function () {
    $rules = app(StudioVoteValidationRules::class);
    $data = $rules->validated(new StudioVotesData([studioVote(['type' => 'vote_rating', 'personaUserId' => $this->anna->id, 'ratingOptionId' => $this->other->id, 'offsetSeconds' => 60])]));

    $resolved = $rules->validatedAgainst($data, $this->plan, User::query()->whereKey([$this->anna->id])->get()->keyBy('id'));

    expect($resolved->votes[0]->ratingGroupId)->toBe($this->group->id);
});

it('keeps an option chosen earlier as it was, whatever became of it since — and refuses it newly chosen', function (Closure $becomes, bool $otherStillOpen) {
    $saved = StudioPlanVote::factory()->forRating($this->option)->for($this->plan, 'plan')->create(['persona_user_id' => $this->anna->id]);
    $becomes($this);
    $kept = studioVote(['id' => $saved->id, 'type' => 'vote_rating', 'personaUserId' => $this->anna->id, 'ratingOptionId' => $this->option->id, 'offsetSeconds' => 90]);
    $chosenAgain = studioVote(['type' => 'vote_rating', 'personaUserId' => $this->boris->id, 'ratingOptionId' => $this->option->id, 'offsetSeconds' => 60]);

    expect(voteErrors([$kept], $this->plan))->toBe([])
        ->and(voteErrors([$kept, $chosenAgain], $this->plan))->toHaveKeys(['votes.1.ratingOptionId'])->toHaveCount(1)
        // Another option is a new choice, and a new choice is of an active option.
        ->and(voteErrors([studioVote(['id' => $saved->id, 'type' => 'vote_rating', 'personaUserId' => $this->anna->id, 'ratingOptionId' => $this->other->id, 'offsetSeconds' => 90])], $this->plan))
        ->toBe($otherStillOpen ? [] : ['votes.0.ratingOptionId' => ['This option is archived, or its rating question is not active. Choose an option of an active rating question.']]);
})->with([
    'archived' => [fn ($test) => $test->option->update(['is_active' => false, 'archived_at' => now()]), true],
    'its question made inactive' => [fn ($test) => $test->group->update(['is_active' => false]), false],
    'deleted' => [fn ($test) => $test->option->delete(), true],
]);

it('refuses another plan\'s vote by its id', function () {
    $theirs = StudioPlanVote::factory()->create(['persona_user_id' => $this->anna->id]);

    expect(voteErrors([postVote($this->anna, ['id' => $theirs->id])], $this->plan))->toBe(['votes.0.id' => ['This vote is not one of this plan’s. Reload the plan.']]);
});

it('keeps every vote of a post published on a date within the dates a post may carry', function () {
    $this->plan->forceFill([
        'publication_mode' => StudioPlanPublicationMode::AtDate,
        'publish_at' => CarbonImmutable::parse(StudioPlanValidationRules::LATEST_PUBLISH_AT)->subHour(),
    ])->save();

    expect(voteErrors([postVote($this->anna, ['offsetSeconds' => 3600])], $this->plan))->toBe([])
        ->and(voteErrors([postVote($this->anna, ['offsetSeconds' => 3601])], $this->plan))
        ->toBe(['votes.0.offsetSeconds' => ['It would come after 2038, past the latest date a post may carry. Bring it closer to the post.']]);

    // Published at once, the post's moment is its approval's: thirty days fit.
    $this->plan->forceFill(['publication_mode' => StudioPlanPublicationMode::Now, 'publish_at' => null])->save();

    expect(voteErrors([postVote($this->anna, ['offsetSeconds' => 2_592_000])], $this->plan))->toBe([]);
});

it('asks more of votes to be cast: every Persona Active and able to vote, every option still to be chosen', function () {
    $carl = User::factory()->persona()->create();
    StudioPlanVote::factory()->for($this->plan, 'plan')->create(['persona_user_id' => $this->anna->id, 'offset_seconds' => 60]);
    StudioPlanVote::factory()->for($this->plan, 'plan')->create(['persona_user_id' => $carl->id, 'offset_seconds' => 120]);
    StudioPlanVote::factory()->forRating($this->option)->for($this->plan, 'plan')->create(['persona_user_id' => $this->boris->id, 'offset_seconds' => 180]);
    $rules = app(StudioVoteValidationRules::class);

    expect($rules->readinessErrors($this->plan))->toBe([]);

    // A Draft keeps both as they are; to be cast, neither will do.
    $this->option->update(['is_active' => false, 'archived_at' => now()]);
    $this->anna->forceFill(['status' => UserStatus::Banned])->save();
    $carl->forceFill(['status' => UserStatus::Limited])->save();

    expect(voteErrors(StudioVotesData::of($this->plan)->votes, $this->plan))->toBe([])
        ->and($rules->readinessErrors($this->plan))->toBe([
            'votes.0.personaUserId' => 'This persona is limited, banned or shadowbanned and cannot vote. Choose another one.',
            'votes.1.personaUserId' => 'This persona is limited, banned or shadowbanned and cannot vote. Choose another one.',
            'votes.2.ratingOptionId' => 'This option is archived, or its rating question is not active. Choose an option of an active rating question.',
        ]);
});

it('finds a vote the post\'s new author would cast on its own post, and an option deleted since, before they are cast', function () {
    StudioPlanVote::factory()->for($this->plan, 'plan')->create(['persona_user_id' => $this->anna->id]);
    StudioPlanVote::factory()->forRating($this->option)->for($this->plan, 'plan')->create(['persona_user_id' => $this->boris->id]);
    $this->plan->forceFill(['author_user_id' => $this->anna->id])->save();
    $this->option->delete();

    expect(app(StudioVoteValidationRules::class)->readinessErrors($this->plan->fresh()))->toBe([
        'votes.0.personaUserId' => 'The post’s own author cannot vote on it. Choose another persona.',
        'votes.1.ratingOptionId' => 'This rating option no longer exists. Choose another one.',
    ]);
});
