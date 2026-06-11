<?php

use App\Models\Post;
use App\Models\User;
use App\Notifications\FollowedAuthorPostedNotification;

it('creates followed author posted notification payload', function () {
    $author = User::factory()->create([
        'name' => 'Alice',
    ]);

    $post = Post::factory()->for($author, 'user')->published()->create([
        'title' => 'New Post',
    ]);

    $notification = new FollowedAuthorPostedNotification($post);

    $data = $notification->toArray(User::factory()->create());

    expect($data['post_id'])->toBe($post->id);
    expect($data['author_id'])->toBe($author->id);
    expect($data['post_title'])->toBe('New Post');
    expect($data['type'])->toBe('followed_author_posted');
});

it('uses database notification channel', function () {
    $post = Post::factory()->published()->create();
    $notification = new FollowedAuthorPostedNotification($post);

    expect($notification->via(User::factory()->make()))->toContain('database');
});

it('includes post url in payload', function () {
    $post = Post::factory()->published()->create();
    $notification = new FollowedAuthorPostedNotification($post);

    $data = $notification->toArray(User::factory()->create());

    expect($data['url'])->toContain((string) $post->id);
});
