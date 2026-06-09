<?php

use App\Livewire\Feed\PostFeed;
use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\RatingVote;
use App\Models\User;
use Database\Seeders\DefaultRatingConfigurationSeeder;
use Illuminate\Support\Facades\Blade;

it('renders post voting component in post card', function () {
    $post = Post::factory()->published()->create();

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)->toContain('post-card-voting');
});

it('renders post card title', function () {
    $post = Post::factory()->published()->make(['title' => 'Homemade Carbonara']);

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)->toContain('Homemade Carbonara');
});

it('renders post image when image url exists', function () {
    $post = Post::factory()->published()->make([
        'title' => 'Dish',
        'image_url' => '/storage/posts/1/dish.jpg',
    ]);

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)->toContain('/storage/posts/1/dish.jpg');
});

it('renders post image from image path when image url is missing', function () {
    $post = Post::factory()->published()->make([
        'title' => 'Dish',
        'image_path' => 'posts/1/dish.jpg',
        'image_url' => null,
    ]);

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)->toContain('/storage/posts/1/dish.jpg');
});

it('renders image placeholder when image url is missing', function () {
    $post = Post::factory()->published()->make([
        'image_path' => null,
        'image_url' => null,
    ]);

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)->toContain('Post image');
});

it('renders post title and description', function () {
    $post = Post::factory()->published()->make([
        'title' => 'Homemade Carbonara',
        'description' => 'Creamy pasta with pepper',
    ]);

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)
        ->toContain('Homemade Carbonara')
        ->toContain('Creamy pasta with pepper');
});

it('renders post description under the title before the image', function () {
    $post = Post::factory()->published()->make([
        'title' => 'Street Tacos',
        'description' => 'Corn tortillas, salsa, cilantro, and a street-food presentation',
        'image_url' => '/storage/posts/1/tacos.jpg',
    ]);

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    $titlePosition = strpos($html, 'Street Tacos');
    $descriptionPosition = strpos($html, 'Corn tortillas, salsa, cilantro, and a street-food presentation');
    $imagePosition = strpos($html, '/storage/posts/1/tacos.jpg');

    expect($titlePosition)->not->toBeFalse()
        ->and($descriptionPosition)->not->toBeFalse()
        ->and($imagePosition)->not->toBeFalse()
        ->and($titlePosition)->toBeLessThan($descriptionPosition)
        ->and($descriptionPosition)->toBeLessThan($imagePosition);
});

it('renders mobile-safe post card structure', function () {
    $post = Post::factory()->published()->make([
        'title' => 'Very long dish title that should wrap safely on narrow screens',
        'description' => 'Compact mobile text should not force horizontal scrolling.',
    ]);

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)
        ->toContain('overflow-hidden')
        ->toContain('break-words')
        ->toContain('flex-wrap')
        ->toContain('hover:bg-rg-cardHover')
        ->toContain('focus-visible:ring-rg-accent');
});

it('does not break when description is missing', function () {
    $post = Post::factory()->published()->make([
        'title' => 'Dish',
        'description' => null,
    ]);

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)->toContain('Dish');
});

it('renders post stats area', function () {
    $post = Post::factory()->published()->make([
        'upvotes_count' => 12,
        'downvotes_count' => 3,
        'comments_count' => 5,
    ]);

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)
        ->toContain('9')
        ->toContain('5 comments');
});

it('renders post author area', function () {
    $user = User::factory()->make([
        'name' => 'Demo Chef',
        'username' => 'demo_chef',
    ]);

    $post = Post::factory()->published()->make(['title' => 'Dish']);
    $post->setRelation('user', $user);

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)
        ->toContain('Demo Chef')
        ->toContain('@demo_chef');
});

it('post card dispatches select post event with post id', function () {
    $post = Post::factory()->published()->create();

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)
        ->toContain("select-post', { postId: {$post->id} }")
        ->toContain('post-card-voting')
        ->toContain('grid-cols-[32px_minmax(0,1fr)]');
});

it('post card uses a null select post id for unsaved previews', function () {
    $post = Post::factory()->published()->make();

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)->toContain("select-post', { postId: null }");
});

it('renders report button in post card menu for persisted posts', function () {
    $post = Post::factory()->published()->create();

    $html = Blade::render('<x-feed.post-card :post="$post" :can-report-post="true" />', ['post' => $post]);

    expect($html)
        ->toContain('data-testid="post-card-report"')
        ->toContain('Report')
        ->toContain('text-rg-dangerText')
        ->toContain('hover:bg-rg-dangerSoft');
});

it('renders feed card vote error placeholder below footer actions', function () {
    $post = Post::factory()->published()->create();

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)
        ->toContain('x-on:post-vote-error.window')
        ->toContain('data-testid="post-card-vote-error"')
        ->toContain('postVoteError');
});

it('does not render report button for unsaved post preview', function () {
    $this->actingAs(User::factory()->create());

    $post = Post::factory()->published()->make(['title' => 'Preview dish']);

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)
        ->toContain('Preview dish')
        ->not->toContain('data-testid="post-card-report"');
});

it('does not render report button for the post owner', function () {
    $owner = User::factory()->create();
    $post = Post::factory()->published()->for($owner)->create();

    $this->actingAs($owner);

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)->not->toContain('data-testid="post-card-report"');
});

it('renders delete action in the post card menu for the owner', function () {
    $post = Post::factory()->published()->create();

    $html = Blade::render('<x-feed.post-card :post="$post" :can-delete-post="true" />', ['post' => $post]);

    expect($html)
        ->toContain('data-testid="post-card-delete"')
        ->toContain('Delete post')
        ->toContain("delete-post', { postId: {$post->id} }");
});

it('places post card actions menu in the footer row', function () {
    $post = Post::factory()->published()->create();

    $html = Blade::render('<x-feed.post-card :post="$post" :can-delete-post="true" />', ['post' => $post]);

    expect($html)
        ->toContain('justify-between')
        ->toContain('bottom-full right-0')
        ->toContain('aria-label="Post actions"');
});

it('does not show no actions fallback when moderation actions may render', function () {
    $post = Post::factory()->published()->create();

    $html = Blade::render('<x-feed.post-card :post="$post" :can-moderate-post="true" />', ['post' => $post]);

    expect($html)
        ->toContain('post-card-moderation')
        ->not->toContain('No actions');
});

it('hides save action from guests on feed cards', function () {
    $post = Post::factory()->published()->create();

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)
        ->not->toContain('data-testid="save-post-button"')
        ->not->toContain('>Save<');
});

it('renders delete action in the post card menu for moderators', function () {
    $post = Post::factory()->published()->create();

    $html = Blade::render('<x-feed.post-card :post="$post" :can-delete-post="true" />', ['post' => $post]);

    expect($html)->toContain('data-testid="post-card-delete"');
});

it('does not render delete action in the post card menu for another user', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    $this->actingAs($user);

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)->not->toContain('data-testid="post-card-delete"');
});

it('renders source voting component in post card for persisted posts', function () {
    $post = Post::factory()->published()->create();

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)
        ->toContain('post-card-source-voting')
        ->toContain('Source')
        ->not->toContain('What do you think?');
});

it('renders feed card rating histogram via preloaded state after the current user votes', function () {
    $this->seed(DefaultRatingConfigurationSeeder::class);

    $user = User::factory()->create();
    $post = Post::factory()->published()->create();
    $source = RatingGroup::query()->where('key', 'source')->firstOrFail();
    [$sourceA, $sourceB] = $source->options()->ordered()->get()->all();

    RatingVote::factory()->count(2)->for($post)->for($source, 'group')->for($sourceA, 'option')->create();
    RatingVote::factory()->count(2)->for($post)->for($source, 'group')->for($sourceB, 'option')->create();
    RatingVote::factory()->for($post)->for($source, 'group')->for($sourceA, 'option')->create(['user_id' => $user->id]);

    $this->actingAs($user);

    $html = Livewire\Livewire::actingAs($user)
        ->test(PostFeed::class)
        ->html();

    // binary source group → 60% (3) / 40% (2)
    expect($html)
        ->toContain('60% (3)')
        ->toContain('40% (2)');
});

it('does not render rating histogram before the current user votes', function () {
    $this->seed(DefaultRatingConfigurationSeeder::class);

    $post = Post::factory()->published()->create();

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    // No selected option → buttons shown, not the results block
    expect($html)->not->toContain('post-card-origin-results');
});

it('does not break rendering on an unsaved post', function () {
    $post = Post::factory()->published()->make();

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)->toContain('post-card');
    // Unsaved posts must not render the interactive Livewire source component.
    expect($html)->not->toContain('post-card-source-voting');
});

it('keeps post card free of service locator vote and authorization queries', function () {
    $component = file_get_contents(app_path('View/Components/Feed/PostCard.php'));
    $view = file_get_contents(resource_path('views/components/feed/post-card.blade.php'));

    expect($view)
        ->not->toContain('PostVoteResultService')
        ->not->toContain('app(');

    expect($component)
        ->not->toContain('PostVoteResultService')
        ->not->toContain('auth()')
        ->not->toContain('app(');
});

it('delegates result rendering to the rating voting components', function () {
    $view = file_get_contents(resource_path('views/components/feed/post-card.blade.php'));

    // Post card no longer renders its own distribution blocks; the rating
    // voting Livewire components (and their rating-options view) own that.
    expect($view)
        ->not->toContain('post-card-origin-results')
        ->not->toContain('post-card-cuisine-results')
        ->toContain('posts.source-voting')
        ->toContain('posts.category-voting');
});
