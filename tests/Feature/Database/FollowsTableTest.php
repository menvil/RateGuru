<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('has follows table', function () {
    expect(Schema::hasTable('follows'))->toBeTrue();
});

it('has follows table with required columns', function () {
    expect(Schema::hasColumns('follows', [
        'id',
        'follower_id',
        'author_id',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

it('prevents duplicate follows for same follower and author', function () {
    $follower = User::factory()->create();
    $author = User::factory()->create();

    DB::table('follows')->insert([
        'follower_id' => $follower->id,
        'author_id' => $author->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('follows')->insert([
        'follower_id' => $follower->id,
        'author_id' => $author->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);
