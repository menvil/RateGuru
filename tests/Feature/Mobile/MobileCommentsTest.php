<?php

use App\Livewire\Comments\CommentsSection;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Livewire\Livewire;

it('renders comments section with mobile testid', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(CommentsSection::class, ['postId' => $post->id])
        ->assertSee('data-testid="comments-section"', false);
});

it('comment item uses grid layout that prevents horizontal overflow', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();
    Comment::factory()->create(['post_id' => $post->id, 'user_id' => $user->id, 'body' => 'Short comment']);

    $html = Livewire::test(CommentsSection::class, ['postId' => $post->id])->html();

    expect($html)->toContain('grid-cols-[32px_minmax(0,1fr)]');
});

it('long comment text uses break-words to prevent overflow', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();
    Comment::factory()->create([
        'post_id' => $post->id,
        'user_id' => $user->id,
        'body' => str_repeat('averylongwordthatwilloverflowonnarrowscreens', 5),
    ]);

    $html = Livewire::test(CommentsSection::class, ['postId' => $post->id])->html();

    expect($html)->toContain('break-words');
});
