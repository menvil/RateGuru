<?php

use App\Models\Comment;
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
