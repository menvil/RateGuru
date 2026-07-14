<?php

use App\Livewire\Feed\FeedPage;
use App\Models\Post;
use App\Models\ProjectSettings;
use Livewire\Livewire;

it('uses the split-grid layout by default when the overlay flag is off', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(FeedPage::class)
        ->call('selectPost', $post->id)
        ->assertSee('data-testid="post-detail-column"', false)
        ->assertSee('rg-feed-split-grid', false);
});

it('does not render the split column itself when the overlay flag is enabled', function () {
    ProjectSettings::factory()->create([
        'feature_flags' => ['post_detail_overlay_mode' => true],
    ]);

    // The global sliding overlay (layouts/app.blade.php + PostDrawer asOverlay=true)
    // now owns the post detail panel; FeedPage no longer renders anything for it.
    $post = Post::factory()->published()->create();

    Livewire::test(FeedPage::class)
        ->call('selectPost', $post->id)
        ->assertDontSee('data-testid="post-detail-column"', false)
        ->assertDontSee('rg-feed-split-grid', false);
});

it('clears the selected post id used for feed scroll behavior', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(FeedPage::class)
        ->call('selectPost', $post->id)
        ->assertSet('selectedPostId', $post->id)
        ->call('clearSelectedPost')
        ->assertSet('selectedPostId', null);
});
