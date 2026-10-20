<?php

use App\Data\Studio\StudioDiscussionData;
use App\Data\Studio\StudioDiscussionNodeData;
use App\Enums\StudioPlanPublicationMode;
use App\Enums\UserStatus;
use App\Models\StudioDiscussionNode;
use App\Models\StudioPlan;
use App\Models\User;
use App\Support\Studio\StudioDiscussionValidationRules;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * The one set of rules a Draft's discussion is held to, whoever wrote it:
 * two levels, a Persona and a text for each comment, times that never go
 * back, and nothing that names a comment the discussion does not hold.
 */

/**
 * The fields a discussion fails, each with its message; none when it passes.
 *
 * @param  list<StudioDiscussionNodeData>  $nodes
 * @return array<string, list<string>>
 */
function discussionErrors(array $nodes, ?StudioPlan $plan = null): array
{
    $rules = app(StudioDiscussionValidationRules::class);
    $data = new StudioDiscussionData($nodes);

    try {
        $data = $rules->validated($data);

        if ($plan !== null) {
            $users = User::query()->whereKey($data->personaIds())->get()->keyBy('id');
            $rules->validateAgainst($data, $plan, $users);
        }
    } catch (ValidationException $invalid) {
        return $invalid->errors();
    }

    return [];
}

beforeEach(function () {
    $this->persona = User::factory()->persona()->create();
});

it('takes an empty discussion, one comment, several, and comments with their replies', function () {
    $p = $this->persona->id;

    expect(discussionErrors([]))->toBe([])
        ->and(discussionErrors([discussionNode(['personaUserId' => $p, 'body' => 'Lovely light.', 'offsetSeconds' => 60])]))->toBe([])
        ->and(discussionErrors([
            discussionNode(['personaUserId' => $p, 'body' => 'Lovely light.', 'offsetSeconds' => 60]),
            discussionNode(['personaUserId' => $p, 'body' => 'Where is this?', 'sortOrder' => 1, 'offsetSeconds' => 120]),
        ]))->toBe([])
        ->and(discussionErrors([
            discussionNode(['key' => 'a', 'personaUserId' => $p, 'body' => 'Lovely light.', 'offsetSeconds' => 60]),
            discussionNode(['parentKey' => 'a', 'personaUserId' => $p, 'body' => 'Agreed.', 'offsetSeconds' => 180]),
            discussionNode(['parentKey' => 'a', 'personaUserId' => $p, 'body' => 'The boats!', 'sortOrder' => 1, 'offsetSeconds' => 300]),
        ]))->toBe([]);
});

it('keeps to two levels: a reply answers a comment on the post, never another reply', function () {
    $p = $this->persona->id;

    expect(discussionErrors([
        discussionNode(['key' => 'a', 'personaUserId' => $p, 'body' => 'Lovely light.', 'offsetSeconds' => 60]),
        discussionNode(['key' => 'b', 'parentKey' => 'a', 'personaUserId' => $p, 'body' => 'Agreed.', 'offsetSeconds' => 120]),
        discussionNode(['parentKey' => 'b', 'personaUserId' => $p, 'body' => 'Me too.', 'offsetSeconds' => 180]),
    ]))->toBe(['nodes.2.parentKey' => ['Reply to a comment on the post, not to another reply.']]);
});

it('lets no loop form: nothing replies to itself, and two replies cannot answer each other', function () {
    $p = $this->persona->id;

    expect(discussionErrors([
        discussionNode(['key' => 'a', 'parentKey' => 'a', 'personaUserId' => $p, 'body' => 'Me.', 'offsetSeconds' => 60]),
    ]))->toBe(['nodes.0.parentKey' => ['A comment cannot reply to itself.']])
        ->and(discussionErrors([
            discussionNode(['key' => 'a', 'parentKey' => 'b', 'personaUserId' => $p, 'body' => 'You.', 'offsetSeconds' => 60]),
            discussionNode(['key' => 'b', 'parentKey' => 'a', 'personaUserId' => $p, 'body' => 'No, you.', 'offsetSeconds' => 60]),
        ]))->toHaveKeys(['nodes.0.parentKey', 'nodes.1.parentKey']);
});

it('refuses a reply to a comment the discussion does not hold: unknown, left out, or another plan\'s', function () {
    $p = $this->persona->id;
    $plan = StudioPlan::factory()->create();
    $mine = StudioDiscussionNode::factory()->for($plan, 'plan')->create();
    $theirs = StudioDiscussionNode::factory()->create();

    expect(discussionErrors([discussionNode(['parentKey' => 'nobody', 'personaUserId' => $p, 'body' => 'Hi.'])]))
        ->toBe(['nodes.0.parentKey' => ['The comment it replies to is not in this discussion.']])
        // Saved, but left out of the save — deleted by it.
        ->and(discussionErrors([discussionNode(['parentId' => $mine->id, 'personaUserId' => $p, 'body' => 'Hi.', 'offsetSeconds' => 120])], $plan))
        ->toBe(['nodes.0.parentId' => ['The comment it replies to is not in this discussion.']])
        ->and(discussionErrors([discussionNode(['parentId' => $theirs->id, 'personaUserId' => $p, 'body' => 'Hi.', 'offsetSeconds' => 120])], $plan))
        ->toBe(['nodes.0.parentId' => ['The comment it replies to is not in this discussion.']])
        // Another plan's comment named as one of this discussion.
        ->and(discussionErrors([
            discussionNode(['id' => $theirs->id, 'personaUserId' => $p, 'body' => 'Mine now.', 'offsetSeconds' => 60]),
            discussionNode(['parentId' => $theirs->id, 'personaUserId' => $p, 'body' => 'Hi.', 'offsetSeconds' => 120]),
        ], $plan))->toBe(['nodes.0.id' => ['This comment is not part of this plan’s discussion. Reload the plan.']])
        ->and(discussionErrors([
            discussionNode(['parentId' => $mine->id, 'parentKey' => 'a', 'key' => 'b', 'personaUserId' => $p, 'body' => 'Hi.']),
        ]))->toHaveKey('nodes.0.parentId');
});

it('keeps to forty comments and replies', function () {
    $node = discussionNode(['personaUserId' => $this->persona->id, 'body' => 'Lovely.', 'offsetSeconds' => 60]);

    expect(discussionErrors(array_fill(0, StudioDiscussionValidationRules::MOST_NODES, $node)))->toBe([])
        ->and(discussionErrors(array_fill(0, StudioDiscussionValidationRules::MOST_NODES + 1, $node)))
        ->toBe(['nodes' => ['Keep the discussion to 40 comments and replies or fewer.']]);
});

it('wants a text for every comment, as long as a comment may be', function () {
    $node = fn (string $body) => [discussionNode(['personaUserId' => $this->persona->id, 'body' => $body])];

    expect(discussionErrors($node('   ')))->toBe(['nodes.0.body' => ['Write the comment.']])
        ->and(discussionErrors($node(str_repeat('ж', 1000))))->toBe([])
        ->and(discussionErrors($node(str_repeat('ж', 1001))))->toBe(['nodes.0.body' => ['Keep the comment to 1,000 characters or fewer.']]);
});

it('wants a Persona that is not deleted as every comment\'s author — a limited, shadowbanned or banned one still may be', function (Closure $author, bool $passes) {
    $errors = discussionErrors([discussionNode(['personaUserId' => $author()->id, 'body' => 'Lovely.'])]);

    expect($errors)->toBe($passes ? [] : ['nodes.0.personaUserId' => ['Choose a persona who is not deleted.']]);
})->with([
    'an ordinary member' => [fn () => User::factory()->create(), false],
    'an administrator' => [fn () => User::factory()->admin()->create(), false],
    'a deleted Persona' => [fn () => User::factory()->persona()->create(['status' => UserStatus::Deleted]), false],
    'a limited Persona' => [fn () => User::factory()->persona()->create(['status' => UserStatus::Limited]), true],
    'a shadowbanned Persona' => [fn () => User::factory()->persona()->create(['status' => UserStatus::Shadowbanned]), true],
    'a banned Persona' => [fn () => User::factory()->persona()->create(['status' => UserStatus::Banned]), true],
]);

it('asks again of the Personas as they are now: one deleted since it was checked is refused', function () {
    $plan = StudioPlan::factory()->create();
    $rules = app(StudioDiscussionValidationRules::class);
    $data = $rules->validated(new StudioDiscussionData([discussionNode(['personaUserId' => $this->persona->id, 'body' => 'Lovely.'])]));

    $this->persona->forceFill(['status' => UserStatus::Deleted])->save();

    expect(fn () => $rules->validateAgainst($data, $plan, User::query()->whereKey($this->persona->id)->get()->keyBy('id')))
        ->toThrow(fn (ValidationException $invalid) => expect($invalid->errors())->toBe(['nodes.0.personaUserId' => ['Choose a persona who is not deleted.']]));
});

it('keeps every comment from the post\'s own moment to thirty days after it, and no reply before its comment', function () {
    $p = $this->persona->id;
    $at = fn (int $offset, int $replyOffset) => discussionErrors([
        discussionNode(['key' => 'a', 'personaUserId' => $p, 'body' => 'Lovely.', 'offsetSeconds' => $offset]),
        discussionNode(['parentKey' => 'a', 'personaUserId' => $p, 'body' => 'Agreed.', 'offsetSeconds' => $replyOffset]),
    ]);

    expect($at(0, 0))->toBe([])
        ->and($at(60, 60))->toBe([])
        ->and($at(-1, 60))->toBe(['nodes.0.offsetSeconds' => ['Set when it comes: from the post’s own moment to 30 days after it.']])
        ->and($at(60, StudioDiscussionValidationRules::LATEST_OFFSET_SECONDS + 1))->toBe(['nodes.1.offsetSeconds' => ['Set when it comes: from the post’s own moment to 30 days after it.']])
        ->and($at(180, 120))->toBe(['nodes.1.offsetSeconds' => ['A reply cannot come before the comment it answers.']]);
});

it('lets comments share a moment and a place, their ids keeping the order', function () {
    $p = $this->persona->id;

    expect(discussionErrors([
        discussionNode(['personaUserId' => $p, 'body' => 'One.', 'sortOrder' => 0, 'offsetSeconds' => 60]),
        discussionNode(['personaUserId' => $p, 'body' => 'Two.', 'sortOrder' => 0, 'offsetSeconds' => 60]),
    ]))->toBe([])
        ->and(discussionErrors([discussionNode(['personaUserId' => $p, 'body' => 'One.', 'sortOrder' => -1])]))->toHaveKey('nodes.0.sortOrder');
});

it('names each comment once: no id and no key twice', function () {
    $p = $this->persona->id;
    $node = StudioDiscussionNode::factory()->create();

    expect(discussionErrors([
        discussionNode(['key' => 'a', 'personaUserId' => $p, 'body' => 'One.']),
        discussionNode(['key' => 'a', 'personaUserId' => $p, 'body' => 'Two.']),
    ]))->toHaveKeys(['nodes.0.key', 'nodes.1.key'])
        ->and(discussionErrors([
            discussionNode(['id' => $node->id, 'personaUserId' => $p, 'body' => 'One.']),
            discussionNode(['id' => $node->id, 'personaUserId' => $p, 'body' => 'Two.']),
        ]))->toHaveKeys(['nodes.0.id', 'nodes.1.id']);
});

it('keeps a dated post\'s discussion inside the dates a post may carry', function () {
    $p = $this->persona->id;
    $plan = StudioPlan::factory()->create([
        'publication_mode' => StudioPlanPublicationMode::AtDate,
        'publish_at' => CarbonImmutable::parse('2038-01-18 23:00:00'),
    ]);
    $now = StudioPlan::factory()->create(['publication_mode' => StudioPlanPublicationMode::Now]);
    $comment = fn (int $offset) => [discussionNode(['personaUserId' => $p, 'body' => 'Lovely.', 'offsetSeconds' => $offset])];

    expect(discussionErrors($comment(3599), $plan))->toBe([])
        ->and(discussionErrors($comment(3600), $plan))->toHaveKey('nodes.0.offsetSeconds')
        // Published at once, the post's moment is its approval's.
        ->and(discussionErrors($comment(StudioDiscussionValidationRules::LATEST_OFFSET_SECONDS), $now))->toBe([]);
});
