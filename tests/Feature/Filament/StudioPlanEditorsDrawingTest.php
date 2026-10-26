<?php

use App\Enums\StudioImageAnalysisStatus;
use App\Filament\Resources\StudioPlans\Pages\EditStudioPlan;
use App\Livewire\Admin\StudioPlanDiscussionEditor;
use App\Livewire\Admin\StudioPlanDiscussionVotesEditor;
use App\Livewire\Admin\StudioPlanPostVotesEditor;
use App\Livewire\Admin\StudioPlanTextSuggestionsCard;
use App\Models\StudioPlan;
use App\Models\StudioPlanImageAnalysis;
use App\Models\StudioPlanVote;
use App\Models\User;
use Database\Factories\StudioPlanImageAnalysisFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * The Content Studio page keeps every editor of a plan at once — the post's,
 * its votes', the discussion's and its votes', the text suggestions — and
 * each write says the plan's new version to all of them. Each takes it, but
 * draws itself again only when the write changed what it shows: a vote set on
 * the post never sends the whole hidden discussion back to the browser. The
 * Discussion step, drawn once, looks again at the photo's analysis when it
 * comes on screen and while the analysis runs, so Generate tree never waits
 * for a save to notice it is complete.
 */
beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
    [$this->author, $this->anna, $this->boris, $this->carl] = collect(['Author Ada', 'Anna', 'Boris', 'Carl'])
        ->map(fn (string $name): User => User::factory()->persona()->create(['display_name' => $name]))
        ->all();
    $this->plan = StudioPlan::factory()->withImage()->authoredBy($this->author)->create(['title' => 'A quiet harbour', 'created_by_user_id' => $this->admin->id]);
    $saved = saveStudioDiscussion($this->admin, $this->plan, [
        discussionNode(['key' => 'c1', 'personaUserId' => $this->anna->id, 'body' => 'Lovely light.', 'offsetSeconds' => 300]),
        discussionNode(['key' => 'c2', 'personaUserId' => $this->boris->id, 'body' => 'Where is this?', 'sortOrder' => 1, 'offsetSeconds' => 600]),
    ]);
    $this->c1 = $saved->ids['c1'];
    $this->plan->refresh();
});

/** Every editor of the plan on its page, as the page draws them. */
function studioPlanEditors(StudioPlan $plan): array
{
    return [
        'page' => Livewire::test(EditStudioPlan::class, ['record' => $plan->id]),
        'discussion' => discussionEditor($plan),
        'commentVotes' => discussionVotesEditor($plan),
        'postVotes' => postVotesEditor($plan),
        'suggestions' => Livewire::test(StudioPlanTextSuggestionsCard::class, ['planId' => $plan->id, 'imageAssetId' => $plan->image_asset_id]),
    ];
}

/** Says a write of one editor to every editor on the page, and which of them drew themselves again for it. */
function drawnFor(array $editors, StudioPlan $plan, int $previous, string $source): array
{
    $drawn = [];

    foreach ($editors as $name => $editor) {
        $editor->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $plan->id, previous: $previous, current: $previous + 1, source: $source);

        // The suggestions card keeps no version: it only draws the plan as it is.
        if ($name !== 'suggestions') {
            $editor->assertSet('lockVersion', $previous + 1)->assertSet('conflict', false);
        }

        if (drawnAgain($editor)) {
            $drawn[] = $name;
        }
    }

    return $drawn;
}

it('draws no other editor again for a vote set on the post, nor for one on a comment', function () {
    $editors = studioPlanEditors($this->plan);
    $version = $this->plan->lock_version;

    $editors['postVotes']->set('voterCount', 2)->set('postOn', true)->set('up', 1)->assertHasNoErrors();

    expect(drawnAgain($editors['postVotes']))->toBeTrue()
        ->and(drawnFor($editors, $this->plan, $version, StudioPlanPostVotesEditor::SOURCE))->toBe([]);

    $editors['commentVotes']->set('voterCount', 2)->call('setVotes', $this->c1, 'up', 1)->assertHasNoErrors();

    expect(drawnFor($editors, $this->plan, $version + 1, StudioPlanDiscussionVotesEditor::SOURCE))->toBe([]);
});

it('draws again the editors a save of the post, or of the discussion, changes', function () {
    $editors = studioPlanEditors($this->plan);
    $version = $this->plan->lock_version;

    $editors['page']->set('postTitle', 'A harbour at dawn')->call('save')->assertDispatched('rg-admin-toast', message: 'Draft saved');

    expect(drawnFor($editors, $this->plan, $version, 'editor'))->toBe(['discussion', 'commentVotes', 'postVotes', 'suggestions']);

    $editors['discussion']->call('askDelete', $this->c1)->call('confirmDelete')->assertHasNoErrors();

    expect(drawnFor($editors, $this->plan, $version + 1, 'discussion'))->toBe(['commentVotes']);
});

it('draws the page again for another editor\'s write on its Publication step, where it shows the plan as saved', function () {
    $page = Livewire::test(EditStudioPlan::class, ['record' => $this->plan->id])->call('goToStep', 'publication');
    $version = $this->plan->lock_version;
    saveStudioVotes($this->admin, $this->plan, [studioVote(['type' => 'vote_post', 'personaUserId' => $this->carl->id, 'voteType' => 'up', 'offsetSeconds' => 60])]);

    $page->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: $version, current: $version + 1, source: StudioPlanPostVotesEditor::SOURCE)
        ->assertSet('lockVersion', $version + 1);

    expect(drawnAgain($page))->toBeTrue();
});

it('saves a setting the same request carries along with another editor\'s write, once, at the version that write left, and draws it', function () {
    $postVotes = postVotesEditor($this->plan)->set('voterCount', 2)->set('postOn', true)->set('up', 1)->assertHasNoErrors();
    $version = $this->plan->fresh()->lock_version;
    saveStudioDiscussion($this->admin, $this->plan->fresh(), [
        discussionNode(['key' => 'c1', 'personaUserId' => $this->anna->id, 'body' => 'Lovely light, really.', 'offsetSeconds' => 300]),
    ]);

    $postVotes->update(
        calls: [['method' => '__dispatch', 'params' => [StudioPlanDiscussionEditor::VERSION_EVENT, ['planId' => $this->plan->id, 'previous' => $version, 'current' => $version + 1, 'source' => 'discussion']], 'path' => '']],
        updates: ['up' => 2],
    )->assertHasNoErrors()->assertSet('conflict', false)->assertSet('lockVersion', $version + 2)
        ->assertDispatched(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: $version + 1, current: $version + 2, source: StudioPlanPostVotesEditor::SOURCE);

    expect(drawnAgain($postVotes))->toBeTrue()
        ->and(StudioPlanVote::query()->where('studio_plan_id', $this->plan->id)->where('type', 'vote_post')->count())->toBe(2)
        ->and($this->plan->fresh()->lock_version)->toBe($version + 2);
});

it('looks again at the photo\'s analysis when the Discussion step comes on screen, so Generate tree comes on once it is complete', function () {
    configureStudioDiscussionGeneration();
    $analysis = StudioPlanImageAnalysis::factory()->forPlan($this->plan)->running()->create();
    $discussion = discussionEditor($this->plan)->assertSet('waitsForAnalysis', true)
        ->assertSee('The discussion can be written once the photo’s analysis is complete.')
        // On the step, read again every few seconds while the analysis runs.
        ->assertSeeHtml('wire:poll.3s.visible');

    $analysis->forceFill(['status' => StudioImageAnalysisStatus::Completed, 'finished_at' => now(), 'result' => StudioPlanImageAnalysisFactory::description()])->save();

    $discussion->dispatch(StudioPlanDiscussionEditor::SHOWN_EVENT, planId: $this->plan->id)
        ->assertSet('waitsForAnalysis', false)
        ->assertDontSee('The discussion can be written once the photo’s analysis is complete.')
        ->assertDontSeeHtml('wire:poll.3s.visible');

    expect(drawnAgain($discussion))->toBeTrue();

    // Nothing waited for any more: on screen again, nothing is drawn.
    $discussion->dispatch(StudioPlanDiscussionEditor::SHOWN_EVENT, planId: $this->plan->id);

    expect(drawnAgain($discussion))->toBeFalse();
});

it('says the Discussion step is on screen again to the discussion\'s editor only when the step changes to it', function () {
    Livewire::test(EditStudioPlan::class, ['record' => $this->plan->id])
        ->call('goToStep', 'post')->assertNotDispatched(StudioPlanDiscussionEditor::SHOWN_EVENT)
        ->call('goToStep', 'discussion')->assertDispatched(StudioPlanDiscussionEditor::SHOWN_EVENT, planId: $this->plan->id)
        ->call('goToStep', 'discussion')->assertNotDispatched(StudioPlanDiscussionEditor::SHOWN_EVENT)
        ->call('nextStep')->call('previousStep')->assertSet('step', 'discussion')->assertDispatched(StudioPlanDiscussionEditor::SHOWN_EVENT, planId: $this->plan->id);
});
