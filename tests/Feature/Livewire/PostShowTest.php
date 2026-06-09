<?php

use App\Livewire\Posts\PostShow;
use App\Models\Post;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

it('can render post show component for published post', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Homemade Carbonara',
    ]);

    Livewire::test(PostShow::class, ['post' => $post])
        ->assertStatus(200)
        ->assertSee('Homemade Carbonara');
});

it('renders generic post show copy', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Generic Test Post',
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('Generic Test Post')
        ->assertSee('Source')
        ->assertSee('Category')
        ->assertDontSee('Cuisine guess')
        ->assertDontSee('Homemade')
        ->assertDontSee('Restaurant');
});

it('does not resolve an unpublished post', function () {
    $post = Post::factory()->hidden()->create();

    expect(fn () => Livewire::test(PostShow::class, ['post' => $post]))
        ->toThrow(ModelNotFoundException::class);
});

it('renders post show page without share side panel', function () {
    $post = Post::factory()->published()->create();

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertDontSee('data-testid="post-show-share-trigger"', false)
        ->assertDontSee('data-testid="post-share-panel"', false);
});

it('uses feed-card title and description sizing on post show page', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Smaller Post Title',
        'description' => 'Post description should match feed sizing.',
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('text-base font-bold leading-snug', false)
        ->assertSee('text-[13px] leading-snug', false);
});

it('renders post show comments header with count and top sort', function () {
    $post = Post::factory()->published()->create([
        'comments_count' => 0,
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('Comments (0)')
        ->assertSee('data-testid="comments-sort-trigger"', false)
        ->assertSee('Top');
});

it('has post show comment scroll action', function () {
    $post = Post::factory()->published()->create([
        'comments_count' => 3,
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('data-testid="post-show-comments-scroll"', false)
        ->assertSee('scrollToComments');
});

it('refreshes the score panel on post votes but not on rating votes', function () {
    // post-voted updates the score summary; rating results update in place via
    // the nested rating-voting components, so the page must not listen for them.
    $component = file_get_contents(app_path('Livewire/Posts/PostShow.php'));

    expect($component)
        ->toContain("#[On('post-voted')]")
        ->not->toContain("#[On('rating-voted')]")
        ->not->toContain("#[On('source-voted')]")
        ->not->toContain("#[On('category-voted')]");
});
