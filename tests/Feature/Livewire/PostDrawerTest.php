<?php

use App\Livewire\Feed\PostDrawer;
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

it('renders drawer vote summary with histogram after voting', function () {
    $this->seed(DefaultRatingConfigurationSeeder::class);

    $user = User::factory()->create();
    $post = Post::factory()->published()->create([
        'upvotes_count' => 12,
        'downvotes_count' => 3,
    ]);
    $source = RatingGroup::query()->where('key', 'source')->firstOrFail();
    [$sourceA, $sourceB] = $source->options()->ordered()->get()->all();

    RatingVote::factory()->count(7)->for($post)->for($source, 'group')->for($sourceA, 'option')->create();
    RatingVote::factory()->count(5)->for($post)->for($source, 'group')->for($sourceB, 'option')->create();
    // User's own vote triggers histogram display
    RatingVote::factory()->for($post)->for($source, 'group')->for($sourceA, 'option')->create(['user_id' => $user->id]);

    // sourceA = 8, sourceB = 5, total = 13 → 62% (8) / 38% (5)
    Livewire::actingAs($user)
        ->test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('Source')
        ->assertSee('Source A')
        ->assertSee('Source B')
        ->assertSee('62% (8)')
        ->assertSee('38% (5)');
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

it('renders source voting buttons in drawer before user votes', function () {
    $this->seed(DefaultRatingConfigurationSeeder::class);

    $post = Post::factory()->published()->create();

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('Source')
        ->assertSee('Source A')
        ->assertSee('Source B');
});

it('renders drawer rating histogram after user votes on source group', function () {
    $this->seed(DefaultRatingConfigurationSeeder::class);

    $user = User::factory()->create();
    $post = Post::factory()->published()->create();
    $source = RatingGroup::query()->where('key', 'source')->firstOrFail();
    [$sourceA, $sourceB] = $source->options()->ordered()->get()->all();

    RatingVote::factory()->count(3)->for($post)->for($source, 'group')->for($sourceA, 'option')->create();
    RatingVote::factory()->count(2)->for($post)->for($source, 'group')->for($sourceB, 'option')->create();
    RatingVote::factory()->for($post)->for($source, 'group')->for($sourceA, 'option')->create(['user_id' => $user->id]);

    // sourceA = 4, sourceB = 2, total = 6 → 67% (4) / 33% (2)
    Livewire::actingAs($user)
        ->test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('Source A')
        ->assertSee('67% (4)')
        ->assertSee('33% (2)');
});

it('renders drawer rating histogram with percentage and vote count on same line', function () {
    $this->seed(DefaultRatingConfigurationSeeder::class);

    $user = User::factory()->create();
    $post = Post::factory()->published()->create();
    $source = RatingGroup::query()->where('key', 'source')->firstOrFail();
    [$sourceA, $sourceB] = $source->options()->ordered()->get()->all();

    RatingVote::factory()->count(3)->for($post)->for($source, 'group')->for($sourceA, 'option')->create();
    RatingVote::factory()->count(2)->for($post)->for($source, 'group')->for($sourceB, 'option')->create();
    RatingVote::factory()->for($post)->for($source, 'group')->for($sourceA, 'option')->create(['user_id' => $user->id]);

    $html = Livewire::actingAs($user)
        ->test(PostDrawer::class, ['postId' => $post->id])
        ->html();

    // source is a binary group → side-by-side percentages with vote counts in parens
    // user's vote is included: sourceA=4, sourceB=2, total=6 → 67% (4) / 33% (2)
    expect($html)->toContain('67% (4)');
    expect($html)->toContain('33% (2)');
});

it('uses rating group question as drawer section header', function () {
    $this->seed(DefaultRatingConfigurationSeeder::class);

    $post = Post::factory()->published()->create();

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('Source')
        ->assertSee('Category');
});

it('renders category voting buttons in drawer', function () {
    $this->seed(DefaultRatingConfigurationSeeder::class);

    $post = Post::factory()->published()->create();

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('Category A')
        ->assertSee('Category B')
        ->assertSee('Category C');
});

it('renders drawer rating groups in order', function () {
    $this->seed(DefaultRatingConfigurationSeeder::class);

    $post = Post::factory()->published()->create();

    $html = Livewire::test(PostDrawer::class, ['postId' => $post->id])->html();

    expect(strpos($html, 'Source'))->toBeLessThan(strpos($html, 'Category'));
});

it('does not listen for vote events so the card does not reload on votes', function () {
    // The drawer delegates vote refreshes to the nested post-voting /
    // rating-voting components, so it must not register its own vote listeners.
    $component = file_get_contents(app_path('Livewire/Feed/PostDrawer.php'));

    expect($component)
        ->not->toContain("#[On('rating-voted')]")
        ->not->toContain("#[On('post-voted')]");
});
