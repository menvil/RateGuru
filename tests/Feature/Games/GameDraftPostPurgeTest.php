<?php

use App\Actions\Posts\DeletePostAction;
use App\Enums\GameOptionSource;
use App\Enums\PostPurgeOutcome;
use App\Models\GameRoundOption;
use App\Models\Post;
use App\Models\User;
use App\Services\Posts\PostRetentionPurgeService;

/*
 * A draft round may show a post, but it never holds the post back: the post
 * purge detaches the option (game_round_options.post_id is SET NULL) and the
 * round then asks its editor for another post. Purges run through the real
 * sanctioned boundary.
 */

it('lets the post purge go ahead and detaches the draft option that showed the post', function () {
    config(['posts.author_delete_retention_days' => 30]);
    $owner = User::factory()->create();
    $post = Post::factory()->published()->withImage()->for($owner)->create();
    $option = GameRoundOption::factory()->create(['post_id' => $post->id]);

    app(DeletePostAction::class)->handle($owner, $post);
    Post::withTrashed()->whereKey($post->id)->update(['deleted_at' => now()->subDays(40)]);

    expect(app(PostRetentionPurgeService::class)->purge($post->id))->toBe(PostPurgeOutcome::Purged)
        ->and(Post::withTrashed()->find($post->id))->toBeNull()
        ->and($option->fresh()->post_id)->toBeNull()
        ->and($option->fresh()->source)->toBe(GameOptionSource::Post);
});
