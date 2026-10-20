<?php

use App\Enums\StudioDiscussionGenerationError;
use App\Enums\StudioDiscussionGenerationMode;
use App\Enums\StudioPostTextTone;
use App\Enums\UserStatus;
use App\Models\StudioPlanImageAnalysis;
use App\Models\User;
use App\Support\Studio\StudioDiscussionValidationRules;
use App\Support\StudioAi\StudioDiscussionContext;
use App\Support\StudioAi\StudioDiscussionPrompt;
use App\Support\StudioAi\StudioDiscussionProposal;

/**
 * What the model answers is a proposal only when it passes every check: the
 * mode's fields and nothing more, of their types; exactly the comments asked
 * for, by the Personas taking part, within the times; and a discussion the
 * one set of rules accepts. Anything else is refused whole — nothing trimmed
 * into shape, and no id ever taken from an answer.
 */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->plan = discussionReadyStudioPlan(['created_by_user_id' => $this->admin->id]);
    [$this->anna, $this->boris] = [studioPersonaWithProfile(), studioPersonaWithProfile()];
});

/** The request a whole discussion of two comments and one reply by Anna and Boris makes. */
function wholeDiscussionPrompt($test, int $roots = 2, int $replies = 1, int $latest = StudioDiscussionValidationRules::LATEST_OFFSET_SECONDS): StudioDiscussionPrompt
{
    $context = StudioDiscussionContext::of($test->plan, StudioPlanImageAnalysis::query()->where('studio_plan_id', $test->plan->id)->first(), [$test->anna->id, $test->boris->id]);

    return new StudioDiscussionPrompt(StudioDiscussionGenerationMode::FullDiscussion, StudioPostTextTone::Cheeky, $context, $roots, $replies, $latest);
}

/** What this answer makes for that request: a proposal, or the error code. */
function proposalOf(StudioDiscussionPrompt $prompt, array $answer): StudioDiscussionProposal|string
{
    $made = StudioDiscussionProposal::from($prompt, $answer, app(StudioDiscussionValidationRules::class));

    return $made instanceof StudioDiscussionGenerationError ? $made->value : $made;
}

function validWholeAnswer(): array
{
    return ['comments' => [
        answeredComment('node_1', null, 'persona_1', 60, 'One.'),
        answeredComment('node_2', 'node_1', 'persona_2', 120, 'Two.'),
        answeredComment('node_3', null, 'persona_2', 90, 'Three.'),
    ]];
}

it('makes a whole discussion of a valid answer, its Personas mapped by the server, its places by the order given', function () {
    $proposal = proposalOf(wholeDiscussionPrompt($this), validWholeAnswer());
    $alias = fn (string $alias): int => collect([$this->anna->id, $this->boris->id])->sort()->values()->all()[$alias === 'persona_1' ? 0 : 1];

    expect($proposal)->toBeInstanceOf(StudioDiscussionProposal::class)
        ->and($proposal->count())->toBe(3)
        ->and($proposal->toArray())->toBe(['nodes' => [
            ['key' => 'node_1', 'parent_key' => null, 'persona_user_id' => $alias('persona_1'), 'body' => 'One.', 'sort_order' => 0, 'offset_seconds' => 60],
            ['key' => 'node_2', 'parent_key' => 'node_1', 'persona_user_id' => $alias('persona_2'), 'body' => 'Two.', 'sort_order' => 0, 'offset_seconds' => 120],
            ['key' => 'node_3', 'parent_key' => null, 'persona_user_id' => $alias('persona_2'), 'body' => 'Three.', 'sort_order' => 1, 'offset_seconds' => 90],
        ]])
        // As kept, and read back.
        ->and(StudioDiscussionProposal::fromStored(StudioDiscussionGenerationMode::FullDiscussion, $proposal->toArray())?->toArray())->toBe($proposal->toArray());
});

it('refuses a whole discussion that is not exactly a valid one', function (Closure $answer, string $error) {
    expect(proposalOf(wholeDiscussionPrompt($this), $answer(validWholeAnswer())))->toBe($error);
})->with([
    'a field missing' => [function (array $a) {
        unset($a['comments'][0]['offset_seconds']);

        return $a;
    }, 'invalid_provider_response'],
    'a field too many' => [function (array $a) {
        $a['comments'][0]['id'] = 7;

        return $a;
    }, 'invalid_provider_response'],
    'a field outside the comments' => [fn (array $a) => [...$a, 'note' => 'Hi.'], 'invalid_provider_response'],
    'a time as text' => [function (array $a) {
        $a['comments'][0]['offset_seconds'] = '60';

        return $a;
    }, 'invalid_provider_response'],
    'a Persona as a number' => [function (array $a) {
        $a['comments'][0]['persona'] = 1;

        return $a;
    }, 'invalid_provider_response'],
    'a key twice' => [function (array $a) {
        $a['comments'][2]['key'] = 'node_1';

        return $a;
    }, 'invalid_provider_response'],
    'a parent nobody has' => [function (array $a) {
        $a['comments'][1]['parent'] = 'node_9';

        return $a;
    }, 'invalid_provider_response'],
    'a reply to a reply' => [function (array $a) {
        $a['comments'][2]['parent'] = 'node_2';

        return $a;
    }, 'invalid_provider_response'],
    'a comment answering itself' => [function (array $a) {
        $a['comments'][1]['parent'] = 'node_2';

        return $a;
    }, 'invalid_provider_response'],
    'a Persona not taking part' => [function (array $a) {
        $a['comments'][0]['persona'] = 'persona_3';

        return $a;
    }, 'invalid_provider_response'],
    'a text too long' => [function (array $a) {
        $a['comments'][0]['text'] = str_repeat('a', 1001);

        return $a;
    }, 'invalid_provider_response'],
    'an empty text' => [function (array $a) {
        $a['comments'][0]['text'] = '   ';

        return $a;
    }, 'invalid_provider_response'],
    'a control character' => [function (array $a) {
        $a['comments'][0]['text'] = "One\x07.";

        return $a;
    }, 'invalid_provider_response'],
    'a time before the post' => [function (array $a) {
        $a['comments'][0]['offset_seconds'] = -1;

        return $a;
    }, 'invalid_provider_response'],
    'a reply before its comment' => [function (array $a) {
        $a['comments'][1]['offset_seconds'] = 30;

        return $a;
    }, 'invalid_provider_response'],
    'one comment too many' => [function (array $a) {
        $a['comments'][] = answeredComment('node_4', null, 'persona_1', 200, 'Four.');

        return $a;
    }, 'invalid_provider_response'],
    'a reply short' => [function (array $a) {
        unset($a['comments'][1]);
        $a['comments'] = array_values($a['comments']);

        return $a;
    }, 'invalid_provider_response'],
    'the same text twice' => [function (array $a) {
        $a['comments'][2]['text'] = 'one.';

        return $a;
    }, 'invalid_provider_response'],
    'no comments at all' => [fn () => ['comments' => []], 'invalid_provider_response'],
]);

it('refuses a comment later than the post\'s date leaves', function () {
    expect(proposalOf(wholeDiscussionPrompt($this, latest: 100), validWholeAnswer()))->toBe('invalid_provider_response')
        ->and(proposalOf(wholeDiscussionPrompt($this, latest: 120), validWholeAnswer()))->toBeInstanceOf(StudioDiscussionProposal::class);
});

it('refuses a whole discussion by a Persona deleted since it was asked for', function () {
    $prompt = wholeDiscussionPrompt($this);
    $this->boris->forceFill(['status' => UserStatus::Deleted])->save();

    expect(proposalOf($prompt, validWholeAnswer()))->toBe('invalid_persona');
});

it('takes a part written again only as exactly its own comments, named as the request named them', function (StudioDiscussionGenerationMode $mode, string $target, array $answer, ?array $bodies) {
    $saved = saveStudioDiscussion($this->admin, $this->plan, [
        discussionNode(['key' => 'a', 'personaUserId' => $this->anna->id, 'body' => 'Lovely light.', 'offsetSeconds' => 60]),
        discussionNode(['key' => 'a1', 'parentKey' => 'a', 'personaUserId' => $this->boris->id, 'body' => 'Agreed.', 'offsetSeconds' => 120]),
        discussionNode(['key' => 'a2', 'parentKey' => 'a', 'personaUserId' => $this->anna->id, 'body' => 'Thank you.', 'sortOrder' => 1, 'offsetSeconds' => 180]),
        discussionNode(['key' => 'b', 'personaUserId' => $this->boris->id, 'body' => 'Where is this?', 'sortOrder' => 1, 'offsetSeconds' => 90]),
    ]);
    $target = $saved->ids[$target];
    $authors = StudioDiscussionContext::authorsOf($this->plan, StudioDiscussionContext::scopeOf($this->plan, $mode, $target));
    $context = StudioDiscussionContext::of($this->plan, StudioPlanImageAnalysis::query()->where('studio_plan_id', $this->plan->id)->first(), $authors);
    $prompt = new StudioDiscussionPrompt($mode, StudioPostTextTone::Cheeky, $context, scope: $context->scope($mode, $target));

    $proposal = proposalOf($prompt, $answer);

    expect($bodies === null ? $proposal : $proposal->toArray())->toBe($bodies === null ? 'invalid_provider_response' : ['bodies' => array_map(
        fn (array $pair): array => ['node_id' => $saved->ids[$pair[0]], 'body' => $pair[1]],
        $bodies,
    )]);
})->with([
    'a comment' => [StudioDiscussionGenerationMode::RegenerateComment, 'a', ['text' => 'Such light.'], [['a', 'Such light.']]],
    'a comment, with a field too many' => [StudioDiscussionGenerationMode::RegenerateComment, 'a', ['text' => 'Such light.', 'id' => 4], null],
    'a comment, its text too long' => [StudioDiscussionGenerationMode::RegenerateComment, 'a', ['text' => str_repeat('a', 1001)], null],
    'a reply' => [StudioDiscussionGenerationMode::RegenerateReply, 'a1', ['text' => 'Indeed.'], [['a1', 'Indeed.']]],
    'a branch' => [StudioDiscussionGenerationMode::RegenerateBranch, 'a', ['comment' => 'What light.', 'replies' => [['reply' => 'existing_2', 'text' => 'Yes.'], ['reply' => 'existing_3', 'text' => 'Thanks.']]], [['a', 'What light.'], ['a1', 'Yes.'], ['a2', 'Thanks.']]],
    'a branch, a reply left out' => [StudioDiscussionGenerationMode::RegenerateBranch, 'a', ['comment' => 'What light.', 'replies' => [['reply' => 'existing_2', 'text' => 'Yes.']]], null],
    'a branch, a reply added' => [StudioDiscussionGenerationMode::RegenerateBranch, 'a', ['comment' => 'What light.', 'replies' => [['reply' => 'existing_2', 'text' => 'Yes.'], ['reply' => 'existing_3', 'text' => 'Thanks.'], ['reply' => 'node_1', 'text' => 'Me too.']]], null],
    'a branch, its replies swapped' => [StudioDiscussionGenerationMode::RegenerateBranch, 'a', ['comment' => 'What light.', 'replies' => [['reply' => 'existing_3', 'text' => 'Thanks.'], ['reply' => 'existing_2', 'text' => 'Yes.']]], null],
    'a branch reaching a neighbour\'s comment' => [StudioDiscussionGenerationMode::RegenerateBranch, 'a', ['comment' => 'What light.', 'replies' => [['reply' => 'existing_2', 'text' => 'Yes.'], ['reply' => 'existing_4', 'text' => 'Hijacked.']]], null],
]);

it('reads no proposal out of what is not one', function (mixed $stored) {
    expect(StudioDiscussionProposal::fromStored(StudioDiscussionGenerationMode::FullDiscussion, $stored))->toBeNull()
        ->and(StudioDiscussionProposal::fromStored(StudioDiscussionGenerationMode::RegenerateComment, $stored))->toBeNull();
})->with([
    'nothing' => [null],
    'a list' => [[1, 2]],
    'nodes of the wrong shape' => [['nodes' => [['key' => 'node_1']], 'bodies' => [['node_id' => '7', 'body' => 'x']]]],
]);
