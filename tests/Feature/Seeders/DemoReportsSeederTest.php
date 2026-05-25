<?php

use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use Database\Seeders\DemoDatabaseSeeder;
use Database\Seeders\DemoReportsSeeder;

it('seeds reports for posts and comments', function () {
    $this->seed(DemoDatabaseSeeder::class);

    expect(Report::query()->count())->toBeGreaterThanOrEqual(4);

    expect(Report::query()->where('target_type', Post::class)->exists())->toBeTrue();
    expect(Report::query()->where('target_type', Comment::class)->exists())->toBeTrue();
});

it('keeps reports count consistent for reported posts and comments', function () {
    $this->seed(DemoDatabaseSeeder::class);

    Post::query()->each(function (Post $post) {
        expect($post->refresh()->reports_count)->toBe(
            Report::query()
                ->where('target_type', Post::class)
                ->where('target_id', $post->id)
                ->count()
        );
    });

    Comment::query()->each(function (Comment $comment) {
        expect($comment->refresh()->reports_count)->toBe(
            Report::query()
                ->where('target_type', Comment::class)
                ->where('target_id', $comment->id)
                ->count()
        );
    });
});

it('seeds reports idempotently', function () {
    $this->seed(DemoDatabaseSeeder::class);
    $count = Report::query()->count();

    $this->seed(DemoReportsSeeder::class);

    expect(Report::query()->count())->toBe($count);
});
