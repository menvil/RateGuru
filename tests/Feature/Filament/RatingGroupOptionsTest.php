<?php

use App\Filament\Resources\RatingGroups\Pages\EditRatingGroup;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\RatingVote;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * A group's options on its page: created and edited in a drawer, their key
 * made from the label and fixed afterwards; activated and deactivated with
 * Undo while the group's range allows it, explained when it does not —
 * with the maximum raised in one step when one more is possible; archived
 * with their votes kept, unarchived inactive, and deleted only unused.
 */
beforeEach(function () {
    ProjectSettings::factory()->create();
    $this->actingAs(User::factory()->admin()->create());
});

/**
 * A group in its range with this many active options, and its editor.
 *
 * @return array{0: RatingGroup, 1: list<RatingOption>}
 */
function optionsGroup(int $active, int $min = 2, int $max = 4, int $inactive = 0): array
{
    $group = RatingGroup::factory()->create(['label' => 'Vibe', 'min_options' => $min, 'max_options' => $max]);
    $options = [
        ...RatingOption::factory()->count($active)->for($group, 'group')->create(['is_active' => true])->all(),
        ...RatingOption::factory()->count($inactive)->for($group, 'group')->create(['is_active' => false])->all(),
    ];

    return [$group, $options];
}

function optionsEditor(RatingGroup $group): Testable
{
    return Livewire::test(EditRatingGroup::class, ['record' => $group->id]);
}

it('creates an option in English, its key made from its label and active while the group has room', function () {
    [$group] = optionsGroup(2);

    optionsEditor($group)
        ->call('createOption')
        ->assertSet('draftOptionActive', true)
        ->set('draftOptionLabel', 'Couch potato')
        ->assertSet('draftOptionKey', 'couch_potato')
        ->set('draftOptionDescription', 'Never leaves the sofa.')
        ->call('saveOption')
        ->assertHasNoErrors()
        ->assertDispatched('rg-admin-toast', message: 'Couch potato created')
        ->assertSet('optionCreating', false);

    expect($group->options()->where('key', 'couch_potato')->firstOrFail())
        ->label->toBe('Couch potato')->description->toBe('Never leaves the sofa.')->is_active->toBeTrue()->label_translations->toBeEmpty();
});

it('creates an option inactive, with the reason, in a group at its maximum', function () {
    [$group] = optionsGroup(4, max: 4);

    optionsEditor($group)
        ->call('createOption')
        ->assertSet('draftOptionActive', false)
        ->assertSeeHtml('Vibe already has 4 of 4 active options. This option stays inactive until another is deactivated or the maximum is raised.')
        ->set('draftOptionLabel', 'Extra')
        // Forged: Active is locked.
        ->set('draftOptionActive', true)
        ->call('saveOption')
        ->assertHasNoErrors()
        ->assertDispatched('rg-admin-toast', message: 'Extra created as inactive');

    expect($group->options()->where('key', 'extra')->firstOrFail()->is_active)->toBeFalse();
});

it('says what to do when an option cannot be saved', function (Closure $draft, string $field, string $message) {
    [$group, [$taken]] = optionsGroup(2);
    $taken->update(['key' => 'taken']);

    $page = optionsEditor($group)->call('createOption');
    $draft($page);

    $page->call('saveOption')->assertHasErrors([$field]);

    expect($page->errors()->first($field))->toBe($message)
        ->and($group->options()->count())->toBe(2);
})->with([
    'no label' => [fn ($page) => $page->set('draftOptionLabel', '')->set('draftOptionKey', 'empty'), 'draftOptionLabel', 'Enter a label.'],
    'a key the group uses' => [fn ($page) => $page->set('draftOptionLabel', 'Taken'), 'draftOptionKey', 'This key is already used by another option of this group. Choose another one.'],
    'a key with spaces' => [fn ($page) => $page->set('draftOptionLabel', 'Spaced')->set('draftOptionKey', 'has spaces'), 'draftOptionKey', 'Use lowercase letters and numbers, with single underscores between words: heart_melting.'],
]);

it('lets another group use the same option key', function () {
    [$group] = optionsGroup(2);
    [$other, [$option]] = optionsGroup(2);
    $option->update(['key' => 'shared']);

    optionsEditor($group)->call('createOption')->set('draftOptionLabel', 'Shared')->call('saveOption')->assertHasNoErrors();
});

it('edits an option, keeping its key', function () {
    [$group, [$option]] = optionsGroup(3);
    $key = $option->key;

    optionsEditor($group)
        ->call('editOption', $option->id)
        ->assertSet('optionEditing', $option->id)
        ->set('draftOptionLabel', 'Updated option')
        ->set('draftOptionKey', 'forged_key')
        ->set('draftOptionDescription', 'Updated description.')
        ->set('draftOptionSort', '30')
        ->set('draftOptionActive', false)
        ->call('saveOption')
        ->assertHasNoErrors()
        ->assertDispatched('rg-admin-toast', message: 'Updated option saved');

    expect($option->fresh())->label->toBe('Updated option')->key->toBe($key)->description->toBe('Updated description.')->sort_order->toBe(30)->is_active->toBeFalse();
});

it('keeps Active as it is while the range would refuse the change', function () {
    [$group, [$option]] = optionsGroup(2, min: 2);

    optionsEditor($group)
        ->call('editOption', $option->id)
        ->assertSeeHtml('Vibe needs at least 2 active options, so this one can’t be turned off right now.')
        ->set('draftOptionActive', false)
        ->call('saveOption')
        ->assertHasNoErrors();

    expect($option->fresh()->is_active)->toBeTrue();
});

it('deactivates an option with Undo, and Undo activates it again', function () {
    [$group, [$option]] = optionsGroup(3);

    $page = optionsEditor($group)
        ->call('deactivateOption', $option->id)
        ->assertDispatched('rg-admin-toast', message: "{$option->label} deactivated", action: ['label' => 'Undo', 'event' => 'activate-rating-option', 'params' => ['id' => $option->id, 'undo' => true]]);

    expect($option->fresh()->is_active)->toBeFalse();

    $page->dispatch('activate-rating-option', id: $option->id, undo: true)
        ->assertDispatched('rg-admin-toast', message: "{$option->label} is active again", action: null);

    expect($option->fresh()->is_active)->toBeTrue();
});

it('explains why an option cannot be deactivated at the minimum', function () {
    [$group, [$option]] = optionsGroup(2, min: 2);

    optionsEditor($group)
        ->assertSeeHtml('Blocked · minimum is 2')
        ->call('deactivateOption', $option->id)
        ->assertSet('confirming', 'below-min')
        ->assertSeeHtml("{$option->label} can’t be deactivated")
        ->assertSeeHtml('Vibe needs at least 2 active options and has exactly 2.');

    expect($option->fresh()->is_active)->toBeTrue();
});

it('raises the maximum and activates an option in one step, while one more is allowed', function () {
    [$group, $options] = optionsGroup(4, max: 4, inactive: 1);
    $inactive = end($options);

    $page = optionsEditor($group)
        ->assertSeeHtml('Blocked · maximum is 4')
        ->call('activateOption', $inactive->id)
        ->assertSet('confirming', 'above-max')
        ->assertSeeHtml('Vibe already has 4 active options, the maximum allowed.')
        ->assertSeeHtml('Raise maximum to 5 and activate')
        ->call('raiseMaximumAndActivate', $inactive->id)
        ->assertDispatched('rg-admin-toast', message: "Maximum raised to 5 · {$inactive->label} activated")
        ->assertSet('saved.max', 5)
        ->assertSet('draftMax', 5);

    expect($group->fresh()->max_options)->toBe(5)
        ->and($inactive->fresh()->is_active)->toBeTrue();
});

it('offers no higher maximum past the most a group allows', function () {
    [$group, $options] = optionsGroup(RatingGroup::MOST_OPTIONS, max: RatingGroup::MOST_OPTIONS, inactive: 1);

    optionsEditor($group)
        ->call('activateOption', end($options)->id)
        ->assertSet('confirming', 'above-max')
        ->assertDontSeeHtml('Raise maximum to');
});

it('archives an option after asking, keeping its votes, and unarchives it inactive', function () {
    [$group, [$option]] = optionsGroup(3);
    RatingVote::factory()->for($group, 'group')->for($option, 'option')->create();

    $page = optionsEditor($group)
        ->call('askToArchive', $option->id)
        ->assertSet('confirming', 'archive')
        ->assertSeeHtml("Archive {$option->label}?")
        ->assertSeeHtml('Its 1 vote stays in results and vote history.')
        ->call('archiveOption', $option->id)
        ->assertDispatched('rg-admin-toast', message: "{$option->label} archived");

    expect($option->fresh())->is_active->toBeFalse()->archived_at->not->toBeNull()
        ->and($option->fresh()->votes()->count())->toBe(1);

    $page->call('unarchiveOption', $option->id)
        ->assertDispatched('rg-admin-toast', message: "{$option->label} unarchived · inactive");

    expect($option->fresh())->is_active->toBeFalse()->archived_at->toBeNull();
});

it('deletes an unused option after asking', function () {
    [$group, [$option]] = optionsGroup(3);

    optionsEditor($group)
        ->call('askToDeleteOption', $option->id)
        ->assertSet('confirming', 'delete')
        ->assertSeeHtml('This option has never been voted on.')
        ->call('deleteOption', $option->id)
        ->assertDispatched('rg-admin-toast', message: "{$option->label} deleted");

    expect(RatingOption::query()->find($option->id))->toBeNull();
});

it('offers the archive instead for an option with votes, and keeps it', function () {
    [$group, [$option]] = optionsGroup(3);
    RatingVote::factory()->count(2)->for($group, 'group')->for($option, 'option')->create();

    optionsEditor($group)
        ->assertSeeHtml('Has votes · archive instead')
        ->call('askToDeleteOption', $option->id)
        ->assertSet('confirming', 'voted')
        ->assertSeeHtml('2 votes reference this option.')
        ->assertSeeHtml('Archive instead')
        // A forged delete is refused by the action, and the dialog stays.
        ->call('deleteOption', $option->id)
        ->assertSet('confirming', 'voted');

    expect($option->fresh())->not->toBeNull();
});

it('shows each option\'s votes', function () {
    [$group, [$option]] = optionsGroup(2);
    RatingVote::factory()->count(2)->for($group, 'group')->for($option, 'option')->create();

    expect(optionsEditor($group)->viewData('options')[$option->id]['votes'])->toBe(2);
});

it('refuses what names no option of the group', function (string $method) {
    [$group] = optionsGroup(2);
    [, [$foreign]] = optionsGroup(2);

    optionsEditor($group)
        ->call($method, $foreign->id)
        ->assertDispatched('rg-admin-toast', message: 'That option no longer exists.', tone: 'error');

    expect($foreign->fresh())->not->toBeNull()->is_active->toBeTrue();
})->with(['editOption', 'activateOption', 'deactivateOption', 'askToArchive', 'archiveOption', 'unarchiveOption', 'askToDeleteOption', 'deleteOption', 'raiseMaximumAndActivate']);
