<?php

use App\Enums\CuisineType;
use App\Enums\OriginType;
use App\Livewire\Feed\PostDrawer;
use App\Models\CuisineVote;
use App\Models\OriginVote;
use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\RatingVote;
use App\Models\User;
use App\Support\Urls\PostUrl;
use Database\Seeders\DefaultRatingConfigurationSeeder;
use Livewire\Livewire;

it('can render post drawer component', function () {
    Livewire::test(PostDrawer::class)
        ->assertStatus(200);
});

it('renders report button in post drawer', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('data-testid="report-button"', false)
        ->assertSee('Report')
        ->assertSee('text-rg-dangerText', false)
        ->assertSee('hover:bg-rg-dangerSoft', false);
});

it('does not render report button in post drawer for the owner', function () {
    $owner = User::factory()->create();
    $post = Post::factory()->published()->for($owner)->create();

    Livewire::actingAs($owner)
        ->test(PostDrawer::class, ['postId' => $post->id])
        ->assertDontSee('data-testid="report-button"', false);
});

it('renders post voting component in drawer', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('data-testid="post-drawer-voting"', false);
});

it('renders selected published post', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Homemade Carbonara',
        'description' => 'Creamy pasta with pepper',
    ]);

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('Homemade Carbonara')
        ->assertSee('Creamy pasta with pepper');
});

it('renders share panel in post drawer for published post', function () {
    config(['app.url' => 'https://rateguru.test']);

    $post = Post::factory()->published()->create();

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('Share this post')
        ->assertSee('data-testid="post-share-panel"', false)
        ->assertSee('data-testid="post-share-copy"', false)
        ->assertDontSee('Open post')
        ->assertDontSee('data-testid="post-drawer-share-panel"', false)
        ->assertSee(app(PostUrl::class)->canonical($post));
});

it('hides save action from guests in the post drawer', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertDontSee('data-testid="save-post-button"', false)
        ->assertDontSee('>Save<', false);
});

it('does not render public share panel in drawer for hidden post', function () {
    $post = Post::factory()->hidden()->create();

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertDontSee('data-testid="post-share-panel"', false);
});

it('does not render hidden post', function () {
    $post = Post::factory()->hidden()->create([
        'title' => 'Hidden Dish',
    ]);

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertDontSee('Hidden Dish')
        ->assertSee('Post not found');
});

it('renders not found state for missing post', function () {
    Livewire::test(PostDrawer::class, ['postId' => 999999])
        ->assertSee('Post not found')
        ->assertSee('This post is unavailable');
});

it('renders not found state for pending post', function () {
    $post = Post::factory()->pending()->create([
        'title' => 'Pending Hidden',
    ]);

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('Post not found')
        ->assertDontSee('Pending Hidden');
});

it('has drawer loading state markup', function () {
    Livewire::test(PostDrawer::class)
        ->assertSee('data-testid="post-drawer-loading"', false)
        ->assertSee('wire:loading', false);
});

it('renders the comments section in drawer', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('Comments')
        ->assertSee('data-testid="comments-section"', false)
        ->assertDontSee('Comments will appear here');
});

it('renders drawer vote summary', function () {
    $this->seed(DefaultRatingConfigurationSeeder::class);

    $post = Post::factory()->published()->create([
        'upvotes_count' => 12,
        'downvotes_count' => 3,
    ]);
    $source = RatingGroup::query()->where('key', 'source')->firstOrFail();
    [$sourceA, $sourceB] = $source->options()->ordered()->get()->all();

    RatingVote::factory()->count(7)->for($post)->for($source, 'group')->for($sourceA, 'option')->create();
    RatingVote::factory()->count(5)->for($post)->for($source, 'group')->for($sourceB, 'option')->create();

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('Source')
        ->assertSee('Source A')
        ->assertSee('Source B')
        ->assertSee('7 votes')
        ->assertSee('5 votes')
        ->assertDontSee('(unvoted)')
        ->assertDontSee('0 votes');
});

it('renders drawer author metadata', function () {
    $user = User::factory()->create([
        'name' => 'Demo Chef',
        'username' => 'demo_chef',
    ]);

    $post = Post::factory()->published()->for($user)->create();

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('data-testid="post-drawer-meta"', false)
        ->assertSee('Demo Chef')
        ->assertSee('@demo_chef')
        ->assertDontSee('Posted by');
});

it('renders large post image in drawer', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Dish',
        'image_url' => '/storage/posts/1/dish.jpg',
    ]);

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('/storage/posts/1/dish.jpg')
        ->assertSee('alt="Dish"', false);
});

it('renders drawer post title and description', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Homemade Carbonara',
        'description' => 'Creamy pasta with pepper',
    ]);

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('Homemade Carbonara')
        ->assertSee('Creamy pasta with pepper');
});

it('renders drawer description under title before image', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Drawer Tacos',
        'description' => 'Description should sit below the title',
        'image_url' => '/storage/posts/1/drawer-tacos.jpg',
    ]);

    $html = Livewire::test(PostDrawer::class, ['postId' => $post->id])->html();

    expect(strpos($html, 'Drawer Tacos'))->toBeLessThan(strpos($html, 'Description should sit below the title'));
    expect(strpos($html, 'Description should sit below the title'))->toBeLessThan(strpos($html, '/storage/posts/1/drawer-tacos.jpg'));
});

it('does not break when drawer post description is missing', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Dish',
        'description' => null,
    ]);

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('Dish')
        ->assertSee('Score')
        ->assertSee('Comments');
});

it('renders image placeholder when drawer post has no image', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Dish',
        'image_url' => null,
    ]);

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('Image preview');
});

it('renders source voting panel in drawer', function () {
    $this->seed(DefaultRatingConfigurationSeeder::class);

    $post = Post::factory()->published()->create();

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('Source')
        ->assertSee('post-drawer-source-voting', false)
        ->assertSee('Source A')
        ->assertSee('Source B')
        ->assertDontSee('Results');
});

it('renders drawer source controls before result labels', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create([
        'homemade_votes_count' => 1,
        'restaurant_votes_count' => 1,
    ]);

    OriginVote::factory()->create([
        'user_id' => $user->id,
        'post_id' => $post->id,
        'origin' => OriginType::Homemade,
    ]);

    Livewire::actingAs($user)
        ->test(PostDrawer::class, ['postId' => $post->id])
        ->assertSeeInOrder([
            'post-drawer-source-voting',
            'Source A</span>',
            'Source B</span>',
        ], false)
        ->assertDontSee('You voted:');
});

it('renders drawer result percentages with vote counts after voting', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create([
        'homemade_votes_count' => 3,
        'restaurant_votes_count' => 2,
    ]);

    OriginVote::factory()->create([
        'user_id' => $user->id,
        'post_id' => $post->id,
        'origin' => OriginType::Homemade,
    ]);

    CuisineVote::factory()->for($post)->create([
        'user_id' => $user->id,
        'cuisine' => CuisineType::Mexican,
    ]);
    CuisineVote::factory()->for($post)->create(['cuisine' => CuisineType::Italian]);

    Livewire::actingAs($user)
        ->test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('60% (3)')
        ->assertSee('40% (2)')
        ->assertSee('50% (1)');
});

it('keeps drawer result visibility logic in the Livewire component', function () {
    $view = file_get_contents(resource_path('views/livewire/feed/post-drawer.blade.php'));

    expect($view)
        ->toContain('$showOriginDistribution')
        ->toContain('$showCuisineDistribution')
        ->not->toContain("originDistribution['current']")
        ->not->toContain("cuisineDistribution['current']");
});

it('renders category voting buttons in drawer', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('data-testid="post-drawer-category-voting"', false)
        ->assertSee('flex-wrap', false)
        ->assertSee('h-7 min-w-9', false)
        ->assertSee('Category A')
        ->assertSee('Category B')
        ->assertSee('Category C')
        ->assertSee('Category D')
        ->assertSee('Other');
});

it('renders drawer category controls directly under the distribution heading', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSeeInOrder([
            'Category',
            'data-testid="post-drawer-category-voting"',
        ], false);
});

it('refreshes after semantic source and category vote events', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->dispatch('source-voted', postId: $post->id)
        ->dispatch('category-voted', postId: $post->id)
        ->assertOk();
});
