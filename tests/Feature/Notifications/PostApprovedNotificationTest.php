<?php

use App\Models\Post;
use App\Models\User;
use App\Notifications\PostApprovedNotification;

it('creates post approved notification payload', function () {
    $postOwner = User::factory()->create([
        'username' => 'owner',
    ]);

    $moderator = User::factory()->moderator()->create([
        'name' => 'Moderator',
        'username' => 'moderator',
    ]);

    $post = Post::factory()->for($postOwner)->published()->create([
        'title' => 'Approved sample post',
    ]);

    $notification = new PostApprovedNotification($post, $moderator);

    expect($notification->via($postOwner))->toBe(['database']);

    $data = $notification->toArray($postOwner);

    expect($data)->toMatchArray([
        'type' => 'post_approved',
        'post_id' => $post->id,
        'post_title' => 'Approved sample post',
        'actor_id' => $moderator->id,
        'actor_username' => 'moderator',
        'message' => 'Your post was approved',
    ]);

    expect(strtolower($data['message']))->not->toContain('dish');
    expect(strtolower($data['message']))->not->toContain('food');
    expect($data)->toHaveKey('url');
});
