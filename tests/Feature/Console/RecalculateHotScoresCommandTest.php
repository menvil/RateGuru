<?php

use App\Models\Post;
use Carbon\CarbonImmutable;

it('has recalculate hot scores command', function () {
    $this->artisan('posts:recalculate-hot-scores')
        ->assertExitCode(0);
});

it('handles empty database gracefully', function () {
    $this->artisan('posts:recalculate-hot-scores')
        ->expectsOutput('Recalculated hot scores for 0 posts.')
        ->assertExitCode(0);
});

it('recalculates hot scores for all posts', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-14 12:00:00'));

    $first = Post::factory()->published()->create([
        'upvotes_count' => 10,
        'downvotes_count' => 0,
        'comments_count' => 1,
        'hot_score' => 0,
        'created_at' => CarbonImmutable::parse('2026-05-14 10:00:00'),
    ]);

    $second = Post::factory()->published()->create([
        'upvotes_count' => 2,
        'downvotes_count' => 0,
        'comments_count' => 5,
        'hot_score' => 0,
        'created_at' => CarbonImmutable::parse('2026-05-13 10:00:00'),
    ]);

    $this->artisan('posts:recalculate-hot-scores')
        ->expectsOutput('Recalculated hot scores for 2 posts.')
        ->assertExitCode(0);

    expect((float) $first->fresh()->hot_score)->toBeGreaterThan(0);
    expect((float) $second->fresh()->hot_score)->toBeGreaterThan(0);
});

it('recalculates hot scores using chunk option', function () {
    Post::factory()->count(3)->published()->create([
        'upvotes_count' => 1,
        'hot_score' => 0,
    ]);

    $this->artisan('posts:recalculate-hot-scores --chunk=1')
        ->assertExitCode(0);

    expect(Post::query()->where('hot_score', '>', 0)->count())->toBe(3);
});

it('handles invalid chunk size by defaulting to minimum', function (string $chunk): void {
    $post = Post::factory()->published()->create([
        'upvotes_count' => 1,
        'hot_score' => 0,
    ]);

    $this->artisan('posts:recalculate-hot-scores', [
        '--chunk' => $chunk,
    ])->assertExitCode(0);

    expect((float) $post->fresh()->hot_score)->toBeGreaterThan(0);
})->with(['0', '-5', 'not-a-number']);

it('continues processing posts and reports failures', function () {
    $valid = Post::factory()->published()->create([
        'upvotes_count' => 1,
        'hot_score' => 0,
    ]);

    Post::factory()->published()->create([
        'created_at' => null,
        'hot_score' => 0,
    ]);

    $this->artisan('posts:recalculate-hot-scores')
        ->expectsOutput('Recalculated hot scores for 1 posts.')
        ->assertExitCode(1);

    expect((float) $valid->fresh()->hot_score)->toBeGreaterThan(0);
});
