<?php

use App\Enums\PostStatus;
use App\Enums\UserStatus;
use App\Livewire\Posts\PostShow;
use App\Models\Category;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

it('can render post show component for published post', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Sample Post',
    ]);

    Livewire::test(PostShow::class, ['post' => $post])
        ->assertStatus(200)
        ->assertSee('Sample Post');
});

it('renders the standalone post category on the public page', function () {
    $category = Category::factory()->create(['name' => 'Desserts', 'slug' => 'desserts']);
    $post = Post::factory()->published()->create(['category_id' => $category->id]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('data-testid="post-show-category"', false)
        ->assertSee('Desserts');
});

it('renders generic post show copy', function () {
    RatingGroup::factory()->create(['key' => 'type', 'label' => 'Type', 'sort_order' => 10]);
    RatingGroup::factory()->create(['key' => 'attribute', 'label' => 'Attribute', 'sort_order' => 20]);

    $post = Post::factory()->published()->create([
        'title' => 'Generic Test Post',
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('Generic Test Post')
        ->assertSee('Type')
        ->assertSee('Attribute');
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
        ->not->toContain("#[On('rating-voted')]");
});

it('renders save button on post show page when feature is enabled', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => true]]);

    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    $this->actingAs($user)
        ->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('data-testid="save-post-button"', false);
});

it('hides save button on post show page when feature is disabled', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => false]]);

    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    $this->actingAs($user)
        ->get(route('posts.show', $post))
        ->assertOk()
        ->assertDontSee('data-testid="save-post-button"', false);
});

it('deletes an owned post from the post show page delete event and redirects to the feed', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->for($user)->create();

    Livewire::actingAs($user)
        ->test(PostShow::class, ['post' => $post])
        ->call('deletePost')
        ->assertRedirect(route('feed'));

    expect(Post::withTrashed()->find($post->id)->status)->toBe(PostStatus::Deleted);
});

it('does not delete another users post from the post show page delete event', function () {
    // Status is Limited (not Active) so the report action is unavailable and the
    // page's action menu (with its lazily-loaded report/moderation children) is
    // not rendered on this second call; keeps this test isolated from an
    // unrelated, pre-existing WIP breakage in report-modal.blade.php that
    // surfaces whenever that lazily-loaded component re-renders. Ownership
    // (not status) is still what the delete authorization check exercises.
    $user = User::factory()->create(['status' => UserStatus::Limited]);
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(PostShow::class, ['post' => $post])
        ->call('deletePost')
        ->assertNoRedirect();

    expect(Post::query()->find($post->id))->not->toBeNull();
});
