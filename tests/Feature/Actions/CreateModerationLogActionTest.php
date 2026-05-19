<?php

use App\Actions\Moderation\CreateModerationLogAction;
use App\Enums\ModerationActionType;
use App\Models\ModerationLog;
use App\Models\Post;
use App\Models\User;

it('creates moderation log', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();

    $log = app(CreateModerationLogAction::class)->handle(
        moderator: $moderator,
        action: ModerationActionType::HidePost,
        target: $post,
        reason: 'Reported content.',
        metadata: ['source' => 'test']
    );

    expect($log)->toBeInstanceOf(ModerationLog::class);
    expect($log->moderator_id)->toBe($moderator->id);
    expect($log->action)->toBe(ModerationActionType::HidePost);
    expect($log->target_type)->toBe(Post::class);
    expect($log->target_id)->toBe($post->id);
    expect($log->reason)->toBe('Reported content.');
    expect($log->metadata)->toMatchArray(['source' => 'test']);
});

it('stores null reason when blank', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();

    $log = app(CreateModerationLogAction::class)->handle(
        moderator: $moderator,
        action: ModerationActionType::HidePost,
        target: $post,
        reason: '   ',
    );

    expect($log->reason)->toBeNull();
});
