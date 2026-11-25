<?php

use App\Actions\Languages\WorkOutPostLanguageAction;
use App\Enums\CommentStatus;
use App\Enums\ContentLanguageCheckStatus;
use App\Enums\VoteType;
use App\Models\Comment;
use App\Models\ContentLanguageCheck;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\User;

/*
 * A post's language worked out again from everything that tells it now: the check of its text, its author, its
 * visible comments and its voters, the votes capped; saved once, without touching the post's updated_at.
 */

function speaker(?string $locale): User
{
    $user = User::factory()->create();
    $user->forceFill(['locale' => $locale])->save();

    return $user;
}

function workOut(Post $post): Post
{
    app(WorkOutPostLanguageAction::class)->handle($post->id);

    return $post->fresh();
}

it('takes OpenAI\'s reading of the text over the local one', function () {
    $post = Post::factory()->create(['user_id' => speaker(null)->id, 'language' => 'es', 'language_stale_at' => now()]);
    ContentLanguageCheck::query()->create(['post_id' => $post->id, 'local_language' => 'es', 'local_weight' => 0.75, 'status' => ContentLanguageCheckStatus::Done, 'ai_language' => 'ru', 'ai_confidence' => 0.95, 'run_after' => now()]);

    $post = workOut($post);

    expect($post->language)->toBe('ru')
        ->and($post->language_share)->toBe(1.0)
        ->and($post->language_stale_at)->toBeNull();
});

it('counts the author when OpenAI found the text tells no language', function () {
    $post = Post::factory()->create(['user_id' => speaker('de')->id, 'language' => 'es']);
    ContentLanguageCheck::query()->create(['post_id' => $post->id, 'local_language' => 'es', 'local_weight' => 0.75, 'status' => ContentLanguageCheckStatus::Done, 'run_after' => now()]);

    expect(workOut($post)->language)->toBe('de');
});

it('lets visible comments outweigh an author of another language, hidden ones not', function () {
    $post = Post::factory()->create(['user_id' => speaker('en')->id]);

    foreach (range(1, 3) as $i) {
        $comment = Comment::factory()->create(['post_id' => $post->id, 'user_id' => speaker('ru')->id, 'language' => 'ru']);
        ContentLanguageCheck::query()->create(['comment_id' => $comment->id, 'local_language' => 'ru', 'local_weight' => 1, 'run_after' => now()]);
    }

    Comment::factory()->count(3)->create(['post_id' => $post->id, 'user_id' => speaker('de')->id, 'language' => 'de', 'status' => CommentStatus::Hidden]);

    expect(workOut($post)->language)->toBe('ru');
});

it('counts an emoji-only comment at half to its writer\'s language', function () {
    $post = Post::factory()->create(['user_id' => speaker(null)->id]);
    Comment::factory()->create(['post_id' => $post->id, 'user_id' => speaker('bg')->id, 'language' => null]);

    expect(workOut($post)->language)->toBe('bg');
});

it('keeps a popular post\'s own language however many of another language vote', function () {
    $post = Post::factory()->create(['user_id' => speaker('de')->id]);
    ContentLanguageCheck::query()->create(['post_id' => $post->id, 'local_language' => 'de', 'local_weight' => 1, 'run_after' => now()]);

    foreach (range(1, 40) as $i) {
        PostVote::query()->create(['post_id' => $post->id, 'user_id' => speaker('en')->id, 'type' => VoteType::Up]);
    }

    $post = workOut($post);

    expect($post->language)->toBe('de')
        ->and($post->language_share)->toBe(0.714);
});

it('lets votes tip a post nothing else tells', function () {
    $post = Post::factory()->create(['user_id' => speaker(null)->id]);
    PostVote::query()->create(['post_id' => $post->id, 'user_id' => speaker('ru')->id, 'type' => VoteType::Up]);
    PostVote::query()->create(['post_id' => $post->id, 'user_id' => speaker(null)->id, 'type' => VoteType::Up]);

    expect(workOut($post)->language)->toBe('ru');
});

it('leaves a post no signal tells without a language', function () {
    $post = Post::factory()->create(['user_id' => speaker(null)->id, 'language' => 'en', 'language_share' => 1]);

    $post = workOut($post);

    expect($post->language)->toBeNull()
        ->and($post->language_share)->toBeNull();
});

it('leaves the post\'s updated_at as it was', function () {
    $post = Post::factory()->create(['user_id' => speaker('ru')->id, 'updated_at' => now()->subDay()]);
    $before = $post->fresh()->updated_at;

    expect(workOut($post)->updated_at->equalTo($before))->toBeTrue();
});
