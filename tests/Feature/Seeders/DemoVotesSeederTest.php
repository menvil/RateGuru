<?php

use App\Enums\PostStatus;
use App\Enums\VoteType;
use App\Models\CuisineVote;
use App\Models\OriginVote;
use App\Models\Post;
use App\Models\PostVote;
use Database\Seeders\DemoDatabaseSeeder;
use Database\Seeders\DemoVotesSeeder;

it('seeds post votes', function () {
    $this->seed(DemoDatabaseSeeder::class);

    expect(PostVote::query()->count())->toBeGreaterThan(0);
    expect(OriginVote::query()->count())->toBeGreaterThan(0);
    expect(CuisineVote::query()->count())->toBeGreaterThan(0);
});

it('keeps vote counters consistent after seeding votes', function () {
    $this->seed(DemoDatabaseSeeder::class);

    Post::query()->each(function (Post $post) {
        expect($post->refresh()->upvotes_count)->toBe(
            $post->postVotes()->where('type', VoteType::Up)->count()
        );

        expect($post->downvotes_count)->toBe(
            $post->postVotes()->where('type', VoteType::Down)->count()
        );
    });
});

it('seeds posts with recalculated hot scores', function () {
    $this->seed(DemoDatabaseSeeder::class);

    expect(Post::query()
        ->where('status', PostStatus::Published)
        ->where('hot_score', '>', 0)
        ->count())->toBeGreaterThan(0);
});

it('seeds votes idempotently', function () {
    $this->seed(DemoDatabaseSeeder::class);
    $postVotes = PostVote::query()->count();
    $originVotes = OriginVote::query()->count();
    $cuisineVotes = CuisineVote::query()->count();

    $this->seed(DemoVotesSeeder::class);

    expect(PostVote::query()->count())->toBe($postVotes);
    expect(OriginVote::query()->count())->toBe($originVotes);
    expect(CuisineVote::query()->count())->toBe($cuisineVotes);
});
