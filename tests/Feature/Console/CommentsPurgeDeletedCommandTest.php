<?php

use App\Actions\Comments\DeleteCommentAction;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;

beforeEach(function () {
    config(['content_lifecycle.comments.author_delete_retention_days' => 30]);
});

function deletedLeafAgedDays(int $ageDays): Comment
{
    $author = User::factory()->create();
    $comment = Comment::factory()->for($author)->for(Post::factory()->published(), 'post')->create();
    app(DeleteCommentAction::class)->handle($author, $comment);

    if ($ageDays > 0) {
        Comment::withTrashed()->whereKey($comment->id)->update(['deleted_at' => now()->subDays($ageDays)]);
    }

    return Comment::withTrashed()->findOrFail($comment->id);
}

it('purges expired leaves and skips young ones at the default retention', function () {
    $expired = deletedLeafAgedDays(31);
    $young = deletedLeafAgedDays(0);

    $this->artisan('comments:purge-deleted')
        ->expectsOutputToContain(sprintf('comment %d: purged', $expired->id))
        ->expectsOutputToContain('purged: 1')
        ->assertSuccessful();

    expect(Comment::withTrashed()->find($expired->id))->toBeNull()
        ->and(Comment::withTrashed()->find($young->id))->not->toBeNull();
});

it('honors --older-than and processes a targeted id with precise outcomes', function () {
    $leaf = deletedLeafAgedDays(10);

    $this->artisan('comments:purge-deleted --older-than=5 --comment='.$leaf->id)
        ->expectsOutputToContain(sprintf('comment %d: purged', $leaf->id))
        ->assertSuccessful();

    $young = deletedLeafAgedDays(0);
    $this->artisan('comments:purge-deleted --comment='.$young->id)
        ->expectsOutputToContain(sprintf('comment %d: not_expired', $young->id))
        ->assertSuccessful();

    $this->artisan('comments:purge-deleted --comment=999999')
        ->expectsOutputToContain('comment 999999: already_gone')
        ->assertSuccessful();
});

it('reports hold outcomes distinctly', function () {
    // Structural anchor: deleted root with a live reply.
    $author = User::factory()->create();
    $post = Post::factory()->published()->create();
    $root = Comment::factory()->for($author)->create(['post_id' => $post->id]);
    Comment::factory()->create(['post_id' => $post->id, 'parent_id' => $root->id]);
    app(DeleteCommentAction::class)->handle($author, $root);
    Comment::withTrashed()->whereKey($root->id)->update(['deleted_at' => now()->subDays(40)]);

    $this->artisan('comments:purge-deleted')
        ->expectsOutputToContain(sprintf('comment %d: structural_anchor', $root->id))
        ->expectsOutputToContain('structural_anchor: 1')
        ->assertSuccessful();

    expect(Comment::withTrashed()->find($root->id))->not->toBeNull();
});

it('rejects invalid options', function (string $args) {
    $this->artisan('comments:purge-deleted '.$args)->assertFailed();
})->with([
    'negative older-than' => ['--older-than=-1'],
    'non-numeric older-than' => ['--older-than=foo'],
    'zero chunk' => ['--chunk=0'],
    'huge chunk' => ['--chunk=1001'],
    'invalid comment id' => ['--comment=abc'],
]);

it('fails closed with invalid retention config and purges nothing', function () {
    $expired = deletedLeafAgedDays(40);

    config(['content_lifecycle.comments.author_delete_retention_days' => 'foo']);

    $this->artisan('comments:purge-deleted')->assertFailed();

    expect(Comment::withTrashed()->find($expired->id))->not->toBeNull();
});

it('mutates nothing on dry-run', function () {
    $expired = deletedLeafAgedDays(31);

    $this->artisan('comments:purge-deleted --dry-run')
        ->expectsOutputToContain(sprintf('comment %d: would_purge', $expired->id))
        ->assertSuccessful();

    expect(Comment::withTrashed()->find($expired->id))->not->toBeNull();
});
