<?php

use App\Enums\CuisineType;
use App\Enums\OriginType;
use App\Enums\PostStatus;
use App\Enums\VoteType;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates post_votes table with required columns', function () {
    expect(Schema::hasTable('post_votes'))->toBeTrue();
    expect(Schema::hasColumns('post_votes', [
        'id',
        'post_id',
        'user_id',
        'type',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

it('does not allow duplicate post vote for same user and post', function () {
    $user = User::factory()->create();

    $postId = DB::table('posts')->insertGetId([
        'user_id' => $user->id,
        'title' => 'Test dish',
        'status' => PostStatus::Published->value,
        'origin_truth' => OriginType::Unknown->value,
        'cuisine_truth' => CuisineType::Unknown->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('post_votes')->insert([
        'post_id' => $postId,
        'user_id' => $user->id,
        'type' => VoteType::Up->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('post_votes')->insert([
        'post_id' => $postId,
        'user_id' => $user->id,
        'type' => VoteType::Down->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);
