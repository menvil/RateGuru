<?php

use App\Filament\Resources\Tags\Pages\ManageTags;
use App\Models\ProjectSettings;
use App\Models\Tag;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * A tag in the drawer: created in English with a slug made from its name
 * unless one is typed, and edited with that slug locked until it is
 * unlocked on purpose. The slug is held unique as it will be saved, the
 * one made from the name included.
 */
beforeEach(function () {
    ProjectSettings::factory()->create();
    $this->actingAs(User::factory()->admin()->create());
});

/** The drawer on a new tag, with what was typed into it. */
function newTagDraft(string $name, ?string $slug = null): Testable
{
    $page = Livewire::test(ManageTags::class)->call('create')->set('draftName', $name);

    return $slug === null ? $page : $page->set('draftSlug', $slug);
}

/** The drawer on a saved tag, unlocked, with what was typed into it. */
function editedTagDraft(Tag $tag, string $name, string $slug): Testable
{
    return Livewire::test(ManageTags::class)
        ->call('edit', $tag->id)
        ->call('unlockSlug')
        ->set('draftName', $name)
        ->set('draftSlug', $slug);
}

it('allows admin to create tag from tag resource', function () {
    newTagDraft('Mexican Food', 'mexican-food')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('rg-admin-toast', message: '#Mexican Food created. Translations are missing for '.(count(offeredLocales()) - 1).' languages.');

    $this->assertDatabaseHas('tags', ['name' => 'Mexican Food', 'slug' => 'mexican-food', 'name_translations' => null]);
});

it('allows moderator to create tag from tag resource', function () {
    $this->actingAs(User::factory()->moderator()->create());

    newTagDraft('Thai Food', 'thai-food')->call('save')->assertHasNoErrors();

    $this->assertDatabaseHas('tags', ['name' => 'Thai Food', 'slug' => 'thai-food']);
});

it('auto generates slug from name when creating tag', function () {
    newTagDraft('Street Food')->assertSet('draftSlug', 'street-food')->call('save')->assertHasNoErrors();

    $this->assertDatabaseHas('tags', ['name' => 'Street Food', 'slug' => 'street-food']);
});

it('does not overwrite a manually edited slug when the name changes on create', function () {
    newTagDraft('Street Food', 'my-slug')
        ->set('draftName', 'Street Food Changed')
        ->assertSet('draftSlug', 'my-slug');
});

it('makes the slug from the name when it is left blank', function () {
    newTagDraft('Street Food', '')->call('save')->assertHasNoErrors();

    $this->assertDatabaseHas('tags', ['name' => 'Street Food', 'slug' => 'street-food']);
});

it('preserves a "0" slug when creating tag instead of falling back to name', function () {
    newTagDraft('Zero Tag', '0')->call('save')->assertHasNoErrors();

    $this->assertDatabaseHas('tags', ['name' => 'Zero Tag', 'slug' => '0']);
});

it('preserves a "0" slug when editing tag instead of falling back to name', function () {
    $tag = Tag::factory()->create(['name' => 'Original', 'slug' => 'original']);

    editedTagDraft($tag, 'Renamed', '0')->call('save')->assertHasNoErrors();

    expect($tag->fresh()->slug)->toBe('0');
});

it('says what to do when a tag cannot be saved', function (Closure $draft, string $field, string $message) {
    Tag::factory()->create(['slug' => 'street-food']);

    $page = $draft()->call('save')->assertHasErrors([$field]);

    expect($page->errors()->first($field))->toBe($message)
        ->and(Tag::query()->count())->toBe(1);
})->with([
    'no name' => [fn (): Testable => newTagDraft('', 'cakes'), 'draftName', 'Enter a name.'],
    'a name that slugifies to nothing, with no slug' => [fn (): Testable => newTagDraft('!!!', ''), 'draftSlug', 'The slug must contain at least one letter or number.'],
    'a blank slug whose name is taken' => [fn (): Testable => newTagDraft('Street Food', ''), 'draftSlug', 'The slug has already been taken.'],
    'a slug in use' => [fn (): Testable => newTagDraft('Other', 'street-food'), 'draftSlug', 'The slug has already been taken.'],
    'a slug that is not URL-safe' => [fn (): Testable => newTagDraft('Bad Slug', 'Bad Slug!'), 'draftSlug', 'Use lowercase letters and numbers, with single hyphens between words: best-friends.'],
]);

it('allows admin to edit tag from tag resource', function () {
    $tag = Tag::factory()->create(['name' => 'Old Name', 'slug' => 'old-name']);

    editedTagDraft($tag, 'New Name', 'new-name')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('rg-admin-toast', message: '#New Name saved');

    expect($tag->fresh())->name->toBe('New Name')->slug->toBe('new-name');
});

it('keeps a saved slug unless it is unlocked', function () {
    $tag = Tag::factory()->create(['name' => 'Pasta', 'slug' => 'pasta']);

    Livewire::test(ManageTags::class)
        ->call('edit', $tag->id)
        ->assertSet('slugUnlocked', false)
        ->set('draftName', 'Pasta Dishes')
        ->set('draftSlug', 'forged')
        ->call('save')
        ->assertHasNoErrors();

    expect($tag->fresh())->name->toBe('Pasta Dishes')->slug->toBe('pasta');
});

it('allows saving a tag without changing its own slug', function () {
    $tag = Tag::factory()->create(['name' => 'Pasta', 'slug' => 'pasta']);

    // The slug as saved is the tag's own: it must not collide with itself.
    editedTagDraft($tag, 'Pasta', 'pasta')->call('save')->assertHasNoErrors();
});

it('rejects a blank slug on edit whose name-derived value already exists', function () {
    Tag::factory()->create(['slug' => 'street-food']);
    $tag = Tag::factory()->create(['name' => 'Old', 'slug' => 'old']);

    editedTagDraft($tag, 'Street Food', '')->call('save')->assertHasErrors(['draftSlug']);

    expect($tag->fresh()->slug)->toBe('old');
});

it('rejects duplicate slug when editing tag', function () {
    Tag::factory()->create(['slug' => 'taken']);
    $tag = Tag::factory()->create(['slug' => 'editable']);

    editedTagDraft($tag, 'Editable', 'taken')->call('save')->assertHasErrors(['draftSlug']);

    expect($tag->fresh()->slug)->toBe('editable');
});

it('refuses a user who may not curate tags', function () {
    $tag = Tag::factory()->create();
    $this->actingAs(User::factory()->moderator()->banned()->create());

    Livewire::test(ManageTags::class)->call('edit', $tag->id)->assertForbidden();
});
