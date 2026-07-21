<?php

namespace Tests\Feature\Seeders;

use App\Models\Comment;
use App\Models\CommentVote;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\RatingVote;
use App\Models\User;
use Database\Seeders\DefaultRatingConfigurationSeeder;
use Database\Seeders\DemoFillSeeder;
use Illuminate\Support\Facades\Storage;

class SmallDemoFillSeeder extends DemoFillSeeder
{
    protected function userCount(): int
    {
        return 6;
    }

    protected function postTitles(): array
    {
        return [
            'Large Demo Sample 01',
            'Large Demo Sample 02',
            'Large Demo Sample 03',
        ];
    }

    protected function voteRatio(): float
    {
        return 0.5;
    }

    protected function commentVoteRatio(): float
    {
        return 0.5;
    }

    protected function topLevelCommentCount(): int
    {
        return 2;
    }

    protected function replyCount(): int
    {
        return 1;
    }

    protected function deepReplyParentCount(): int
    {
        return 1;
    }

    protected function deepReplyCount(): int
    {
        return 1;
    }
}

beforeEach(function () {
    Storage::fake('public');
    $this->seed(DefaultRatingConfigurationSeeder::class);
});

it('creates a mix of categorized and uncategorized large demo posts with media', function () {
    $this->seed(SmallDemoFillSeeder::class);

    $posts = Post::query()->whereIn('title', [
        'Large Demo Sample 01',
        'Large Demo Sample 02',
        'Large Demo Sample 03',
    ])->get();

    expect($posts)->toHaveCount(3)
        ->and($posts->whereNotNull('category_option_id'))->toHaveCount(2)
        ->and($posts->whereNull('category_option_id'))->toHaveCount(1);

    foreach ($posts as $post) {
        Storage::disk('public')->assertExists($post->image_path);
    }
});

it('rebuilds generated interactions and media without accumulating rows', function () {
    $this->seed(SmallDemoFillSeeder::class);
    $this->seed(SmallDemoFillSeeder::class);

    expect(User::query()->where('email', 'like', 'fill%@demo.test')->count())->toBe(6)
        ->and(Post::query()->where('title', 'like', 'Large Demo Sample %')->count())->toBe(3)
        ->and(PostVote::query()->count())->toBe(9)
        ->and(RatingVote::query()->count())->toBe(18)
        ->and(Comment::withTrashed()->count())->toBe(15)
        ->and(CommentVote::query()->count())->toBe(45)
        ->and(Storage::disk('public')->allFiles('posts'))->toHaveCount(3);
});
