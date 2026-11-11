<?php

use App\Models\GameVersion;
use App\Models\GameVersionPost;
use App\Models\Post;

/**
 * A version's listing of a post is keyed by the version and the post together:
 * another version listing the same post is a row of its own, untouched by
 * anything done to this one.
 */
it('refreshes and deletes one version\'s listing of a post, never another version\'s', function () {
    $post = Post::factory()->create();
    [$first, $second] = GameVersion::factory()->count(2)->create()->all();

    foreach ([$first, $second] as $version) {
        GameVersionPost::query()->create(['version_id' => $version->id, 'post_id' => $post->id]);
    }

    $listing = GameVersionPost::query()->where('version_id', $second->id)->sole();

    expect($listing->fresh()?->version_id)->toBe($second->id);

    $listing->delete();

    expect(GameVersionPost::query()->pluck('version_id')->all())->toBe([$first->id]);
});
