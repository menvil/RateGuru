<?php

use App\Enums\StudioDiscussionGenerationError;
use App\Enums\StudioDiscussionGenerationMode;
use App\Enums\StudioDiscussionGenerationStatus;
use App\Jobs\Studio\GenerateStudioDiscussionJob;
use App\Livewire\Admin\StudioPlanDiscussionEditor;
use App\Livewire\Admin\StudioPlanDiscussionProposals;
use App\Models\Comment;
use App\Models\Post;
use App\Models\StudioAction;
use App\Models\StudioDiscussionNode;
use App\Models\StudioPlan;
use App\Models\StudioPlanDiscussionGeneration;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Writing the discussion with AI from the Discussion step: a whole tree or
 * one saved part, each an explicit request of its own, never while anything
 * is unsaved; its state read again on its own, never drawing the draft again;
 * the proposal previewed apart from the discussion, ignored or applied — a
 * whole tree only once confirmed, a part changing its own texts alone — and
 * nothing published.
 */
beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    configureStudioDiscussionGeneration();
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
    $this->plan = discussionReadyStudioPlan(['created_by_user_id' => $this->admin->id]);
    [$this->anna, $this->boris] = [studioPersonaWithProfile([], ['display_name' => 'Anna']), studioPersonaWithProfile([], ['display_name' => 'Boris'])];
});

afterEach(function () {
    Carbon::setTestNow();
});

function proposalsPanel(StudioPlan $plan): Testable
{
    return Livewire::test(StudioPlanDiscussionProposals::class, ['planId' => $plan->id]);
}

/** A whole discussion of two comments and a reply, asked for by Anna and Boris from the editor and written. */
function generatedWholeTree($test): StudioPlanDiscussionGeneration
{
    Http::fake(['api.openai.com/*' => Http::response(studioDiscussionBody(['comments' => [
        answeredComment('node_1', null, 'persona_1', 60, 'Somebody owns this harbour now.'),
        answeredComment('node_2', 'node_1', 'persona_2', 180, 'The seagulls might disagree.'),
        answeredComment('node_3', null, 'persona_2', 120, 'That mist does all the work.'),
    ]]))]);

    discussionEditor($test->plan)
        ->call('toggleParticipant', $test->anna->id)
        ->call('toggleParticipant', $test->boris->id)
        ->set('totalCount', 3)
        ->set('rootCount', 2)
        ->call('generateTree', 0);

    $generation = StudioPlanDiscussionGeneration::query()->latest('id')->firstOrFail();
    runStudioDiscussionJob($generation);

    return $generation->fresh();
}

it('shows no generation on a draft that has none, and asks nothing on opening', function () {
    proposalsPanel($this->plan)->assertSee('No discussion has been generated for this draft yet.')->assertDontSeeHtml('wire:poll');
    discussionEditor($this->plan)->assertSee('Generate tree');

    expect(StudioPlanDiscussionGeneration::query()->exists())->toBeFalse();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('asks for one whole tree on Generate: its counts as comments and replies, the personas and tone chosen', function () {
    discussionEditor($this->plan)
        ->call('toggleParticipant', $this->boris->id)
        ->call('toggleParticipant', $this->anna->id)
        ->set('totalCount', 7)
        ->set('rootCount', 3)
        ->set('tone', 'irony')
        ->call('generateTree', 0)
        ->assertDispatched('studio-discussion-generations-changed');

    $generation = StudioPlanDiscussionGeneration::query()->sole();

    expect($generation)->mode->toBe(StudioDiscussionGenerationMode::FullDiscussion)
        ->persona_user_ids->toBe(collect([$this->anna->id, $this->boris->id])->sort()->values()->all())
        ->and($generation->parameters)->toMatchArray(['root_count' => 3, 'reply_count' => 4, 'tone' => 'irony']);
    Queue::assertPushed(GenerateStudioDiscussionJob::class, 1);
});

it('sends no second paid request while one is on its way', function () {
    $editor = discussionEditor($this->plan)->call('toggleParticipant', $this->anna->id)->call('generateTree', 0);

    $editor->call('generateTree', 0)->assertDispatched('rg-admin-toast', message: 'A generation is already on its way. Wait for it before asking for another.');

    expect(StudioPlanDiscussionGeneration::query()->count())->toBe(1);
    Queue::assertPushed(GenerateStudioDiscussionJob::class, 1);
});

it('keeps the counts within bounds: one to forty in all, one to all of them on the post', function () {
    discussionEditor($this->plan)
        ->set('totalCount', 99)->assertSet('totalCount', 40)
        ->set('rootCount', 50)->assertSet('rootCount', 40)
        ->set('totalCount', 0)->assertSet('totalCount', 1)->assertSet('rootCount', 1);
});

it('picks a random team only on Pick personas, and shows it before generating', function () {
    $editor = discussionEditor($this->plan)->set('participantMode', 'random')->set('randomCount', 2);

    $editor->call('generateTree', 0)->assertHasErrors(['participants']);
    expect(StudioPlanDiscussionGeneration::query()->exists())->toBeFalse();

    $editor->call('pickPersonas');
    $picked = $editor->get('participants');

    expect($picked)->toHaveCount(2)->each->toBeIn([$this->anna->id, $this->boris->id]);
    $editor->assertSee('Anna')->assertSee('Boris')->assertSee('Pick again');

    $editor->call('generateTree', 0);

    expect(StudioPlanDiscussionGeneration::query()->sole()->persona_user_ids)->toBe(collect($picked)->sort()->values()->all());
});

it('says so when there are too few personas to pick, sending nothing', function () {
    discussionEditor($this->plan)->set('participantMode', 'random')->set('randomCount', 5)
        ->call('pickPersonas')
        ->assertHasErrors(['participants'])
        ->assertSee('Only 2 personas are available. Pick fewer.')
        ->assertSet('participants', []);
});

it('generates nothing while the post or the discussion has unsaved changes', function () {
    discussionEditor($this->plan)->call('toggleParticipant', $this->anna->id)
        ->call('generateTree', 2)
        ->assertDispatched('rg-admin-toast', message: 'Save the post first: the discussion is written from the saved title and description.');

    discussionEditor($this->plan)->call('toggleParticipant', $this->anna->id)->call('addComment')
        ->call('generateTree', 0)
        ->assertDispatched('rg-admin-toast', message: 'Save or discard your changes to the discussion first: it is written from the discussion as saved.');

    expect(StudioPlanDiscussionGeneration::query()->exists())->toBeFalse();
});

it('shows each state of the newest generation, read again only while it is on its way', function (Closure $state, string $label, bool $polls) {
    Carbon::setTestNow('2026-10-20 10:00:00');
    StudioPlanDiscussionGeneration::factory()->state($state())->create(['studio_plan_id' => $this->plan->id, 'requested_at' => now()]);

    $panel = proposalsPanel($this->plan)->assertSee($label);
    $polls ? $panel->assertSeeHtml('wire:poll.3s.visible') : $panel->assertDontSeeHtml('wire:poll');
})->with([
    'queued' => [fn () => ['status' => StudioDiscussionGenerationStatus::Queued], 'Waiting for generation', true],
    'running' => [fn () => ['status' => StudioDiscussionGenerationStatus::Running, 'started_at' => now()], 'Generating discussion', true],
    'completed' => [fn () => ['status' => StudioDiscussionGenerationStatus::Completed, 'result' => ['nodes' => []]], 'Proposal ready', false],
    'failed' => [fn () => ['status' => StudioDiscussionGenerationStatus::Failed, 'error_code' => StudioDiscussionGenerationError::RateLimited], 'OpenAI is limiting requests right now.', false],
    'lost' => [fn () => ['status' => StudioDiscussionGenerationStatus::Running, 'started_at' => now()->subMinutes(10)], 'Generation may have been lost', false],
]);

it('reads the generations again without ever drawing the draft again', function () {
    $editor = discussionEditor($this->plan)->call('toggleParticipant', $this->anna->id)->call('generateTree', 0)
        ->call('addComment')->set('nodes.k1.body', 'Typed while it generates.');
    $panel = proposalsPanel($this->plan)->assertSeeHtml('wire:poll.3s.visible');

    $panel->call('$refresh')->call('$refresh');

    expect($editor->get('nodes')['k1']['body'])->toBe('Typed while it generates.');
    // The draft's own component is never polled.
    $editor->assertDontSeeHtml('wire:poll');
});

it('tells the editor once the generation it waited for settles, so it offers Generate again with the draft kept', function () {
    Http::fake(['api.openai.com/*' => Http::response(studioDiscussionBody(['comments' => [
        answeredComment('node_1', null, 'persona_1', 60, 'Somebody owns this harbour now.'),
    ]]))]);
    $editor = discussionEditor($this->plan)->call('toggleParticipant', $this->anna->id)->set('totalCount', 1)->set('rootCount', 1)->call('generateTree', 0)
        ->assertDispatched('studio-discussion-generations-changed', requested: true)
        ->assertSee('A generation is on its way — see below.');
    $panel = proposalsPanel($this->plan)->assertSet('wasPolling', true);

    $panel->call('$refresh')->assertNotDispatched('studio-discussion-generation-settled');
    runStudioDiscussionJob(StudioPlanDiscussionGeneration::query()->sole());
    $panel->call('$refresh')->assertDispatched('studio-discussion-generation-settled')->assertSet('wasPolling', false);
    $panel->call('$refresh')->assertNotDispatched('studio-discussion-generation-settled');

    $editor->call('addComment')->set('nodes.k1.body', 'Typed meanwhile.')
        ->dispatch('studio-discussion-generation-settled')
        ->assertDontSee('A generation is on its way — see below.');

    expect($editor->get('nodes')['k1']['body'])->toBe('Typed meanwhile.');
});

it('tells the editor at once of a generation it asked for that settled before it was read', function () {
    StudioPlanDiscussionGeneration::factory()->failed()->create(['studio_plan_id' => $this->plan->id]);

    proposalsPanel($this->plan)->assertSet('wasPolling', false)
        ->dispatch('studio-discussion-generations-changed')->assertNotDispatched('studio-discussion-generation-settled')
        ->dispatch('studio-discussion-generations-changed', requested: true)->assertDispatched('studio-discussion-generation-settled')
        ->assertSet('wasPolling', false);
});

it('previews a whole tree apart from the discussion, and Ignore changes nothing', function () {
    $generation = generatedWholeTree($this);
    $before = DB::table('studio_plan_discussion_nodes')->count();

    $panel = proposalsPanel($this->plan)->assertSee('Proposal ready')->call('preview', $generation->id)
        ->assertSee('Applying this proposal replaces the entire prepared discussion.')
        ->assertSeeInOrder(['Somebody owns this harbour now.', 'The seagulls might disagree.', 'That mist does all the work.']);

    expect(DB::table('studio_plan_discussion_nodes')->count())->toBe($before)
        ->and($generation->fresh()->applied_at)->toBeNull();

    $panel->call('closePreview')->assertSet('previewId', null)->assertDontSee('Applying this proposal');

    expect($generation->fresh())->status->toBe(StudioDiscussionGenerationStatus::Completed)->applied_at->toBeNull();
});

it('applies a whole tree over a saved discussion only once confirmed', function () {
    saveStudioDiscussion($this->admin, $this->plan, [discussionNode(['personaUserId' => $this->anna->id, 'body' => 'By hand.'])]);
    $generation = generatedWholeTree($this);

    $panel = proposalsPanel($this->plan)->call('preview', $generation->id)->call('apply', $generation->id)
        ->assertSet('confirmingId', $generation->id)
        ->assertSee('Replace the prepared discussion?')
        ->assertNotDispatched('apply-studio-discussion-generation');

    expect(StudioDiscussionNode::query()->pluck('body')->all())->toBe(['By hand.']);

    $panel->call('confirmApply')->assertDispatched('apply-studio-discussion-generation', generationId: $generation->id);

    discussionEditor($this->plan)
        ->dispatch('apply-studio-discussion-generation', generationId: $generation->id)
        ->assertDispatched('rg-admin-toast', message: 'Proposal applied to the discussion')
        ->assertDispatched(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 2, current: 3, source: 'discussion')
        ->assertSet('lockVersion', 3);

    expect(StudioDiscussionNode::query()->orderBy('id')->pluck('body')->all())->toBe(['Somebody owns this harbour now.', 'That mist does all the work.', 'The seagulls might disagree.'])
        ->and($generation->fresh()->applied_at)->not->toBeNull();
});

it('writes one saved part again — a comment, a reply or a branch — keeping every id, parent, persona, place and time', function (string $target, string $what, array $answer, array $changed) {
    $saved = savedStudioBranchFixture($this->admin, $this->plan, $this->anna, $this->boris);
    $before = storedStudioDiscussionNodes($this->plan);
    Http::fake(['api.openai.com/*' => Http::response(studioDiscussionBody($answer))]);

    discussionEditor($this->plan)->call('openRegenerate', 'n'.$saved->ids[$target])->call('regenerate', 'n'.$saved->ids[$target], $what, 0);

    $generation = StudioPlanDiscussionGeneration::query()->sole();
    expect($generation->targetNodeId())->toBe($saved->ids[$target]);
    runStudioDiscussionJob($generation);

    proposalsPanel($this->plan)->call('preview', $generation->id)
        ->assertSee('Only these texts change.')
        ->assertSee(array_values($changed)[0]);

    discussionEditor($this->plan)->dispatch('apply-studio-discussion-generation', generationId: $generation->id);

    $after = storedStudioDiscussionNodes($this->plan);

    foreach ($before as $id => [$parent, $persona, $body, $sort, $offset]) {
        $key = array_search($id, $saved->ids, true);
        expect($after[$id])->toBe([$parent, $persona, $changed[$key] ?? $body, $sort, $offset]);
    }
})->with([
    'a comment' => ['a', 'comment', ['text' => 'Such light.'], ['a' => 'Such light.']],
    'a reply' => ['a1', 'reply', ['text' => 'Indeed.'], ['a1' => 'Indeed.']],
    'a branch' => ['a', 'branch', ['comment' => 'What a morning.', 'replies' => [['reply' => 'existing_2', 'text' => 'The mist!'], ['reply' => 'existing_3', 'text' => 'I was there.']]], ['a' => 'What a morning.', 'a1' => 'The mist!', 'a2' => 'I was there.']],
]);

it('writes no new comment again before it is saved', function () {
    discussionEditor($this->plan)->call('addComment')->call('regenerate', 'k1', 'comment', 0)
        ->assertDispatched('rg-admin-toast', message: 'Save the discussion first: only a saved comment can be written again.');

    expect(StudioPlanDiscussionGeneration::query()->exists())->toBeFalse();
});

it('refuses a proposal written from a post that changed since, keeping it in the history', function () {
    $generation = generatedWholeTree($this);
    $this->plan->forceFill(['title' => 'Another title'])->save();

    proposalsPanel($this->plan)->call('preview', $generation->id)
        ->assertSee('so it can no longer be applied. Generate again.')
        ->assertDontSee('Apply proposal');

    discussionEditor($this->plan)->dispatch('apply-studio-discussion-generation', generationId: $generation->id)
        ->assertDispatched('rg-admin-toast', message: StudioDiscussionGenerationError::StaleContext->message());

    expect(StudioDiscussionNode::query()->exists())->toBeFalse()
        ->and(StudioPlanDiscussionGeneration::query()->whereKey($generation->id)->exists())->toBeTrue();
});

it('applies a proposal once, the second time saying so', function () {
    $generation = generatedWholeTree($this);
    $editor = discussionEditor($this->plan)->dispatch('apply-studio-discussion-generation', generationId: $generation->id);

    $editor->dispatch('apply-studio-discussion-generation', generationId: $generation->id)
        ->assertDispatched('rg-admin-toast', message: StudioDiscussionGenerationError::AlreadyApplied->message());

    expect(StudioDiscussionNode::query()->count())->toBe(3)
        ->and($this->plan->fresh()->lock_version)->toBe(2);
});

it('applies nothing over unsaved changes to the discussion', function () {
    $generation = generatedWholeTree($this);

    discussionEditor($this->plan)->call('addComment')
        ->dispatch('apply-studio-discussion-generation', generationId: $generation->id)
        ->assertDispatched('rg-admin-toast', message: 'Save or discard your changes to the discussion first: a proposal is applied to the discussion as saved.');

    expect($generation->fresh()->applied_at)->toBeNull();
});

it('asks a failed generation again with Retry, a request of its own', function () {
    $failed = StudioPlanDiscussionGeneration::factory()->failed()->create([
        'studio_plan_id' => $this->plan->id,
        'persona_user_ids' => [$this->anna->id],
        'parameters' => ['mode' => 'full_discussion', 'target_node_id' => null, 'persona_user_ids' => [$this->anna->id], 'root_count' => 2, 'reply_count' => 1, 'tone' => 'humour', 'expected_lock_version' => 1],
    ]);
    proposalsPanel($this->plan)->assertSee('Retry');

    discussionEditor($this->plan)->call('retryGeneration', $failed->id, 0);

    $retry = StudioPlanDiscussionGeneration::query()->whereKeyNot($failed->id)->sole();

    expect($retry)->persona_user_ids->toBe([$this->anna->id])
        ->and($retry->parameters)->toMatchArray(['root_count' => 2, 'reply_count' => 1, 'tone' => 'humour']);
});

it('works by hand while generation is switched off, offering none', function () {
    config(['content_studio.image_analysis.enabled' => false]);

    discussionEditor($this->plan)
        ->assertSee('Discussion generation is not configured. Comments can still be written by hand.')
        ->call('toggleParticipant', $this->anna->id)
        ->call('generateTree', 0)
        ->assertDispatched('rg-admin-toast', message: StudioDiscussionGenerationError::NotConfigured->message())
        ->call('addComment')->call('choosePersona', 'k1', $this->anna->id)->set('nodes.k1.body', 'By hand.')
        ->call('save')->assertHasNoErrors();

    expect(StudioPlanDiscussionGeneration::query()->exists())->toBeFalse()
        ->and(StudioDiscussionNode::query()->sole()->body)->toBe('By hand.');
});

it('publishes nothing: no post, comment, action or vote', function () {
    $generation = generatedWholeTree($this);
    discussionEditor($this->plan)->dispatch('apply-studio-discussion-generation', generationId: $generation->id);

    expect(Post::query()->exists())->toBeFalse()
        ->and(Comment::query()->exists())->toBeFalse()
        ->and(StudioAction::query()->exists())->toBeFalse()
        ->and(DB::table('post_votes')->exists())->toBeFalse()
        ->and(DB::table('comment_votes')->exists())->toBeFalse();
});
