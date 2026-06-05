<?php

use App\Enums\CuisineType;
use App\Enums\OriginType;
use App\Models\CuisineVote;
use App\Models\OriginVote;
use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\RatingVote;
use App\Models\User;

it('has a dry run mode for migrating legacy rating votes', function () {
    $this->artisan('rateguru:rating:migrate-legacy-votes', [
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Dry run')
        ->assertExitCode(0);
});

it('migrates legacy origin votes into generic source votes', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    OriginVote::factory()->for($user)->for($post)->create([
        'origin' => OriginType::Homemade,
    ]);

    $this->artisan('rateguru:rating:migrate-legacy-votes')
        ->assertExitCode(0);

    $source = RatingGroup::query()->where('key', 'source')->firstOrFail();
    $sourceA = $source->options()->where('key', 'source_a')->firstOrFail();

    $this->assertDatabaseHas('rating_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'rating_group_id' => $source->id,
        'rating_option_id' => $sourceA->id,
    ]);
    $this->assertDatabaseHas('origin_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]);
});

it('migrates legacy cuisine votes into generic category votes', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    CuisineVote::factory()->for($user)->for($post)->create([
        'cuisine' => CuisineType::Mexican,
    ]);

    $this->artisan('rateguru:rating:migrate-legacy-votes')
        ->assertExitCode(0);

    $category = RatingGroup::query()->where('key', 'category')->firstOrFail();
    $categoryD = $category->options()->where('key', 'category_d')->firstOrFail();

    $this->assertDatabaseHas('rating_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'rating_group_id' => $category->id,
        'rating_option_id' => $categoryD->id,
    ]);
    $this->assertDatabaseHas('cuisine_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]);
});

it('migrates legacy votes idempotently', function () {
    OriginVote::factory()->create([
        'origin' => OriginType::Restaurant,
    ]);

    $this->artisan('rateguru:rating:migrate-legacy-votes')->assertExitCode(0);
    $this->artisan('rateguru:rating:migrate-legacy-votes')->assertExitCode(0);

    expect(RatingVote::query()->count())->toBe(1);
});

it('does not write configuration or votes in dry run mode', function () {
    OriginVote::factory()->create([
        'origin' => OriginType::Homemade,
    ]);

    $this->artisan('rateguru:rating:migrate-legacy-votes', [
        '--dry-run' => true,
    ])->assertExitCode(0);

    expect(RatingGroup::query()->count())->toBe(0)
        ->and(RatingOption::query()->count())->toBe(0)
        ->and(RatingVote::query()->count())->toBe(0)
        ->and(OriginVote::query()->count())->toBe(1);
});

it('preserves an existing generic vote when a legacy vote conflicts', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    $this->artisan('rateguru:rating:migrate-legacy-votes')->assertExitCode(0);

    $source = RatingGroup::query()->where('key', 'source')->firstOrFail();
    $sourceA = $source->options()->where('key', 'source_a')->firstOrFail();

    RatingVote::factory()
        ->for($user)
        ->for($post)
        ->for($source, 'group')
        ->for($sourceA, 'option')
        ->create();

    OriginVote::factory()->for($user)->for($post)->create([
        'origin' => OriginType::Restaurant,
    ]);

    $this->artisan('rateguru:rating:migrate-legacy-votes')->assertExitCode(0);

    expect(RatingVote::query()
        ->where('user_id', $user->id)
        ->where('post_id', $post->id)
        ->where('rating_group_id', $source->id)
        ->value('rating_option_id'))
        ->toBe($sourceA->id);
});
