<?php

use App\Enums\CuisineType;
use App\Enums\OriginType;
use App\Enums\PostStatus;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates cuisine_votes table with required columns', function () {
    expect(Schema::hasTable('cuisine_votes'))->toBeTrue();
    expect(Schema::hasColumns('cuisine_votes', [
        'id',
        'post_id',
        'user_id',
        'cuisine',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

it('does not allow duplicate cuisine vote for same user and post', function () {
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

    DB::table('cuisine_votes')->insert([
        'post_id' => $postId,
        'user_id' => $user->id,
        'cuisine' => CuisineType::Italian->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('cuisine_votes')->insert([
        'post_id' => $postId,
        'user_id' => $user->id,
        'cuisine' => CuisineType::Asian->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);
