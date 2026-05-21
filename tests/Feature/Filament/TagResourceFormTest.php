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
