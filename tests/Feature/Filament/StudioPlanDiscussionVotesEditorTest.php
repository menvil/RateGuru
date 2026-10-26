<?php

use App\Actions\Studio\DeleteStudioPlanDraftAction;
use App\Data\Studio\StudioDiscussionData;
use App\Enums\StudioActionType;
use App\Enums\StudioPlanStatus;
use App\Enums\UserStatus;
use App\Filament\Resources\StudioPlans\Pages\CreateStudioPlan;
use App\Filament\Resources\StudioPlans\Pages\EditStudioPlan;
use App\Livewire\Admin\StudioPlanDiscussionEditor;
use App\Livewire\Admin\StudioPlanDiscussionVotesEditor;
use App\Livewire\Admin\StudioPlanPostVotesEditor;
use App\Models\CommentVote;
use App\Models\PostVote;
use App\Models\RatingVote;
use App\Models\StudioAction;
use App\Models\StudioDiscussionNode;
use App\Models\StudioPlan;
use App\Models\StudioPlanVote;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * The Discussion step's votes on its comments: each comment's up and down
 * votes, set by hand or shared out by priority — the totals always what they
 * add up to — the Personas who cast them, never on their own comment, and
 * when after each comment; saved as soon as they are asked for, once for a
 * request, and shown on the tree, in each comment's breakdown and in time on
 * the Timing tab, the votes on the post and its questions kept, every write
 * sharing the plan's one version with the other editors on the page; the
 * voters kept as they were when the editor is opened again, and the step not
 * left with a change that can't be saved without asking.
 */
beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
    [$this->author, $this->anna, $this->boris, $this->carl, $this->dana, $this->eli] = collect(['Author Ada', 'Anna', 'Boris', 'Carl', 'Dana', 'Eli'])
        ->map(fn (string $name): User => User::factory()->persona()->create(['display_name' => $name]))
        ->all();
    $this->plan = StudioPlan::factory()->authoredBy($this->author)->create(['created_by_user_id' => $this->admin->id]);
    $saved = saveStudioDiscussion($this->admin, $this->plan, [
        discussionNode(['key' => 'c1', 'personaUserId' => $this->anna->id, 'body' => 'Lovely light on the water.', 'offsetSeconds' => 300]),
        discussionNode(['key' => 'r1', 'parentKey' => 'c1', 'personaUserId' => $this->boris->id, 'body' => 'Agreed, the mist helps.', 'offsetSeconds' => 600]),
        discussionNode(['key' => 'c2', 'personaUserId' => $this->carl->id, 'body' => 'Where is this?', 'sortOrder' => 1, 'offsetSeconds' => 900]),
    ]);
    [$this->c1, $this->r1, $this->c2] = [$saved->ids['c1'], $saved->ids['r1'], $saved->ids['c2']];
    // The post's own votes, kept through every save here.
    saveStudioVotes($this->admin, $this->plan, [studioVote(['type' => 'vote_post', 'personaUserId' => $this->dana->id, 'voteType' => 'up', 'offsetSeconds' => 60])]);
    $this->postVote = StudioPlanVote::query()->sole()->id;
    $this->plan->refresh();
});

/** The plan's comment votes as saved: who, which way, on which comment, when, in which place. */
function savedCommentVotes(StudioPlan $plan): array
{
    return StudioPlanVote::query()->where('studio_plan_id', $plan->id)->where('type', StudioActionType::VoteComment)
        ->orderBy('offset_seconds')->orderBy('sort_order')->orderBy('id')->get()
        ->map(fn (StudioPlanVote $vote): array => [$vote->persona_user_id, $vote->vote_type?->value, $vote->discussion_node_id, $vote->offset_seconds, $vote->sort_order])
        ->all();
}

/**
 * Two up votes and one down on C1, and one up on C2, by four Personas drawn
 * at random: each saved as it is set, the plan at version 6 after them.
 */
function setCommentVotes(Testable $editor, int $c1, int $c2): Testable
{
    return $editor->set('voterCount', 4)
        ->call('setVotes', $c1, 'up', 2)->call('setVotes', $c1, 'down', 1)->call('setVotes', $c2, 'up', 1)
        ->assertHasNoErrors();
}

it('is for administrators who may manage plans only — drawn, or clicked', function () {
    Livewire::actingAs(User::factory()->moderator()->create())->test(StudioPlanDiscussionVotesEditor::class, ['planId' => $this->plan->id])->assertForbidden();
    Livewire::actingAs(User::factory()->create())->test(StudioPlanDiscussionVotesEditor::class, ['planId' => $this->plan->id])->assertForbidden();

    $this->actingAs($this->admin);
    $editor = discussionVotesEditor($this->plan);
    $this->admin->forceFill(['role' => 'moderator'])->save();

    $editor->call('setVotes', $this->c1, 'up', 1)->assertForbidden();

    expect(savedCommentVotes($this->plan))->toBe([]);
});

it('starts every comment and reply Normal and with no votes, from a random draw of the active Personas, and says so to the tree', function () {
    discussionVotesEditor($this->plan)
        ->assertSet('voterMode', 'random')->assertSet('voterCount', 6)
        ->assertSet('targets', [
            $this->c1 => ['up' => 0, 'down' => 0, 'priority' => 'normal', 'first' => 300, 'spread' => 21600, 'moments' => []],
            $this->r1 => ['up' => 0, 'down' => 0, 'priority' => 'normal', 'first' => 300, 'spread' => 21600, 'moments' => []],
            $this->c2 => ['up' => 0, 'down' => 0, 'priority' => 'normal', 'first' => 300, 'spread' => 21600, 'moments' => []],
        ])
        ->assertSet('summary', [
            $this->c1 => ['up' => 0, 'down' => 0, 'changed' => false, 'problem' => null],
            $this->r1 => ['up' => 0, 'down' => 0, 'changed' => false, 'problem' => null],
            $this->c2 => ['up' => 0, 'down' => 0, 'changed' => false, 'problem' => null],
        ])
        ->assertSet('unsaved', false)->assertSet('preview', null)->assertSet('selected', null)
        ->assertSet('totalUp', 0)->assertSet('totalDown', 0)->assertSet('status', 'no comment votes')
        ->assertSee('On 0 of 3 comments.');
});

it('says the totals wait for the comments when the discussion has none, and saves nothing for them', function () {
    $plan = StudioPlan::factory()->create(['created_by_user_id' => $this->admin->id]);
    $version = $plan->fresh()->lock_version;

    discussionVotesEditor($plan)->assertSee('No comments yet: once the tree is generated or written')->assertSee('saved as soon as the tree is written')->assertSee('Comment voters')
        ->call('redraw')->assertHasNoErrors()->assertSet('preview', null)->assertSet('unsaved', false);

    expect($plan->fresh()->lock_version)->toBe($version);
});

it('shares a total out by priority as soon as it changes — Low 1, Normal 2, High 3 — and keeps the totals what the comments\' own votes add up to', function () {
    $editor = discussionVotesEditor($this->plan)
        ->call('setPriority', $this->c1, 'high')->call('setPriority', $this->c2, 'low')
        ->set('totalUp', 12)->set('totalDown', 5);

    // Up: 12 × 3/6, 2/6, 1/6 = 6, 4, 2; down: 5 × the same = 2.5, 1.67, 0.83 — 2, 1, 0, the two left to the largest fractions.
    $editor->assertSet("targets.{$this->c1}.up", 6)->assertSet("targets.{$this->r1}.up", 4)->assertSet("targets.{$this->c2}.up", 2)
        ->assertSet("targets.{$this->c1}.down", 2)->assertSet("targets.{$this->r1}.down", 2)->assertSet("targets.{$this->c2}.down", 1)
        ->call('setVotes', $this->c2, 'up', 0)->assertSet("targets.{$this->c2}.up", 0)->assertSet('totalUp', 10)
        ->call('setVotes', $this->c1, 'up', 9)->assertSet('totalUp', 13)
        ->call('setVotes', $this->c2, 'down', -4)->assertSet("targets.{$this->c2}.down", 0)->assertSet('totalDown', 4)
        ->call('setPriority', $this->c2, 'urgent')->assertSet("targets.{$this->c2}.priority", 'low')
        ->assertSee('On 2 of 3 comments.');

    // Distribute automatically: both totals shared out again — up 6.5, 4.33, 2.17 and down 2, 1.33, 0.67, the one left over each to the largest fraction.
    $editor->call('distribute')
        ->assertSet("targets.{$this->c1}.up", 7)->assertSet("targets.{$this->r1}.up", 4)->assertSet("targets.{$this->c2}.up", 2)
        ->assertSet("targets.{$this->c1}.down", 2)->assertSet("targets.{$this->r1}.down", 1)->assertSet("targets.{$this->c2}.down", 1)
        ->assertSet('totalUp', 13)->assertSet('totalDown', 4)->assertSee('On 3 of 3 comments.');

    // Nine votes on C1, whose own Persona never votes on it, are more than the five others there are: nothing is saved.
    expect(savedCommentVotes($this->plan))->toBe([])
        ->and($editor->get('unsaved'))->toBeTrue();
    $editor->assertHasErrors(["targets.{$this->c1}"]);
});

it('gives a total\'s new votes to the comments furthest below their share, and takes them back from the ones furthest above, the rest kept as set', function () {
    discussionVotesEditor($this->plan)->set('totalUp', 3)
        ->assertSet("targets.{$this->c1}.up", 1)->assertSet("targets.{$this->r1}.up", 1)->assertSet("targets.{$this->c2}.up", 1)
        ->call('setVotes', $this->c1, 'up', 3)->assertSet('totalUp', 5)
        // Six shared evenly is two each: C2 and C3 are one short, the first in the tree gets the vote.
        ->set('totalUp', 6)
        ->assertSet("targets.{$this->c1}.up", 3)->assertSet("targets.{$this->r1}.up", 2)->assertSet("targets.{$this->c2}.up", 1)
        // Four is 2, 1, 1: C1 and C2 are one over each, the later in the tree gives first.
        ->set('totalUp', 4)
        ->assertSet("targets.{$this->c1}.up", 2)->assertSet("targets.{$this->r1}.up", 1)->assertSet("targets.{$this->c2}.up", 1)
        ->set('totalUp', 0)->assertSet('targets', fn (array $targets): bool => array_sum(array_column($targets, 'up')) === 0)
        ->assertSet('totalDown', 0);
});

it('saves the votes as soon as they are asked for — never a vote on a comment by its own Persona — and shows them on the tree as saved, keeping the votes on the post', function () {
    $editor = setCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2);
    $saved = savedCommentVotes($this->plan);

    expect($saved)->toHaveCount(4)
        ->and(collect($saved)->where(2, $this->c1)->pluck(0)->all())->not->toContain($this->anna->id)
        ->and(collect($saved)->where(2, $this->c2)->pluck(0)->all())->not->toContain($this->carl->id)
        ->and(collect($saved)->where(2, $this->c1)->pluck(0)->unique())->toHaveCount(3)
        ->and(StudioPlanVote::query()->whereKey($this->postVote)->exists())->toBeTrue()
        ->and($this->plan->fresh()->lock_version)->toBe(6);

    $editor->assertSet('status', '4 comment votes (+3 / −1)')->assertSet('unsaved', false)->assertSet('preview', null)
        ->assertSet("summary.{$this->c1}.up", 2)->assertSet("summary.{$this->c1}.down", 1)->assertSet("summary.{$this->c1}.changed", false)
        ->assertDispatched(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 5, current: 6, source: StudioPlanDiscussionVotesEditor::SOURCE)
        ->assertNotDispatched('rg-admin-toast');
});

it('saves once for a request however many settings it carries, and nothing for the votes saved already', function () {
    $editor = discussionVotesEditor($this->plan)->update(
        calls: [
            ['method' => 'setVotes', 'params' => [$this->c1, 'up', 2], 'path' => ''],
            ['method' => 'setVotes', 'params' => [$this->c2, 'up', 1], 'path' => ''],
        ],
        updates: ['voterCount' => 4],
    )->assertHasNoErrors()
        ->assertDispatched(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 3, current: 4, source: StudioPlanDiscussionVotesEditor::SOURCE);

    expect(savedCommentVotes($this->plan))->toHaveCount(3)
        ->and($this->plan->fresh()->lock_version)->toBe(4);

    $editor->call('setVotes', $this->c2, 'up', 1)->call('setTiming', 'first', 300)
        ->assertNotDispatched(StudioPlanDiscussionEditor::VERSION_EVENT);

    expect($this->plan->fresh()->lock_version)->toBe(4);
});

it('dates each vote from its comment, for every comment or for the one changed in its breakdown', function () {
    $editor = discussionVotesEditor($this->plan)->call('setVotes', $this->c2, 'up', 3)->call('setTiming', 'first', 1800)->call('setTiming', 'spread', 3600);

    expect(array_column(savedCommentVotes($this->plan), 3))->toBe([2700, 4500, 6300]);

    $editor->call('setVotes', $this->c1, 'up', 1)->call('setTiming', 'first', 3600, $this->c2)
        ->assertSet("targets.{$this->c2}.first", 3600)->assertSet("targets.{$this->c1}.first", 1800)->assertSet("targets.{$this->r1}.first", 1800)
        ->call('setTiming', 'first', 3600, 999_999)->call('setTiming', 'first', 17, $this->c2)->assertSet("targets.{$this->c2}.first", 3600);

    expect(collect(savedCommentVotes($this->plan))->map(fn (array $vote): array => [$vote[2], $vote[3]])->all())
        ->toBe([[$this->c1, 2100], [$this->c2, 4500], [$this->c2, 6300], [$this->c2, 8100]]);
});

it('keeps the Personas voting when opened again — the draw as this session left it, or one around them — so a vote more is never short of them', function () {
    setCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2);
    $saved = savedCommentVotes($this->plan);
    $onC1 = fn (array $votes): array => collect($votes)->where(2, $this->c1)->map(fn (array $vote): array => array_slice($vote, 0, 3))->values()->all();

    discussionVotesEditor($this->plan)
        ->assertSet("targets.{$this->c1}.up", 2)->assertSet("targets.{$this->c1}.down", 1)->assertSet("targets.{$this->c2}.up", 1)
        ->assertSet("targets.{$this->r1}.up", 0)->assertSet("targets.{$this->c1}.priority", 'normal')
        ->assertSet("targets.{$this->c1}.first", 300)
        ->assertSet('voterMode', 'random')->assertSet('voterCount', 4)->assertSet('voterIds', [])
        ->assertSet('totalUp', 3)->assertSet('totalDown', 1)
        ->assertSet('status', '4 comment votes (+3 / −1)')->assertSet('unsaved', false);

    // In another session: drawn at random from every active Persona, the ones voting kept in the draw.
    session()->forget(StudioPlanDiscussionVotesEditor::VOTERS_SESSION.'.'.$this->plan->id);

    discussionVotesEditor($this->plan)->assertSet('voterMode', 'random')->assertSet('voterCount', 6)
        ->call('setVotes', $this->c1, 'up', 4)->assertHasNoErrors()->assertSet('unsaved', false);

    $now = $onC1(savedCommentVotes($this->plan));

    expect($now)->toHaveCount(5)
        ->and(collect($onC1($saved))->every(fn (array $vote): bool => in_array($vote, $now, true)))->toBeTrue();
});

it('chooses the voters by hand again when opened again in the session they were chosen in', function () {
    discussionVotesEditor($this->plan)->set('voterMode', 'manual')
        ->call('toggleVoter', $this->anna->id)->call('toggleVoter', $this->dana->id)->call('toggleVoter', $this->eli->id)
        ->call('setVotes', $this->c2, 'up', 2)->assertHasNoErrors();

    discussionVotesEditor($this->plan)->assertSet('voterMode', 'manual')->assertSet('voterIds', [$this->anna->id, $this->dana->id, $this->eli->id])
        ->assertSet("targets.{$this->c2}.up", 2)->assertSet('unsaved', false);
});

it('draws more voters at random as a comment asks for more votes, as far as there are Personas', function () {
    $editor = discussionVotesEditor($this->plan)->set('voterCount', 2)
        ->call('setVotes', $this->c1, 'up', 3)->assertHasNoErrors()->assertSet('voterCount', 4);

    expect(collect(savedCommentVotes($this->plan))->where(2, $this->c1))->toHaveCount(3);

    // Six on C1 are more than the five Personas but its own who can vote on it: all six are drawn, and C1 says how many it is short.
    $editor->call('setVotes', $this->c1, 'up', 6)->assertSet('voterCount', 6)->assertHasErrors(["targets.{$this->c1}"])->assertSet('unsaved', true);

    expect(collect(savedCommentVotes($this->plan))->where(2, $this->c1))->toHaveCount(3);
});

it('says on the comment how many voters it is short, saves nothing until it is fixed, and goes back to the votes saved on Discard', function () {
    $short = 'Its 2 votes need 2 personas who can vote on it; 1 can — 1 short. Choose more personas, or ask for fewer.';

    $editor = discussionVotesEditor($this->plan)->set('voterMode', 'manual')->call('toggleVoter', $this->anna->id)->call('toggleVoter', $this->dana->id)
        ->call('setVotes', $this->c1, 'up', 2)
        ->assertHasErrors(["targets.{$this->c1}" => $short])
        ->assertSet('preview', null)->assertSet("targets.{$this->c1}.up", 2)
        ->assertSet("summary.{$this->c1}.problem", $short)->assertSet("summary.{$this->c2}.problem", null)->assertSet('unsaved', true)
        ->assertSet('status', 'comment votes: fix what is marked on C1')
        ->assertSee('Not saved · fix what is marked, or Discard to go back to the votes saved.');

    expect(savedCommentVotes($this->plan))->toBe([]);

    $editor->call('discard')->assertHasNoErrors()->assertSet("targets.{$this->c1}.up", 0)->assertSet('unsaved', false)
        ->assertSet('voterMode', 'manual')->assertSet('voterIds', [$this->anna->id, $this->dana->id]);

    // One more voter chosen: saved at once.
    $editor->call('setVotes', $this->c1, 'up', 2)->assertHasErrors(["targets.{$this->c1}"])
        ->call('toggleVoter', $this->eli->id)->assertHasNoErrors()->assertSet('unsaved', false);

    expect(collect(savedCommentVotes($this->plan))->pluck(0)->sort()->values()->all())->toBe(collect([$this->dana->id, $this->eli->id])->sort()->values()->all());
});

it('keeps each vote\'s id when a save changes only its moment', function () {
    setCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2);
    $ids = StudioPlanVote::query()->where('type', StudioActionType::VoteComment)->orderBy('id')->pluck('id')->all();

    discussionVotesEditor($this->plan)->call('setTiming', 'first', 3600)->assertHasNoErrors();

    expect(StudioPlanVote::query()->where('type', StudioActionType::VoteComment)->orderBy('id')->pluck('id')->all())->toBe($ids);
});

it('keeps the saved votes of a comment whose count is not changed, and draws anew on Pick others', function () {
    setCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2);
    $saved = savedCommentVotes($this->plan);
    $onC1 = fn (array $votes): array => collect($votes)->where(2, $this->c1)->map(fn (array $vote): array => array_slice($vote, 0, 4))->values()->all();

    $editor = discussionVotesEditor($this->plan)->call('setVotes', $this->c2, 'down', 1)->assertHasNoErrors();
    $seed = $editor->get('seed');

    expect($onC1(savedCommentVotes($this->plan)))->toBe($onC1($saved))
        ->and(savedCommentVotes($this->plan))->toHaveCount(5);

    $editor->call('setTiming', 'spread', 3600, $this->c2)->assertSet('seed', $seed);

    expect($onC1(savedCommentVotes($this->plan)))->toBe($onC1($saved));

    $editor->call('redraw')->assertHasNoErrors()->assertSet('keepSaved', true)->assertSet('unsaved', false);

    expect($editor->get('seed'))->not->toBe($seed)
        ->and(savedCommentVotes($this->plan))->toHaveCount(5);
});

it('removes the comment votes once both totals are set to none, the votes on the post staying', function () {
    setCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2);

    discussionVotesEditor($this->plan)->set('totalUp', 0)->set('totalDown', 0)
        ->assertSet('status', 'no comment votes')->assertSet('unsaved', false)->assertSet("summary.{$this->c1}.changed", false);

    expect(savedCommentVotes($this->plan))->toBe([])
        ->and(StudioPlanVote::query()->pluck('id')->all())->toBe([$this->postVote]);
});

it('keeps a Persona restricted since in the saved votes, saying so, and gives a deleted one no new vote', function () {
    discussionVotesEditor($this->plan)->set('voterMode', 'manual')->call('toggleVoter', $this->eli->id)->call('toggleVoter', $this->dana->id)
        ->call('setVotes', $this->c2, 'up', 2)->assertHasNoErrors();
    $this->eli->forceFill(['status' => UserStatus::Shadowbanned])->save();
    $this->dana->forceFill(['status' => UserStatus::Deleted])->save();

    $editor = discussionVotesEditor($this->plan)
        ->assertSee('Shadowbanned')->assertSee('Deleted')
        ->assertSee('A deleted or restricted persona gets no new votes.')
        ->assertSet("summary.{$this->c2}.problem", fn (?string $problem): bool => $problem !== null)
        ->assertSet('status', '2 comment votes (+2 / −0) · see what is marked on C3')
        ->dispatch(StudioPlanDiscussionVotesEditor::SELECT_EVENT, node: $this->c2)
        ->assertSee('This persona is limited, banned or shadowbanned and cannot vote. Choose another one.');

    $editor->call('setTiming', 'first', 1800)->assertHasErrors(["targets.{$this->c2}"]);
    // Only Eli keeps a vote — saved once Dana's own vote on the post, deleted since, is fixed on Post · Votes.
    $editor->call('setVotes', $this->c2, 'up', 1)->assertHasNoErrors()
        ->assertDispatched('rg-admin-toast', message: 'A vote on the post or its questions saved earlier stands in the way: Choose a persona who is not deleted. Fix it on Post · Votes.', tone: 'error')
        ->assertSet('unsaved', true);

    expect(array_column($editor->get('preview'), 'personaUserId'))->toBe([$this->eli->id]);
});

it('takes no version somebody else wrote: nothing is saved until Reload', function () {
    $editor = setCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2);
    saveStudioVotes(User::factory()->admin()->create(), $this->plan, [
        studioVote(['type' => 'vote_post', 'personaUserId' => $this->dana->id, 'voteType' => 'up', 'offsetSeconds' => 60, 'id' => $this->postVote]),
        studioVote(['type' => 'vote_comment', 'personaUserId' => $this->eli->id, 'voteType' => 'down', 'discussionNodeId' => $this->c2, 'offsetSeconds' => 1000]),
    ]);

    $editor->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 6, current: 7, source: 'post_votes')
        ->assertSet('conflict', true)->assertSee('Someone changed this draft after you opened it')
        ->call('setVotes', $this->c1, 'up', 1);

    expect(savedCommentVotes($this->plan))->toBe([[$this->eli->id, 'down', $this->c2, 1000, 0]]);

    $editor->call('reload')->assertSet('conflict', false)->assertSet('lockVersion', 7)->assertSet("targets.{$this->c2}.down", 1)
        ->assertDispatched(StudioPlanDiscussionEditor::RELOAD_EVENT, planId: $this->plan->id, current: 7, source: StudioPlanDiscussionVotesEditor::SOURCE);
});

it('keeps a change not saved through another editor\'s Reload, saying the conflict, and draws the saved votes again when there is none', function () {
    $editor = discussionVotesEditor($this->plan)->set('voterMode', 'manual')->call('toggleVoter', $this->dana->id)
        ->call('setVotes', $this->c1, 'up', 2)->assertHasErrors(["targets.{$this->c1}"])->assertSet('unsaved', true);
    saveStudioVotes(User::factory()->admin()->create(), $this->plan, [
        studioVote(['type' => 'vote_post', 'personaUserId' => $this->dana->id, 'voteType' => 'up', 'offsetSeconds' => 60, 'id' => $this->postVote]),
        studioVote(['type' => 'vote_comment', 'personaUserId' => $this->eli->id, 'voteType' => 'down', 'discussionNodeId' => $this->c2, 'offsetSeconds' => 1000]),
    ]);

    $editor->dispatch(StudioPlanDiscussionEditor::RELOAD_EVENT, planId: $this->plan->id, current: 4, source: 'discussion')
        ->assertSet('conflict', true)->assertSet('lockVersion', 3)->assertSet("targets.{$this->c1}.up", 2)
        ->assertSee('Someone changed this draft after you opened it');

    $editor->call('reload')->assertSet('conflict', false)->assertSet('lockVersion', 4)->assertSet("targets.{$this->c2}.down", 1);

    $untouched = discussionVotesEditor($this->plan->fresh());
    saveStudioVotes(User::factory()->admin()->create(), $this->plan->fresh(), [
        studioVote(['type' => 'vote_post', 'personaUserId' => $this->dana->id, 'voteType' => 'up', 'offsetSeconds' => 60, 'id' => $this->postVote]),
    ]);

    $untouched->dispatch(StudioPlanDiscussionEditor::RELOAD_EVENT, planId: $this->plan->id, current: 5, source: 'discussion')
        ->assertSet('conflict', false)->assertSet('lockVersion', 5)->assertSet("targets.{$this->c2}.down", 0);
});

it('refuses a write drawn from a version somebody else replaced', function () {
    $editor = discussionVotesEditor($this->plan)->set('voterCount', 4);
    saveStudioDiscussion(User::factory()->admin()->create(), $this->plan, array_map(fn ($node) => $node, StudioDiscussionData::of($this->plan->fresh())->nodes));

    $editor->call('setVotes', $this->c1, 'up', 1)->assertSet('conflict', true)->assertSet('unsaved', false)
        ->assertDispatched('rg-admin-toast', message: 'Someone changed this draft meanwhile, so nothing was saved. Reload to see their changes.', tone: 'error');

    expect(savedCommentVotes($this->plan))->toBe([]);
});

it('takes the whole save back when a write of it is not saved, the votes and the version as they were', function () {
    $editor = discussionVotesEditor($this->plan)->set('voterCount', 4);
    StudioPlanVote::creating(fn (): bool => false);

    expect(fn () => $editor->call('setVotes', $this->c1, 'up', 2))->toThrow(RuntimeException::class);

    expect(savedCommentVotes($this->plan))->toBe([])
        ->and(StudioPlanVote::query()->pluck('id')->all())->toBe([$this->postVote])
        ->and($this->plan->fresh()->lock_version)->toBe(3);
});

it('says which vote on the post stands in the way of a save, and changes nothing of it', function () {
    // The post's voter made its author since: its vote on the post is no vote any more.
    $this->plan->forceFill(['author_user_id' => $this->dana->id])->save();

    discussionVotesEditor($this->plan)->call('setVotes', $this->c2, 'up', 1)
        ->assertDispatched('rg-admin-toast', message: 'A vote on the post or its questions saved earlier stands in the way: The post’s own author cannot vote on it. Choose another persona. Fix it on Post · Votes.', tone: 'error')
        ->assertSet('unsaved', true);

    expect(savedCommentVotes($this->plan))->toBe([])->and(StudioPlanVote::query()->pluck('id')->all())->toBe([$this->postVote]);
});

it('takes a deleted comment\'s votes away with it, never putting them back', function () {
    setCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2);
    $editor = discussionVotesEditor($this->plan)
        ->dispatch(StudioPlanDiscussionVotesEditor::SELECT_EVENT, node: $this->c1)->assertSet('selected', $this->c1);

    // The whole branch of C1 deleted on the page's Discussion editor.
    discussionEditor($this->plan)->call('askDelete', $this->c1)->call('confirmDelete')
        ->assertDispatched(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 6, current: 7, source: 'discussion');

    expect(collect(savedCommentVotes($this->plan))->pluck(2)->unique()->values()->all())->toBe([$this->c2]);

    $editor->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 6, current: 7, source: 'discussion')
        ->assertSet('lockVersion', 7)->assertSet('conflict', false)->assertSet('targets', fn (array $targets): bool => array_keys($targets) === [$this->c2])
        ->assertSet('selected', null)->assertSet('summary', fn (array $summary): bool => array_keys($summary) === [$this->c2])
        ->assertSet('unsaved', false)->assertSet('totalUp', 1)->assertSet('totalDown', 0)
        ->assertDispatched(StudioPlanDiscussionVotesEditor::SAVE_EVENT)
        ->dispatch(StudioPlanDiscussionVotesEditor::SAVE_EVENT)->assertHasNoErrors()->assertSet('lockVersion', 7);

    expect(collect(savedCommentVotes($this->plan))->pluck(2)->unique()->values()->all())->toBe([$this->c2])
        ->and(StudioPlanVote::query()->whereKey($this->postVote)->exists())->toBeTrue();
});

it('carries what was asked of the votes over to a discussion written anew — the voters, the totals shared out over the new comments, their timing — saved once every editor on the page has taken the write', function () {
    setCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2);
    $editor = discussionVotesEditor($this->plan)->call('setTiming', 'first', 1800)->assertHasNoErrors();
    $version = $this->plan->fresh()->lock_version;

    // The tree generated anew: every comment replaced, their votes with them.
    $new = saveStudioDiscussion($this->admin, $this->plan, [
        discussionNode(['key' => 'n1', 'personaUserId' => $this->anna->id, 'body' => 'A new one.', 'offsetSeconds' => 120]),
        discussionNode(['key' => 'n2', 'personaUserId' => $this->boris->id, 'body' => 'Another.', 'sortOrder' => 1, 'offsetSeconds' => 240]),
    ])->ids;

    expect(savedCommentVotes($this->plan))->toBe([]);

    // Three up shared over two Normal comments: 1.5 each, the one over to the first; one down to the first.
    $editor->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: $version, current: $version + 1, source: 'discussion')
        ->assertSet('conflict', false)->assertSet('lockVersion', $version + 1)
        ->assertSet('voterMode', 'random')->assertSet('voterCount', 4)
        ->assertSet('totalUp', 3)->assertSet('totalDown', 1)
        ->assertSet("targets.{$new['n1']}.up", 2)->assertSet("targets.{$new['n2']}.up", 1)
        ->assertSet("targets.{$new['n1']}.down", 1)->assertSet("targets.{$new['n2']}.down", 0)
        ->assertSet("targets.{$new['n1']}.first", 1800)->assertSet("targets.{$new['n2']}.first", 1800)
        ->assertSet('status', '4 comment votes (+3 / −1)')
        ->assertDispatched(StudioPlanDiscussionVotesEditor::SAVE_EVENT);

    // Not on the request that took the write: the other editors take its version on it too.
    expect(savedCommentVotes($this->plan))->toBe([]);

    $editor->dispatch(StudioPlanDiscussionVotesEditor::SAVE_EVENT)->assertHasNoErrors()->assertSet('unsaved', false)
        ->assertDispatched(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: $version + 1, current: $version + 2, source: StudioPlanDiscussionVotesEditor::SOURCE);

    expect(collect(savedCommentVotes($this->plan))->pluck(2)->countBy()->all())->toEqual([$new['n1'] => 3, $new['n2'] => 1]);
});

it('takes totals and timing asked before the tree is written, nothing unsaved meanwhile, and saves them over its comments once it is', function () {
    $plan = StudioPlan::factory()->authoredBy($this->author)->create(['created_by_user_id' => $this->admin->id]);
    $editor = discussionVotesEditor($plan)->assertSee('No comments yet: once the tree is generated or written')
        ->set('voterCount', 4)->set('totalUp', 3)->set('totalDown', 1)->call('setTiming', 'first', 1800)->call('distribute')
        ->assertSet('totalUp', 3)->assertSet('totalDown', 1)->assertSet('timingAsked.first', 1800)->assertSet('targets', [])
        ->assertSet('unsaved', false)->assertSet('status', '4 comment votes (+3 / −1)');
    $version = $plan->fresh()->lock_version;

    // Kept for the plan while it has no comments: the editor opened again — a new publication saved, say — starts from it.
    $editor = discussionVotesEditor($plan)->assertSet('totalUp', 3)->assertSet('totalDown', 1)->assertSet('timingAsked.first', 1800)
        ->assertSet('voterCount', 4)->assertSet('unsaved', false);

    // The tree generated: two comments, nothing asked of them by hand.
    $new = saveStudioDiscussion($this->admin, $plan, [
        discussionNode(['key' => 'n1', 'personaUserId' => $this->anna->id, 'body' => 'A first one.', 'offsetSeconds' => 120]),
        discussionNode(['key' => 'n2', 'personaUserId' => $this->boris->id, 'body' => 'A second.', 'sortOrder' => 1, 'offsetSeconds' => 240]),
    ])->ids;

    $editor->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $plan->id, previous: $version, current: $version + 1, source: 'discussion')
        ->assertSet('conflict', false)->assertSet('voterCount', 4)
        ->assertSet("targets.{$new['n1']}.up", 2)->assertSet("targets.{$new['n2']}.up", 1)
        ->assertSet("targets.{$new['n1']}.down", 1)->assertSet("targets.{$new['n2']}.down", 0)
        ->assertSet("targets.{$new['n1']}.first", 1800)->assertSet("targets.{$new['n2']}.first", 1800)
        ->assertDispatched(StudioPlanDiscussionVotesEditor::SAVE_EVENT)
        ->dispatch(StudioPlanDiscussionVotesEditor::SAVE_EVENT)->assertHasNoErrors()->assertSet('unsaved', false);

    expect(collect(savedCommentVotes($plan))->pluck(2)->countBy()->all())->toEqual([$new['n1'] => 3, $new['n2'] => 1])
        ->and(session(StudioPlanDiscussionVotesEditor::ASKED_SESSION.'.'.$plan->id))->toBeNull();
});

it('gives a comment added since the timing every comment was last given', function () {
    $editor = discussionVotesEditor($this->plan)->call('setTiming', 'spread', 86400);
    $version = $this->plan->fresh()->lock_version;
    $added = saveStudioDiscussion($this->admin, $this->plan, [
        ...StudioDiscussionData::of($this->plan->fresh())->nodes,
        discussionNode(['key' => 'c3', 'personaUserId' => $this->eli->id, 'body' => 'And one more.', 'sortOrder' => 2, 'offsetSeconds' => 1200]),
    ])->ids['c3'];

    $editor->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: $version, current: $version + 1, source: 'discussion')
        ->assertSet("targets.{$added}.spread", 86400)->assertSet("targets.{$this->c1}.spread", 86400);
});

it('gives a comment Undo brings back none of the votes it had: it is a new comment', function () {
    setCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2);
    $discussion = discussionEditor($this->plan)->call('askDelete', $this->c1)->call('confirmDelete');
    $discussion->dispatch('undo-studio-discussion', token: $discussion->get('undo')['token']);
    $restored = StudioDiscussionNode::query()->where('body', 'Lovely light on the water.')->sole();

    expect($restored->id)->not->toBe($this->c1)
        ->and(StudioPlanVote::query()->where('discussion_node_id', $restored->id)->exists())->toBeFalse();

    discussionVotesEditor($this->plan)->assertSet("targets.{$restored->id}.up", 0)->assertSet("targets.{$this->c2}.up", 1);
});

it('moves the version once for a save, which every other editor on the page takes', function () {
    $page = Livewire::test(EditStudioPlan::class, ['record' => $this->plan->id])->set('postTitle', 'Typed, not saved');
    $discussion = discussionEditor($this->plan);
    $postVotes = postVotesEditor($this->plan);

    discussionVotesEditor($this->plan)->set('voterCount', 4)->call('setVotes', $this->c1, 'up', 2)->assertHasNoErrors()
        ->assertDispatched(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 3, current: 4, source: StudioPlanDiscussionVotesEditor::SOURCE);

    foreach ([$page, $discussion, $postVotes] as $editor) {
        $editor->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 3, current: 4, source: StudioPlanDiscussionVotesEditor::SOURCE)
            ->assertSet('lockVersion', 4);
    }

    $page->assertSet('stale', false)->assertSet('postTitle', 'Typed, not saved')->call('save')->assertDispatched('rg-admin-toast', message: 'Draft saved')
        ->assertDispatched(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 4, current: 5, source: 'editor');
    $postVotes->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 4, current: 5, source: 'editor')->assertSet('conflict', false)->call('setTiming', 'first', 300)->assertHasNoErrors();

    expect($this->plan->fresh()->lock_version)->toBe(6)
        ->and(savedCommentVotes($this->plan))->toHaveCount(2)
        ->and(StudioPlanVote::query()->where('type', StudioActionType::VotePost)->count())->toBe(1);
});

it('takes the version a Save draft or a save of the post votes moved, so its own next save is no conflict', function () {
    $editor = discussionVotesEditor($this->plan);

    Livewire::test(EditStudioPlan::class, ['record' => $this->plan->id])->set('postTitle', 'A harbour at dawn')->call('save');
    $editor->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 3, current: 4, source: 'editor')->assertSet('lockVersion', 4);

    postVotesEditor($this->plan)->call('selectShown')->set('up', 2)->assertHasNoErrors();
    $editor->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 4, current: 5, source: StudioPlanPostVotesEditor::SOURCE)->assertSet('lockVersion', 5);

    setCommentVotes($editor, $this->c1, $this->c2)->assertSet('lockVersion', 8);

    expect(StudioPlanVote::query()->where('type', StudioActionType::VotePost)->count())->toBe(2)
        ->and(savedCommentVotes($this->plan))->toHaveCount(4);
});

it('draws in the page\'s Discussion step, its cards beside the Discussion settings, the breakdown beside the tree, the voters on its tab, and a new publication asks for its draft first', function () {
    Livewire::test(EditStudioPlan::class, ['record' => $this->plan->id])->call('goToStep', 'discussion')
        ->assertSeeHtml('wire:name="admin.studio-plan-discussion-votes-editor" wire:key="studio-plan-discussion-votes-'.$this->plan->id.'"')
        ->assertSeeHtml("window.Alpine.store('rgCommentVotes', { nodes: {}, selected: null, unsaved: false, status: null, generating: false })");

    discussionEditor($this->plan)->assertSeeHtmlInOrder([
        'Discussion settings',
        '<div id="rg-admin-studio-comment-voters" class="rg-admin-studio__comment-voters-slot" wire:ignore></div>',
        '<div id="rg-admin-studio-comment-vote-settings" class="rg-admin-studio__comment-voters-slot" wire:ignore></div>',
        'Generate from the settings above',
        'Discussion tree',
        '<div id="rg-admin-studio-comment-voters-table" wire:ignore></div>',
        '<div id="rg-admin-studio-timing" wire:ignore></div>',
        '<div id="rg-admin-studio-comment-breakdown" class="rg-admin-studio__breakdown-slot" wire:ignore></div>',
    ]);
    discussionVotesEditor($this->plan)->assertSeeHtmlInOrder([
        '<template x-teleport="#rg-admin-studio-comment-voters">', 'Comment voters', '</template>',
        '<template x-teleport="#rg-admin-studio-comment-vote-settings">', 'Comment votes', 'Distribute automatically', 'First vote after', '</template>',
        '<template x-teleport="#rg-admin-studio-comment-breakdown">', '</template>',
        '<template x-teleport="#rg-admin-studio-comment-voters-table">', 'Reshuffle', '</template>',
        '<template x-teleport="#rg-admin-studio-timing">', 'Offset from post', '</template>',
    ])->assertDontSee('Prepared comment votes')->assertDontSee('Save votes');

    Livewire::test(CreateStudioPlan::class)->call('goToStep', 'discussion')
        ->assertSee('Save the draft first to prepare its discussion.')
        ->assertDontSeeHtml('wire:name="admin.studio-plan-discussion-votes-editor"');
});

it('changes nothing of a plan past its Draft, or deleted', function () {
    $this->plan->forceFill(['status' => StudioPlanStatus::Approved])->save();

    discussionVotesEditor($this->plan)->assertSee('This plan is no longer a draft, so its comment votes can’t be changed here.')
        ->call('setVotes', $this->c2, 'up', 1)->call('redraw')->assertSet('preview', null)->assertSet('unsaved', false)
        ->dispatch(StudioPlanDiscussionVotesEditor::LEAVE_EVENT, step: 'next')->assertSet('leaving', null)->assertDispatched(StudioPlanDiscussionVotesEditor::STEP_EVENT, step: 'next');

    $this->plan->forceFill(['status' => StudioPlanStatus::Draft])->save();
    $editor = discussionVotesEditor($this->plan->fresh());
    app(DeleteStudioPlanDraftAction::class)->handle($this->admin, $this->plan, $this->plan->fresh()->lock_version);

    $editor->call('setVotes', $this->c2, 'up', 1)->assertSet('gone', true)
        ->assertDispatched('rg-admin-toast', message: 'This draft was deleted, so its votes can’t be changed any more.', tone: 'error');
});

it('lists every voter the votes drew on the tree\'s Voters tab, with what it votes and writes', function () {
    $editor = discussionVotesEditor($this->plan)->set('voterMode', 'manual')
        ->call('toggleVoter', $this->anna->id)->call('toggleVoter', $this->eli->id)->call('toggleVoter', $this->dana->id)
        ->call('setVotes', $this->c2, 'up', 2);

    $rows = collect($editor->viewData('voterTable')['rows'])->keyBy(fn (array $row): int => $row['persona']['id']);

    expect($rows->keys()->sort()->values()->all())->toBe(collect([$this->anna->id, $this->dana->id, $this->eli->id])->sort()->values()->all())
        ->and($rows[$this->anna->id]['writes'])->toBe(['C1'])
        ->and(collect($editor->set('votersFilter', 'writers')->viewData('voterTable')['rows'])->pluck('persona.id')->all())->toBe([$this->anna->id]);
});

it('lists every comment, reply and vote on them in one list on the tree\'s Timing tab, in the order they come, each with what it comes after', function () {
    $editor = setCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2);
    $timing = collect($editor->viewData('timing'));

    expect($timing->pluck('seconds')->all())->toBe($timing->pluck('seconds')->sort()->values()->all())
        ->and($timing->where('kind', '!=', 'vote')->pluck('action')->all())->toBe(['Comment C1', 'Reply C2', 'Comment C3'])
        ->and($timing->where('kind', 'vote')->pluck('parent')->countBy()->all())->toEqual(['C1' => 3, 'C3' => 1])
        ->and($timing->first())->toMatchArray(['kind' => 'comment', 'parent' => 'Post', 'seconds' => 300, 'after' => null, 'dependency' => 'After the post', 'votes' => [2, 1], 'window' => '+10 min → +6 h 10 min after the post · from 5 min after the comment', 'changed' => false])
        ->and($timing->firstWhere('kind', 'reply'))->toMatchArray(['parent' => 'C1', 'seconds' => 600, 'after' => '+5 min', 'dependency' => 'After C1 at +5 min'])
        ->and($timing->firstWhere('parent', 'C3'))->toMatchArray(['kind' => 'vote', 'action' => 'Vote up', 'seconds' => 1200, 'after' => '+5 min', 'dependency' => 'After C3 at +15 min', 'votes' => null, 'changed' => false]);

    $editor->assertSeeHtmlInOrder(['<template x-teleport="#rg-admin-studio-timing">', 'Offset from post', 'After its parent', 'Comment C1', 'Reply C2', 'Vote', '</template>'])
        ->assertDontSee('Not saved')
        ->assertSeeHtml('x-on:change="commit($event.target)"')
        ->assertSeeHtml("\$dispatch('studio-discussion-offset', { id: {$this->c1}, seconds })")
        ->assertViewHas('moving', true)
        // No comment moves while the discussion editor says a generation is on its way.
        ->assertSeeHtml('x-bind:disabled="false || $store.rgCommentVotes?.generating"');
});

it('sets a vote\'s own moment on the Timing tab — never before its comment, nor past thirty days — saved at once and kept through a reload, and spreads the votes again on a new timing', function () {
    setCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2);
    $votes = collect(savedCommentVotes($this->plan))->where(2, $this->c1)->values();
    $middle = $votes[1][0];

    $editor = discussionVotesEditor($this->plan)->call('setMoment', $this->c1, $middle, 9000)
        ->assertSet("targets.{$this->c1}.moments", [$middle => 8700])->assertSet('unsaved', false)->assertSet("summary.{$this->c1}.changed", false);

    expect(collect(savedCommentVotes($this->plan))->where(2, $this->c1)->map(fn (array $vote): array => [$vote[0], $vote[3]])->values()->all())
        ->toBe([[$votes[0][0], $votes[0][3]], [$middle, 9000], [$votes[2][0], $votes[2][3]]]);

    $editor->call('setMoment', $this->c1, $middle, 299)
        ->assertDispatched('rg-admin-toast', message: 'No vote can come before the comment it is on: C1 comes at +5 min.', tone: 'error')
        ->call('setMoment', $this->c1, $middle, 2_592_001)
        ->assertDispatched('rg-admin-toast', message: 'A vote comes at most 30 days after the post.', tone: 'error')
        // Anna writes C1, so has no vote on it to move.
        ->call('setMoment', $this->c1, $this->anna->id, 4000)
        ->assertSet("targets.{$this->c1}.moments", [$middle => 8700]);

    expect(collect(savedCommentVotes($this->plan))->where(2, $this->c1)->firstWhere(0, $middle)[3])->toBe(9000);

    // Opened again, the vote keeps its moment, through other comments' saves too.
    $again = discussionVotesEditor($this->plan)->assertSet("targets.{$this->c1}.moments", [$middle => 8700])->assertSet('unsaved', false)
        ->call('setVotes', $this->c2, 'up', 2)->call('setVotes', $this->c2, 'up', 1)->assertSet('unsaved', false);

    // A new timing spreads them all again.
    $again->call('setTiming', 'spread', 3600, $this->c1)->assertSet("targets.{$this->c1}.moments", []);

    expect(collect(savedCommentVotes($this->plan))->where(2, $this->c1)->pluck(3)->all())->toBe([600, 2400, 4200]);
});

it('follows a comment moved on the Timing tab with its saved votes, no conflict, and saves a change after it from the comment\'s new moment', function () {
    setCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2);
    $editor = discussionVotesEditor($this->plan);
    $before = collect(savedCommentVotes($this->plan))->where(2, $this->c2)->pluck(3)->all();

    discussionEditor($this->plan)->dispatch(StudioPlanDiscussionEditor::OFFSET_EVENT, id: $this->c2, seconds: 4500)->assertHasNoErrors()
        ->assertDispatched(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 6, current: 7, source: 'discussion');

    expect(collect(savedCommentVotes($this->plan))->where(2, $this->c2)->pluck(3)->all())->toBe(array_map(fn (int $offset): int => $offset + 3600, $before));

    $editor->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 6, current: 7, source: 'discussion')
        ->assertSet('conflict', false)->assertSet('lockVersion', 7)->assertSet('unsaved', false)->assertSet("targets.{$this->c2}.first", 300)
        ->assertNotDispatched(StudioPlanDiscussionVotesEditor::SAVE_EVENT)
        // Two votes on C3: of the three Personas voting on C1, Carl, who writes C3, is one at most.
        ->call('setVotes', $this->c2, 'down', 1)->assertHasNoErrors()->assertSet('lockVersion', 8);

    expect(min(collect(savedCommentVotes($this->plan))->where(2, $this->c2)->pluck(3)->all()))->toBe(4800);

    // A comment moved past its reply is not moved, nor are its votes.
    discussionEditor($this->plan)->dispatch(StudioPlanDiscussionEditor::OFFSET_EVENT, id: $this->c1, seconds: 3900)
        ->assertDispatched('rg-admin-toast', message: 'A reply cannot come before the comment it answers.')
        ->assertNotDispatched(StudioPlanDiscussionEditor::VERSION_EVENT);
});

it('opens a comment\'s breakdown beside the tree — its votes, priority and timing, and who casts each, when — and closes it', function () {
    $editor = setCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2)
        ->dispatch(StudioPlanDiscussionVotesEditor::SELECT_EVENT, node: $this->c1)->assertSet('selected', $this->c1)
        ->assertSeeHtmlInOrder(['<template x-teleport="#rg-admin-studio-comment-breakdown">', 'Comment votes · C1', 'Lovely light on the water.', 'Appears +5 min after the post', 'aria-label="More up votes on C1"', 'Priority', 'First vote after', 'Who votes', 'after the post', 'Every change is saved at once.', '</template>'])
        ->assertDontSee('Save votes');
    $breakdown = $editor->viewData('breakdown');

    expect($breakdown['label'])->toBe('C1')
        ->and([$breakdown['up'], $breakdown['down'], $breakdown['priority'], $breakdown['changed']])->toBe([2, 1, 'normal', false])
        ->and(collect($breakdown['votes'])->pluck('vote')->sort()->values()->all())->toBe(['down', 'up', 'up'])
        ->and(collect($breakdown['votes'])->pluck('persona.id'))->not->toContain($this->anna->id);

    $editor->call('setPriority', $this->c1, 'high')->assertSet("targets.{$this->c1}.priority", 'high')
        ->dispatch(StudioPlanDiscussionVotesEditor::SELECT_EVENT, node: $this->c2)->assertSet('selected', $this->c2)->assertSee('Comment votes · C3')
        ->dispatch(StudioPlanDiscussionVotesEditor::SELECT_EVENT, node: $this->c2)->assertSet('selected', null)->assertDontSee('Comment votes · C3')
        ->dispatch(StudioPlanDiscussionVotesEditor::SELECT_EVENT, node: 999_999)->assertSet('selected', null)
        ->dispatch(StudioPlanDiscussionVotesEditor::SELECT_EVENT, node: $this->r1)->call('closeBreakdown')->assertSet('selected', null)->assertViewHas('breakdown', null);
});

it('goes on from the step at once with every change saved, and asks first with one that can\'t be: kept to fix, or discarded and on', function () {
    // Nothing changed, or every change saved: on at once.
    discussionVotesEditor($this->plan)->dispatch(StudioPlanDiscussionVotesEditor::LEAVE_EVENT, step: 'next')
        ->assertSet('leaving', null)->assertDispatched(StudioPlanDiscussionVotesEditor::STEP_EVENT, step: 'next');
    setCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2)->dispatch(StudioPlanDiscussionVotesEditor::LEAVE_EVENT, step: 'next')
        ->assertSet('leaving', null)->assertDispatched(StudioPlanDiscussionVotesEditor::STEP_EVENT, step: 'next');

    // Six votes on C3, whose own Persona never votes on it, are more than the five others there are.
    $editor = discussionVotesEditor($this->plan)->call('setVotes', $this->c2, 'up', 6)->assertHasErrors(["targets.{$this->c2}"])->assertSet('unsaved', true)
        ->dispatch(StudioPlanDiscussionVotesEditor::LEAVE_EVENT, step: 'elsewhere')->assertSet('leaving', null)->assertNotDispatched(StudioPlanDiscussionVotesEditor::STEP_EVENT)
        ->dispatch(StudioPlanDiscussionVotesEditor::LEAVE_EVENT, step: 'publication')->assertSet('leaving', 'publication')->assertNotDispatched(StudioPlanDiscussionVotesEditor::STEP_EVENT)
        ->assertSee('Leave the comment votes as saved?')->assertSee('it can’t be made until what is marked is fixed')
        ->assertViewHas('changes', fn (array $changes): bool => collect($changes)->contains(fn (array $change): bool => $change['label'] === 'C3' && $change['problem']))
        ->assertSeeHtmlInOrder(['Leave the comment votes as saved?', 'C3</span> · ', 'Keep editing', 'Discard change'])
        ->assertDontSee('Save and continue')
        ->call('stay')->assertSet('leaving', null)->assertDontSee('Leave the comment votes as saved?');

    // Fixed: saved at once, and the step left at once.
    $editor->call('setVotes', $this->c2, 'up', 2)->assertHasNoErrors()->assertSet('unsaved', false)
        ->dispatch(StudioPlanDiscussionVotesEditor::LEAVE_EVENT, step: 'next')->assertSet('leaving', null)->assertDispatched(StudioPlanDiscussionVotesEditor::STEP_EVENT, step: 'next');

    // Or discarded: the votes as saved, and on.
    $editor->call('setVotes', $this->c2, 'up', 6)->dispatch(StudioPlanDiscussionVotesEditor::LEAVE_EVENT, step: 'previous')->assertSet('leaving', 'previous')
        ->call('discardAndLeave')->assertDispatched(StudioPlanDiscussionVotesEditor::STEP_EVENT, step: 'previous')
        ->assertSet("targets.{$this->c2}.up", 2)->assertSet('unsaved', false);

    expect(collect(savedCommentVotes($this->plan))->where(2, $this->c2))->toHaveCount(2);
});

it('asks before the browser leaves with comment votes not saved, unless the page is leaving with its draft deleted', function () {
    discussionVotesEditor($this->plan)->assertSeeHtml('x-on:beforeunload.window="if ($wire.unsaved && ! $store.adminEditor?.leaving)');
});

it('goes on to the step asked once the comment votes are saved or discarded, and asks the votes editor first from Back, Continue and the steps', function () {
    Livewire::test(EditStudioPlan::class, ['record' => $this->plan->id])->call('goToStep', 'discussion')
        ->assertSeeHtml('$wire.step === &#039;discussion&#039; &amp;&amp; $store.rgCommentVotes?.unsaved ? $dispatch(&#039;studio-comment-votes-leave&#039;, { step: &#039;next&#039; }) : (')
        ->assertSeeHtml('$dispatch(&#039;studio-comment-votes-leave&#039;, { step: &#039;previous&#039; }) : (')
        ->assertSeeHtml('$dispatch(&#039;studio-comment-votes-leave&#039;, { step: &#039;image&#039; }) : (')
        ->assertSeeHtml(': $wire.goToStep(&#039;image&#039;))')
        ->dispatch(StudioPlanDiscussionVotesEditor::STEP_EVENT, step: 'next')->assertSet('step', 'publication')
        ->call('goToStep', 'discussion')->dispatch(StudioPlanDiscussionVotesEditor::STEP_EVENT, step: 'previous')->assertSet('step', 'post')->assertSet('postTab', 'votes')
        ->dispatch(StudioPlanDiscussionVotesEditor::STEP_EVENT, step: 'image')->assertSet('step', 'image')
        ->dispatch(StudioPlanDiscussionVotesEditor::STEP_EVENT, step: 'nowhere')->assertSet('step', 'image');
});

it('casts nothing: no vote, action, job or notification', function () {
    Bus::fake();
    Notification::fake();

    setCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2);

    expect(PostVote::query()->exists())->toBeFalse()
        ->and(CommentVote::query()->exists())->toBeFalse()
        ->and(RatingVote::query()->exists())->toBeFalse()
        ->and(StudioAction::query()->exists())->toBeFalse();
    Queue::assertNothingPushed();
    Bus::assertNothingDispatched();
    Notification::assertNothingSent();
});
