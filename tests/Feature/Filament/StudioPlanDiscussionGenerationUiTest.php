<?php

use App\Enums\StudioDiscussionGenerationError;
use App\Enums\StudioDiscussionGenerationMode;
use App\Enums\StudioDiscussionGenerationStatus;
use App\Enums\StudioPostTextTone;
use App\Jobs\Studio\GenerateStudioDiscussionJob;
use App\Livewire\Admin\StudioPlanDiscussionEditor;
use App\Models\Comment;
use App\Models\Post;
use App\Models\StudioAction;
use App\Models\StudioDiscussionNode;
use App\Models\StudioPlanDiscussionGeneration;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Writing the discussion with AI from the Discussion step, as the prototype's
 * works: Generate tree by the settings — counts, and Personas checked or
 * picked at random only as the tree is generated — asked first when it would
 * replace comments, never while the post is unsaved or another generation is
 * on its way; read again while it is, and the tree it wrote taken in the
 * moment it is ready. Regenerate writes one comment again in place, with
 * Undo. Nothing is published.
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

/** An answer of two comments and a reply, as the model writes a whole discussion. */
function wholeTreeAnswer(): array
{
    return ['comments' => [
        answeredComment('node_1', null, 'persona_1', 60, 'Somebody owns this harbour now.'),
        answeredComment('node_2', 'node_1', 'persona_2', 180, 'The seagulls might disagree.'),
        answeredComment('node_3', null, 'persona_2', 120, 'That mist does all the work.'),
    ]];
}

it('asks nothing on opening, and polls for nothing', function () {
    discussionEditor($this->plan)
        ->assertSee('Discussion settings')
        ->assertSee('Generate from the settings above')
        ->assertSee('Generate tree')
        ->assertDontSeeHtml('wire:poll');

    expect(StudioPlanDiscussionGeneration::query()->exists())->toBeFalse();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('keeps the settings within bounds: up to forty comments, one to all of them on the post, and the personas there are', function () {
    discussionEditor($this->plan)
        ->assertSet('participantMode', 'random')
        ->assertSet('randomCount', 2)
        ->assertSee('of 2 active')
        ->set('totalCount', 99)->assertSet('totalCount', 40)
        ->set('rootCount', 50)->assertSet('rootCount', 40)
        ->set('totalCount', 3)->assertSet('rootCount', 3)
        ->set('totalCount', 0)->assertSet('rootCount', 0)->assertSee('No discussion · the post publishes without automated comments')
        ->set('randomCount', 9)->assertSet('randomCount', 2);
});

it('picks a random team only as the tree is generated, and asks for the counts set', function () {
    $editor = discussionEditor($this->plan)->set('totalCount', 7)->set('rootCount', 3)->set('randomCount', 2)
        ->assertSee('7 comments · 3 top-level · 2 personas')
        ->assertSee('Personas are picked when the tree is generated.');

    expect(StudioPlanDiscussionGeneration::query()->exists())->toBeFalse();

    $editor->call('generateTree', 0)->assertSet('awaiting', fn ($id) => $id !== null)->assertSeeHtml('wire:poll.3s.visible="check"');

    $generation = StudioPlanDiscussionGeneration::query()->sole();

    expect($generation)->mode->toBe(StudioDiscussionGenerationMode::FullDiscussion)
        ->and($generation->persona_user_ids)->toEqualCanonicalizing([$this->anna->id, $this->boris->id])
        ->and($generation->parameters)->toMatchArray(['root_count' => 3, 'reply_count' => 4, 'tone' => StudioPostTextTone::DEFAULT->value]);
    Queue::assertPushed(GenerateStudioDiscussionJob::class, 1);
    $editor->assertSee('Generating the discussion', escape: false)->assertSee('Picked for the current tree. Generate again to reshuffle.');
});

it('writes a whole tree by the personas checked, and none without one', function () {
    $editor = discussionEditor($this->plan)->set('participantMode', 'manual')
        ->assertSee('0 of 2 active personas selected')
        ->assertSee('Select at least one persona.');

    $editor->call('generateTree', 0)->assertHasErrors(['participants']);
    expect(StudioPlanDiscussionGeneration::query()->exists())->toBeFalse();

    $editor->call('toggleParticipant', $this->boris->id)->assertSee('1 of 2 active personas selected')
        ->call('generateTree', 0);

    expect(StudioPlanDiscussionGeneration::query()->sole()->persona_user_ids)->toBe([$this->boris->id]);
});

it('checks every persona shown at once, as many as take part, and clears them', function () {
    $editor = discussionEditor($this->plan)->set('participantMode', 'manual')
        ->call('selectShown')
        ->assertSet('manual', fn (array $ids) => collect($ids)->sort()->values()->all() === collect([$this->anna->id, $this->boris->id])->sort()->values()->all());

    $editor->set('participantSearch', 'Anna')->assertSee('Select 1 shown')->call('clearParticipants')->assertSet('manual', []);

    config(['content_studio.discussion_generation.max_personas' => 1]);
    $editor->set('participantSearch', '')->call('selectShown')
        ->assertHasErrors(['participants'])
        ->assertSee('Up to 1 personas take part in one discussion.')
        ->assertSet('manual', fn (array $ids) => count($ids) === 1);
});

it('says so when there are too few personas to pick', function () {
    $this->boris->forceFill(['role' => 'user'])->save();
    $this->anna->forceFill(['role' => 'user'])->save();

    discussionEditor($this->plan)->call('generateTree', 0)
        ->assertHasErrors(['participants'])
        ->assertSee('There are no personas to pick from. Create a persona first.');

    expect(StudioPlanDiscussionGeneration::query()->exists())->toBeFalse();
});

it('generates nothing while the post has unsaved changes, or a generation is on its way', function () {
    discussionEditor($this->plan)->call('generateTree', 2)
        ->assertDispatched('rg-admin-toast', message: 'Save the post first: the discussion is written from the saved title and description.');
    expect(StudioPlanDiscussionGeneration::query()->exists())->toBeFalse();

    $editor = discussionEditor($this->plan)->call('generateTree', 0);
    $editor->call('generateTree', 0)->assertDispatched('rg-admin-toast', message: 'A generation is already on its way. Wait for it before asking for another.');

    expect(StudioPlanDiscussionGeneration::query()->count())->toBe(1);
    Queue::assertPushed(GenerateStudioDiscussionJob::class, 1);
});

it('asks before a new tree replaces a discussion with comments, and asks for nothing until confirmed', function () {
    savedStudioBranchFixture($this->admin, $this->plan, $this->anna, $this->boris);

    $editor = discussionEditor($this->plan)->assertSee('Regenerate tree')->assertSee('The tree has comments. You’ll be asked before it’s replaced.')
        ->call('generateTree', 0)
        ->assertSet('confirmingGenerate', true)
        ->assertSee('Replace the discussion?')
        ->assertSee('4 current comments are replaced');

    expect(StudioPlanDiscussionGeneration::query()->exists())->toBeFalse();

    $editor->call('closeConfirmation')->assertSet('confirmingGenerate', false);
    expect(StudioPlanDiscussionGeneration::query()->exists())->toBeFalse();

    $editor->call('generateTree', 0)->call('confirmGenerate', 0)->assertSet('confirmingGenerate', false);
    expect(StudioPlanDiscussionGeneration::query()->count())->toBe(1);
});

it('takes the tree in the moment it is ready, never before', function () {
    Http::fake(['api.openai.com/*' => Http::response(studioDiscussionBody(wholeTreeAnswer()))]);
    $editor = discussionEditor($this->plan)->set('totalCount', 3)->set('rootCount', 2)->call('generateTree', 0);
    $generation = StudioPlanDiscussionGeneration::query()->sole();

    $editor->call('check')->assertSet('awaiting', $generation->id);
    expect(StudioDiscussionNode::query()->exists())->toBeFalse();

    runStudioDiscussionJob($generation);
    $editor->call('check')
        ->assertSet('awaiting', null)
        ->assertSet('lockVersion', 2)
        ->assertDispatched(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 1, current: 2, source: 'discussion')
        ->assertDontSeeHtml('wire:poll')
        ->assertSeeInOrder(['Somebody owns this harbour now.', 'The seagulls might disagree.', 'That mist does all the work.'])
        ->assertSee('Anna')->assertSee('Boris');

    expect(StudioDiscussionNode::query()->count())->toBe(3)
        ->and($generation->fresh()->applied_at)->not->toBeNull();
});

it('replaces a discussion with comments once confirmed, ids new', function () {
    $saved = savedStudioBranchFixture($this->admin, $this->plan, $this->anna, $this->boris);
    Http::fake(['api.openai.com/*' => Http::response(studioDiscussionBody(wholeTreeAnswer()))]);
    $editor = discussionEditor($this->plan)->set('totalCount', 3)->set('rootCount', 2)->call('generateTree', 0)->call('confirmGenerate', 0);

    runStudioDiscussionJob(StudioPlanDiscussionGeneration::query()->sole());
    $editor->call('check')->assertSet('lockVersion', 3);

    expect(StudioDiscussionNode::query()->pluck('body')->all())->toContain('Somebody owns this harbour now.')->not->toContain('Lovely light.')
        ->and(StudioDiscussionNode::query()->whereKey(array_values($saved->ids))->exists())->toBeFalse();
});

it('leaves nothing to undo once a new tree is taken in, so an earlier Undo cannot put the old discussion back over it', function () {
    $saved = savedStudioBranchFixture($this->admin, $this->plan, $this->anna, $this->boris);
    Http::fake(['api.openai.com/*' => Http::response(studioDiscussionBody(wholeTreeAnswer()))]);
    $editor = discussionEditor($this->plan)->call('askDelete', $saved->ids['b'])->call('confirmDelete');
    $token = $editor->get('undo')['token'];

    $editor->set('totalCount', 3)->set('rootCount', 2)->call('generateTree', 0)->call('confirmGenerate', 0);
    runStudioDiscussionJob(StudioPlanDiscussionGeneration::query()->sole());
    $editor->call('check')->assertSet('undo', null)
        ->dispatch('undo-studio-discussion', token: $token);

    expect(StudioDiscussionNode::query()->pluck('body')->all())->toContain('Somebody owns this harbour now.')->not->toContain('Lovely light.');
});

it('says why a tree was not taken in, keeping the discussion as it is', function (Closure $settle, string $message) {
    savedStudioBranchFixture($this->admin, $this->plan, $this->anna, $this->boris);
    Carbon::setTestNow('2026-10-20 10:00:00');
    $editor = discussionEditor($this->plan)->call('generateTree', 0)->call('confirmGenerate', 0);
    $generation = StudioPlanDiscussionGeneration::query()->sole();

    $settle($generation, $this);
    $editor->call('check')
        ->assertSet('awaiting', null)
        ->assertSee('The tree wasn’t generated:')
        ->assertSee($message)
        ->assertSee('Try again');

    expect(StudioDiscussionNode::query()->count())->toBe(4)
        ->and($generation->fresh()->applied_at)->toBeNull();
})->with([
    'failed' => [fn ($generation) => $generation->forceFill(['status' => StudioDiscussionGenerationStatus::Failed, 'error_code' => StudioDiscussionGenerationError::RateLimited, 'finished_at' => now()])->save(), StudioDiscussionGenerationError::RateLimited->message()],
    'lost' => [fn () => Carbon::setTestNow('2026-10-20 10:10:00'), 'The generation did not finish. Try again.'],
    'written from a post that changed since' => [function ($generation, $test) {
        Http::fake(['api.openai.com/*' => Http::response(studioDiscussionBody(['comments' => [answeredComment('node_1', null, 'persona_1', 60, 'Late.')]]))]);
        $generation->forceFill(['parameters' => [...$generation->parameters, 'root_count' => 1, 'reply_count' => 0]])->save();
        runStudioDiscussionJob($generation);
        $test->plan->forceFill(['title' => 'Another title'])->save();
    }, StudioDiscussionGenerationError::StaleContext->message()],
]);

it('takes in nothing another page of the plan took in already', function () {
    Http::fake(['api.openai.com/*' => Http::response(studioDiscussionBody(wholeTreeAnswer()))]);
    $editor = discussionEditor($this->plan)->set('totalCount', 3)->set('rootCount', 2)->call('generateTree', 0);
    $generation = StudioPlanDiscussionGeneration::query()->sole();
    runStudioDiscussionJob($generation);
    applyStudioDiscussion($this->admin, $this->plan, $generation->fresh());

    $editor->call('check')->assertSet('awaiting', null)->assertSet('lockVersion', 2)->assertSet('conflict', false);

    expect(StudioDiscussionNode::query()->count())->toBe(3);
});

it('writes one comment or reply again in place, same persona, parent and time, with Undo', function (string $target, string $mode) {
    $saved = savedStudioBranchFixture($this->admin, $this->plan, $this->anna, $this->boris);
    $before = storedStudioDiscussionNodes($this->plan);
    Http::fake(['api.openai.com/*' => Http::response(studioDiscussionBody(['text' => 'Written again.']))]);

    $editor = discussionEditor($this->plan)->call('regenerate', $saved->ids[$target], 0)
        ->assertSet('rewriting', $saved->ids[$target])
        ->assertSee('Rewriting…');

    $generation = StudioPlanDiscussionGeneration::query()->sole();
    expect($generation)->mode->value->toBe($mode)
        ->and($generation->targetNodeId())->toBe($saved->ids[$target]);

    runStudioDiscussionJob($generation);
    $label = $target === 'a' ? 'C1' : 'C2';
    $editor->call('check')
        ->assertSet('rewriting', null)
        ->assertDispatched('rg-admin-toast', message: "New text for {$label} · same author and time", tone: 'success');

    $after = storedStudioDiscussionNodes($this->plan);
    expect($after[$saved->ids[$target]])->toBe([...array_slice($before[$saved->ids[$target]], 0, 2), 'Written again.', ...array_slice($before[$saved->ids[$target]], 3)]);

    foreach ($before as $id => $row) {
        if ($id !== $saved->ids[$target]) {
            expect($after[$id])->toBe($row);
        }
    }

    $editor->dispatch('undo-studio-discussion', token: $editor->get('undo')['token']);
    expect(storedStudioDiscussionNodes($this->plan))->toBe($before);
})->with([
    'a comment' => ['a', 'regenerate_comment'],
    'a reply' => ['a1', 'regenerate_reply'],
]);

it('says why a comment was not written again, keeping it', function () {
    $saved = savedStudioBranchFixture($this->admin, $this->plan, $this->anna, $this->boris);
    $editor = discussionEditor($this->plan)->call('regenerate', $saved->ids['b'], 0);
    StudioPlanDiscussionGeneration::query()->sole()->forceFill(['status' => StudioDiscussionGenerationStatus::Failed, 'error_code' => StudioDiscussionGenerationError::ProviderTimeout, 'finished_at' => now()])->save();

    $editor->call('check')->assertDispatched('rg-admin-toast', message: 'The comment wasn’t written again: '.StudioDiscussionGenerationError::ProviderTimeout->message());

    expect(StudioDiscussionNode::query()->findOrFail($saved->ids['b'])->body)->toBe('Where is this?');
});

it('waits for a generation found on its way when the step opens', function () {
    $saved = savedStudioBranchFixture($this->admin, $this->plan, $this->anna, $this->boris);
    $generation = requestStudioDiscussion($this->admin, $this->plan, ['mode' => StudioDiscussionGenerationMode::RegenerateComment, 'targetNodeId' => $saved->ids['a']]);

    discussionEditor($this->plan)
        ->assertSet('awaiting', $generation->id)
        ->assertSet('rewriting', $saved->ids['a'])
        ->assertSeeHtml('wire:poll.3s.visible="check"');
});

it('offers no generation without a saved title, a finished analysis or a configured integration, and works by hand', function () {
    config(['content_studio.image_analysis.enabled' => false]);

    discussionEditor($this->plan)
        ->assertSee('Discussion generation is not configured. Comments can still be written by hand.')
        ->call('generateTree', 0)
        ->assertDispatched('rg-admin-toast', message: StudioDiscussionGenerationError::NotConfigured->message());

    commentFromDialog(discussionEditor($this->plan), $this->anna, 'By hand.')->assertHasNoErrors();

    expect(StudioPlanDiscussionGeneration::query()->exists())->toBeFalse()
        ->and(StudioDiscussionNode::query()->sole()->body)->toBe('By hand.');

    config(['content_studio.image_analysis.enabled' => true]);
    $this->plan->forceFill(['title' => null])->save();
    discussionEditor($this->plan)->assertSee('Save the post’s final title first: the discussion is written from it.');
});

it('empties the discussion when none is planned, with no request at all', function () {
    savedStudioBranchFixture($this->admin, $this->plan, $this->anna, $this->boris);

    discussionEditor($this->plan)->set('totalCount', 0)
        ->assertSee('Nothing is generated: the tree is emptied.')
        ->call('generateTree', 0)->call('confirmGenerate', 0)
        ->assertSee('No discussion planned');

    expect(StudioDiscussionNode::query()->exists())->toBeFalse()
        ->and(StudioPlanDiscussionGeneration::query()->exists())->toBeFalse();
    Http::assertNothingSent();
});

it('publishes nothing: no post, comment, action or vote', function () {
    Http::fake(['api.openai.com/*' => Http::response(studioDiscussionBody(wholeTreeAnswer()))]);
    $editor = discussionEditor($this->plan)->set('totalCount', 3)->set('rootCount', 2)->call('generateTree', 0);
    runStudioDiscussionJob(StudioPlanDiscussionGeneration::query()->sole());
    $editor->call('check');

    expect(StudioDiscussionNode::query()->count())->toBe(3)
        ->and(Post::query()->exists())->toBeFalse()
        ->and(Comment::query()->exists())->toBeFalse()
        ->and(StudioAction::query()->exists())->toBeFalse()
        ->and(DB::table('post_votes')->exists())->toBeFalse()
        ->and(DB::table('comment_votes')->exists())->toBeFalse();
});
