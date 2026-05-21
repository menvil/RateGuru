<?php

use App\Filament\Resources\Tags\Pages\CreateTag;
use App\Filament\Resources\Tags\Pages\EditTag;
use App\Models\Tag;
use App\Models\User;

use Livewire\Livewire;

it('allows admin to create tag from tag resource', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    Livewire::test(CreateTag::class)
        ->fillForm([
            'name' => 'Mexican Food',
            'slug' => 'mexican-food',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('tags', [
        'name' => 'Mexican Food',
        'slug' => 'mexican-food',
    ]);
});

it('allows moderator to create tag from tag resource', function () {
    $moderator = User::factory()->moderator()->create();

    $this->actingAs($moderator);

    Livewire::test(CreateTag::class)
        ->fillForm([
            'name' => 'Vegan',
            'slug' => 'vegan',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('tags', [
        'name' => 'Vegan',
        'slug' => 'vegan',
    ]);
});

it('auto generates slug from name when creating tag', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    Livewire::test(CreateTag::class)
        ->fillForm([
            'name' => 'Street Food',
            'slug' => '',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('tags', [
        'name' => 'Street Food',
        'slug' => 'street-food',
    ]);
});

it('does not overwrite a manually edited slug when the name changes on create', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    Livewire::test(CreateTag::class)
        ->fillForm(['name' => 'Street Food'])
        ->assertFormSet(['slug' => 'street-food'])
        // User overrides the auto-generated slug...
        ->fillForm(['slug' => 'custom-slug'])
        // ...then tweaks the name again. The slug must be preserved.
        ->fillForm(['name' => 'Street Food Stalls'])
        ->assertFormSet(['slug' => 'custom-slug'])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('tags', [
        'name' => 'Street Food Stalls',
        'slug' => 'custom-slug',
    ]);
});

it('preserves a "0" slug when creating tag instead of falling back to name', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    Livewire::test(CreateTag::class)
        ->fillForm([
            'name' => 'Zero',
            'slug' => '0',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('tags', [
        'name' => 'Zero',
        'slug' => '0',
    ]);
});

it('preserves a "0" slug when editing tag instead of falling back to name', function () {
    $admin = User::factory()->admin()->create();

    $tag = Tag::factory()->create([
        'name' => 'Zero',
        'slug' => 'zero',
    ]);

    $this->actingAs($admin);

    Livewire::test(EditTag::class, ['record' => $tag->getRouteKey()])
        ->fillForm([
            'name' => 'Zero',
            'slug' => '0',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tag->fresh()->slug)->toBe('0');
});

it('requires a name when creating tag', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    Livewire::test(CreateTag::class)
        ->fillForm([
            'name' => '',
            'slug' => 'no-name',
        ])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required']);
});

it('rejects a blank slug whose name-derived value already exists', function () {
    $admin = User::factory()->admin()->create();
    Tag::factory()->create(['slug' => 'street-food']);

    $this->actingAs($admin);

    // Slug left blank: the effective slug is generated from the name and
    // must still be caught by validation before reaching the DB.
    Livewire::test(CreateTag::class)
        ->fillForm([
            'name' => 'Street Food',
            'slug' => '',
        ])
        ->call('create')
        ->assertHasFormErrors(['slug']);

    expect(Tag::query()->where('slug', 'street-food')->count())->toBe(1);
});

it('rejects duplicate slug when creating tag', function () {
    $admin = User::factory()->admin()->create();
    Tag::factory()->create(['slug' => 'pasta']);

    $this->actingAs($admin);

    Livewire::test(CreateTag::class)
        ->fillForm([
            'name' => 'Pasta',
            'slug' => 'pasta',
        ])
        ->call('create')
        ->assertHasFormErrors(['slug']);
});

it('rejects non url-safe slug when creating tag', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    Livewire::test(CreateTag::class)
        ->fillForm([
            'name' => 'Bad Slug',
            'slug' => 'Bad Slug!',
        ])
        ->call('create')
        ->assertHasFormErrors(['slug']);
});

it('allows admin to edit tag from tag resource', function () {
    $admin = User::factory()->admin()->create();

    $tag = Tag::factory()->create([
        'name' => 'Old Name',
        'slug' => 'old-name',
    ]);

    $this->actingAs($admin);

    Livewire::test(EditTag::class, ['record' => $tag->getRouteKey()])
        ->fillForm([
            'name' => 'New Name',
            'slug' => 'new-name',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tag->fresh()->name)->toBe('New Name');
    expect($tag->fresh()->slug)->toBe('new-name');
});

it('allows keeping current slug when editing tag', function () {
    $admin = User::factory()->admin()->create();

    $tag = Tag::factory()->create([
        'name' => 'Pasta',
        'slug' => 'pasta',
    ]);

    $this->actingAs($admin);

    Livewire::test(EditTag::class, ['record' => $tag->getRouteKey()])
        ->fillForm([
            'name' => 'Pasta Dishes',
            'slug' => 'pasta',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tag->fresh()->name)->toBe('Pasta Dishes');
    expect($tag->fresh()->slug)->toBe('pasta');
});

it('rejects a blank slug on edit whose name-derived value already exists', function () {
    $admin = User::factory()->admin()->create();

    Tag::factory()->create(['slug' => 'street-food']);
    $tag = Tag::factory()->create(['name' => 'Old', 'slug' => 'old']);

    $this->actingAs($admin);

    Livewire::test(EditTag::class, ['record' => $tag->getRouteKey()])
        ->fillForm([
            'name' => 'Street Food',
            'slug' => '',
        ])
        ->call('save')
        ->assertHasFormErrors(['slug']);

    expect($tag->fresh()->slug)->toBe('old');
});

it('allows saving a tag without changing its own slug', function () {
    $admin = User::factory()->admin()->create();
    $tag = Tag::factory()->create(['name' => 'Pasta', 'slug' => 'pasta']);

    $this->actingAs($admin);

    // Effective slug equals the record's own slug — must not collide with itself.
    Livewire::test(EditTag::class, ['record' => $tag->getRouteKey()])
        ->fillForm(['name' => 'Pasta', 'slug' => 'pasta'])
        ->call('save')
        ->assertHasNoFormErrors();
});

it('rejects duplicate slug when editing tag', function () {
    $admin = User::factory()->admin()->create();

    Tag::factory()->create(['slug' => 'taken']);
    $tag = Tag::factory()->create(['slug' => 'editable']);

    $this->actingAs($admin);

    Livewire::test(EditTag::class, ['record' => $tag->getRouteKey()])
        ->fillForm([
            'name' => 'Editable',
            'slug' => 'taken',
        ])
        ->call('save')
        ->assertHasFormErrors(['slug']);

    expect($tag->fresh()->slug)->toBe('editable');
});
