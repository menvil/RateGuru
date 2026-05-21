<?php

use App\Filament\Resources\Tags\Pages\CreateTag;
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
