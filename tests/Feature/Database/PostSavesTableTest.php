<?php

use App\Models\Post;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('has post_saves table', function () {
    expect(Schema::hasTable('post_saves'))->toBeTrue();
});

it('creates post_saves table with required columns', function () {
    expect(Schema::hasColumns('post_saves', [
        'id',
        'user_id',
        'post_id',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

it('prevents duplicate saved posts for same user and post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    DB::table('post_saves')->insert([
        'user_id' => $user->id,
        'post_id' => $post->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('post_saves')->insert([
        'user_id' => $user->id,
        'post_id' => $post->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);
