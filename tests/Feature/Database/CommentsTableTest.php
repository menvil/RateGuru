<?php

use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates comments table with required columns', function () {
    expect(Schema::hasTable('comments'))->toBeTrue();
    expect(Schema::hasColumns('comments', [
        'id',
        'post_id',
        'user_id',
        'body',
        'status',
        'reports_count',
        'created_at',
        'updated_at',
        'deleted_at',
    ]))->toBeTrue();
});

it('gives a comment written without a status one it can be read back with', function () {
    $post = Post::factory()->published()->create();
    $id = DB::table('comments')->insertGetId([
        'post_id' => $post->id,
        'user_id' => User::factory()->create()->id,
        'body' => 'Written straight to the table.',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(Comment::query()->findOrFail($id)->status)->toBe(CommentStatus::Visible);
});

it('makes a comment the old default left unreadable a visible one', function () {
    $post = Post::factory()->published()->create();
    $id = DB::table('comments')->insertGetId([
        'post_id' => $post->id,
        'user_id' => User::factory()->create()->id,
        'body' => 'From before.',
        'status' => 'published',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = require base_path('database/migrations/2026_11_07_120000_default_comment_status_to_visible.php');
    $migration->up();

    expect(Comment::query()->findOrFail($id)->status)->toBe(CommentStatus::Visible);

    // Rolled back, only the default returns: the row stays readable.
    $migration->down();

    expect(Comment::query()->findOrFail($id)->status)->toBe(CommentStatus::Visible);
});
