<?php

use App\Enums\MediaVariantName;
use App\Enums\UserRole;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Filament\Resources\Posts\PostResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\AdminNavigationGroup;
use App\Models\Category;
use App\Models\MediaVariant;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * Posts in Admin v2: who may open the screen, what it lists on each tab and
 * how each row reads — the thumbnail and the original, the author and
 * whether they publish at once, the status and its note, the reports, how
 * long a pending post has waited — with the search, the category and the
 * order read on the server.
 */
it('opens for administrators and moderators, and for nobody else', function (string $role, int $status) {
    $user = match ($role) {
        'admin' => User::factory()->admin()->create(),
        'moderator' => User::factory()->moderator()->create(),
        default => User::factory()->create(),
    };

    $this->actingAs($user)->get(PostResource::getUrl('index'))->assertStatus($status);
})->with([
    'an administrator' => ['admin', 200],
    'a moderator' => ['moderator', 200],
    'a member' => ['user', 403],
]);

it('sits first in the Moderation section, with the posts waiting for review as its count', function () {
    Post::factory()->count(2)->pending()->create();
    Post::factory()->published()->create();

    expect(PostResource::getNavigationGroup())->toBe(AdminNavigationGroup::MODERATION)
        ->and(PostResource::getNavigationSort())->toBe(10)
        ->and(PostResource::getNavigationBadge())->toBe('2')
        ->and(PostResource::getNavigationBadgeColor())->toBe('warning')
        ->and(array_keys(PostResource::getPages()))->toBe(['index']);

    Post::query()->update(['status' => 'published']);

    expect(PostResource::getNavigationBadge())->toBeNull();
});

it('opens a post found by global search in the list, by its id', function () {
    $post = Post::factory()->published()->create();

    expect(PostResource::getGlobalSearchResultUrl($post))->toBe(ListPosts::getUrl(['status' => 'all', 'q' => '#'.$post->id]));
});

// One request: Filament reads the page's scope, the top bar's actions
// included, from the request the page was first rendered for.
it('draws the screen in Admin v2, with Refresh in the top bar', function () {
    $this->actingAs(User::factory()->moderator()->create());
    Post::factory()->pending()->create(['title' => 'Luna howling along']);

    expect($this->get(PostResource::getUrl('index'))->assertOk()->getContent())
        ->toContain('<h1 id="rg-admin-posts-title" class="rg-admin-page-header__title">Posts</h1>')
        ->toContain('Luna howling along')
        ->toContain("\$dispatch('refresh-posts')")
        ->toContain('Updated ')
        ->not->toContain('fi-ta-table');
});

it('opens on the queue, oldest first, and on any other tab newest first', function () {
    $this->actingAs(User::factory()->moderator()->create());
    $older = Post::factory()->pending()->create(['created_at' => now()->subHours(3)]);
    $newer = Post::factory()->pending()->create(['created_at' => now()->subHour()]);
    $published = Post::factory()->published()->create(['created_at' => now()->subMinutes(5)]);

    expect(array_column(Livewire::test(ListPosts::class)->assertSet('status', 'pending')->viewData('rows'), 'id'))->toBe([$older->id, $newer->id])
        ->and(array_column(Livewire::withQueryParams(['status' => 'all'])->test(ListPosts::class)->viewData('rows'), 'id'))->toBe([$published->id, $newer->id, $older->id])
        ->and(array_column(Livewire::withQueryParams(['status' => 'all', 'sort' => 'oldest'])->test(ListPosts::class)->viewData('rows'), 'id'))->toBe([$older->id, $newer->id, $published->id]);
});

it('counts every tab, and reads whatever the address says as something it offers', function () {
    $this->actingAs(User::factory()->moderator()->create());
    Post::factory()->count(2)->pending()->create();
    Post::factory()->published()->create(['reports_count' => 3]);

    $page = Livewire::withQueryParams(['status' => 'nonsense', 'sort' => 'sideways', 'page' => 'x', 'category' => 'nowhere'])->test(ListPosts::class)
        ->assertSet('status', 'pending')
        ->assertSet('sort', '')
        ->assertSet('pageNumber', 1)
        ->assertSet('category', '');

    expect(array_column($page->viewData('tabs'), 'count', 'id'))->toBe(['all' => '3', 'pending' => '2', 'published' => '1', 'hidden' => '0', 'reported' => '1', 'deleted' => '0'])
        ->and($page->viewData('stats'))->toMatchArray(['waiting' => '2', 'reported' => '1']);
});

it('searches the title, the author and the id on the server, and narrows to a category', function () {
    $this->actingAs(User::factory()->moderator()->create());
    $dogs = Category::factory()->create(['name' => 'Dogs', 'slug' => 'dogs']);
    $luna = Post::factory()->published()->for(User::factory()->create(['username' => 'luna.the.husky']))->create(['title' => 'Howl', 'category_id' => $dogs->id]);
    $other = Post::factory()->published()->create(['title' => 'Pepper the cockatiel']);

    $page = Livewire::withQueryParams(['status' => 'all'])->test(ListPosts::class);

    expect(array_column($page->set('q', 'luna.the')->viewData('rows'), 'id'))->toBe([$luna->id])
        ->and(array_column($page->set('q', '#'.$other->id)->viewData('rows'), 'id'))->toBe([$other->id])
        ->and(array_column($page->set('q', '')->set('category', 'dogs')->viewData('rows'), 'id'))->toBe([$luna->id]);

    $page->assertSeeHtml('Category: Dogs')->assertSeeHtml('1 post match');

    $page->set('q', 'nothing like it')->assertSeeHtml('No posts match these filters')
        ->call('clearFilters')
        ->assertSet('q', '')
        ->assertSet('category', '');
});

it('says the queue is clear when nothing waits, and nothing is here on an empty tab', function () {
    $this->actingAs(User::factory()->moderator()->create());

    Livewire::test(ListPosts::class)->assertSeeHtml('The queue is clear');
    Livewire::withQueryParams(['status' => 'hidden'])->test(ListPosts::class)->assertSeeHtml('Nothing here');
});

it('shows the smallest variant as the thumbnail, lazily, and the original only when it is opened', function () {
    Storage::fake('public');
    Storage::disk('public')->put('posts/variants/thumb-640.jpg', 'image');
    $this->actingAs(User::factory()->admin()->create());
    $post = Post::factory()->published()->withImage(path: 'posts/original.jpg', width: 4000, height: 3000)->create();
    MediaVariant::factory()->named(MediaVariantName::PostFeed640)->create([
        'media_asset_id' => $post->fresh()->image_asset_id,
        'path' => 'posts/variants/thumb-640.jpg',
    ]);

    $page = Livewire::withQueryParams(['status' => 'all'])->test(ListPosts::class);

    expect($page->html())
        ->toContain('<img src="/storage/posts/variants/thumb-640.jpg" alt="" loading="lazy"')
        ->not->toContain('/storage/posts/original.jpg');

    $page->call('view', $post->id)
        ->assertSet('viewing', $post->id)
        ->assertSeeHtml('src="/storage/posts/original.jpg"')
        ->assertSeeHtml('4000 × 3000')
        ->assertSeeHtml('Open original')
        ->call('closeViewer')
        ->assertSet('viewing', null);
});

it('reads the rows with their authors, categories and image variants in a fixed number of queries', function () {
    $this->actingAs(User::factory()->admin()->create());
    Post::factory()->count(3)->published()->withImage()->create();

    DB::enableQueryLog();
    Livewire::withQueryParams(['status' => 'all'])->test(ListPosts::class)->assertSuccessful();

    $lookups = fn (string $table): int => collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => preg_match('/from ["`]'.$table.'["`]/', $query['query']) === 1)
        ->count();

    expect($lookups('media_variants'))->toBe(1)
        ->and($lookups('media_assets'))->toBe(1);
});

it('links a published post to its public page, and no other', function () {
    $this->actingAs(User::factory()->admin()->create());
    $published = Post::factory()->published()->create(['title' => 'Sample Entry']);
    $pending = Post::factory()->pending()->create(['title' => 'Pending Pasta']);

    Livewire::withQueryParams(['status' => 'all'])->test(ListPosts::class)
        ->assertSeeHtml('href="'.route('posts.show', $published).'"')
        ->assertDontSeeHtml('href="'.route('posts.show', $pending).'"');
});

it('names the author, whether they publish at once and how many posts they have', function (array $state, string $trust) {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $author = User::factory()->create(['username' => 'chef_ivan', ...$state]);
    $post = Post::factory()->pending()->for($author)->create();
    Post::factory()->published()->for($author)->create();

    $row = listedRow(Livewire::test(ListPosts::class), $post);

    expect($row['author'])->toMatchArray([
        'name' => 'chef_ivan',
        'initials' => 'CH',
        'meta' => $trust.' · 2 posts',
        'url' => UserResource::getUrl('edit', ['record' => $author]),
    ]);
})->with([
    'trusted' => [['trust_level' => 10], 'Trusted'],
    'not trusted' => [['trust_level' => 0], 'Not trusted'],
    'without a confirmed email' => [['trust_level' => 10, 'email_verified_at' => null], 'Email not confirmed'],
    // Staff publish without a confirmed email; a Persona's posts always wait.
    'a moderator without a confirmed email' => [['role' => UserRole::Moderator, 'trust_level' => 10, 'email_verified_at' => null], 'Trusted'],
    'a persona' => [['role' => UserRole::Persona, 'trust_level' => 10, 'email_verified_at' => null, 'password' => null], 'Always reviewed'],
    'a persona with a confirmed address on its row' => [['role' => UserRole::Persona, 'trust_level' => 10, 'password' => null], 'Always reviewed'],
]);

it('shows each status with its tone and note, the reports and how long a pending post has waited', function () {
    $this->actingAs(User::factory()->admin()->create());
    $waiting = Post::factory()->pending()->create(['created_at' => now()->subMinutes(192)]);
    $fresh = Post::factory()->pending()->create(['created_at' => now()->subMinutes(50)]);
    $hidden = Post::factory()->hidden()->create(['reports_count' => 4]);
    $reported = Post::factory()->published()->create(['reports_count' => 2]);
    $deleted = Post::factory()->authorDeleted()->create();

    $page = Livewire::withQueryParams(['status' => 'all'])->test(ListPosts::class);

    expect(listedRow($page, $waiting))->toMatchArray(['waiting' => 'Waiting 3 h 12 min', 'due' => true])
        ->and(listedRow($page, $fresh))->toMatchArray(['waiting' => 'Waiting 50 min', 'due' => false])
        ->and(listedRow($page, $hidden)['status'])->toBe(['label' => 'Hidden', 'tone' => 'neutral', 'dot' => false, 'note' => 'Restorable'])
        ->and(listedRow($page, $reported)['status'])->toMatchArray(['label' => 'Published', 'tone' => 'success', 'dot' => true])
        ->and(listedRow($page, $reported)['reports'])->toBe(2)
        ->and(listedRow($page, $deleted)['status'])->toMatchArray(['label' => 'Deleted by author', 'tone' => 'outline', 'note' => 'Retained for audit']);
});

it('keeps the confirmation and the open image on the server', function (string $property, mixed $value) {
    $this->actingAs(User::factory()->moderator()->create());

    Livewire::test(ListPosts::class)->set($property, $value);
})->throws(CannotUpdateLockedPropertyException::class)
    ->with([
        'the confirmation' => ['confirming', ['action' => 'hide', 'ids' => [1]]],
        'the open image' => ['viewing', 1],
    ]);
