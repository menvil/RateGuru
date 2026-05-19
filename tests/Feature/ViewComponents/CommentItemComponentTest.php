<?php

use App\Models\Comment;
use App\Models\User;
use Illuminate\Support\Facades\Blade;

it('renders comment item component with body', function () {
    $comment = Comment::factory()->make([
        'body' => 'Looks delicious.',
    ]);

    $html = Blade::render('<x-comments.comment-item :comment="$comment" />', [
        'comment' => $comment,
    ]);

    expect($html)
        ->toContain('data-testid="comment-item"')
        ->toContain('Looks delicious.');
});

it('renders comment author name and username', function () {
    $user = User::factory()->make([
        'name' => 'Ivan',
        'username' => 'ivan',
    ]);

    $comment = Comment::factory()->make(['body' => 'Nice.']);
    $comment->setRelation('user', $user);

    $html = Blade::render('<x-comments.comment-item :comment="$comment" />', [
        'comment' => $comment,
    ]);

    expect($html)
        ->toContain('Ivan')
        ->toContain('@ivan');
});

it('does not break when user relation is missing', function () {
    $comment = Comment::factory()->make(['body' => 'Orphan comment']);
    $comment->setRelation('user', null);

    $html = Blade::render('<x-comments.comment-item :comment="$comment" />', [
        'comment' => $comment,
    ]);

    expect($html)
        ->toContain('Orphan comment')
        ->toContain('Unknown user');
});
