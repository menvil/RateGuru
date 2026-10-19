<?php

use App\Actions\Studio\UpdateStudioPlanAction;
use App\Data\Studio\StudioPlanData;
use App\Enums\StudioPlanStatus;
use App\Filament\Resources\StudioPlans\Pages\EditStudioPlan;
use App\Filament\Resources\StudioPlans\Pages\ListStudioPlans;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\StudioPlan;
use App\Models\User;
use Livewire\Livewire;

/**
 * Delete draft, from the list and from the editor: asked first, through
 * DeleteStudioPlanDraftAction only, the photo released to the media
 * lifecycle; never for a plan past its draft, never over somebody else's
 * newer save, and harmless when the draft is already gone.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

it('asks first, and Cancel changes nothing', function () {
    $plan = StudioPlan::factory()->create(['title' => 'Harbour']);

    Livewire::test(ListStudioPlans::class)
        ->call('askToDelete', $plan->id, $plan->lock_version)
        ->assertSet('confirmingId', $plan->id)
        ->assertSee('Delete this draft?')
        ->assertSee('The draft will be removed. Its image will be released if nothing else uses it.')
        ->assertDontSee('permanently')
        ->call('closeConfirmation')
        ->assertSet('confirmingId', null)
        ->assertDontSee('Delete this draft?');

    expect($plan->fresh())->not->toBeNull();
});

it('deletes a draft from the list, releasing its photo and touching no persona or post', function () {
    $persona = User::factory()->persona()->create();
    $post = Post::factory()->published()->for($persona)->create();
    $plan = StudioPlan::factory()->authoredBy($persona)->withImage()->create(['title' => 'Harbour']);
    $assetId = $plan->image_asset_id;

    Livewire::test(ListStudioPlans::class)
        ->call('askToDelete', $plan->id, $plan->lock_version)
        ->call('delete')
        ->assertDispatched('rg-admin-toast', message: 'Harbour deleted', tone: 'success')
        ->assertSet('confirmingId', null)
        ->assertDontSee('Harbour');

    expect(StudioPlan::query()->find($plan->id))->toBeNull()
        ->and(MediaAsset::withTrashed()->find($assetId)->trashed())->toBeTrue()
        ->and($persona->fresh()->isPersona())->toBeTrue()
        ->and(Post::query()->whereKey($post->id)->exists())->toBeTrue();
});

it('offers no deletion for a plan past its draft, and refuses one asked for by hand', function () {
    $plan = StudioPlan::factory()->status(StudioPlanStatus::Approved)->create();

    Livewire::test(ListStudioPlans::class)
        ->call('askToDelete', $plan->id, $plan->lock_version)
        ->assertSet('confirmingId', null)
        ->assertDispatched('rg-admin-toast', message: 'Only a draft can be deleted.', tone: 'info');

    expect($plan->fresh())->not->toBeNull();
});

it('asks about no draft somebody saved after the list was drawn, and deletes nothing', function () {
    $plan = StudioPlan::factory()->create(['title' => 'Harbour']);
    // The list is drawn here: its row shows version 1.
    $page = Livewire::test(ListStudioPlans::class);
    $shown = listedRow($page, $plan)['version'];

    app(UpdateStudioPlanAction::class)->handle(User::factory()->admin()->create(), $plan, new StudioPlanData(title: 'Harbour, edited'), 1);

    // Delete pressed on the row as it was drawn.
    $page->call('askToDelete', $plan->id, $shown)
        ->assertSet('confirmingId', null)
        ->assertSet('confirmingVersion', null)
        ->assertDispatched('rg-admin-toast', message: 'Someone changed this draft since the list was drawn. Refresh the list and check it before deleting.', tone: 'error')
        ->assertDontSee('Delete this draft?')
        // Nothing was asked, so nothing is deleted however the confirmation is reached.
        ->call('delete');

    expect($shown)->toBe(1)
        ->and($plan->fresh())->not->toBeNull()
        ->and($plan->fresh()->title)->toBe('Harbour, edited');
});

it('deletes nothing when somebody saves the draft while its deletion is being confirmed', function () {
    $plan = StudioPlan::factory()->create(['title' => 'Harbour']);
    $page = Livewire::test(ListStudioPlans::class);
    $page->call('askToDelete', $plan->id, listedRow($page, $plan)['version'])
        ->assertSet('confirmingVersion', 1)
        ->assertSee('Delete this draft?');

    app(UpdateStudioPlanAction::class)->handle(User::factory()->admin()->create(), $plan, new StudioPlanData(title: 'Harbour, edited'), 1);

    $page->call('delete')
        ->assertDispatched('rg-admin-toast', message: 'Someone changed this draft since the list was drawn, so it wasn’t deleted. Check it and try again.', tone: 'error');

    expect($plan->fresh())->not->toBeNull()
        ->and($plan->fresh()->title)->toBe('Harbour, edited');
});

it('asks about no version it is not shown', function (mixed $version) {
    $plan = StudioPlan::factory()->create();

    Livewire::test(ListStudioPlans::class)
        ->call('askToDelete', $plan->id, $version)
        ->assertSet('confirmingId', null);

    expect($plan->fresh())->not->toBeNull();
})->with([
    'a later version' => [2],
    'no version at all' => [null],
    'something that is no number' => ['latest'],
]);

it('says a draft is already gone rather than failing, however it was asked', function () {
    $plan = StudioPlan::factory()->withImage()->create();
    $page = Livewire::test(ListStudioPlans::class)->call('askToDelete', $plan->id, $plan->lock_version);
    $other = Livewire::test(ListStudioPlans::class)->call('askToDelete', $plan->id, $plan->lock_version);

    $page->call('delete')->assertSuccessful();
    $released = MediaAsset::withTrashed()->findOrFail($plan->image_asset_id)->deleted_at;

    $other->call('delete')
        ->assertSuccessful()
        ->assertDispatched('rg-admin-toast', message: 'This draft was already deleted.', tone: 'info');
    Livewire::test(ListStudioPlans::class)
        ->call('askToDelete', $plan->id, $plan->lock_version)
        ->assertDispatched('rg-admin-toast', message: 'This draft was already deleted.', tone: 'info');

    expect(MediaAsset::withTrashed()->findOrFail($plan->image_asset_id)->deleted_at->equalTo($released))->toBeTrue();
});

it('deletes a draft from its editor, then shows the list with no link to it', function () {
    $plan = StudioPlan::factory()->create(['title' => 'Harbour']);

    Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->set('postTitle', 'Unsaved')
        ->call('askToDelete')
        ->assertSee('Delete this draft?')
        ->call('delete')
        ->assertRedirect(ListStudioPlans::getUrl());

    expect(StudioPlan::query()->find($plan->id))->toBeNull()
        ->and(session('rg-admin-toast'))->toBe(['message' => 'Harbour deleted', 'tone' => 'success']);

    $this->get(ListStudioPlans::getUrl())->assertOk()
        ->assertDontSee(EditStudioPlan::getUrl(['record' => $plan->id]));
    $this->get(EditStudioPlan::getUrl(['record' => $plan->id]))->assertNotFound();
});

it('deletes nothing from an editor drawn before somebody else\'s save, and from one whose draft is gone', function () {
    $plan = StudioPlan::factory()->create(['title' => 'Harbour']);
    $stale = Livewire::test(EditStudioPlan::class, ['record' => $plan->id]);
    $late = Livewire::test(EditStudioPlan::class, ['record' => $plan->id]);

    app(UpdateStudioPlanAction::class)->handle(User::factory()->admin()->create(), $plan, new StudioPlanData(title: 'Edited'), 1);

    $stale->call('askToDelete')->call('delete')
        ->assertSet('stale', true)
        ->assertNoRedirect()
        ->assertDispatched('rg-admin-toast', message: 'Someone saved this draft after you opened it, so it wasn’t deleted.', tone: 'error');

    expect($plan->fresh())->not->toBeNull();

    StudioPlan::query()->whereKey($plan->id)->delete();

    $late->call('askToDelete')->call('delete')
        ->assertSuccessful()
        ->assertSet('gone', true)
        ->assertDispatched('rg-admin-toast', message: 'This draft was already deleted.', tone: 'info');
});
