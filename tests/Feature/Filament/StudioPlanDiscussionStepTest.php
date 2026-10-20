<?php

use App\Filament\Resources\StudioPlans\Pages\CreateStudioPlan;
use App\Filament\Resources\StudioPlans\Pages\EditStudioPlan;
use App\Filament\Resources\StudioPlans\StudioPlanResource;
use App\Livewire\Admin\StudioPlanDiscussionEditor;
use App\Models\StudioPlan;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * The Discussion step inside a publication's editor: one discussion editor for
 * as long as the page is open, kept through every step so nothing typed in it
 * is lost; a new publication asked to save its draft first; and the plan's one
 * version shared by the two saves on the page — each taking the other's move,
 * neither taking somebody else's.
 */
beforeEach(function () {
    Storage::fake('public');
    Queue::fake();
    Http::preventStrayRequests();
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
    $this->persona = studioPersonaWithProfile([], ['display_name' => 'Anna']);
    $this->plan = StudioPlan::factory()->create(['created_by_user_id' => $this->admin->id, 'title' => 'A quiet harbour']);
});

/** The id the page gave its discussion editor, or null when it has none. */
function discussionChildId(Testable $page, StudioPlan $plan): ?string
{
    return $page->snapshot['memo']['children']['studio-plan-discussion-'.$plan->id][1] ?? null;
}

/** Whether the page draws its Discussion step hidden. */
function discussionStepHidden(Testable $page): bool
{
    preg_match('/<div class="rg-admin-studio__discussion-step"([^>]*)>/', $page->html(), $wrapper);

    return str_contains($wrapper[1] ?? '', 'hidden');
}

/** Saves one new comment from the discussion editor. */
function savedOneComment(Testable $editor, User $persona, string $body = 'Lovely light.'): Testable
{
    return $editor->call('addComment')->call('choosePersona', 'k1', $persona->id)->set('nodes.k1.body', $body)->call('save')->assertHasNoErrors();
}

it('keeps one discussion editor through every step, drawn hidden off its own step', function () {
    $page = Livewire::test(EditStudioPlan::class, ['record' => $this->plan->id])->assertSet('step', 'image');
    $child = discussionChildId($page, $this->plan);

    expect($child)->not->toBeNull()->and(discussionStepHidden($page))->toBeTrue();

    foreach (['post', 'discussion', 'publication', 'discussion', 'image', 'discussion'] as $step) {
        $page->call('goToStep', $step)->assertSeeHtml('wire:name="admin.studio-plan-discussion-editor" wire:key="studio-plan-discussion-'.$this->plan->id.'"');

        expect(discussionChildId($page, $this->plan))->toBe($child)
            ->and(discussionStepHidden($page))->toBe($step !== 'discussion');
    }
});

it('asks a new publication to save its draft first, and offers the discussion once a photo has made the plan', function () {
    $page = Livewire::test(CreateStudioPlan::class)->call('goToStep', 'discussion')
        ->assertSee('Save the draft first to prepare its discussion.')
        ->assertDontSeeHtml('wire:name="admin.studio-plan-discussion-editor"');

    $page->upload('photo', [UploadedFile::fake()->image('harbour.jpg', 64, 64)])->assertHasNoErrors();
    $plan = StudioPlan::query()->latest('id')->firstOrFail();

    $page->assertSet('planId', $plan->id)->assertSet('step', 'discussion')
        ->assertDontSee('Save the draft first to prepare its discussion.')
        ->assertSeeLivewire(StudioPlanDiscussionEditor::class);

    expect(discussionChildId($page, $plan))->not->toBeNull();
});

it('warns before leaving with unsaved changes to the post or the discussion, saved each on its own', function () {
    $html = $this->get(StudioPlanResource::getUrl('edit', ['record' => $this->plan]))->assertOk()->getContent();

    expect($html)
        ->toContain('rgAdminEditorChanges(')
        ->toContain('$store.adminEditor?.discussionDirty')
        ->toContain('$store.adminEditor.discussionDirty = JSON.stringify($wire.nodes) !== JSON.stringify($wire.savedNodes)')
        ->toContain('Save draft in the top bar saves the post and its publication, not this discussion');
});

it('moves the version once for a discussion save, which the post editor takes, so its next Save draft is no conflict', function () {
    $page = Livewire::test(EditStudioPlan::class, ['record' => $this->plan->id])->set('postTitle', 'A harbour at dawn');
    $editor = discussionEditor($this->plan)->call('addComment')->call('addComment');
    foreach (['k1', 'k2'] as $key) {
        $editor->call('choosePersona', $key, $this->persona->id)->set("nodes.{$key}.body", "Comment {$key}.");
    }

    $editor->call('save')->assertHasNoErrors()
        ->assertDispatched(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 1, current: 2, source: 'discussion');
    expect($this->plan->fresh()->lock_version)->toBe(2);

    $page->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 1, current: 2, source: 'discussion')
        ->assertSet('lockVersion', 2)->assertSet('stale', false)
        // What was typed and unsaved stays as typed.
        ->assertSet('postTitle', 'A harbour at dawn');

    $page->call('save')->assertSet('stale', false)
        ->assertDispatched('rg-admin-toast', message: 'Draft saved')
        ->assertDispatched(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 2, current: 3, source: 'editor');

    expect($this->plan->fresh())->title->toBe('A harbour at dawn')->lock_version->toBe(3)
        ->and($this->plan->discussionNodes()->count())->toBe(2);
});

it('takes the version a Save draft moved in the discussion editor, so its next save is no conflict', function () {
    $editor = discussionEditor($this->plan);

    Livewire::test(EditStudioPlan::class, ['record' => $this->plan->id])->set('postTitle', 'A harbour at dawn')->call('save')
        ->assertDispatched(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 1, current: 2, source: 'editor');

    $editor->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 1, current: 2, source: 'editor')
        ->assertSet('lockVersion', 2)->assertSet('conflict', false);

    savedOneComment($editor, $this->persona)->assertSet('conflict', false)->assertSet('lockVersion', 3);

    expect($this->plan->fresh()->lock_version)->toBe(3);
});

it('tells the discussion editor of a photo kept or removed', function () {
    $page = Livewire::test(EditStudioPlan::class, ['record' => $this->plan->id])
        ->upload('photo', [UploadedFile::fake()->image('harbour.jpg', 64, 64)])
        ->assertDispatched(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 1, current: 2, source: 'editor');

    $page->call('removePhoto')
        ->assertDispatched(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 2, current: 3, source: 'editor');

    discussionEditor($this->plan)->assertSet('lockVersion', 3);
});

it('takes no version written elsewhere: the post editor says the draft changed, and Reload draws it', function (int $previous, int $current) {
    $page = Livewire::test(EditStudioPlan::class, ['record' => $this->plan->id])->set('postTitle', 'Typed');

    $page->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: $previous, current: $current, source: 'discussion')
        ->assertSet('stale', true)->assertSet('lockVersion', 1)->assertSet('postTitle', 'Typed')
        ->assertSee('Someone saved this draft after you opened it.');

    $this->plan->forceFill(['lock_version' => 3])->save();

    $page->call('reload')->assertSet('stale', false)->assertSet('lockVersion', 3)->assertSet('postTitle', 'A quiet harbour');
})->with([
    'a version skipped' => [1, 3],
    'a save from another version' => [2, 3],
]);

it('ignores what is not its own plan\'s discussion, or a move it already holds', function (Closure $event) {
    Livewire::test(EditStudioPlan::class, ['record' => $this->plan->id])
        ->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, ...$event($this->plan))
        ->assertSet('stale', false)->assertSet('lockVersion', 1);
})->with([
    'its own save' => [fn (StudioPlan $plan) => ['planId' => $plan->id, 'previous' => 1, 'current' => 2, 'source' => 'editor']],
    'another plan' => [fn (StudioPlan $plan) => ['planId' => $plan->id + 1, 'previous' => 1, 'current' => 2, 'source' => 'discussion']],
    'the version it holds' => [fn (StudioPlan $plan) => ['planId' => $plan->id, 'previous' => 0, 'current' => 1, 'source' => 'discussion']],
    'no numbers' => [fn (StudioPlan $plan) => ['planId' => $plan->id, 'previous' => 'one', 'current' => 'two', 'source' => 'discussion']],
]);

it('leaves the discussion as it is on Discard of the post, and the post on Discard of the discussion', function () {
    savedOneComment(discussionEditor($this->plan), $this->persona);
    $page = Livewire::test(EditStudioPlan::class, ['record' => $this->plan->id])->set('postTitle', 'Typed');
    $editor = discussionEditor($this->plan)->set('nodes.n'.$this->plan->discussionNodes()->sole()->id.'.body', 'Changed.');

    $page->call('discard')->assertSet('postTitle', 'A quiet harbour');
    expect(array_column($editor->get('nodes'), 'body'))->toBe(['Changed.']);

    $page->set('postTitle', 'Typed again');
    $editor->call('discardChanges');
    $page->assertSet('postTitle', 'Typed again');
    expect(array_column($editor->get('nodes'), 'body'))->toBe(['Lovely light.']);
});
