<?php

use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\Post;
use Database\Seeders\DemoCommentsSeeder;
use Database\Seeders\DemoDatabaseSeeder;

it('seeds comments for published posts', function () {
    $this->seed(DemoDatabaseSeeder::class);

    expect(Comment::query()->count())->toBeGreaterThanOrEqual(10);

    $comment = Comment::query()->firstOrFail();

    expect($comment->post)->not->toBeNull();
    expect($comment->user)->not->toBeNull();
});

it('keeps comments count consistent after seeding comments', function () {
    $this->seed(DemoDatabaseSeeder::class);

    Post::query()->each(function (Post $post) {
        expect($post->refresh()->comments_count)->toBe(
            $post->comments()->where('status', CommentStatus::Visible)->count()
        );
    });
});

it('seeds comments idempotently', function () {
    $this->seed(DemoDatabaseSeeder::class);
    $count = Comment::query()->count();

    $this->seed(DemoCommentsSeeder::class);

    expect(Comment::query()->count())->toBe($count);
});
