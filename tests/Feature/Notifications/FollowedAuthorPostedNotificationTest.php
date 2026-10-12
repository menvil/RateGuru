<?php

use App\Models\Post;
use App\Models\User;
use App\Notifications\FollowedAuthorPostedNotification;

it('builds a database notification carrying the post, its author, title and url', function () {
    $author = User::factory()->create([
        'name' => 'Alice',
    ]);

    $post = Post::factory()->for($author, 'user')->published()->create([
        'title' => 'New Post',
    ]);

    $notification = new FollowedAuthorPostedNotification($post, $post->user);

    $data = $notification->toArray(User::factory()->create());

    expect($notification->via(User::factory()->make()))->toContain('database');
    expect($data['post_id'])->toBe($post->id);
    expect($data['author_id'])->toBe($author->id);
    expect($data['post_title'])->toBe('New Post');
    expect($data['type'])->toBe('followed_author_posted');
    expect($data['url'])->toContain((string) $post->id);
});
