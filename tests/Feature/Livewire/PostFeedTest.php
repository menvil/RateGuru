<?php

use App\Actions\Posts\DeletePostAction;
use App\Livewire\Feed\PostFeed;
use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\RatingVote;
use App\Models\User;
use Database\Seeders\DefaultRatingConfigurationSeeder;
use Livewire\Livewire;

it('refreshes feed after upload success event', function () {
    $user = User::factory()->trusted()->create();

    Livewire::actingAs($user)
        ->test(PostFeed::class)
        ->dispatch('post-uploaded')
        ->assertStatus(200);
});

it('shows newly published post after upload event', function () {
    $user = User::factory()->trusted()->create();

    $component = Livewire::actingAs($user)
        ->test(PostFeed::class)
        ->assertSee('No posts yet');

    Post::factory()->published()->create([
        'user_id' => $user->id,
        'title' => 'New Uploaded Dish',
    ]);

    $component
        ->dispatch('post-uploaded')
        ->assertSee('New Uploaded Dish');
});

it('drops a post its author deleted from the feed', function () {
    $author = User::factory()->create();
    $post = Post::factory()->published()->for($author)->create(['title' => 'Soon Deleted Dish']);

    $component = Livewire::actingAs($author)
        ->test(PostFeed::class)
        ->assertSee('Soon Deleted Dish');

    app(DeletePostAction::class)->handle($author, $post);

    $component
        ->dispatch('post-deleted', postId: $post->id)
        ->assertDontSee('Soon Deleted Dish');
});

it('can render post feed component', function () {
    Livewire::test(PostFeed::class)
        ->assertStatus(200);
});

it('shows published post title', function () {
    Post::factory()->published()->create(['title' => 'Sample Post']);

    Livewire::test(PostFeed::class)
        ->assertSee('Sample Post');
});

it('does not show pending post title', function () {
    Post::factory()->pending()->create(['title' => 'Pending Dish']);

    Livewire::test(PostFeed::class)
        ->assertDontSee('Pending Dish');
});

it('shows empty feed state when no published posts exist', function () {
    Livewire::test(PostFeed::class)
        ->assertSee('No posts yet');
});

it('renders empty feed state when there are no published posts', function () {
    Post::factory()->pending()->create(['title' => 'Pending Dish']);

    Livewire::test(PostFeed::class)
        ->assertSee('No posts yet')
        ->assertDontSee('Pending Dish');
});

it('has loading skeleton markup', function () {
    Livewire::test(PostFeed::class)
        ->assertSee('data-testid="post-feed-loading"', false)
        ->assertSee('transition-opacity', false);
});

it('renders post cards using the post card component', function () {
    Post::factory()->published()->create(['title' => 'Sample Post']);

    Livewire::test(PostFeed::class)
        ->assertSee('data-testid="post-card"', false)
        ->assertSee('Sample Post');
});

it('renders an arbitrary active rating group on every feed card', function () {
    $post = Post::factory()->published()->create(['title' => 'Open Question']);
    $group = RatingGroup::factory()->create([
        'key' => 'confidence',
        'label' => 'Confidence',
    ]);
    RatingOption::factory()->for($group, 'group')->create([
        'key' => 'low',
        'label' => 'Low',
        'sort_order' => 10,
    ]);
    RatingOption::factory()->for($group, 'group')->create([
        'key' => 'high',
        'label' => 'High',
        'sort_order' => 20,
    ]);

    Livewire::test(PostFeed::class)
        ->assertSee('Open Question')
        ->assertSee('data-testid="post-card-rating-confidence"', false)
        ->assertSee('data-testid="rating-voting-confidence-'.$post->id.'"', false)
        ->assertSee('Confidence');
});

it('passes bulk loaded post card vote results and permissions into feed cards', function () {
    $this->seed(DefaultRatingConfigurationSeeder::class);

    $user = User::factory()->create();
    $post = Post::factory()->published()->create([
        'title' => 'Bulk Loaded Results',
    ]);

    $type = RatingGroup::query()->where('key', 'type')->firstOrFail();
    [$typeA, $typeB] = $type->options()->ordered()->get()->all();

    RatingVote::factory()->count(2)->for($post)->for($type, 'group')->for($typeA, 'option')->create();
    RatingVote::factory()->count(2)->for($post)->for($type, 'group')->for($typeB, 'option')->create();
    // Current user's vote makes the histogram show (typeA=3, typeB=2, total=5)
    RatingVote::factory()->for($post)->for($type, 'group')->for($typeA, 'option')->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test(PostFeed::class)
        ->assertSee('Bulk Loaded Results')
        ->assertSee('60% (3)')
        ->assertSee('40% (2)');
});

it('fetches only the first card image eagerly, at high priority, and the rest lazily', function () {
    Post::factory()->published()->withImage(path: 'posts/1/first.jpg')->create(['published_at' => now()]);
    Post::factory()->published()->withImage(path: 'posts/2/second.jpg')->create(['published_at' => now()->subMinute()]);

    $images = livewireDom($this->get(route('feed', ['sort' => 'newest']))->assertOk()->getContent())
        ->query("//*[@data-testid='post-card-image-open']//img");

    expect($images)->toHaveCount(2)
        ->and($images->item(0)->hasAttribute('loading'))->toBeFalse()
        ->and($images->item(0)->getAttribute('fetchpriority'))->toBe('high')
        ->and($images->item(1)->getAttribute('loading'))->toBe('lazy')
        ->and($images->item(1)->hasAttribute('fetchpriority'))->toBeFalse();
});

it('serves the original image with no srcset while its variants have not been generated', function () {
    Post::factory()->published()->withImage(path: 'posts/1/original.jpg')->create();

    $image = livewireDom($this->get(route('feed'))->assertOk()->getContent())
        ->query("//*[@data-testid='post-card-image-open']//img")->item(0);

    expect($image?->getAttribute('src'))->toContain('posts/1/original.jpg')
        ->and($image?->hasAttribute('srcset'))->toBeFalse();
});
