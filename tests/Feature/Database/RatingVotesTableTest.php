<?php

use App\Models\Post;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates rating votes table with required columns', function () {
    expect(Schema::hasTable('rating_votes'))->toBeTrue();
    expect(Schema::hasColumns('rating_votes', [
        'id',
        'post_id',
        'user_id',
        'rating_group_id',
        'rating_option_id',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

it('allows only one rating vote per user post and group', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();
    [$groupId, $firstOptionId] = createRatingGroupAndOptionForVotes('source', 'source_a');
    $secondOptionId = createRatingOptionForVotes($groupId, 'source_b');

    insertRatingVote($user->id, $post->id, $groupId, $firstOptionId);

    expect(fn () => insertRatingVote($user->id, $post->id, $groupId, $secondOptionId))
        ->toThrow(QueryException::class);
});

it('allows a user to vote on the same post in different rating groups', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();
    [$sourceGroupId, $sourceOptionId] = createRatingGroupAndOptionForVotes('source', 'source_a');
    [$categoryGroupId, $categoryOptionId] = createRatingGroupAndOptionForVotes('category', 'category_a');

    insertRatingVote($user->id, $post->id, $sourceGroupId, $sourceOptionId);
    insertRatingVote($user->id, $post->id, $categoryGroupId, $categoryOptionId);

    expect(DB::table('rating_votes')->count())->toBe(2);
});

it('rejects rating votes whose option belongs to another group', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();
    [$sourceGroupId] = createRatingGroupAndOptionForVotes('source', 'source_a');
    [, $categoryOptionId] = createRatingGroupAndOptionForVotes('category', 'category_a');

    expect(fn () => insertRatingVote($user->id, $post->id, $sourceGroupId, $categoryOptionId))
        ->toThrow(QueryException::class);
});

it('creates rating vote lookup indexes', function () {
    $indexes = collect(Schema::getIndexes('rating_votes'))
        ->pluck('name');

    expect($indexes)
        ->toContain('rating_votes_user_id_post_id_rating_group_id_unique')
        ->toContain('rating_votes_post_id_rating_group_id_index')
        ->toContain('rating_votes_rating_option_id_index');
});

it('deletes rating votes when their post is deleted', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();
    [$groupId, $optionId] = createRatingGroupAndOptionForVotes('source', 'source_a');

    insertRatingVote($user->id, $post->id, $groupId, $optionId);
    $post->forceDelete();

    expect(DB::table('rating_votes')->count())->toBe(0);
});

/**
 * @return array{int, int}
 */
function createRatingGroupAndOptionForVotes(string $groupKey, string $optionKey): array
{
    $groupId = DB::table('rating_groups')->insertGetId([
        'key' => $groupKey,
        'label' => ucfirst($groupKey),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$groupId, createRatingOptionForVotes($groupId, $optionKey)];
}

function createRatingOptionForVotes(int $groupId, string $key): int
{
    return DB::table('rating_options')->insertGetId([
        'rating_group_id' => $groupId,
        'key' => $key,
        'label' => ucfirst(str_replace('_', ' ', $key)),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function insertRatingVote(int $userId, int $postId, int $groupId, int $optionId): void
{
    DB::table('rating_votes')->insert([
        'user_id' => $userId,
        'post_id' => $postId,
        'rating_group_id' => $groupId,
        'rating_option_id' => $optionId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
