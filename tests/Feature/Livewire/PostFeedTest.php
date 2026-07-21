<?php

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

it('can render post feed component', function () {
    Livewire::test(PostFeed::class)
        ->assertStatus(200);
});

it('shows published post title', function () {
    Post::factory()->published()->create(['title' => 'Homemade Carbonara']);

    Livewire::test(PostFeed::class)
        ->assertSee('Homemade Carbonara');
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
    Post::factory()->published()->create(['title' => 'Homemade Carbonara']);

    Livewire::test(PostFeed::class)
        ->assertSee('data-testid="post-card"', false)
        ->assertSee('Homemade Carbonara');
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

    $source = RatingGroup::query()->where('key', 'source')->firstOrFail();
    [$sourceA, $sourceB] = $source->options()->ordered()->get()->all();

    RatingVote::factory()->count(2)->for($post)->for($source, 'group')->for($sourceA, 'option')->create();
    RatingVote::factory()->count(2)->for($post)->for($source, 'group')->for($sourceB, 'option')->create();
    // Current user's vote makes the histogram show (sourceA=3, sourceB=2, total=5)
    RatingVote::factory()->for($post)->for($source, 'group')->for($sourceA, 'option')->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test(PostFeed::class)
        ->assertSee('Bulk Loaded Results')
        ->assertSee('60% (3)')
        ->assertSee('40% (2)');
});
