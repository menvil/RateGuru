<?php

use App\Enums\StudioPlanApprovalRejection;
use App\Exceptions\Studio\CannotApproveStudioPlanException;
use App\Filament\Resources\StudioPlans\Pages\CreateStudioPlan;
use App\Filament\Resources\StudioPlans\Pages\EditStudioPlan;
use App\Livewire\Admin\StudioPlanDiscussionEditor;
use App\Livewire\Admin\StudioPlanPostVotesEditor;
use App\Models\StudioAction;
use App\Models\StudioPlan;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * The Post step's two tabs, Titles & texts and Votes, inside a publication's
 * editor: one votes editor for as long as the page is open, kept through
 * every step and tab; a new publication asked to save its draft first; and
 * the plan's one version shared by every write on the page — the post's,
 * the photo's, the discussion's and the votes' — each taking the others'
 * moves and none taking somebody else's.
 */
beforeEach(function () {
    Storage::fake('public');
    Queue::fake();
    Http::preventStrayRequests();
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
    $this->plan = StudioPlan::factory()->authoredBy(User::factory()->persona()->create())->create(['created_by_user_id' => $this->admin->id, 'title' => 'A quiet harbour']);
    User::factory()->persona()->count(3)->create();
});

/** The id the page gave its votes editor, or null when it has none. */
function postVotesChildId(Testable $page, StudioPlan $plan): ?string
{
    return $page->snapshot['memo']['children']['studio-plan-post-votes-'.$plan->id][1] ?? null;
}

/** Whether the page draws this wrapper of its step body hidden. */
function studioWrapperHidden(Testable $page, string $class): bool
{
    preg_match('/<div class="'.preg_quote($class, '/').'"([^>]*)>/', $page->html(), $wrapper);

    return str_contains($wrapper[1] ?? '', 'hidden');
}

/** Votes on the plan's post, made and saved as the Votes tab does. */
function savedPostVotesFromEditor(StudioPlan $plan): Testable
{
    return postVotesEditor($plan)->set('postOn', true)->set('up', 2)->set('voterCount', 2)->call('build', false)->call('save', false)->assertHasNoErrors();
}

it('gives the Post step two tabs, Titles & texts and Votes, which Continue and Back go through in turn', function () {
    Livewire::test(EditStudioPlan::class, ['record' => $this->plan->id])
        ->call('goToStep', 'post')
        ->assertSeeHtml('aria-pressed="true"')->assertSee('Titles & texts')->assertSee('Votes')
        ->assertSee('Step 2 of 4 · Post · Titles & texts')
        ->call('nextStep')->assertSet('step', 'post')->assertSet('postTab', 'votes')->assertSee('Step 2 of 4 · Post · Votes')
        ->call('nextStep')->assertSet('step', 'discussion')
        ->call('previousStep')->assertSet('step', 'post')->assertSet('postTab', 'votes')
        ->call('previousStep')->assertSet('step', 'post')->assertSet('postTab', 'texts')
        ->call('previousStep')->assertSet('step', 'image')
        ->call('showPostTab', 'somewhere')->assertSet('postTab', 'texts')
        ->call('showPostTab', 'votes')->assertSet('postTab', 'votes');
});

it('keeps one votes editor through every step and tab, drawn hidden but on the Votes tab, and loses nothing typed', function () {
    $page = Livewire::test(EditStudioPlan::class, ['record' => $this->plan->id])->set('postTitle', 'Typed, not saved');
    $child = postVotesChildId($page, $this->plan);

    expect($child)->not->toBeNull()->and(studioWrapperHidden($page, 'rg-admin-studio__votes-step'))->toBeTrue();

    foreach ([['post', 'texts'], ['post', 'votes'], ['discussion', 'votes'], ['image', 'votes'], ['post', 'votes'], ['post', 'texts']] as [$step, $tab]) {
        $page->call('goToStep', $step)->call('showPostTab', $tab);

        expect(postVotesChildId($page, $this->plan))->toBe($child)
            ->and(studioWrapperHidden($page, 'rg-admin-studio__votes-step'))->toBe(! ($step === 'post' && $tab === 'votes'));

        if ($step === 'post') {
            expect(studioWrapperHidden($page, 'rg-admin-studio__post-texts'))->toBe($tab !== 'texts');
        }
    }

    $page->assertSet('postTitle', 'Typed, not saved');
    expect($this->plan->fresh()->lock_version)->toBe(1);
});

it('asks a new publication to save its draft first, and offers the votes once a photo has made the plan', function () {
    $page = Livewire::test(CreateStudioPlan::class)->call('goToStep', 'post')->call('showPostTab', 'votes')
        ->assertSee('Save the draft first to prepare its votes.')
        ->assertDontSeeHtml('wire:name="admin.studio-plan-post-votes-editor"');

    $page->upload('photo', [UploadedFile::fake()->image('harbour.jpg', 64, 64)])->assertHasNoErrors();
    $plan = StudioPlan::query()->latest('id')->firstOrFail();

    $page->assertSet('planId', $plan->id)
        ->assertDontSee('Save the draft first to prepare its votes.')
        ->assertSeeLivewire(StudioPlanPostVotesEditor::class);

    expect(postVotesChildId($page, $plan))->not->toBeNull();
});

it('tells the votes editor of a post\'s unsaved author, from the draft the browser compares', function () {
    $html = Livewire::test(EditStudioPlan::class, ['record' => $this->plan->id])->html();

    expect($html)->toContain('$store.adminEditor.authorChanged = String($wire.authorUserId ?? \'\') !== String($wire.saved?.authorUserId ?? \'\')');
});

it('moves the version once for a votes save, which the post and discussion editors take, so their next saves are no conflict', function () {
    $page = Livewire::test(EditStudioPlan::class, ['record' => $this->plan->id])->set('postTitle', 'A harbour at dawn');
    $discussion = discussionEditor($this->plan);

    savedPostVotesFromEditor($this->plan)
        ->assertDispatched(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 1, current: 2, source: StudioPlanPostVotesEditor::SOURCE);

    $page->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 1, current: 2, source: StudioPlanPostVotesEditor::SOURCE)
        ->assertSet('lockVersion', 2)->assertSet('stale', false)->assertSet('postTitle', 'A harbour at dawn');
    $discussion->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 1, current: 2, source: StudioPlanPostVotesEditor::SOURCE)
        ->assertSet('lockVersion', 2)->assertSet('conflict', false);

    $page->call('save')->assertSet('stale', false)->assertDispatched('rg-admin-toast', message: 'Draft saved');

    expect($this->plan->fresh())->title->toBe('A harbour at dawn')->lock_version->toBe(3);
});

it('takes the version a Save draft or a photo moved in the votes editor, so its next save is no conflict', function () {
    $votes = postVotesEditor($this->plan);

    Livewire::test(EditStudioPlan::class, ['record' => $this->plan->id])->set('postTitle', 'A harbour at dawn')->call('save')
        ->assertDispatched(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 1, current: 2, source: 'editor');

    $votes->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 1, current: 2, source: 'editor')
        ->assertSet('lockVersion', 2)->assertSet('conflict', false)
        ->set('postOn', true)->set('up', 1)->call('build', false)->call('save', false)->assertHasNoErrors()->assertSet('lockVersion', 3);
});

it('reads the author a Save draft gave the post: never a voter, and a preview made before it is out of date', function () {
    $votes = postVotesEditor($this->plan)->set('voterMode', 'manual')->set('postOn', true)->set('up', 1);
    $newAuthor = User::factory()->persona()->create();
    $votes->call('toggleVoter', $newAuthor->id)->call('build', false)->assertHasNoErrors();

    Livewire::test(EditStudioPlan::class, ['record' => $this->plan->id])->set('authorUserId', (string) $newAuthor->id)->call('save');

    $votes->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 1, current: 2, source: 'editor')
        ->assertSet('authorId', $newAuthor->id)
        ->assertSee('The settings changed since this preview. Rebuild it to save.')
        ->call('build', false)->assertHasErrors(['post']);
});

it('draws the votes again when the post editor is reloaded at a version whose votes changed', function () {
    $votes = postVotesEditor($this->plan);
    savedPostVotesFromEditor($this->plan);

    Livewire::test(EditStudioPlan::class, ['record' => $this->plan->id])->call('reload')
        ->assertDispatched(StudioPlanDiscussionEditor::RELOAD_EVENT, planId: $this->plan->id, current: 2, source: 'editor');

    $votes->dispatch(StudioPlanDiscussionEditor::RELOAD_EVENT, planId: $this->plan->id, current: 2, source: 'editor')
        ->assertSet('lockVersion', 2)->assertSet('up', 2)->assertSet('postOn', true)->assertSet('conflict', false);
});

it('keeps a plan with prepared votes from being approved, and approves one without them as before', function () {
    $plan = approvableStudioPlan(['created_by_user_id' => $this->admin->id]);
    savedPostVotesFromEditor($plan);

    try {
        approveStudioPlan($this->admin, $plan);
        $refusal = null;
    } catch (CannotApproveStudioPlanException $exception) {
        $refusal = $exception;
    }

    expect($refusal?->rejection)->toBe(StudioPlanApprovalRejection::UnsupportedVotes)
        ->and(StudioAction::query()->exists())->toBeFalse();

    postVotesEditor($plan)->call('askRemove')->call('confirmRemove');
    approveStudioPlan($this->admin, $plan->fresh());

    expect(StudioAction::query()->where('studio_plan_id', $plan->id)->count())->toBe(1);
});
