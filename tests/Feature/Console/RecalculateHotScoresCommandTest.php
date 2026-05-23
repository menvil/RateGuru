<?php

use App\Models\Post;
use Carbon\CarbonImmutable;

it('has recalculate hot scores command', function () {
    $this->artisan('posts:recalculate-hot-scores')
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
