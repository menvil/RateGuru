<?php

use App\Actions\Moderation\FinalizeCommentRemovalAction;
use App\Actions\Moderation\FinalizePostRemovalAction;
use App\Enums\CommentStatus;
use App\Enums\ReportStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;

function finalizedPostAgedDays(int $ageDays): Post
{
    $post = Post::factory()->hidden()->create();
    app(FinalizePostRemovalAction::class)->handle(User::factory()->admin()->create(), $post, 'Finalized.');

    if ($ageDays > 0) {
        Post::query()->whereKey($post->id)->update(['moderation_removed_at' => now()->subDays($ageDays)]);
    }

    return $post->fresh();
}

it('is a safe no-op while retention is disabled', function () {
    config(['content_lifecycle.moderation.content_retention_days' => null]);

    $finalized = finalizedPostAgedDays(3650);

    $this->artisan('moderation:purge-content')
        ->expectsOutputToContain('retention is disabled')
        ->assertSuccessful();

    expect(Post::query()->find($finalized->id))->not->toBeNull();
});

it('purges finalized targets when retention is enabled', function () {
    config(['content_lifecycle.moderation.content_retention_days' => 30]);

    $expired = finalizedPostAgedDays(31);
    $young = finalizedPostAgedDays(0);

    $comment = Comment::factory()->for(Post::factory()->published(), 'post')->create(['status' => CommentStatus::Hidden]);
    app(FinalizeCommentRemovalAction::class)->handle(User::factory()->admin()->create(), $comment, 'Finalized.');
    Comment::query()->whereKey($comment->id)->update(['moderation_removed_at' => now()->subDays(31)]);

    $this->artisan('moderation:purge-content')
        ->expectsOutputToContain(sprintf('post %d: purged', $expired->id))
        ->expectsOutputToContain(sprintf('comment %d: purged', $comment->id))
        ->expectsOutputToContain('purged: 2')
        ->assertSuccessful();

    // The young target never even enters the candidate set; a targeted run
    // reports its precise outcome.
    $this->artisan('moderation:purge-content --type=post --id='.$young->id)
        ->expectsOutputToContain(sprintf('post %d: not_expired', $young->id))
        ->assertSuccessful();

    expect(Post::withTrashed()->find($expired->id))->toBeNull()
        ->and(Post::query()->find($young->id))->not->toBeNull()
        ->and(Comment::withTrashed()->find($comment->id))->toBeNull();
});

it('allows dry-run with a manual override while disabled, but requires --force to destroy', function () {
    config(['content_lifecycle.moderation.content_retention_days' => null]);

    $finalized = finalizedPostAgedDays(100);

    $this->artisan('moderation:purge-content --older-than=30 --dry-run')
        ->expectsOutputToContain(sprintf('post %d: would_purge', $finalized->id))
        ->assertSuccessful();

    expect(Post::query()->find($finalized->id))->not->toBeNull();

    $this->artisan('moderation:purge-content --older-than=30')
        ->expectsOutputToContain('requires --force')
        ->assertFailed();

    expect(Post::query()->find($finalized->id))->not->toBeNull();

    $this->artisan('moderation:purge-content --older-than=30 --force')
        ->expectsOutputToContain(sprintf('post %d: purged', $finalized->id))
        ->assertSuccessful();

    expect(Post::withTrashed()->find($finalized->id))->toBeNull();
});

it('force cannot bypass state validation or holds', function () {
    config(['content_lifecycle.moderation.content_retention_days' => null]);

    // Reversible Hidden: not finalized, not purge material even with force.
    $reversible = Post::factory()->hidden()->create();

    $this->artisan('moderation:purge-content --type=post --id='.$reversible->id.' --older-than=0 --force')
        ->expectsOutputToContain(sprintf('post %d: invalid_state', $reversible->id))
        ->assertSuccessful();

    expect(Post::query()->find($reversible->id))->not->toBeNull()
        ->and($reversible->fresh()->moderation_removed_at)->toBeNull();

    // Open report hold survives force.
    $held = finalizedPostAgedDays(100);
    Report::factory()->create([
        'target_type' => Post::class,
        'target_id' => $held->id,
        'status' => ReportStatus::Open,
    ]);

    $this->artisan('moderation:purge-content --type=post --id='.$held->id.' --older-than=0 --force')
        ->expectsOutputToContain(sprintf('post %d: moderation_hold', $held->id))
        ->assertSuccessful();

    expect(Post::query()->find($held->id))->not->toBeNull();
});

it('filters by type and validates options', function () {
    config(['content_lifecycle.moderation.content_retention_days' => 0]);

    $post = finalizedPostAgedDays(1);

    $this->artisan('moderation:purge-content --type=comment')
        ->expectsOutputToContain('No purge candidates.')
        ->assertSuccessful();

    expect(Post::query()->find($post->id))->not->toBeNull();

    $this->artisan('moderation:purge-content --type=bogus')->assertFailed();
    $this->artisan('moderation:purge-content --id=5')->assertFailed();
    $this->artisan('moderation:purge-content --type=post --id=abc')->assertFailed();
    $this->artisan('moderation:purge-content --older-than=-1')->assertFailed();
    $this->artisan('moderation:purge-content --chunk=0')->assertFailed();
});

it('requires --force to destructively shorten an enabled retention window', function () {
    config(['content_lifecycle.moderation.content_retention_days' => 90]);

    $finalized = finalizedPostAgedDays(30);

    $this->artisan('moderation:purge-content --type=post --id='.$finalized->id.' --older-than=10 --dry-run')
        ->expectsOutputToContain(sprintf('post %d: would_purge', $finalized->id))
        ->assertSuccessful();

    $this->artisan('moderation:purge-content --type=post --id='.$finalized->id.' --older-than=10')
        ->expectsOutputToContain('requires --force')
        ->assertFailed();

    expect(Post::query()->find($finalized->id))->not->toBeNull();

    $this->artisan('moderation:purge-content --type=post --id='.$finalized->id.' --older-than=10 --force')
        ->expectsOutputToContain(sprintf('post %d: purged', $finalized->id))
        ->assertSuccessful();

    // Lengthening (>= configured) stays force-free.
    $other = finalizedPostAgedDays(200);
    $this->artisan('moderation:purge-content --type=post --id='.$other->id.' --older-than=120')
        ->expectsOutputToContain(sprintf('post %d: purged', $other->id))
        ->assertSuccessful();
});

it('fails closed on invalid moderation retention config', function () {
    config(['content_lifecycle.moderation.content_retention_days' => 'foo']);

    $finalized = finalizedPostAgedDays(100);

    $this->artisan('moderation:purge-content')->assertFailed();

    expect(Post::query()->find($finalized->id))->not->toBeNull();
});
