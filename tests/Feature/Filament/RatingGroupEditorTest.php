<?php

use App\Filament\Pages\TranslationCenterPage;
use App\Filament\Resources\RatingGroups\Pages\CreateRatingGroup;
use App\Filament\Resources\RatingGroups\Pages\EditRatingGroup;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\User;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * A rating group's own page: created with a key made from its label and
 * fixed afterwards, starting inactive; its settings saved from the top bar,
 * with the range checked against the group's active options as they are
 * typed; its translations, read-only.
 */
beforeEach(function () {
    ProjectSettings::factory()->create();
    $this->actingAs(User::factory()->admin()->create());
});

/** The editor of a group with this many active options. */
function groupEditor(int $active = 3, array $attributes = []): Testable
{
    $group = RatingGroup::factory()->create(['min_options' => 2, 'max_options' => 4, ...$attributes]);
    RatingOption::factory()->count($active)->for($group, 'group')->create(['is_active' => true]);

    return Livewire::test(EditRatingGroup::class, ['record' => $group->id]);
}

// One request per test: Filament reads the page's scope, the top bar's actions
// included, from the request the page was first rendered for.
it('draws a new group in Admin v2, with Create group in the top bar', function () {
    expect($this->get(CreateRatingGroup::getUrl())->assertOk()->getContent())
        ->toContain('New rating group')
        ->toContain("\$dispatch('save-rating-group')")
        ->toContain('Create group')
        ->not->toContain('fi-section');
});

it('draws a group in Admin v2, with Save group and its unsaved state in the top bar', function () {
    $group = RatingGroup::factory()->create(['label' => 'Cuteness']);

    expect($this->get(EditRatingGroup::getUrl(['record' => $group->id]))->assertOk()->getContent())
        ->toContain('<h1 id="rg-admin-rating-group-title" class="rg-admin-page-header__title">Cuteness</h1>')
        ->toContain("\$dispatch('discard-rating-group')")
        ->toContain('Save group')
        ->toContain('Unsaved group changes')
        ->not->toContain('fi-section');
});

it('makes a new group\'s key from its label until one is typed', function () {
    Livewire::test(CreateRatingGroup::class)
        ->set('draftLabel', 'Real or Fake?')
        ->assertSet('draftKey', 'real_or_fake')
        ->set('draftKey', 'authenticity')
        ->assertSet('keyGenerated', false)
        ->set('draftLabel', 'Real or fake')
        ->assertSet('draftKey', 'authenticity');
});

it('creates a group inactive, last on post pages, and opens its editor', function () {
    RatingGroup::factory()->create(['sort_order' => 7]);

    $page = Livewire::test(CreateRatingGroup::class)
        ->assertSet('draftSort', 8)
        ->set('draftLabel', 'Real or fake')
        ->set('draftDescription', 'Is the photo real?')
        ->set('draftMin', 2)
        ->set('draftMax', 3)
        // Forged: a new group has no options, so it cannot start active.
        ->set('draftActive', true)
        ->dispatch('save-rating-group')
        ->assertHasNoErrors();

    $group = RatingGroup::query()->where('key', 'real_or_fake')->firstOrFail();

    expect($group)->label->toBe('Real or fake')->description->toBe('Is the photo real?')->min_options->toBe(2)->max_options->toBe(3)->is_active->toBeFalse()->sort_order->toBe(8)
        ->and(session('rg-admin-toast'))->toBe('Real or fake created. Add its options, then activate it.');

    $page->assertRedirect(EditRatingGroup::getUrl(['record' => $group->id]));
});

it('says what to do when a new group cannot be saved', function (array $draft, string $field, string $message) {
    RatingGroup::factory()->create(['key' => 'existing_group']);

    $page = Livewire::test(CreateRatingGroup::class);

    foreach ($draft as $property => $value) {
        $page->set($property, $value);
    }

    $page->dispatch('save-rating-group')->assertHasErrors([$field]);

    expect($page->errors()->first($field))->toBe($message)
        ->and(RatingGroup::query()->count())->toBe(1);
})->with([
    'no label' => [['draftLabel' => '', 'draftKey' => 'cuteness'], 'draftLabel', 'Enter a label.'],
    'a key in use' => [['draftLabel' => 'Existing', 'draftKey' => 'existing_group'], 'draftKey', 'This key is already used by another group. Choose another one.'],
    'a key that is not lowercase with underscores' => [['draftLabel' => 'Group', 'draftKey' => 'Existing group'], 'draftKey', 'Use lowercase letters and numbers, with single underscores between words: real_or_fake.'],
    'a minimum above the maximum' => [['draftLabel' => 'Group', 'draftMin' => 5, 'draftMax' => 4], 'draftMin', 'Minimum can’t be higher than the maximum.'],
    'a maximum past the most a group allows' => [['draftLabel' => 'Group', 'draftMax' => 16], 'draftMax', 'Enter a whole number from 2 to 15.'],
]);

it('saves a group\'s settings from the top bar, keeping its key', function () {
    $page = groupEditor(3, ['label' => 'Cuteness', 'key' => 'cuteness', 'is_active' => true]);
    $group = RatingGroup::query()->where('key', 'cuteness')->firstOrFail();

    $page->set('draftLabel', '  Cuteness   level ')
        ->set('draftDescription', 'How cute?')
        ->set('draftMax', 5)
        ->set('draftSort', 40)
        ->dispatch('save-rating-group')
        ->assertHasNoErrors()
        ->assertDispatched('rg-admin-toast', message: 'Cuteness level saved')
        ->assertSet('saved.label', 'Cuteness level')
        ->assertSet('saved.max', 5);

    expect($group->fresh())->label->toBe('Cuteness level')->key->toBe('cuteness')->description->toBe('How cute?')->max_options->toBe(5)->sort_order->toBe(40);
});

it('checks the range against the active options as it is typed, and refuses to save an active group outside it', function () {
    $page = groupEditor(3, ['is_active' => true]);
    $group = RatingGroup::query()->latest('id')->firstOrFail();

    $page->set('draftMin', 4)
        ->assertSet('settingsBlocked', true)
        ->assertSeeHtml('3 options are active, fewer than this minimum.')
        ->assertSeeHtml('Saving is blocked until you activate 1 more.')
        ->dispatch('save-rating-group')
        ->assertHasErrors(['draftMin']);

    $page->set('draftMin', 2)->set('draftMax', 2)
        ->assertSeeHtml('3 options are active. Raise the maximum to 3, or deactivate or archive 1.')
        ->dispatch('save-rating-group')
        ->assertHasErrors(['draftMax']);

    expect($group->fresh())->min_options->toBe(2)->max_options->toBe(4);
});

it('offers to raise a maximum left under the group\'s active options, as a group made when fewer were allowed has it, and saves it raised', function () {
    $page = groupEditor(11, ['is_active' => true, 'max_options' => 10]);
    $group = RatingGroup::query()->latest('id')->firstOrFail();

    $page->assertSet('settingsBlocked', true)
        ->assertSeeHtml('11 options are active. Raise the maximum to 11, or deactivate or archive 1.')
        ->set('draftMax', 11)
        ->assertSet('settingsBlocked', false)
        ->assertDontSeeHtml('options are active.')
        ->dispatch('save-rating-group')
        ->assertHasNoErrors();

    expect($group->fresh()->max_options)->toBe(11);

    // More active options than any maximum may be: only fewer of them will do.
    groupEditor(RatingGroup::MOST_OPTIONS + 1, ['is_active' => true, 'max_options' => 10])
        ->assertSeeHtml((RatingGroup::MOST_OPTIONS + 1).' options are active. Deactivate or archive 6 first.');
});

it('lets an inactive group sit outside its range while its options are made', function () {
    $page = groupEditor(1, ['is_active' => false, 'min_options' => 2, 'max_options' => 4]);
    $group = RatingGroup::query()->latest('id')->firstOrFail();

    $page->set('draftMin', 3)
        ->assertSet('settingsBlocked', false)
        ->assertSeeHtml('Activate 2 more before the group is active.')
        ->dispatch('save-rating-group')
        ->assertHasNoErrors();

    expect($group->fresh()->min_options)->toBe(3);

    // Activating it now is refused with the reason.
    $page->set('draftActive', true)
        ->assertSet('settingsBlocked', true)
        ->dispatch('save-rating-group')
        ->assertHasErrors(['draftMin' => '1 option is active, fewer than this minimum.']);
});

it('puts the saved settings back on Discard', function () {
    groupEditor(3, ['label' => 'Cuteness'])
        ->set('draftLabel', 'Something else')
        ->set('draftMax', 3)
        ->dispatch('discard-rating-group')
        ->assertSet('draftLabel', 'Cuteness')
        ->assertSet('draftMax', 4);
});

it('keeps what is saved and whether Save may run on the server', function (string $property, mixed $value) {
    groupEditor()->set($property, $value);
})->throws(CannotUpdateLockedPropertyException::class)
    ->with([
        'what is saved' => ['saved', ['label' => 'Forged']],
        'whether Save may run' => ['settingsBlocked', false],
        'the group it edits' => ['groupId', 1],
    ]);

it('shows where post pages put an active group, and none for an inactive one', function () {
    RatingGroup::factory()->create(['sort_order' => 1, 'is_active' => true]);
    RatingGroup::factory()->create(['sort_order' => 2, 'is_active' => false]);

    groupEditor(3, ['sort_order' => 3, 'is_active' => true])->assertSeeHtml('Shown second on post pages');
    groupEditor(3, ['sort_order' => 4, 'is_active' => false])->assertSeeHtml('Not shown on post pages');
});

it('lists the group\'s own translations read-only, with Translation Center on what is missing', function () {
    [$first, $second] = twoTranslatedLocales();
    offerLocales([$first, $second]);
    $page = groupEditor(3, ['label' => 'Cuteness', 'description' => 'How cute?', 'label_translations' => [$first => 'A', $second => 'B'], 'description_translations' => [$first => 'C'], 'is_active' => true]);
    $group = RatingGroup::query()->where('label', 'Cuteness')->firstOrFail();
    $translations = $page->viewData('translations');

    expect(array_column($translations['rows'], 'state'))->toBe(['Label and description', 'Description missing'])
        ->and($translations['url'])->toBe(TranslationCenterPage::getUrl(['locale' => $second, 'section' => 'rating_groups', 'unit' => "rating_groups:{$group->id}:description"]));

    groupEditor(3, ['is_active' => false])->assertSeeHtml('Inactive groups are translated once they are active again.');
});

it('opens nothing for a group that does not exist', function () {
    $this->get(EditRatingGroup::getUrl(['record' => 999999]))->assertNotFound();
});
