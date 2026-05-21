<?php

use App\Filament\Resources\Tags\Pages\ListTags;
use App\Filament\Resources\Tags\TagResource;
use App\Filament\Support\AdminNavigationGroup;
use App\Models\Post;
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

it('renders tag slug in tag resource table', function () {
    $admin = User::factory()->admin()->create();

    Tag::factory()->create([
        'name' => 'Asian Food',
        'slug' => 'asian-food',
    ]);

    $this->actingAs($admin)
        ->get(TagResource::getUrl('index'))
        ->assertOk()
        ->assertSee('asian-food');
});

it('renders a searchable, sortable slug column', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    Livewire::test(ListTags::class)
        ->assertTableColumnExists('slug')
        ->assertCanRenderTableColumn('slug');
});

it('renders posts count in tag resource table', function () {
    $admin = User::factory()->admin()->create();

    $tag = Tag::factory()->create([
        'name' => 'Pasta',
        'slug' => 'pasta',
    ]);

    $posts = Post::factory()->count(2)->published()->create();

    foreach ($posts as $post) {
        $post->tags()->attach($tag);
    }

    $this->actingAs($admin);

    Livewire::test(ListTags::class)
        ->assertCanSeeTableRecords([$tag])
        ->assertTableColumnStateSet('posts_count', 2, record: $tag);
});

it('counts posts from the posts relationship', function () {
    $tag = Tag::factory()->create();
    $post = Post::factory()->published()->create();
    $post->tags()->attach($tag);

    expect($tag->loadCount('posts')->posts_count)->toBe(1);
});
