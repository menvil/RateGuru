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
        'title' => 'Commented sample post',
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
        'post_title' => 'Commented sample post',
        'comment_id' => $comment->id,
        'actor_id' => $commenter->id,
        'actor_username' => 'commenter',
        'message' => '@commenter commented on your post',
    ]);

    expect(strtolower($data['message']))->not->toContain('dish');
    expect(strtolower($data['message']))->not->toContain('food');
    expect($data)->toHaveKey('url');
});
