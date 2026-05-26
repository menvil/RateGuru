<?php

use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Support\Facades\Blade;

it('renders report button in comment item for persisted comment', function () {
    $comment = Comment::factory()->create([
        'status' => CommentStatus::Visible,
    ]);

    $html = Blade::render('<x-comments.comment-item :comment="$comment" />', [
        'comment' => $comment,
    ]);

    expect($html)
        ->toContain('data-testid="comment-report"')
        ->toContain('Report');
});

it('renders comment actions menu in the comment header', function () {
    $comment = Comment::factory()->create([
        'status' => CommentStatus::Visible,
    ]);

    $html = Blade::render('<x-comments.comment-item :comment="$comment" />', [
        'comment' => $comment,
    ]);

    expect($html)
        ->toContain('items-start justify-between')
        ->toContain('aria-label="Comment actions"')
        ->toContain('absolute right-0 top-full')
        ->toContain('py-1.5');
});

it('keeps comment actions out of the reply and vote row', function () {
    $comment = Comment::factory()->create([
        'status' => CommentStatus::Visible,
    ]);

    $html = Blade::render('<x-comments.comment-item :comment="$comment" />', [
        'comment' => $comment,
    ]);

    $actionsRow = substr($html, strpos($html, 'comment-voting'));

    expect($actionsRow)->not->toContain('aria-label="Comment actions"');
});

it('renders live comment voting for persisted comments', function () {
    $comment = Comment::factory()->create([
        'status' => CommentStatus::Visible,
    ]);

    $html = Blade::render('<x-comments.comment-item :comment="$comment" />', [
        'comment' => $comment,
    ]);

    expect($html)->toContain('comment-voting-'.$comment->id);
});

it('does not break comment item report button for unsaved comment preview', function () {
    $comment = Comment::factory()->make(['body' => 'Preview']);

    $html = Blade::render('<x-comments.comment-item :comment="$comment" />', [
        'comment' => $comment,
    ]);

    expect($html)
        ->toContain('Preview')
        ->not->toContain('data-testid="comment-report"');
});

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
