<?php

use App\Filament\Pages\TranslationCenterPage;
use App\Filament\Resources\RatingGroups\Pages\EditRatingGroup;
use App\Filament\Resources\RatingGroups\Pages\ManageRatingGroups;
use App\Filament\Resources\RatingGroups\RatingGroupResource;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\RatingVote;
use App\Models\User;
use Livewire\Livewire;

/**
 * Rating groups in Admin v2: every group in the order post pages show them,
 * with its options, votes and active range; activated with Undo when its
 * range holds its active options, explained when it does not; deactivated
 * after a warning; never deleted.
 */
beforeEach(function () {
    ProjectSettings::factory()->create();
    $this->actingAs(User::factory()->admin()->create());
});

/** A group with this many active options, and optionally inactive and archived ones. */
function ratingGroupWith(int $active, array $attributes = [], int $inactive = 0, int $archived = 0): RatingGroup
{
    $group = RatingGroup::factory()->create($attributes);
    RatingOption::factory()->count($active)->for($group, 'group')->create(['is_active' => true]);
    RatingOption::factory()->count($inactive)->for($group, 'group')->create(['is_active' => false]);
    RatingOption::factory()->count($archived)->for($group, 'group')->create(['is_active' => false, 'archived_at' => now()]);

    return $group;
}

it('allows only admins to manage rating groups', function () {
    $this->get(RatingGroupResource::getUrl('index'))->assertOk();

    $this->actingAs(User::factory()->moderator()->create())->get(RatingGroupResource::getUrl('index'))->assertForbidden();
    $this->actingAs(User::factory()->create())->get(RatingGroupResource::getUrl('index'))->assertForbidden();
});

it('draws the screen in Admin v2, with Create group in the top bar', function () {
    $html = $this->get(ManageRatingGroups::getUrl())->assertOk()->getContent();

    expect($html)->toContain('<h1 id="rg-admin-rating-groups-title" class="rg-admin-page-header__title">Rating groups</h1>')
        ->toContain('Create group')
        ->toContain('href="'.e(RatingGroupResource::getUrl('create')).'"')
        ->not->toContain('fi-ta');
});

it('lists every group in the order post pages show them, its options by state, its votes and its range', function () {
    $photo = ratingGroupWith(3, ['label' => 'Photo', 'sort_order' => 2, 'min_options' => 3, 'max_options' => 5], archived: 1);
    $cuteness = ratingGroupWith(4, ['label' => 'Cuteness', 'sort_order' => 1, 'min_options' => 2, 'max_options' => 4]);
    $tricks = ratingGroupWith(1, ['label' => 'Tricks', 'sort_order' => 3, 'min_options' => 2, 'max_options' => 4, 'is_active' => false], inactive: 1);
    RatingVote::factory()->count(2)->create(['rating_option_id' => $cuteness->options()->first()->id]);

    $page = Livewire::test(ManageRatingGroups::class);
    $rows = $page->viewData('rows');

    expect(array_column(array_values($rows), 'label'))->toBe(['Cuteness', 'Photo', 'Tricks'])
        ->and($rows[$cuteness->id])->toMatchArray(['activeOptions' => 4, 'votes' => 2, 'valid' => true])
        ->and($rows[$photo->id])->toMatchArray(['activeOptions' => 3, 'archivedOptions' => 1, 'inactiveOptions' => 0])
        ->and($rows[$tricks->id])->toMatchArray(['activeOptions' => 1, 'inactiveOptions' => 1, 'valid' => false])
        ->and($page->viewData('stats'))->toBe(['groups' => 2, 'options' => 7, 'votes' => 2])
        // One slot past the widest range, for every row alike.
        ->and($page->viewData('slotCount'))->toBe(6);

    $page->assertSeeHtml('2–4 allowed · at maximum')
        ->assertSeeHtml('2–4 allowed · needs 1 more')
        ->assertSeeHtml('1 inactive')
        ->assertSeeHtml('aria-label="1 active, 2 to 4 allowed"');
});

it('activates a group whose range holds its options, with Undo', function () {
    $group = ratingGroupWith(2, ['label' => 'Vibe', 'is_active' => false, 'min_options' => 2, 'max_options' => 4]);

    $page = Livewire::test(ManageRatingGroups::class)
        ->call('activate', $group->id)
        ->assertDispatched('rg-admin-toast', message: 'Vibe activated', action: ['label' => 'Undo', 'event' => 'deactivate-rating-group', 'params' => ['id' => $group->id, 'undo' => true]]);

    expect($group->fresh()->is_active)->toBeTrue();

    $page->dispatch('deactivate-rating-group', id: $group->id, undo: true)
        ->assertDispatched('rg-admin-toast', message: 'Vibe is inactive again', action: null);

    expect($group->fresh()->is_active)->toBeFalse();
});

it('explains why a group cannot be activated yet, and opens its editor', function () {
    $group = ratingGroupWith(1, ['label' => 'Tricks', 'is_active' => false, 'min_options' => 3, 'max_options' => 4]);

    Livewire::test(ManageRatingGroups::class)
        ->assertSeeHtml('Needs 2 more active options')
        ->call('activate', $group->id)
        ->assertSet('confirming', 'blocked')
        ->assertSeeHtml('Tricks can’t be activated yet')
        ->assertSeeHtml('An active group must have between 3 and 4 active options. Tricks has 1.')
        ->assertSeeHtml('Activate or create 2 more options.')
        ->assertDontSeeHtml('lower the minimum')
        ->assertSeeHtml('href="'.e(EditRatingGroup::getUrl(['record' => $group->id])).'"');

    expect($group->fresh()->is_active)->toBeFalse();
});

it('offers to lower the minimum when the group has enough options for one', function () {
    $group = ratingGroupWith(2, ['label' => 'Tricks', 'is_active' => false, 'min_options' => 3, 'max_options' => 4]);

    Livewire::test(ManageRatingGroups::class)
        ->call('activate', $group->id)
        ->assertSeeHtml('Activate or create 1 more option, or')
        ->assertSeeHtml('lower the minimum to 2 in the group settings.');
});

it('asks before deactivating a group, then keeps its votes', function () {
    $group = ratingGroupWith(2, ['label' => 'Cuteness']);
    RatingVote::factory()->count(3)->create(['rating_option_id' => $group->options()->first()->id]);

    Livewire::test(ManageRatingGroups::class)
        ->call('askToDeactivate', $group->id)
        ->assertSet('confirming', 'deactivate')
        ->assertSeeHtml('Deactivate Cuteness?')
        ->assertSeeHtml('Its 3 votes stay stored and come back if you activate it again.')
        ->call('deactivate', $group->id)
        ->assertSet('confirming', null)
        ->assertDispatched('rg-admin-toast', message: 'Cuteness deactivated', action: ['label' => 'Undo', 'event' => 'activate-rating-group', 'params' => ['id' => $group->id, 'undo' => true]]);

    expect($group->fresh()->is_active)->toBeFalse()
        ->and(RatingVote::query()->count())->toBe(3);
});

it('keeps Delete in the menu, unavailable: a group is never deleted', function () {
    $group = ratingGroupWith(2);

    $item = livewireFragment(Livewire::test(ManageRatingGroups::class), "//*[@id='rg-admin-rating-group-{$group->id}-actions-menu']//*[@role='menuitem'][last()]");

    expect($item)->toContain('aria-disabled="true"')->toContain('Delete')->toContain('Groups are kept · deactivate instead')->not->toContain('wire:click');
});

it('translates an active group in Translation Center, on what it is missing', function () {
    [$first, $second] = twoTranslatedLocales();
    offerLocales([$first, $second]);
    $group = ratingGroupWith(2, ['description' => 'How cute?', 'label_translations' => [$first => 'A', $second => 'B'], 'description_translations' => [$first => 'C']]);
    $inactive = ratingGroupWith(2, ['is_active' => false]);

    $rows = Livewire::test(ManageRatingGroups::class)->assertSeeHtml('Inactive · activate it to translate')->viewData('rows');

    expect($rows[$group->id]['translate'])->toBe(TranslationCenterPage::getUrl(['locale' => $second, 'section' => 'rating_groups', 'unit' => "rating_groups:{$group->id}:description"]))
        ->and($rows[$inactive->id]['translate'])->toBeNull();
});

it('refuses what names no group', function (string $method) {
    Livewire::test(ManageRatingGroups::class)
        ->call($method, 999999)
        ->assertDispatched('rg-admin-toast', message: 'That rating group no longer exists.', tone: 'error');
})->with(['activate', 'askToDeactivate', 'deactivate']);

it('opens a group found by global search in its editor', function () {
    $group = RatingGroup::factory()->create();

    expect(RatingGroupResource::getGlobalSearchResultUrl($group))->toBe(EditRatingGroup::getUrl(['record' => $group]));
});
