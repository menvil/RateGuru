<?php

use App\Livewire\Feed\FeedPage;
use App\Models\Post;
use App\Models\ProjectSettings;
use Livewire\Livewire;

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
