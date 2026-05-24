<?php

use App\Actions\Ranking\RecalculatePostScoreAction;
use App\Enums\PostStatus;
use App\Models\Post;
use Carbon\CarbonImmutable;

it('recalculates and stores post hot score', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-14 12:00:00'));

    $post = Post::factory()->published()->create([
        'upvotes_count' => 10,
        'downvotes_count' => 0,
        'comments_count' => 4,
        'created_at' => CarbonImmutable::parse('2026-05-14 10:00:00'),
        'hot_score' => 0,
    ]);

    $score = app(RecalculatePostScoreAction::class)->handle($post);

    $post->refresh();

    expect($score)->toBeFloat();
    expect((float) $post->hot_score)->toBe($score);
    expect((float) $post->hot_score)->toBeGreaterThan(0);
});

it('only updates hot score during recalculation', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-14 12:00:00'));

    $post = Post::factory()->published()->create([
        'upvotes_count' => 7,
        'downvotes_count' => 2,
        'comments_count' => 3,
        'reports_count' => 1,
        'created_at' => CarbonImmutable::parse('2026-05-14 10:00:00'),
        'published_at' => CarbonImmutable::parse('2026-05-14 10:30:00'),
        'hot_score' => 0,
    ]);

    app(RecalculatePostScoreAction::class)->handle($post);

    $post->refresh();

    expect($post->status)->toBe(PostStatus::Published);
    expect($post->upvotes_count)->toBe(7);
    expect($post->downvotes_count)->toBe(2);
    expect($post->comments_count)->toBe(3);
    expect($post->reports_count)->toBe(1);
    expect($post->published_at->toDateTimeString())->toBe('2026-05-14 10:30:00');
});
