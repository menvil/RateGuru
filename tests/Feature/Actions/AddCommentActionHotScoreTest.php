<?php

use App\Actions\Comments\AddCommentAction;
use App\Exceptions\Comments\CannotCommentException;
use App\Models\Post;
use App\Models\User;
use Carbon\CarbonImmutable;

it('recalculates hot score after comment', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-14 12:00:00'));

    $user = User::factory()->create();
    $post = Post::factory()->published()->create([
        'upvotes_count' => 0,
        'downvotes_count' => 0,
        'comments_count' => 0,
        'hot_score' => 0,
        'created_at' => CarbonImmutable::parse('2026-05-14 10:00:00'),
    ]);

    app(AddCommentAction::class)->handle($user, $post, 'Nice dish.');

    expect((float) $post->fresh()->hot_score)->toBeGreaterThan(0);
    expect($post->fresh()->comments_count)->toBe(1);
});

it('does not recalculate hot score when comment creation fails', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-14 12:00:00'));

    $user = User::factory()->create();
    $post = Post::factory()->published()->create([
        'comments_count' => 0,
        'hot_score' => 0,
        'created_at' => CarbonImmutable::parse('2026-05-14 10:00:00'),
    ]);

    try {
        app(AddCommentAction::class)->handle($user, $post, '');
        $this->fail('Expected CannotCommentException was not thrown.');
    } catch (CannotCommentException $e) {
        expect((float) $post->fresh()->hot_score)->toBe(0.0);
        expect($post->fresh()->comments_count)->toBe(0);
    }
});
