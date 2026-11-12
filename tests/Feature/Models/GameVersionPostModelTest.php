<?php

use App\Models\GameVersion;
use App\Models\GameVersionPost;
use App\Models\Post;

/**
 * A version's listing of a post is keyed by the version and the post together:
 * another version listing the same post, and another post the same version
 * lists, are rows of their own, untouched by anything done to this one.
 */
it('refreshes and deletes one version\'s listing of one post, never another version\'s or another post\'s', function () {
    $post = Post::factory()->create();
    $other = Post::factory()->create();
    [$first, $second] = GameVersion::factory()->count(2)->create()->all();

    foreach ([$first, $second] as $version) {
        GameVersionPost::query()->create(['version_id' => $version->id, 'post_id' => $post->id]);
    }

    GameVersionPost::query()->create(['version_id' => $second->id, 'post_id' => $other->id]);

    $listing = GameVersionPost::query()->where('version_id', $second->id)->where('post_id', $post->id)->sole();

    expect($listing->fresh()?->version_id)->toBe($second->id)
        ->and($listing->fresh()?->post_id)->toBe($post->id);

    $listing->delete();

    expect(GameVersionPost::query()->orderBy('version_id')->orderBy('post_id')->get()
        ->map(fn (GameVersionPost $row): array => [$row->version_id, $row->post_id])->all())
        ->toBe([[$first->id, $post->id], [$second->id, $other->id]]);
});
