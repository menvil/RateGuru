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

    $this->actingAs($user)
        ->get(PostResource::getUrl('index'))
        ->assertForbidden();
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
    $post = Post::factory()->published()->create([
        'image_path' => 'posts/demo.jpg',
        'image_url' => null,
    ]);

    $this->actingAs($admin);

    Livewire::test(ListPosts::class)
        ->assertCanSeeTableRecords([$post])
        ->assertTableColumnExists('public_image_url')
        ->assertCanRenderTableColumn('public_image_url')
        ->assertTableColumnStateSet('public_image_url', '/storage/posts/demo.jpg', $post);
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

it('renders the author username column', function () {
    $admin = User::factory()->admin()->create();
    $author = User::factory()->create(['username' => 'chef_ivan']);
    $post = Post::factory()->published()->for($author)->create();

    $this->actingAs($admin);

    Livewire::test(ListPosts::class)
        ->assertCanSeeTableRecords([$post])
        ->assertTableColumnExists('user.username')
        ->assertSee('chef_ivan');
});

it('eager-loads the author to avoid N+1 in the posts table', function () {
    $admin = User::factory()->admin()->create();
    Post::factory()->count(3)->published()->create();

    $this->actingAs($admin);

    DB::enableQueryLog();
    Livewire::test(ListPosts::class)->assertSuccessful();

    $userLookups = collect(DB::getQueryLog())
        ->filter(fn ($q) => str_contains($q['query'], 'from "users"') || str_contains($q['query'], 'from `users`'))
        ->count();

    expect($userLookups)->toBe(1);
});

it('renders the status column as a badge', function () {
    $admin = User::factory()->admin()->create();
    $post = Post::factory()->pending()->create();

    $this->actingAs($admin);

    Livewire::test(ListPosts::class)
        ->assertCanSeeTableRecords([$post])
        ->assertTableColumnExists('status')
        ->assertSee('pending');
});

it('renders the reports_count column', function () {
    $admin = User::factory()->admin()->create();
    $post = Post::factory()->published()->create(['reports_count' => 5]);

    $this->actingAs($admin);

    Livewire::test(ListPosts::class)
        ->assertCanSeeTableRecords([$post])
        ->assertTableColumnExists('reports_count')
        ->assertSee('5');
});

it('renders the created_at column and sorts by newest first', function () {
    $admin = User::factory()->admin()->create();
    $older = Post::factory()->published()->create(['created_at' => now()->subDays(2)]);
    $newer = Post::factory()->published()->create(['created_at' => now()->subDay()]);

    $this->actingAs($admin);

    Livewire::test(ListPosts::class)
        ->assertCanSeeTableRecords([$newer, $older], inOrder: true)
        ->assertTableColumnExists('created_at');
});

it('filters posts by pending status', function () {
    $admin = User::factory()->admin()->create();
    $pending = Post::factory()->pending()->create();
    $published = Post::factory()->published()->create();

    $this->actingAs($admin);

    Livewire::test(ListPosts::class)
        ->filterTable('pending')
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$published]);
});

it('filters posts by published status', function () {
    $admin = User::factory()->admin()->create();
    $published = Post::factory()->published()->create();
    $pending = Post::factory()->pending()->create();

    $this->actingAs($admin);

    Livewire::test(ListPosts::class)
        ->filterTable('published')
        ->assertCanSeeTableRecords([$published])
        ->assertCanNotSeeTableRecords([$pending]);
});

it('filters posts by hidden status', function () {
    $admin = User::factory()->admin()->create();
    $hidden = Post::factory()->hidden()->create();
    $published = Post::factory()->published()->create();

    $this->actingAs($admin);

    Livewire::test(ListPosts::class)
        ->filterTable('hidden')
        ->assertCanSeeTableRecords([$hidden])
        ->assertCanNotSeeTableRecords([$published]);
});

it('filters posts with reports_count greater than zero', function () {
    $admin = User::factory()->admin()->create();
    $reported = Post::factory()->published()->create(['reports_count' => 2]);
    $clean = Post::factory()->published()->create(['reports_count' => 0]);

    $this->actingAs($admin);

    Livewire::test(ListPosts::class)
        ->filterTable('reported')
        ->assertCanSeeTableRecords([$reported])
        ->assertCanNotSeeTableRecords([$clean]);
});
