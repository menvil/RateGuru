<?php

use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Filament\Resources\Posts\PostResource;
use App\Filament\Support\AdminNavigationGroup;
use App\Models\Post;
use App\Models\User;
use Livewire\Livewire;

it('allows admin to access post resource index', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(PostResource::getUrl('index'))
        ->assertOk();
});

it('allows moderator to access post resource index', function () {
    $moderator = User::factory()->moderator()->create();

    $this->actingAs($moderator)
        ->get(PostResource::getUrl('index'))
        ->assertOk();
});

it('does not allow normal user to access post resource index', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(PostResource::getUrl('index'));

    expect($response->getStatusCode())->toBe(403);
});

it('uses the Post model', function () {
    expect(PostResource::getModel())->toBe(Post::class);
});

it('lives under the Content navigation group', function () {
    expect(PostResource::getNavigationGroup())->toBe(AdminNavigationGroup::CONTENT);
});

it('does not expose create or edit pages in this phase', function () {
    expect(array_keys(PostResource::getPages()))->toBe(['index']);
});

it('renders an image column in the post resource table', function () {
    $admin = User::factory()->admin()->create();
    $post = Post::factory()->published()->create(['image_path' => 'posts/demo.jpg']);

    $this->actingAs($admin);

    Livewire::test(ListPosts::class)
        ->assertCanSeeTableRecords([$post])
        ->assertTableColumnExists('image_path');
});

it('renders a searchable, sortable title column', function () {
    $admin = User::factory()->admin()->create();
    $post = Post::factory()->published()->create(['title' => 'Homemade Pasta']);

    $this->actingAs($admin);

    Livewire::test(ListPosts::class)
        ->assertCanSeeTableRecords([$post])
        ->assertTableColumnExists('title')
        ->assertCanRenderTableColumn('title')
        ->assertSee('Homemade Pasta');
});
