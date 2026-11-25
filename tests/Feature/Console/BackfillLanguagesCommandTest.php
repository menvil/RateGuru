<?php

use App\Enums\ContentLanguageCheckPriority;
use App\Models\Comment;
use App\Models\ContentLanguageCheck;
use App\Models\Post;
use App\Models\User;

/*
 * languages:backfill gives the posts and comments written before languages were kept a language, queues their
 * checks behind texts written now, and run again reads only what it has not.
 */

it('reads every older post and comment, and works out each post\'s language', function () {
    $author = User::factory()->create();
    $author->forceFill(['locale' => 'en'])->save();
    $post = Post::factory()->published()->create(['user_id' => $author->id, 'title' => 'Очень красивая фотография', 'description' => 'Мне нравится этот вид']);
    $comment = Comment::factory()->create(['post_id' => $post->id, 'body' => 'Согласен, отличный кадр, спасибо']);
    $updatedAt = $post->fresh()->updated_at;

    $this->artisan('languages:backfill')->assertSuccessful();

    expect($post->fresh()->language)->toBe('ru')
        ->and($post->fresh()->language_stale_at)->toBeNull()
        ->and($post->fresh()->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and($comment->fresh()->language)->toBe('ru')
        ->and(ContentLanguageCheck::query()->where('post_id', $post->id)->sole()->priority)->toBe(ContentLanguageCheckPriority::Backfill)
        ->and(ContentLanguageCheck::query()->where('comment_id', $comment->id)->sole()->local_language)->toBe('ru');
});

it('reads only what it has not read when run again', function () {
    $post = Post::factory()->published()->create(['title' => 'Wunderschönes Foto aus den Bergen']);
    $this->artisan('languages:backfill')->assertSuccessful();
    $newer = Post::factory()->published()->create(['title' => 'Bardzo ładne zdjęcie z gór']);

    $this->artisan('languages:backfill')
        ->expectsOutputToContain('Read 1 texts')
        ->assertSuccessful();

    expect(ContentLanguageCheck::query()->count())->toBe(2)
        ->and($newer->fresh()->language)->toBe('pl');
});
