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
 * when after each comment; made as soon as they are asked for, shown on the
 * tree, in each comment's breakdown and in time on the Timing tab, and saved
 * exactly as shown, the votes on the post and its questions kept, every write
 * sharing the plan's one version with the other editors on the page, and the
 * step not left with them unsaved without asking.
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

/** The votes the editor made of its settings, as savedCommentVotes() lists votes. */
function previewedCommentVotes(Testable $editor): array
{
    return array_map(fn (array $vote): array => [$vote['personaUserId'], $vote['voteType'], $vote['discussionNodeId'], $vote['offsetSeconds'], $vote['sortOrder']], $editor->get('preview') ?? []);
}

/** Two up votes and one down on C1, and one up on C2, by four Personas drawn at random: made at once, not saved. */
function previewCommentVotes(Testable $editor, int $c1, int $c2): Testable
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

    $editor->call('save')->assertForbidden();
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

it('asks for comments first when the discussion has none, and has nothing to save', function () {
    $plan = StudioPlan::factory()->create(['created_by_user_id' => $this->admin->id]);

    discussionVotesEditor($plan)->assertSee('No comments to vote on yet')->assertSee('Comment voters')
        ->call('redraw')->assertHasNoErrors()->assertSet('preview', [])->assertSet('unsaved', false);
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

    expect(savedCommentVotes($this->plan))->toBe([])
        ->and($editor->get('unsaved'))->toBeTrue();
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

it('makes the votes as soon as they are asked for, shows them on the tree as not saved, then saves exactly them — never a vote on a comment by its own Persona — keeping the votes on the post', function () {
    $editor = previewCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2);
    $preview = previewedCommentVotes($editor);

    expect($preview)->toHaveCount(4)
        ->and(collect($preview)->where(2, $this->c1)->pluck(0)->all())->not->toContain($this->anna->id)
        ->and(collect($preview)->where(2, $this->c2)->pluck(0)->all())->not->toContain($this->carl->id)
        ->and(collect($preview)->where(2, $this->c1)->pluck(0)->unique())->toHaveCount(3)
        ->and(savedCommentVotes($this->plan))->toBe([]);

    $editor->assertSet('status', '4 comment votes (+3 / −1)')->assertSet('unsaved', true)
        ->assertSet("summary.{$this->c1}.up", 2)->assertSet("summary.{$this->c1}.down", 1)->assertSet("summary.{$this->c1}.changed", true)
        ->assertSet("summary.{$this->r1}.changed", false)
        ->call('save')->assertHasNoErrors()
        ->assertDispatched('rg-admin-toast', message: 'Comment votes saved', tone: 'success')
        ->assertDispatched(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 3, current: 4, source: StudioPlanDiscussionVotesEditor::SOURCE)
        ->assertSet('preview', null)->assertSet('unsaved', false)->assertSet("summary.{$this->c1}.changed", false)->assertSet("summary.{$this->c1}.up", 2)
        ->assertSet('status', '4 comment votes (+3 / −1)');

    expect(savedCommentVotes($this->plan))->toBe($preview)
        ->and(StudioPlanVote::query()->whereKey($this->postVote)->exists())->toBeTrue()
        ->and($this->plan->fresh()->lock_version)->toBe(4);
});

it('dates each vote from its comment, for every comment or for the one changed in its breakdown', function () {
    $editor = discussionVotesEditor($this->plan)->call('setVotes', $this->c2, 'up', 3)->call('setTiming', 'first', 1800)->call('setTiming', 'spread', 3600);

    expect(array_column(previewedCommentVotes($editor), 3))->toBe([2700, 4500, 6300]);

    $editor->call('setVotes', $this->c1, 'up', 1)->call('setTiming', 'first', 3600, $this->c2)
        ->assertSet("targets.{$this->c2}.first", 3600)->assertSet("targets.{$this->c1}.first", 1800)->assertSet("targets.{$this->r1}.first", 1800)
        ->call('setTiming', 'first', 3600, 999_999)->call('setTiming', 'first', 17, $this->c2)->assertSet("targets.{$this->c2}.first", 3600);

    expect(collect(previewedCommentVotes($editor))->map(fn (array $vote): array => [$vote[2], $vote[3]])->all())
        ->toBe([[$this->c1, 2100], [$this->c2, 4500], [$this->c2, 6300], [$this->c2, 8100]]);
});

it('draws the votes again from what is saved when opened again', function () {
    previewCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2)->call('save');
    $voters = collect(savedCommentVotes($this->plan))->pluck(0)->unique()->values()->all();

    discussionVotesEditor($this->plan)
        ->assertSet("targets.{$this->c1}.up", 2)->assertSet("targets.{$this->c1}.down", 1)->assertSet("targets.{$this->c2}.up", 1)
        ->assertSet("targets.{$this->r1}.up", 0)->assertSet("targets.{$this->c1}.priority", 'normal')
        ->assertSet("targets.{$this->c1}.first", 300)
        ->assertSet('voterMode', 'manual')->assertSet('voterIds', $voters)
        ->assertSet('totalUp', 3)->assertSet('totalDown', 1)
        ->assertSet('status', '4 comment votes (+3 / −1)');
});

it('makes the votes again whenever the settings or the comments change, and saves the ones made last', function () {
    $editor = previewCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2)->call('setVotes', $this->c2, 'down', 1);

    expect(previewedCommentVotes($editor))->toHaveCount(5);

    // The comments moved on the page's Discussion editor: the votes on them are made again.
    $other = previewCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2);
    $discussion = discussionEditor($this->plan)->call('setOffset', $this->c2, 1200)->assertHasNoErrors();
    $discussion->assertDispatched(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 3, current: 4, source: 'discussion');

    $other->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 3, current: 4, source: 'discussion')
        ->assertSet('lockVersion', 4)->assertSet('conflict', false);

    expect(collect(previewedCommentVotes($other))->where(2, $this->c2)->pluck(3)->all())->toBe([1500]);

    $other->call('save')->assertHasNoErrors();

    expect(collect(savedCommentVotes($this->plan))->where(2, $this->c2)->pluck(3)->all())->toBe([1500]);
});

it('says on the comment how many voters it is short, and makes no votes to save', function () {
    $short = 'Its 2 votes need 2 personas who can vote on it; 1 can — 1 short. Choose more personas, or ask for fewer.';

    discussionVotesEditor($this->plan)->set('voterMode', 'manual')->call('toggleVoter', $this->anna->id)->call('toggleVoter', $this->dana->id)
        ->call('setVotes', $this->c1, 'up', 2)
        ->assertHasErrors(["targets.{$this->c1}" => $short])
        ->assertSet('preview', null)->assertSet("targets.{$this->c1}.up", 2)
        ->assertSet("summary.{$this->c1}.problem", $short)->assertSet("summary.{$this->c2}.problem", null)->assertSet('unsaved', true)
        ->assertSet('status', 'comment votes: fix what is marked on C1')
        ->call('save')->assertDispatched('rg-admin-toast', message: 'These votes can’t be saved yet: fix what is marked on the comments first.', tone: 'error');

    expect(savedCommentVotes($this->plan))->toBe([]);
});

it('keeps each vote\'s id when a save changes only its moment', function () {
    previewCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2)->call('save');
    $ids = StudioPlanVote::query()->where('type', StudioActionType::VoteComment)->orderBy('id')->pluck('id')->all();

    discussionVotesEditor($this->plan)->call('setTiming', 'first', 3600)->call('save')->assertHasNoErrors();

    expect(StudioPlanVote::query()->where('type', StudioActionType::VoteComment)->orderBy('id')->pluck('id')->all())->toBe($ids);
});

it('saves nothing for votes that are the saved ones again, and once for Save pressed twice', function () {
    $editor = previewCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2)->call('save');
    $editor->call('save')->assertDispatched('rg-admin-toast', message: 'Nothing to save: these are the votes saved.', tone: 'info');

    // Changed and changed back: the saved votes again, nothing to save.
    discussionVotesEditor($this->plan)->call('setVotes', $this->c2, 'up', 2)->assertSet('unsaved', true)
        ->call('setVotes', $this->c2, 'up', 1)->assertSet('unsaved', false)->assertSet('preview', null)
        ->call('save')->assertDispatched('rg-admin-toast', message: 'Nothing to save: these are the votes saved.', tone: 'info');

    expect(StudioPlanVote::query()->where('type', StudioActionType::VoteComment)->count())->toBe(4)
        ->and($this->plan->fresh()->lock_version)->toBe(4);
});

it('keeps the saved votes of a comment whose count is not changed, draws anew on Pick others, and draws the saved votes again on Discard', function () {
    previewCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2)->call('save');
    $saved = savedCommentVotes($this->plan);
    $onC1 = fn (array $votes): array => collect($votes)->where(2, $this->c1)->map(fn (array $vote): array => array_slice($vote, 0, 4))->values()->all();

    $editor = discussionVotesEditor($this->plan)->call('setVotes', $this->c2, 'down', 1)
        ->assertSet("summary.{$this->c1}.changed", false)->assertSet("summary.{$this->c2}.changed", true);
    $seed = $editor->get('seed');

    expect($onC1(previewedCommentVotes($editor)))->toBe($onC1($saved));

    $editor->call('setTiming', 'spread', 3600, $this->c2)->assertSet('seed', $seed);

    expect($onC1(previewedCommentVotes($editor)))->toBe($onC1($saved));

    $editor->call('setVotes', $this->c2, 'down', 0)->call('setTiming', 'spread', 21600, $this->c2)->call('redraw')->assertHasNoErrors()->assertSet('keepSaved', false);

    expect($editor->get('seed'))->not->toBe($seed)
        ->and(previewedCommentVotes($editor))->toHaveCount(4);

    $editor->call('setVotes', $this->c2, 'down', 1)->call('discard')
        ->assertSet('preview', null)->assertSet('keepSaved', true)->assertSet("targets.{$this->c2}.down", 0)
        ->assertSet('totalDown', 1)->assertSet('status', '4 comment votes (+3 / −1)');

    expect(savedCommentVotes($this->plan))->toBe($saved);
});

it('removes the comment votes once both totals are set to none and saved, the votes on the post staying', function () {
    previewCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2)->call('save');

    discussionVotesEditor($this->plan)->set('totalUp', 0)->set('totalDown', 0)
        ->assertSet('status', 'no comment votes')->assertSet('unsaved', true)->assertSet("summary.{$this->c1}.changed", true)->assertSet('preview', [])
        ->call('save')->assertDispatched('rg-admin-toast', message: 'Comment votes saved', tone: 'success')->assertSet('unsaved', false);

    expect(savedCommentVotes($this->plan))->toBe([])
        ->and(StudioPlanVote::query()->pluck('id')->all())->toBe([$this->postVote]);
});

it('keeps a Persona restricted since in the saved votes, saying so, and gives a deleted one no new vote', function () {
    discussionVotesEditor($this->plan)->set('voterMode', 'manual')->call('toggleVoter', $this->eli->id)->call('toggleVoter', $this->dana->id)
        ->call('setVotes', $this->c2, 'up', 2)->call('save')->assertHasNoErrors();
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
    $editor->call('setVotes', $this->c2, 'up', 1)->assertHasNoErrors();

    expect(array_column(previewedCommentVotes($editor), 0))->toBe([$this->eli->id]);
});

it('takes no version somebody else wrote: nothing is saved until Reload', function () {
    $editor = previewCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2);
    saveStudioVotes(User::factory()->admin()->create(), $this->plan, [
        studioVote(['type' => 'vote_post', 'personaUserId' => $this->dana->id, 'voteType' => 'up', 'offsetSeconds' => 60, 'id' => $this->postVote]),
        studioVote(['type' => 'vote_comment', 'personaUserId' => $this->eli->id, 'voteType' => 'down', 'discussionNodeId' => $this->c2, 'offsetSeconds' => 1000]),
    ]);

    $editor->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 3, current: 4, source: 'post_votes')
        ->assertSet('conflict', true)->assertSee('Someone changed this draft after you opened it')
        ->call('save');

    expect(savedCommentVotes($this->plan))->toBe([[$this->eli->id, 'down', $this->c2, 1000, 0]]);

    $editor->call('reload')->assertSet('conflict', false)->assertSet('lockVersion', 4)->assertSet("targets.{$this->c2}.down", 1)
        ->assertDispatched(StudioPlanDiscussionEditor::RELOAD_EVENT, planId: $this->plan->id, current: 4, source: StudioPlanDiscussionVotesEditor::SOURCE);
});

it('refuses a save drawn from a version somebody else replaced', function () {
    $editor = previewCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2);
    saveStudioDiscussion(User::factory()->admin()->create(), $this->plan, array_map(fn ($node) => $node, StudioDiscussionData::of($this->plan->fresh())->nodes));

    $editor->call('save')->assertSet('conflict', true)
        ->assertDispatched('rg-admin-toast', message: 'Someone changed this draft meanwhile, so nothing was saved. Reload to see their changes.', tone: 'error');

    expect(savedCommentVotes($this->plan))->toBe([]);
});

it('takes the whole save back when a write of it is not saved, the votes and the version as they were', function () {
    $editor = previewCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2);
    StudioPlanVote::creating(fn (): bool => false);

    expect(fn () => $editor->call('save'))->toThrow(RuntimeException::class);

    expect(savedCommentVotes($this->plan))->toBe([])
        ->and(StudioPlanVote::query()->pluck('id')->all())->toBe([$this->postVote])
        ->and($this->plan->fresh()->lock_version)->toBe(3);
});

it('says which vote on the post stands in the way of a save, and changes nothing of it', function () {
    // The post's voter made its author since: its vote on the post is no vote any more.
    $this->plan->forceFill(['author_user_id' => $this->dana->id])->save();
    $editor = discussionVotesEditor($this->plan)->call('setVotes', $this->c2, 'up', 1);

    $editor->call('save')->assertDispatched('rg-admin-toast', message: 'A vote on the post or its questions saved earlier stands in the way: The post’s own author cannot vote on it. Choose another persona. Fix it on Post · Votes.', tone: 'error');

    expect(savedCommentVotes($this->plan))->toBe([])->and(StudioPlanVote::query()->pluck('id')->all())->toBe([$this->postVote]);
});

it('takes a deleted comment\'s votes away with it, never putting them back from a preview made before', function () {
    $editor = previewCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2)->call('save');
    $editor = previewCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2)
        ->dispatch(StudioPlanDiscussionVotesEditor::SELECT_EVENT, node: $this->c1)->assertSet('selected', $this->c1);

    // The whole branch of C1 deleted on the page's Discussion editor.
    discussionEditor($this->plan)->call('askDelete', $this->c1)->call('confirmDelete')
        ->assertDispatched(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 4, current: 5, source: 'discussion');

    expect(collect(savedCommentVotes($this->plan))->pluck(2)->unique()->values()->all())->toBe([$this->c2]);

    $editor->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 4, current: 5, source: 'discussion')
        ->assertSet('lockVersion', 5)->assertSet('conflict', false)->assertSet('targets', fn (array $targets): bool => array_keys($targets) === [$this->c2])
        ->assertSet('selected', null)->assertSet('summary', fn (array $summary): bool => array_keys($summary) === [$this->c2])
        ->call('save')->assertHasNoErrors();

    expect(collect(savedCommentVotes($this->plan))->pluck(2)->unique()->values()->all())->toBe([$this->c2])
        ->and(StudioPlanVote::query()->whereKey($this->postVote)->exists())->toBeTrue();
});

it('gives a comment Undo brings back none of the votes it had: it is a new comment', function () {
    previewCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2)->call('save');
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

    previewCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2)->call('save');

    foreach ([$page, $discussion, $postVotes] as $editor) {
        $editor->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 3, current: 4, source: StudioPlanDiscussionVotesEditor::SOURCE)
            ->assertSet('lockVersion', 4);
    }

    $page->assertSet('stale', false)->assertSet('postTitle', 'Typed, not saved')->call('save')->assertDispatched('rg-admin-toast', message: 'Draft saved')
        ->assertDispatched(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 4, current: 5, source: 'editor');
    $postVotes->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 4, current: 5, source: 'editor')->assertSet('conflict', false)->set('postOn', true)->set('up', 1)->set('voterCount', 3)->call('build', false)->call('save', false)->assertHasNoErrors();

    expect($this->plan->fresh()->lock_version)->toBe(6)
        ->and(savedCommentVotes($this->plan))->toHaveCount(4)
        ->and(StudioPlanVote::query()->where('type', StudioActionType::VotePost)->count())->toBe(1);
});

it('takes the version a Save draft or a save of the post votes moved, so its own next save is no conflict', function () {
    $editor = discussionVotesEditor($this->plan);

    Livewire::test(EditStudioPlan::class, ['record' => $this->plan->id])->set('postTitle', 'A harbour at dawn')->call('save');
    $editor->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 3, current: 4, source: 'editor')->assertSet('lockVersion', 4);

    postVotesEditor($this->plan)->set('voterMode', 'random')->set('voterCount', 2)->set('postOn', true)->set('up', 2)->call('build', false)->call('save', false)->assertHasNoErrors();
    $editor->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 4, current: 5, source: StudioPlanPostVotesEditor::SOURCE)->assertSet('lockVersion', 5);

    previewCommentVotes($editor, $this->c1, $this->c2)->call('save')->assertHasNoErrors()->assertSet('lockVersion', 6);

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
    $editor = previewCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2);
    $timing = collect($editor->viewData('timing'));

    expect($timing->pluck('seconds')->all())->toBe($timing->pluck('seconds')->sort()->values()->all())
        ->and($timing->where('kind', '!=', 'vote')->pluck('action')->all())->toBe(['Comment C1', 'Reply C2', 'Comment C3'])
        ->and($timing->where('kind', 'vote')->pluck('parent')->countBy()->all())->toEqual(['C1' => 3, 'C3' => 1])
        ->and($timing->first())->toMatchArray(['kind' => 'comment', 'parent' => 'Post', 'seconds' => 300, 'after' => null, 'dependency' => 'After the post', 'votes' => [2, 1], 'window' => '+10 min → +6 h 10 min after the post · from 5 min after the comment', 'changed' => true])
        ->and($timing->firstWhere('kind', 'reply'))->toMatchArray(['parent' => 'C1', 'seconds' => 600, 'after' => '+5 min', 'dependency' => 'After C1 at +5 min'])
        ->and($timing->firstWhere('parent', 'C3'))->toMatchArray(['kind' => 'vote', 'action' => 'Vote up', 'seconds' => 1200, 'after' => '+5 min', 'dependency' => 'After C3 at +15 min', 'votes' => null, 'changed' => true]);

    $editor->assertSeeHtmlInOrder(['<template x-teleport="#rg-admin-studio-timing">', 'Offset from post', 'After its parent', 'Comment C1', 'Reply C2', 'Vote', 'The votes changed here are not saved yet', 'Save votes', '</template>'])
        ->assertSeeHtml('x-on:change="commit($event.target)"')
        ->assertSeeHtml("\$dispatch('studio-discussion-offset', { id: {$this->c1}, seconds })")
        ->assertViewHas('moving', true)
        // No comment moves while the discussion editor says a generation is on its way.
        ->assertSeeHtml('x-bind:disabled="false || $store.rgCommentVotes?.generating"');
});

it('sets a vote\'s own moment on the Timing tab — never before its comment, nor past thirty days — keeps it through a save and a reload, and spreads the votes again on a new timing', function () {
    $editor = previewCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2)->call('save');
    $votes = collect(savedCommentVotes($this->plan))->where(2, $this->c1)->values();
    $middle = $votes[1][0];

    $editor = discussionVotesEditor($this->plan)->call('setMoment', $this->c1, $middle, 9000)
        ->assertSet("targets.{$this->c1}.moments", [$middle => 8700])->assertSet('unsaved', true)->assertSet("summary.{$this->c1}.changed", true);

    expect(collect(previewedCommentVotes($editor))->where(2, $this->c1)->map(fn (array $vote): array => [$vote[0], $vote[3]])->values()->all())
        ->toBe([[$votes[0][0], $votes[0][3]], [$middle, 9000], [$votes[2][0], $votes[2][3]]]);

    $editor->call('setMoment', $this->c1, $middle, 299)
        ->assertDispatched('rg-admin-toast', message: 'No vote can come before the comment it is on: C1 comes at +5 min.', tone: 'error')
        ->call('setMoment', $this->c1, $middle, 2_592_001)
        ->assertDispatched('rg-admin-toast', message: 'A vote comes at most 30 days after the post.', tone: 'error')
        // Anna writes C1, so has no vote on it to move.
        ->call('setMoment', $this->c1, $this->anna->id, 4000)
        ->assertSet("targets.{$this->c1}.moments", [$middle => 8700])
        ->call('save')->assertHasNoErrors();

    expect(collect(savedCommentVotes($this->plan))->where(2, $this->c1)->firstWhere(0, $middle)[3])->toBe(9000);

    // Opened again, the vote keeps its moment: nothing to save.
    $again = discussionVotesEditor($this->plan)->assertSet("targets.{$this->c1}.moments", [$middle => 8700])->assertSet('unsaved', false)
        ->call('setVotes', $this->c2, 'up', 2)->call('setVotes', $this->c2, 'up', 1)->assertSet('unsaved', false);

    // A new timing spreads them all again.
    $again->call('setTiming', 'spread', 3600, $this->c1)->assertSet("targets.{$this->c1}.moments", []);

    expect(collect(previewedCommentVotes($again))->where(2, $this->c1)->pluck(3)->all())->toBe([600, 2400, 4200]);
});

it('follows a comment moved on the Timing tab with its saved votes, no conflict, and moves the votes not saved yet with it', function () {
    previewCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2)->call('save');
    $editor = discussionVotesEditor($this->plan);
    // Two votes on C3: of the three Personas voting on C1, Carl, who writes C3, is one at most.
    $draft = discussionVotesEditor($this->plan)->call('setVotes', $this->c2, 'down', 1)->assertHasNoErrors();
    $before = collect(savedCommentVotes($this->plan))->where(2, $this->c2)->pluck(3)->all();

    discussionEditor($this->plan)->dispatch(StudioPlanDiscussionEditor::OFFSET_EVENT, id: $this->c2, seconds: 4500)->assertHasNoErrors()
        ->assertDispatched(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 4, current: 5, source: 'discussion');

    expect(collect(savedCommentVotes($this->plan))->where(2, $this->c2)->pluck(3)->all())->toBe(array_map(fn (int $offset): int => $offset + 3600, $before));

    foreach ([$editor, $draft] as $votes) {
        $votes->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 4, current: 5, source: 'discussion')
            ->assertSet('conflict', false)->assertSet('lockVersion', 5);
    }

    $editor->assertSet('unsaved', false)->assertSet("targets.{$this->c2}.first", 300);
    $draft->assertSet('unsaved', true)->assertSet("targets.{$this->c2}.down", 1);

    expect(min(collect(previewedCommentVotes($draft))->where(2, $this->c2)->pluck(3)->all()))->toBe(4800);

    // A comment moved past its reply is not moved, nor are its votes.
    discussionEditor($this->plan)->dispatch(StudioPlanDiscussionEditor::OFFSET_EVENT, id: $this->c1, seconds: 3900)
        ->assertDispatched('rg-admin-toast', message: 'A reply cannot come before the comment it answers.')
        ->assertNotDispatched(StudioPlanDiscussionEditor::VERSION_EVENT);
});

it('opens a comment\'s breakdown beside the tree — its votes, priority and timing, and who casts each, when — and closes it', function () {
    $editor = previewCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2)
        ->dispatch(StudioPlanDiscussionVotesEditor::SELECT_EVENT, node: $this->c1)->assertSet('selected', $this->c1)
        ->assertSeeHtmlInOrder(['<template x-teleport="#rg-admin-studio-comment-breakdown">', 'Comment votes · C1', 'Lovely light on the water.', 'Appears +5 min after the post', 'aria-label="More up votes on C1"', 'Priority', 'First vote after', 'Who votes', 'Not saved', 'after the post', '</template>']);
    $breakdown = $editor->viewData('breakdown');

    expect($breakdown['label'])->toBe('C1')
        ->and([$breakdown['up'], $breakdown['down'], $breakdown['priority'], $breakdown['changed']])->toBe([2, 1, 'normal', true])
        ->and(collect($breakdown['votes'])->pluck('vote')->sort()->values()->all())->toBe(['down', 'up', 'up'])
        ->and(collect($breakdown['votes'])->pluck('persona.id'))->not->toContain($this->anna->id);

    $editor->call('setPriority', $this->c1, 'high')->assertSet("targets.{$this->c1}.priority", 'high')
        ->dispatch(StudioPlanDiscussionVotesEditor::SELECT_EVENT, node: $this->c2)->assertSet('selected', $this->c2)->assertSee('Comment votes · C3')
        ->dispatch(StudioPlanDiscussionVotesEditor::SELECT_EVENT, node: $this->c2)->assertSet('selected', null)->assertDontSee('Comment votes · C3')
        ->dispatch(StudioPlanDiscussionVotesEditor::SELECT_EVENT, node: 999_999)->assertSet('selected', null)
        ->dispatch(StudioPlanDiscussionVotesEditor::SELECT_EVENT, node: $this->r1)->call('closeBreakdown')->assertSet('selected', null)->assertViewHas('breakdown', null);
});

it('asks before the step is left with its votes not saved: saves them and goes on, discards them and goes on, or stays', function () {
    // Nothing unsaved: on at once.
    discussionVotesEditor($this->plan)->dispatch(StudioPlanDiscussionVotesEditor::LEAVE_EVENT, step: 'next')
        ->assertSet('leaving', null)->assertDispatched(StudioPlanDiscussionVotesEditor::STEP_EVENT, step: 'next');

    $editor = previewCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2)
        ->dispatch(StudioPlanDiscussionVotesEditor::LEAVE_EVENT, step: 'elsewhere')->assertSet('leaving', null)->assertNotDispatched(StudioPlanDiscussionVotesEditor::STEP_EVENT)
        ->dispatch(StudioPlanDiscussionVotesEditor::LEAVE_EVENT, step: 'publication')->assertSet('leaving', 'publication')->assertNotDispatched(StudioPlanDiscussionVotesEditor::STEP_EVENT)
        ->assertSee('Save the comment votes?')
        ->assertViewHas('changes', [
            ['label' => 'C1', 'text' => '+2 / −1 · saved +0 / −0', 'problem' => false],
            ['label' => 'C3', 'text' => '+1 / −0 · saved +0 / −0', 'problem' => false],
        ])
        ->assertSeeHtmlInOrder(['Save the comment votes?', 'C1</span> · +2 / −1 · saved +0 / −0', 'C3</span> · +1 / −0 · saved +0 / −0', 'Keep editing', 'Discard changes', 'Save and continue'])
        ->call('stay')->assertSet('leaving', null)->assertDontSee('Save the comment votes?');

    $editor->dispatch(StudioPlanDiscussionVotesEditor::LEAVE_EVENT, step: 'next')->call('saveAndLeave')
        ->assertDispatched('rg-admin-toast', message: 'Comment votes saved', tone: 'success')
        ->assertDispatched(StudioPlanDiscussionVotesEditor::STEP_EVENT, step: 'next')->assertSet('leaving', null)->assertSet('unsaved', false);

    expect(savedCommentVotes($this->plan))->toHaveCount(4);

    discussionVotesEditor($this->plan)->call('setVotes', $this->c2, 'down', 1)
        ->dispatch(StudioPlanDiscussionVotesEditor::LEAVE_EVENT, step: 'previous')->assertSet('leaving', 'previous')
        ->call('discardAndLeave')->assertDispatched(StudioPlanDiscussionVotesEditor::STEP_EVENT, step: 'previous')
        ->assertSet("targets.{$this->c2}.down", 0)->assertSet('unsaved', false);

    // Votes that can't be saved yet: the dialog says what is marked, and Save and continue stays.
    discussionVotesEditor($this->plan)->call('clearVoters')->call('setVotes', $this->c2, 'up', 2)->assertSet('unsaved', true)
        ->dispatch(StudioPlanDiscussionVotesEditor::LEAVE_EVENT, step: 'next')
        ->assertSee('can’t be until what is marked is fixed')
        ->assertViewHas('changes', fn (array $changes): bool => collect($changes)->contains(fn (array $change): bool => $change['label'] === 'C3' && $change['problem']))
        ->call('saveAndLeave')
        ->assertNotDispatched(StudioPlanDiscussionVotesEditor::STEP_EVENT)->assertSet('leaving', null)->assertSet('unsaved', true);

    expect(savedCommentVotes($this->plan))->toHaveCount(4)
        ->and($this->plan->fresh()->lock_version)->toBe(4);
});

it('goes on to the step asked once the comment votes are saved or discarded, and asks the votes editor first from Back, Continue and the steps', function () {
    Livewire::test(EditStudioPlan::class, ['record' => $this->plan->id])->call('goToStep', 'discussion')
        ->assertSeeHtml('$wire.step === &#039;discussion&#039; &amp;&amp; $store.rgCommentVotes?.unsaved ? $dispatch(&#039;studio-comment-votes-leave&#039;, { step: &#039;next&#039; }) : $wire.nextStep()')
        ->assertSeeHtml('$dispatch(&#039;studio-comment-votes-leave&#039;, { step: &#039;previous&#039; }) : $wire.previousStep()')
        ->assertSeeHtml('$dispatch(&#039;studio-comment-votes-leave&#039;, { step: &#039;image&#039; }) : $wire.goToStep(&#039;image&#039;)')
        ->dispatch(StudioPlanDiscussionVotesEditor::STEP_EVENT, step: 'next')->assertSet('step', 'publication')
        ->call('goToStep', 'discussion')->dispatch(StudioPlanDiscussionVotesEditor::STEP_EVENT, step: 'previous')->assertSet('step', 'post')->assertSet('postTab', 'votes')
        ->dispatch(StudioPlanDiscussionVotesEditor::STEP_EVENT, step: 'image')->assertSet('step', 'image')
        ->dispatch(StudioPlanDiscussionVotesEditor::STEP_EVENT, step: 'nowhere')->assertSet('step', 'image');
});

it('casts nothing: no vote, action, job or notification', function () {
    Bus::fake();
    Notification::fake();

    previewCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2)->call('save')->assertHasNoErrors();

    expect(PostVote::query()->exists())->toBeFalse()
        ->and(CommentVote::query()->exists())->toBeFalse()
        ->and(RatingVote::query()->exists())->toBeFalse()
        ->and(StudioAction::query()->exists())->toBeFalse();
    Queue::assertNothingPushed();
    Bus::assertNothingDispatched();
    Notification::assertNothingSent();
});
