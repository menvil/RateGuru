<?php

use App\Filament\Resources\Tags\Pages\ListTags;
use App\Filament\Resources\Tags\TagResource;
use App\Filament\Support\AdminNavigationGroup;
use App\Models\Tag;
use App\Models\User;
use Livewire\Livewire;

it('allows admin to access tag resource index', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(TagResource::getUrl('index'))
        ->assertOk();
});

it('allows moderator to access tag resource index', function () {
    $moderator = User::factory()->moderator()->create();

    $this->actingAs($moderator)
        ->get(TagResource::getUrl('index'))
        ->assertOk();
});

it('does not allow normal user to access tag resource index', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(TagResource::getUrl('index'))
        ->assertForbidden();
});

it('uses the Tag model', function () {
    expect(TagResource::getModel())->toBe(Tag::class);
});

it('lives under the Taxonomy navigation group', function () {
    expect(TagResource::getNavigationGroup())->toBe(AdminNavigationGroup::TAXONOMY);
});

it('lists tags on the index page', function () {
    $admin = User::factory()->admin()->create();
    $tag = Tag::factory()->create();

    $this->actingAs($admin);

    Livewire::test(ListTags::class)
        ->assertCanSeeTableRecords([$tag]);
});

it('renders tag name in tag resource table', function () {
    $admin = User::factory()->admin()->create();

    Tag::factory()->create([
        'name' => 'Italian',
        'slug' => 'italian',
    ]);

    $this->actingAs($admin)
        ->get(TagResource::getUrl('index'))
        ->assertOk()
        ->assertSee('Italian');
});

it('renders a searchable, sortable name column', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    Livewire::test(ListTags::class)
        ->assertTableColumnExists('name')
        ->assertCanRenderTableColumn('name');
});
