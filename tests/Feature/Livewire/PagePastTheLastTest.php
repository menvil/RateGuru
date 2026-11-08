<?php

use App\Actions\Posts\DeletePostAction;
use App\Livewire\Feed\PostFeed;
use App\Livewire\Posts\RecentlyDeletedPostsPage;
use App\Livewire\Profile\ProfilePage;
use App\Livewire\SavedPosts\SavedPostsPage;
use App\Models\Post;
use App\Models\PostSave;
use App\Models\ProjectSettings;
use App\Models\User;
use Livewire\Livewire;

/*
 * A list opened on a page past its last — an old link, or a list that shrank
 * meanwhile — shows its last page rather than saying there is nothing in it.
 */

it('shows the last page of a list asked for a page past it', function (string $list) {
    $owner = User::factory()->create(['username' => 'page_owner']);
    $post = Post::factory()->published()->for($owner)->create(['title' => 'The only post there is']);

    [$component, $parameters] = match ($list) {
        'feed' => [PostFeed::class, []],
        'saved' => [SavedPostsPage::class, []],
        'deleted' => [RecentlyDeletedPostsPage::class, []],
        'profile' => [ProfilePage::class, ['username' => 'page_owner']],
    };

    if ($list === 'saved') {
        ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => true]]);
        PostSave::factory()->create(['user_id' => $owner->id, 'post_id' => $post->id]);
    }

    if ($list === 'deleted') {
        app(DeletePostAction::class)->handle($owner, $post);
    }

    Livewire::actingAs($owner)
        ->withQueryParams(['page' => 9])
        ->test($component, $parameters)
        ->assertSet('paginators.page', 1)
        ->assertSee('The only post there is');
})->with(['feed', 'saved', 'deleted', 'profile']);

it('shows the last of several pages, not the first', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => true]]);
    $owner = User::factory()->create();
    // A page more than the twelve the saved posts show at once.
    Post::factory()->count(13)->published()->create()
        ->each(fn (Post $post) => PostSave::factory()->create(['user_id' => $owner->id, 'post_id' => $post->id]));

    Livewire::actingAs($owner)
        ->withQueryParams(['page' => 9])
        ->test(SavedPostsPage::class)
        ->assertSet('paginators.page', 2);
});
