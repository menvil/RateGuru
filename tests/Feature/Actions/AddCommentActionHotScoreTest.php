<?php

use App\Actions\Comments\AddCommentAction;
use App\Actions\Comments\DeleteCommentAction;
use App\Actions\Comments\HideCommentAction;
use App\Actions\Comments\RestoreCommentAction;
use App\Exceptions\Comments\CannotCommentException;
use App\Models\Comment;
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

it('moves the hot score with the comment count when a comment is deleted, hidden or restored', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-14 12:00:00'));

    $author = User::factory()->create();
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create([
        'upvotes_count' => 0,
        'downvotes_count' => 0,
        'comments_count' => 0,
        'hot_score' => 0,
        'created_at' => CarbonImmutable::parse('2026-05-14 10:00:00'),
    ]);

    app(AddCommentAction::class)->handle($author, $post, 'First.');
    app(AddCommentAction::class)->handle($author, $post, 'Second.');
    $withTwo = (float) $post->fresh()->hot_score;
    [$first, $second] = Comment::query()->where('post_id', $post->id)->orderBy('id')->get()->all();

    app(HideCommentAction::class)->handle($moderator, $first);
    $withOneVisible = (float) $post->fresh()->hot_score;

    app(RestoreCommentAction::class)->handle($moderator, $first->fresh());
    $restored = (float) $post->fresh()->hot_score;

    app(DeleteCommentAction::class)->handle($author, $second);
    $afterDelete = (float) $post->fresh()->hot_score;

    expect($withOneVisible)->toBeLessThan($withTwo)
        ->and($restored)->toBe($withTwo)
        ->and($afterDelete)->toBe($withOneVisible)
        ->and($post->fresh()->comments_count)->toBe(1);
});
