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

it('renders escaped comment body', function () {
    $comment = Comment::factory()->make([
        'body' => '<script>alert("x")</script> Nice.',
    ]);

    $html = Blade::render('<x-comments.comment-item :comment="$comment" />', [
        'comment' => $comment,
    ]);

    expect($html)
        ->toContain('&lt;script&gt;')
        ->not->toContain('<script>alert');
});

it('renders comment timestamp', function () {
    $comment = Comment::factory()->make([
        'body' => 'Timed.',
        'created_at' => now()->subMinutes(5),
    ]);

    $html = Blade::render('<x-comments.comment-item :comment="$comment" />', [
        'comment' => $comment,
    ]);

    expect($html)
        ->toContain('<time')
        ->toContain('datetime=');
});

it('does not break when created_at is missing', function () {
    $comment = Comment::factory()->make(['body' => 'No timestamp']);
    $comment->created_at = null;

    $html = Blade::render('<x-comments.comment-item :comment="$comment" />', [
        'comment' => $comment,
    ]);

    expect($html)
        ->toContain('No timestamp')
        ->not->toContain('<time');
});
