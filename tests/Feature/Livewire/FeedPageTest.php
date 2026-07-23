<?php

use App\Enums\PostStatus;
use App\Livewire\Feed\FeedPage;
use App\Models\Category;
use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\User;
use Livewire\Livewire;

it('can render feed page component', function () {
    Livewire::test(FeedPage::class)
        ->assertStatus(200);
});

it('renders the feed page shell', function () {
    Livewire::test(FeedPage::class)
        ->assertSee('data-testid="feed-page"', false)
        ->assertSee('data-testid="feed-content-shell"', false)
        ->assertSee('max-w-[820px]', false)
        ->assertDontSee('data-testid="post-detail-column"', false);
});

it('has search state on feed page', function () {
    Livewire::test(FeedPage::class)
        ->assertSet('search', '');
});

it('filters feed results when search state changes', function () {
    Post::factory()->published()->create(['title' => 'Sample Entry']);
    Post::factory()->published()->create(['title' => 'Chocolate Cake']);

    Livewire::test(FeedPage::class)
        ->set('search', 'sample')
        ->assertSee('Sample Entry')
        ->assertDontSee('Chocolate Cake');
});

it('has rating filter state on feed page', function () {
    Livewire::test(FeedPage::class)
        ->assertSet('category', [])
        ->assertSet('ratings', []);
});

it('has sort state on feed page with default newest', function () {
    Livewire::test(FeedPage::class)
        ->assertSet('sort', 'newest');
});

it('sorts feed when sort is changed to top', function () {
    Post::factory()->published()->create([
        'title' => 'Low Score',
        'upvotes_count' => 1,
        'downvotes_count' => 0,
        'published_at' => now()->subMinutes(5),
    ]);

    Post::factory()->published()->create([
        'title' => 'High Score',
        'upvotes_count' => 10,
        'downvotes_count' => 0,
        'published_at' => now()->subMinutes(10),
    ]);

    Livewire::test(FeedPage::class)
        ->set('sort', 'top')
        ->assertSeeInOrder(['High Score', 'Low Score']);
});

it('selects post for detail column on feed page', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Detail Dish',
    ]);

    Livewire::test(FeedPage::class)
        ->call('selectPost', $post->id)
        ->assertSet('selectedPostId', $post->id)
        ->assertDispatched('post-selected', postId: $post->id)
        ->assertSee('data-testid="post-detail-column"', false)
        ->assertSee('rg-feed-split-grid', false);
});

it('clears selected post from detail column', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(FeedPage::class)
        ->call('selectPost', $post->id)
        ->call('clearSelectedPost')
        ->assertSet('selectedPostId', null);
});

it('deletes an owned post from the feed page action menu event', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->for($user)->create();

    Livewire::actingAs($user)
        ->test(FeedPage::class)
        ->call('selectPost', $post->id)
        ->call('deletePost', $post->id)
        ->assertSet('selectedPostId', null)
        ->assertDispatched('post-deleted', postId: $post->id);

    expect(Post::withTrashed()->find($post->id)->status)->toBe(PostStatus::Deleted);
});

it('does not delete another users post from the feed page action menu event', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(FeedPage::class)
        ->call('deletePost', $post->id)
        ->assertNotDispatched('post-deleted');

    expect(Post::query()->find($post->id))->not->toBeNull();
});

it('does not render a sort dropdown in the feed header (sorting lives in the sidebar nav)', function () {
    Livewire::test(FeedPage::class)
        ->assertDontSee('data-testid="feed-rating-filters"', false)
        ->assertDontSee('data-testid="tag-tabs"', false);
});

it('filters feed when a category is selected', function () {
    $first = Category::factory()->create(['slug' => 'desserts']);
    $second = Category::factory()->create(['slug' => 'soups']);

    Post::factory()->published()->create([
        'title' => 'First category post',
        'category_id' => $first->id,
    ]);
    Post::factory()->published()->create([
        'title' => 'Second category post',
        'category_id' => $second->id,
    ]);

    Livewire::test(FeedPage::class)
        ->call('toggleCategory', 'desserts')
        ->assertSee('First category post')
        ->assertDontSee('Second category post');
});

it('supports selecting multiple categories', function () {
    $first = Category::factory()->create(['slug' => 'desserts']);
    $second = Category::factory()->create(['slug' => 'soups']);

    Post::factory()->published()->create([
        'title' => 'First category post',
        'category_id' => $first->id,
    ]);
    Post::factory()->published()->create([
        'title' => 'Second category post',
        'category_id' => $second->id,
    ]);

    Livewire::test(FeedPage::class)
        ->call('toggleCategory', 'desserts')
        ->call('toggleCategory', 'soups')
        ->assertSee('First category post')
        ->assertSee('Second category post');
});

it('ignores inactive and unknown category filters', function () {
    Category::factory()->inactive()->create(['slug' => 'hidden']);

    Livewire::withQueryParams(['category' => ['hidden', 'unknown']])
        ->test(FeedPage::class)
        ->assertSet('category', []);
});

it('filters feed by a generic author answer', function () {
    seedFeedFilterGroups();
    $group = RatingGroup::query()->where('key', 'category')->firstOrFail();
    $first = $group->options()->where('key', 'category_a')->firstOrFail();
    $second = $group->options()->where('key', 'category_b')->firstOrFail();

    $matching = Post::factory()->published()->create(['title' => 'Matching answer']);
    $matching->authorAnswers()->create([
        'rating_group_id' => $group->id,
        'rating_option_id' => $first->id,
    ]);

    $other = Post::factory()->published()->create(['title' => 'Other answer']);
    $other->authorAnswers()->create([
        'rating_group_id' => $group->id,
        'rating_option_id' => $second->id,
    ]);

    Livewire::test(FeedPage::class)
        ->call('toggleRatingOption', 'category', 'category_a')
        ->assertSee('Matching answer')
        ->assertDontSee('Other answer');
});

it('does not search feed until at least three characters are entered', function () {
    Post::factory()->published()->create(['title' => 'Pasta']);
    Post::factory()->published()->create(['title' => 'Cake']);

    Livewire::test(FeedPage::class)
        ->set('search', 'pa')
        ->assertSee('Pasta')
        ->assertSee('Cake')
        ->set('search', 'pas')
        ->assertSee('Pasta')
        ->assertDontSee('Cake');
});
