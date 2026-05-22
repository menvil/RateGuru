<?php

use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Notifications\PostCommentedNotification;

it('creates post commented notification payload', function () {
    $postOwner = User::factory()->create([
        'username' => 'owner',
    ]);

    $commenter = User::factory()->create([
        'name' => 'Commenter',
        'username' => 'commenter',
    ]);

    $post = Post::factory()->for($postOwner)->published()->create([
        'title' => 'Pasta dish',
    ]);

    $comment = Comment::factory()
        ->for($post)
        ->for($commenter, 'user')
        ->create([
            'body' => 'Looks good.',
        ]);

    $notification = new PostCommentedNotification($post, $comment, $commenter);

    expect($notification->via($postOwner))->toBe(['database']);

    $data = $notification->toArray($postOwner);

    expect($data)->toMatchArray([
        'type' => 'post_commented',
        'post_id' => $post->id,
        'post_title' => 'Pasta dish',
        'comment_id' => $comment->id,
        'actor_id' => $commenter->id,
        'actor_username' => 'commenter',
    ]);

    expect($data)->toHaveKey('message');
    expect($data)->toHaveKey('url');
});
