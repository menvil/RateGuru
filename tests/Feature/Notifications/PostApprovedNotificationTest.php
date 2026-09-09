<?php

use App\Models\Post;
use App\Models\User;
use App\Notifications\PostApprovedNotification;
use App\Support\Notifications\NotificationMessage;
use Illuminate\Notifications\DatabaseNotification;

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
        'message_key' => 'ui.notifications.messages.post_approved',
        'message_params' => [],
    ]);

    // The payload stores a key, never a sentence — the language is decided
    // when it is read. The wording guard therefore applies to what renders.
    $rendered = strtolower(NotificationMessage::for(
        tap(new DatabaseNotification, fn ($n) => $n->forceFill(['data' => $data]))
    ));

    expect($rendered)->not->toContain('dish');
    expect($rendered)->not->toContain('food');
    expect($data)->toHaveKey('url');
    expect($data)->not->toHaveKey('message');
});
