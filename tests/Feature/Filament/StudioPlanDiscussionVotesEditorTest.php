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
 * votes, set by hand or shared out by priority, the Personas who cast them —
 * never on their own comment — and when after each comment; made into a
 * preview drawn once and saved exactly as shown, the votes on the post and its
 * questions kept, and every write sharing the plan's one version with the
 * other editors on the page.
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

/** The editor's preview, as savedCommentVotes() lists votes. */
function previewedCommentVotes(Testable $editor): array
{
    return array_map(fn (array $vote): array => [$vote['personaUserId'], $vote['voteType'], $vote['discussionNodeId'], $vote['offsetSeconds'], $vote['sortOrder']], $editor->get('preview') ?? []);
}

/** Two up votes and one down on C1, and one up on C2, by four Personas drawn at random. */
function previewCommentVotes(Testable $editor, int $c1, int $c2): Testable
{
    return $editor->set('voterCount', 4)
        ->call('setVotes', $c1, 'up', 2)->call('setVotes', $c1, 'down', 1)->call('setVotes', $c2, 'up', 1)
        ->call('build')->assertHasNoErrors();
}

it('is for administrators who may manage plans only — drawn, or clicked', function () {
    Livewire::actingAs(User::factory()->moderator()->create())->test(StudioPlanDiscussionVotesEditor::class, ['planId' => $this->plan->id])->assertForbidden();
    Livewire::actingAs(User::factory()->create())->test(StudioPlanDiscussionVotesEditor::class, ['planId' => $this->plan->id])->assertForbidden();

    $this->actingAs($this->admin);
    $editor = discussionVotesEditor($this->plan);
    $this->admin->forceFill(['role' => 'moderator'])->save();

    $editor->call('build')->assertForbidden();
});

it('lists every comment and reply by its label, Persona and moment, Normal and with no votes, from a random draw of the active Personas', function () {
    discussionVotesEditor($this->plan)
        ->assertSet('voterMode', 'random')->assertSet('voterCount', 6)
        ->assertSet('targets', [
            $this->c1 => ['up' => 0, 'down' => 0, 'priority' => 'normal', 'first' => 300, 'spread' => 21600],
            $this->r1 => ['up' => 0, 'down' => 0, 'priority' => 'normal', 'first' => 300, 'spread' => 21600],
            $this->c2 => ['up' => 0, 'down' => 0, 'priority' => 'normal', 'first' => 300, 'spread' => 21600],
        ])
        ->assertSeeInOrder(['C1', 'Lovely light on the water.', 'C2', 'Agreed, the mist helps.', 'C3', 'Where is this?'])
        ->assertSee('No comment votes prepared · the comments get only real users’ votes.')
        ->assertSee('No votes are saved on the comments.');
});

it('asks for comments first when the discussion has none', function () {
    $plan = StudioPlan::factory()->create(['created_by_user_id' => $this->admin->id]);

    discussionVotesEditor($plan)->assertSee('No comments to vote on yet')->assertSee('Comment voters')->call('build')->assertHasErrors(['votes'])->assertSet('preview', null);
});

it('shares the totals out by priority, Low 1, Normal 2, High 3, then lets each count be set by hand', function () {
    $editor = discussionVotesEditor($this->plan)
        ->call('setPriority', $this->c1, 'high')->call('setPriority', $this->c2, 'low')
        ->set('totalUp', 12)->set('totalDown', 5)->call('distribute');

    // Up: 12 × 3/6, 2/6, 1/6 = 6, 4, 2; down: 5 × the same = 2.5, 1.67, 0.83 — 2, 1, 0, the two left to the largest fractions.
    $editor->assertSet("targets.{$this->c1}.up", 6)->assertSet("targets.{$this->r1}.up", 4)->assertSet("targets.{$this->c2}.up", 2)
        ->assertSet("targets.{$this->c1}.down", 2)->assertSet("targets.{$this->r1}.down", 2)->assertSet("targets.{$this->c2}.down", 1)
        ->call('setVotes', $this->c2, 'up', 0)->assertSet("targets.{$this->c2}.up", 0)
        ->call('setVotes', $this->c2, 'down', -4)->assertSet("targets.{$this->c2}.down", 0)
        ->call('setPriority', $this->c2, 'urgent')->assertSet("targets.{$this->c2}.priority", 'low');

    expect(savedCommentVotes($this->plan))->toBe([]);
});

it('shows a preview, then saves exactly it — never a vote on a comment by its own Persona — keeping the votes on the post', function () {
    $editor = previewCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2);
    $preview = previewedCommentVotes($editor);

    expect($preview)->toHaveCount(4)
        ->and(collect($preview)->where(2, $this->c1)->pluck(0)->all())->not->toContain($this->anna->id)
        ->and(collect($preview)->where(2, $this->c2)->pluck(0)->all())->not->toContain($this->carl->id)
        ->and(collect($preview)->where(2, $this->c1)->pluck(0)->unique())->toHaveCount(3);

    $editor->assertSee('4 votes in the preview, not saved yet.')
        ->call('save')->assertHasNoErrors()
        ->assertDispatched('rg-admin-toast', message: 'Comment votes saved', tone: 'success')
        ->assertDispatched(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 3, current: 4, source: StudioPlanDiscussionVotesEditor::SOURCE)
        ->assertSet('preview', null)->assertSee('4 votes are saved on the comments.');

    expect(savedCommentVotes($this->plan))->toBe($preview)
        ->and(StudioPlanVote::query()->whereKey($this->postVote)->exists())->toBeTrue()
        ->and($this->plan->fresh()->lock_version)->toBe(4);
});

it('dates each vote from its comment', function () {
    $editor = discussionVotesEditor($this->plan)->call('setVotes', $this->c2, 'up', 3)->call('setTiming', 'first', 1800)->call('setTiming', 'spread', 3600)->call('build');

    expect(array_column(previewedCommentVotes($editor), 3))->toBe([2700, 4500, 6300]);
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
        ->assertSee('4 votes are saved on the comments.');
});

it('saves only a preview made from the settings and the comments as they are now', function () {
    $editor = previewCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2)->call('setVotes', $this->c2, 'down', 1);

    $editor->assertSee('The settings or the comments changed since this preview. Rebuild it to save.')
        ->call('save')->assertHasErrors(['preview']);

    // The comments moved on the page's Discussion editor: the preview drawn for them is out of date.
    $other = previewCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2);
    $discussion = discussionEditor($this->plan)->call('setOffset', $this->c2, 1200)->assertHasNoErrors();
    $discussion->assertDispatched(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 3, current: 4, source: 'discussion');

    $other->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 3, current: 4, source: 'discussion')
        ->assertSet('lockVersion', 4)->assertSet('conflict', false)
        ->call('save')->assertHasErrors(['preview'])
        ->call('build')->call('save')->assertHasNoErrors();

    expect(collect(savedCommentVotes($this->plan))->where(2, $this->c2)->pluck(3)->all())->toBe([1500]);
});

it('says how many voters a comment is short, and builds nothing', function () {
    discussionVotesEditor($this->plan)->set('voterMode', 'manual')->call('toggleVoter', $this->anna->id)->call('toggleVoter', $this->dana->id)
        ->call('setVotes', $this->c1, 'up', 2)
        ->assertSee('Needs 2 voters; 1 can vote on it — 1 short.')
        ->call('build')
        ->assertHasErrors(["targets.{$this->c1}" => 'Its 2 votes need 2 personas who can vote on it; 1 can — 1 short. Choose more personas, or ask for fewer.'])
        ->assertSet('preview', null)->assertSet("targets.{$this->c1}.up", 2);
});

it('keeps each vote\'s id when a save changes only its moment', function () {
    previewCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2)->call('save');
    $ids = StudioPlanVote::query()->where('type', StudioActionType::VoteComment)->orderBy('id')->pluck('id')->all();

    discussionVotesEditor($this->plan)->call('setTiming', 'first', 3600)->call('build')->call('save')->assertHasNoErrors();

    expect(StudioPlanVote::query()->where('type', StudioActionType::VoteComment)->orderBy('id')->pluck('id')->all())->toBe($ids);
});

it('saves nothing for a preview that is the saved votes again, and once for Save pressed twice', function () {
    $editor = previewCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2)->call('save');
    $editor->call('save')->assertHasErrors(['preview']);

    discussionVotesEditor($this->plan)->call('build')->call('save')
        ->assertDispatched('rg-admin-toast', message: 'Nothing to save: these are the votes saved.', tone: 'info');

    expect(StudioPlanVote::query()->where('type', StudioActionType::VoteComment)->count())->toBe(4)
        ->and($this->plan->fresh()->lock_version)->toBe(4);
});

it('keeps the draw on Rebuild, draws anew on Pick others, and draws the saved votes again on Discard', function () {
    previewCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2)->call('save');
    $saved = savedCommentVotes($this->plan);

    $editor = discussionVotesEditor($this->plan)->call('build');
    $seed = $editor->get('seed');

    expect(previewedCommentVotes($editor))->toBe($saved);

    $editor->call('build')->assertSet('seed', $seed);

    expect(previewedCommentVotes($editor))->toBe($saved);

    $editor->call('redraw')->assertHasNoErrors()->assertSet('keepSaved', false);

    expect($editor->get('seed'))->not->toBe($seed)
        ->and(previewedCommentVotes($editor))->toHaveCount(4);

    $editor->call('setVotes', $this->c2, 'down', 1)->call('discard')
        ->assertSet('preview', null)->assertSet('keepSaved', true)->assertSet("targets.{$this->c2}.down", 0)
        ->assertSee('4 votes are saved on the comments.');

    expect(savedCommentVotes($this->plan))->toBe($saved);
});

it('removes the comment votes once asked, the votes on the post staying', function () {
    previewCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2)->call('save');

    discussionVotesEditor($this->plan)->call('askRemove')->assertSee('Remove the comment votes?')
        ->call('confirmRemove')->assertDispatched('rg-admin-toast', message: 'Comment votes removed', tone: 'success');

    expect(savedCommentVotes($this->plan))->toBe([])
        ->and(StudioPlanVote::query()->pluck('id')->all())->toBe([$this->postVote]);
});

it('keeps a Persona restricted since in the saved votes, saying so, and gives a deleted one no new vote', function () {
    discussionVotesEditor($this->plan)->set('voterMode', 'manual')->call('toggleVoter', $this->eli->id)->call('toggleVoter', $this->dana->id)
        ->call('setVotes', $this->c2, 'up', 2)->call('build')->call('save')->assertHasNoErrors();
    $this->eli->forceFill(['status' => UserStatus::Shadowbanned])->save();
    $this->dana->forceFill(['status' => UserStatus::Deleted])->save();

    $editor = discussionVotesEditor($this->plan)
        ->assertSee('Shadowbanned')->assertSee('Deleted')
        ->assertSee('This persona is limited, banned or shadowbanned and cannot vote. Choose another one.')
        ->assertSee('A deleted or restricted persona gets no new votes.');

    $editor->call('build')->assertHasErrors(["targets.{$this->c2}"]);
    $editor->call('setVotes', $this->c2, 'up', 1)->call('build')->assertHasNoErrors();

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
    $editor = discussionVotesEditor($this->plan)->call('setVotes', $this->c2, 'up', 1)->call('build');

    $editor->call('save')->assertDispatched('rg-admin-toast', message: 'A vote on the post or its questions saved earlier stands in the way: The post’s own author cannot vote on it. Choose another persona. Fix it on Post · Votes.', tone: 'error');

    expect(savedCommentVotes($this->plan))->toBe([])->and(StudioPlanVote::query()->pluck('id')->all())->toBe([$this->postVote]);
});

it('takes a deleted comment\'s votes away with it, never putting them back from a preview made before', function () {
    $editor = previewCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2)->call('save');
    $editor = previewCommentVotes(discussionVotesEditor($this->plan), $this->c1, $this->c2);

    // The whole branch of C1 deleted on the page's Discussion editor.
    discussionEditor($this->plan)->call('askDelete', $this->c1)->call('confirmDelete')
        ->assertDispatched(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 4, current: 5, source: 'discussion');

    expect(collect(savedCommentVotes($this->plan))->pluck(2)->unique()->values()->all())->toBe([$this->c2]);

    $editor->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 4, current: 5, source: 'discussion')
        ->assertSet('lockVersion', 5)->assertSet('conflict', false)->assertSet('targets', fn (array $targets): bool => array_keys($targets) === [$this->c2])
        ->call('save')->assertHasErrors(['preview'])
        ->call('build')->call('save');

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

it('draws in the page\'s Discussion step, its Comment voters in the place beside the Discussion settings, and a new publication asks for its draft first', function () {
    Livewire::test(EditStudioPlan::class, ['record' => $this->plan->id])->call('goToStep', 'discussion')
        ->assertSeeHtml('wire:name="admin.studio-plan-discussion-votes-editor" wire:key="studio-plan-discussion-votes-'.$this->plan->id.'"');

    discussionEditor($this->plan)->assertSeeHtmlInOrder(['Discussion settings', '<div id="rg-admin-studio-comment-voters" class="rg-admin-studio__comment-voters-slot" wire:ignore></div>', 'Generate from the settings above']);
    discussionVotesEditor($this->plan)->assertSeeHtmlInOrder(['<template x-teleport="#rg-admin-studio-comment-voters">', 'Comment voters', '</template>', 'Comment votes']);

    Livewire::test(CreateStudioPlan::class)->call('goToStep', 'discussion')
        ->assertSee('Save the draft first to prepare its discussion.')
        ->assertDontSeeHtml('wire:name="admin.studio-plan-discussion-votes-editor"');
});

it('changes nothing of a plan past its Draft, or deleted', function () {
    $this->plan->forceFill(['status' => StudioPlanStatus::Approved])->save();

    discussionVotesEditor($this->plan)->assertSee('This plan is no longer a draft, so its comment votes can’t be changed here.')
        ->call('setVotes', $this->c2, 'up', 1)->call('build')->assertSet('preview', null);

    $this->plan->forceFill(['status' => StudioPlanStatus::Draft])->save();
    $editor = discussionVotesEditor($this->plan->fresh());
    app(DeleteStudioPlanDraftAction::class)->handle($this->admin, $this->plan, $this->plan->fresh()->lock_version);

    $editor->call('setVotes', $this->c2, 'up', 1)->call('build')->assertSet('gone', true)
        ->assertDispatched('rg-admin-toast', message: 'This draft was deleted, so its votes can’t be changed any more.', tone: 'error');
});

it('lists every voter the preview drew on the Voters tab, with what it votes and writes', function () {
    $editor = discussionVotesEditor($this->plan)->set('voterMode', 'manual')
        ->call('toggleVoter', $this->anna->id)->call('toggleVoter', $this->eli->id)->call('toggleVoter', $this->dana->id)
        ->call('setVotes', $this->c2, 'up', 2)->call('build')->set('tab', 'voters');

    $rows = collect($editor->viewData('voterTable')['rows'])->keyBy(fn (array $row): int => $row['persona']['id']);

    expect($rows->keys()->sort()->values()->all())->toBe(collect([$this->anna->id, $this->dana->id, $this->eli->id])->sort()->values()->all())
        ->and($rows[$this->anna->id]['writes'])->toBe(['C1'])
        ->and(collect($editor->set('votersFilter', 'writers')->viewData('voterTable')['rows'])->pluck('persona.id')->all())->toBe([$this->anna->id]);
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
