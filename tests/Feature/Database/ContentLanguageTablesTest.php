<?php

use App\Enums\ContentLanguageCheckStatus;
use App\Models\Comment;
use App\Models\ContentLanguageCheck;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/*
 * The language a post and a comment are written in, when a member was last seen and when their previous visit
 * ended, and the checks of each text's language — what the feed and the threads put the viewer's language first by.
 */

it('keeps a language on posts and comments, none on the ones written before', function () {
    $post = Post::factory()->create();
    $comment = Comment::factory()->create();

    expect(Schema::hasColumns('posts', ['language', 'language_share', 'language_stale_at']))->toBeTrue()
        ->and(Schema::hasColumn('comments', 'language'))->toBeTrue()
        ->and($post->fresh()->language)->toBeNull()
        ->and($comment->fresh()->language)->toBeNull()
        ->and(collect(Schema::getIndexes('posts'))->pluck('name'))->toContain('posts_status_language_published_index');
});

it('reads a post\'s language share as a number and its staleness as a date', function () {
    $post = Post::factory()->create(['language' => 'ru', 'language_share' => 0.857, 'language_stale_at' => now()]);

    expect($post->fresh()->language_share)->toBe(0.857)
        ->and($post->fresh()->language_stale_at)->toBeInstanceOf(DateTimeInterface::class);
});

it('keeps when a member was last seen and when their previous visit ended', function () {
    $user = User::factory()->create();
    $user->forceFill(['last_seen_at' => now(), 'previous_visit_at' => now()->subDays(2)])->save();

    expect($user->fresh()->previous_visit_at->lessThan($user->fresh()->last_seen_at))->toBeTrue();
});

it('keeps one check per post and per comment', function () {
    $post = Post::factory()->create();
    ContentLanguageCheck::query()->create(['post_id' => $post->id, 'run_after' => now()]);

    expect(fn () => ContentLanguageCheck::query()->create(['post_id' => $post->id, 'run_after' => now()]))
        ->toThrow(QueryException::class);
});

it('starts a check as waiting, for a text written now', function () {
    $check = ContentLanguageCheck::query()->create(['comment_id' => Comment::factory()->create()->id, 'run_after' => now()])->fresh();

    expect($check->status)->toBe(ContentLanguageCheckStatus::Pending)
        ->and($check->priority->value)->toBe('live')
        ->and($check->attempts)->toBe(0);
});

it('lets the checks go with the post and the comment they checked', function () {
    $post = Post::factory()->create();
    $comment = Comment::factory()->create(['post_id' => $post->id]);
    ContentLanguageCheck::query()->create(['post_id' => $post->id, 'run_after' => now()]);
    ContentLanguageCheck::query()->create(['comment_id' => $comment->id, 'run_after' => now()]);

    $comment->forceDelete();

    expect(ContentLanguageCheck::query()->whereNotNull('comment_id')->exists())->toBeFalse()
        ->and(ContentLanguageCheck::query()->where('post_id', $post->id)->exists())->toBeTrue();

    $post->forceDelete();

    expect(ContentLanguageCheck::query()->exists())->toBeFalse();
});

it('settles a check on OpenAI\'s answer, on the local one until then, and on none when OpenAI found no language', function () {
    $check = new ContentLanguageCheck(['local_language' => 'bg', 'local_weight' => 0.5, 'status' => ContentLanguageCheckStatus::Pending]);

    expect($check->language())->toBe('bg')->and($check->weight())->toBe(0.5);

    $check->forceFill(['status' => ContentLanguageCheckStatus::Done, 'ai_language' => 'ru', 'ai_confidence' => 0.99]);

    expect($check->language())->toBe('ru')->and($check->weight())->toBe(1.0);

    $check->forceFill(['ai_language' => null]);

    expect($check->language())->toBeNull()->and($check->weight())->toBe(0.0);

    $check->forceFill(['status' => ContentLanguageCheckStatus::Failed]);

    expect($check->language())->toBe('bg')->and($check->weight())->toBe(0.5);
});

it('undoes what it added and adds it again, the rows staying', function (string $file, string $table, array $columns) {
    $row = match ($table) {
        'posts' => Post::factory()->create(),
        'users' => User::factory()->create(),
    };
    $migration = require database_path("migrations/{$file}");

    $migration->down();

    expect(Schema::hasColumns($table, $columns))->toBeFalse();

    $migration->up();

    expect(Schema::hasColumns($table, $columns))->toBeTrue()
        ->and($row->newQuery()->whereKey($row->getKey())->exists())->toBeTrue();
})->with([
    'the language of posts and comments' => ['2026_11_25_100000_add_language_to_posts_and_comments_tables.php', 'posts', ['language', 'language_share', 'language_stale_at']],
    'a member\'s visits' => ['2026_11_25_100100_add_visit_times_to_users_table.php', 'users', ['last_seen_at', 'previous_visit_at']],
]);

it('drops the checks table on the way down and makes it again on the way up', function () {
    $migration = require database_path('migrations/2026_11_25_100200_create_content_language_checks_table.php');

    $migration->down();

    expect(Schema::hasTable('content_language_checks'))->toBeFalse();

    $migration->up();

    expect(Schema::hasTable('content_language_checks'))->toBeTrue();
});
