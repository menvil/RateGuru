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

it('stores null reason when blank', function (string $reason) {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();

    $log = app(CreateModerationLogAction::class)->handle(
        moderator: $moderator,
        action: ModerationActionType::HidePost,
        target: $post,
        reason: $reason,
    );

    expect($log->reason)->toBeNull();
})->with([
    'ASCII whitespace' => ['   '],
    // The shapes this writer and the finalizers' guard used to disagree about. It
    // reduced them to null through plain trim(), whose character list includes "\0"
    // and excludes every Unicode blank — so a NUL-only reason passed the guard and
    // arrived here to become an irreversible removal recorded with no reason at all.
    // Both now go through ModerationReason, so there is one definition of empty.
    'NUL' => ["\0"],
    'zero-width space' => ["\u{200B}"],
    'no-break space' => ["\u{00A0}"],
]);

it('keeps a reason that merely has Unicode blanks around it', function () {
    // The counterpart: one definition of empty must not also mean one definition of
    // "strip everything". Interior content survives, and so do interior blanks.
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();

    $log = app(CreateModerationLogAction::class)->handle(
        moderator: $moderator,
        action: ModerationActionType::HidePost,
        target: $post,
        reason: "\u{200B} Repeated\u{00A0}spam \0",
    );

    expect($log->reason)->toBe("Repeated\u{00A0}spam");
});
