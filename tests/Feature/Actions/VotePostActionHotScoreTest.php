<?php

use App\Actions\Votes\VotePostAction;
use App\Enums\VoteType;
use App\Exceptions\Votes\CannotVoteException;
use App\Models\Post;
use App\Models\User;
use Carbon\CarbonImmutable;

it('recalculates hot score after post vote', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-14 12:00:00'));

    $user = User::factory()->create();
    $post = Post::factory()->published()->create([
        'upvotes_count' => 0,
        'downvotes_count' => 0,
        'comments_count' => 0,
        'hot_score' => 0,
        'created_at' => CarbonImmutable::parse('2026-05-14 10:00:00'),
    ]);

    app(VotePostAction::class)->handle($user, $post, VoteType::Up);

    expect((float) $post->fresh()->hot_score)->toBeGreaterThan(0);
});

it('does not recalculate hot score when post vote fails', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-14 12:00:00'));

    $post = Post::factory()->published()->create([
        'hot_score' => 0,
        'created_at' => CarbonImmutable::parse('2026-05-14 10:00:00'),
    ]);

    try {
        app(VotePostAction::class)->handle(null, $post, VoteType::Up);
        $this->fail('Expected CannotVoteException was not thrown.');
    } catch (CannotVoteException $e) {
        expect((float) $post->fresh()->hot_score)->toBe(0.0);
    }
});
