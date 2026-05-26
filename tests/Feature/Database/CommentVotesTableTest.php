<?php

use App\Enums\CommentStatus;
use App\Enums\VoteType;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates comment vote columns and table', function () {
    expect(Schema::hasColumns('comments', [
        'upvotes_count',
        'downvotes_count',
    ]))->toBeTrue();

    expect(Schema::hasTable('comment_votes'))->toBeTrue();
    expect(Schema::hasColumns('comment_votes', [
        'id',
        'comment_id',
        'user_id',
        'type',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

it('does not allow duplicate comment vote for same user and comment', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    $commentId = DB::table('comments')->insertGetId([
        'post_id' => $post->id,
        'user_id' => $user->id,
        'body' => 'Useful comment',
        'status' => CommentStatus::Visible->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('comment_votes')->insert([
        'comment_id' => $commentId,
        'user_id' => $user->id,
        'type' => VoteType::Up->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('comment_votes')->insert([
        'comment_id' => $commentId,
        'user_id' => $user->id,
        'type' => VoteType::Down->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
