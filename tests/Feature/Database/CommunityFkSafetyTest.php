<?php

use App\Enums\CommentStatus;
use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\VoteType;
use App\Models\Comment;
use App\Models\CommentVote;
use App\Models\Follow;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Models\Post;
use App\Models\PostSave;
use App\Models\PostVote;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\RatingVote;
use App\Models\Report;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Community FK safety: the database no longer implements a hidden deletion
 * policy. A low-level DELETE against users/posts/comments must be refused
 * by the FK graph whenever community/history rows reference the target —
 * destruction of the graph may only ever happen through explicit,
 * sanctioned lifecycle services (account anonymization today; comment/post
 * purge in PR-D/PR-E). Raw DB deletes here deliberately bypass Eloquent to
 * simulate exactly the "accidental maintenance DELETE" the constraints
 * exist to stop.
 */

function rawDelete(string $table, int $id): void
{
    // Wrapped in DB::transaction so the statement runs inside a savepoint:
    // on PostgreSQL a rejected FK statement aborts the enclosing
    // transaction (RefreshDatabase's), and only a savepoint rollback keeps
    // the test connection usable for the assertions that follow.
    DB::transaction(fn () => DB::table($table)->where('id', $id)->delete());
}

it('refuses to hard-delete a user who authored a post, keeping the post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->for($user)->create();

    expect(fn () => rawDelete('users', $user->id))->toThrow(QueryException::class);

    expect(User::find($user->id))->not->toBeNull()
        ->and(Post::find($post->id))->not->toBeNull();
});

it('refuses to hard-delete a user who authored a comment, keeping the comment', function () {
    $user = User::factory()->create();
    $comment = Comment::factory()->for($user)->create(['status' => CommentStatus::Visible]);

    expect(fn () => rawDelete('users', $user->id))->toThrow(QueryException::class);

    expect(Comment::find($comment->id))->not->toBeNull();
});

it('refuses to hard-delete a user with votes of any kind, keeping the votes', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();
    $comment = Comment::factory()->create(['status' => CommentStatus::Visible]);
    PostVote::create(['user_id' => $user->id, 'post_id' => $post->id, 'type' => VoteType::Up]);
    CommentVote::create(['user_id' => $user->id, 'comment_id' => $comment->id, 'type' => VoteType::Up]);
    RatingVote::factory()->create(['user_id' => $user->id, 'post_id' => $post->id]);

    expect(fn () => rawDelete('users', $user->id))->toThrow(QueryException::class);

    expect(PostVote::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(CommentVote::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(RatingVote::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('refuses to hard-delete a user with reports, keeping the moderation evidence', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();
    $report = Report::create([
        'reporter_id' => $user->id,
        'target_type' => Post::class,
        'target_id' => $post->id,
        'reason' => ReportReason::Spam,
        'status' => ReportStatus::Open,
    ]);

    expect(fn () => rawDelete('users', $user->id))->toThrow(QueryException::class);

    expect(Report::find($report->id))->not->toBeNull();
});

it('refuses to hard-delete a user with follows in either direction', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    Follow::create(['follower_id' => $user->id, 'author_id' => $other->id]);

    expect(fn () => rawDelete('users', $user->id))->toThrow(QueryException::class);

    expect(Follow::query()->count())->toBe(1);
});

it('refuses to hard-delete a user with saved posts', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();
    PostSave::create(['user_id' => $user->id, 'post_id' => $post->id]);

    expect(fn () => rawDelete('users', $user->id))->toThrow(QueryException::class);

    expect(PostSave::query()->count())->toBe(1);
});

it('refuses to hard-delete a post with comments, keeping the whole thread', function () {
    $post = Post::factory()->published()->create();
    $comment = Comment::factory()->create(['post_id' => $post->id, 'status' => CommentStatus::Visible]);
    $reply = Comment::factory()->create([
        'post_id' => $post->id,
        'parent_id' => $comment->id,
        'status' => CommentStatus::Visible,
    ]);

    expect(fn () => rawDelete('posts', $post->id))->toThrow(QueryException::class);

    expect(Comment::find($comment->id))->not->toBeNull()
        ->and(Comment::find($reply->id))->not->toBeNull();
});

it('refuses to hard-delete a post with votes, saves, rating votes or author answers', function (string $child) {
    $post = Post::factory()->published()->create();
    $user = User::factory()->create();

    match ($child) {
        'vote' => PostVote::create(['user_id' => $user->id, 'post_id' => $post->id, 'type' => VoteType::Up]),
        'save' => PostSave::create(['user_id' => $user->id, 'post_id' => $post->id]),
        'rating vote' => RatingVote::factory()->create(['user_id' => $user->id, 'post_id' => $post->id]),
        'author answer' => (function () use ($post): void {
            $option = RatingOption::factory()->create();
            $post->authorAnswers()->create([
                'rating_group_id' => $option->rating_group_id,
                'rating_option_id' => $option->id,
            ]);
        })(),
    };

    expect(fn () => rawDelete('posts', $post->id))->toThrow(QueryException::class);

    expect(Post::find($post->id))->not->toBeNull();
})->with(['vote', 'save', 'rating vote', 'author answer']);

it('refuses to hard-delete a parent comment, keeping the reply subtree', function () {
    $parent = Comment::factory()->create(['status' => CommentStatus::Visible]);
    $reply = Comment::factory()->create([
        'post_id' => $parent->post_id,
        'parent_id' => $parent->id,
        'status' => CommentStatus::Visible,
    ]);

    expect(fn () => rawDelete('comments', $parent->id))->toThrow(QueryException::class);

    expect(Comment::find($parent->id))->not->toBeNull()
        ->and(Comment::find($reply->id))->not->toBeNull();
});

it('refuses to hard-delete a comment with votes, keeping the vote', function () {
    $comment = Comment::factory()->create(['status' => CommentStatus::Visible]);
    $voter = User::factory()->create();
    CommentVote::create(['user_id' => $voter->id, 'comment_id' => $comment->id, 'type' => VoteType::Up]);

    expect(fn () => rawDelete('comments', $comment->id))->toThrow(QueryException::class);

    expect(CommentVote::query()->count())->toBe(1);
});

it('refuses to delete a rating group once votes reference it', function () {
    $vote = RatingVote::factory()->create();

    expect(fn () => rawDelete('rating_groups', $vote->rating_group_id))->toThrow(QueryException::class);

    expect(RatingVote::find($vote->id))->not->toBeNull();
});

it('refuses to delete a rating group once author answers reference it', function () {
    $post = Post::factory()->published()->create();
    $option = RatingOption::factory()->create();
    $answer = $post->authorAnswers()->create([
        'rating_group_id' => $option->rating_group_id,
        'rating_option_id' => $option->id,
    ]);

    expect(fn () => rawDelete('rating_groups', $option->rating_group_id))->toThrow(QueryException::class);

    expect($answer->fresh())->not->toBeNull();
});

/*
 * Positive side of the taxonomy: the deliberate technical cascades and
 * protective SET NULLs keep working.
 */

it('still cascades rating options when an unused rating group is deleted', function () {
    $group = RatingGroup::factory()->create();
    $option = RatingOption::factory()->create(['rating_group_id' => $group->id]);

    rawDelete('rating_groups', $group->id);

    expect(RatingOption::find($option->id))->toBeNull();
});

it('still cascades pivot rows when a childless post or a tag is deleted', function () {
    $post = Post::factory()->published()->create();
    $tag = Tag::factory()->create();
    $post->tags()->sync([$tag->id]);

    rawDelete('posts', $post->id);

    expect(DB::table('post_tag')->where('tag_id', $tag->id)->count())->toBe(0)
        ->and(Tag::find($tag->id))->not->toBeNull();
});

it('still cascades media variants and detaches references when an asset is purged', function () {
    $asset = MediaAsset::factory()->postImage()->create();
    $variant = MediaVariant::factory()->create(['media_asset_id' => $asset->id]);
    $post = Post::factory()->published()->create(['image_asset_id' => $asset->id]);

    rawDelete('media_assets', $asset->id);

    expect(MediaVariant::find($variant->id))->toBeNull()
        ->and($post->fresh()->image_asset_id)->toBeNull();
});

it('still allows deleting a user no community data references', function () {
    // What PR-B made unreachable from application code stays possible for
    // deliberate maintenance SQL: a user nothing references is deletable.
    $user = User::factory()->create();

    rawDelete('users', $user->id);

    expect(User::find($user->id))->toBeNull();
});

/*
 * Sanctioned-purge contract: physical deletion of community models may
 * only live inside explicit lifecycle services. Today that is
 * MediaLifecycleService alone; PR-D/PR-E will add comment/post purge
 * services to this allowlist.
 */

it('keeps forceDelete() out of application code except sanctioned purge services', function () {
    $allowlist = [
        'app/Services/Media/MediaLifecycleService.php',
    ];

    $offenders = collect(File::allFiles(app_path()))
        ->filter(fn ($file) => str_ends_with($file->getFilename(), '.php'))
        ->map(fn ($file) => str_replace(base_path().'/', '', $file->getPathname()))
        ->reject(fn (string $path) => in_array($path, $allowlist, true))
        ->filter(fn (string $path) => str_contains((string) file_get_contents(base_path($path)), 'forceDelete('))
        ->values();

    expect($offenders->all())->toBe([]);
});
