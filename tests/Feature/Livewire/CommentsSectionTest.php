<?php

use App\Enums\CommentStatus;
use App\Enums\UserStatus;
use App\Enums\VoteType;
use App\Livewire\Comments\CommentsSection;
use App\Models\Comment;
use App\Models\CommentVote;
use App\Models\Post;
use App\Models\User;
use App\Queries\Comments\CommentListQuery;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

it('can render comments section component', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(CommentsSection::class, ['postId' => $post->id])
        ->assertStatus(200)
        ->assertSee('data-testid="comments-section"', false);
});

it('renders visible comments and hides hidden or deleted comments', function () {
    $post = Post::factory()->published()->create();

    Comment::factory()->for($post)->create([
        'body' => 'Looks delicious.',
        'status' => CommentStatus::Visible,
    ]);

    Comment::factory()->for($post)->create([
        'body' => 'Hidden comment',
        'status' => CommentStatus::Hidden,
    ]);

    $deleted = Comment::factory()->for($post)->create([
        'body' => 'Deleted comment',
        'status' => CommentStatus::Visible,
    ]);
    $deleted->delete();

    Livewire::test(CommentsSection::class, ['postId' => $post->id])
        ->assertSee('Looks delicious.')
        ->assertDontSee('Hidden comment')
        ->assertDontSee('Deleted comment');
});

it('refreshes comments section after comment-created event', function () {
    $post = Post::factory()->published()->create();

    $component = Livewire::test(CommentsSection::class, ['postId' => $post->id])
        ->assertDontSee('Fresh comment');

    Comment::factory()->for($post)->create([
        'body' => 'Fresh comment',
        'status' => CommentStatus::Visible,
    ]);

    $component
        ->dispatch('comment-created', postId: $post->id)
        ->assertSee('Fresh comment')
        ->assertSee('data-testid="comment-item"', false);
});

it('allows owner to delete own comment from comments section', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    $comment = Comment::factory()->for($user)->for($post)->create([
        'body' => 'My comment',
        'status' => CommentStatus::Visible,
    ]);

    Livewire::actingAs($user)
        ->test(CommentsSection::class, ['postId' => $post->id])
        ->assertSee('Delete')
        ->call('deleteComment', $comment->id)
        ->assertDontSee('My comment')
        ->assertDispatched('comment-deleted');

    $this->assertSoftDeleted('comments', ['id' => $comment->id]);
});

it('says so, rather than failing, when a delete or hide is refused for what changed meanwhile', function (string $method, Closure $actor) {
    $post = Post::factory()->published()->create();
    $comment = Comment::factory()->for($post)->create(['body' => 'Still here', 'status' => CommentStatus::Visible]);
    $user = $actor($comment);
    $page = Livewire::actingAs($user)->test(CommentsSection::class, ['postId' => $post->id]);

    // The account loses the right to act after the thread was drawn.
    $user->forceFill(['status' => UserStatus::Banned])->save();

    $page->call($method, $comment->id)
        ->assertOk()
        ->assertDispatched('toast', message: __('ui.comments.unavailable'))
        ->assertNotDispatched('comment-deleted')
        ->assertNotDispatched('comment-hidden');

    expect($comment->fresh())->status->toBe(CommentStatus::Visible)->deleted_at->toBeNull();
})->with([
    'its author deleting it' => ['deleteComment', fn (Comment $comment) => $comment->user],
    'a moderator hiding it' => ['hideComment', fn (Comment $comment) => User::factory()->moderator()->create()],
]);

it('does not render delete button for non owner', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $post = Post::factory()->published()->create();

    Comment::factory()->for($owner)->for($post)->create([
        'body' => 'Owner comment',
        'status' => CommentStatus::Visible,
    ]);

    Livewire::actingAs($viewer)
        ->test(CommentsSection::class, ['postId' => $post->id])
        ->assertSee('Owner comment')
        ->assertDontSee('Delete');
});

it('allows moderator to hide comment from comments section', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();

    $comment = Comment::factory()->for($post)->create([
        'body' => 'Bad comment',
        'status' => CommentStatus::Visible,
    ]);

    Livewire::actingAs($moderator)
        ->test(CommentsSection::class, ['postId' => $post->id])
        ->assertSee('Hide')
        ->call('hideComment', $comment->id)
        ->assertDontSee('Bad comment')
        ->assertDispatched('comment-hidden');

    expect($comment->fresh()->status)->toBe(CommentStatus::Hidden);
});

it('does not render hide button for normal user', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Comment::factory()->for($post)->create([
        'body' => 'Normal visible comment',
        'status' => CommentStatus::Visible,
    ]);

    Livewire::actingAs($user)
        ->test(CommentsSection::class, ['postId' => $post->id])
        ->assertSee('Normal visible comment')
        ->assertDontSee('Hide');
});

it('renders comments empty state when no visible comments exist', function () {
    $post = Post::factory()->published()->create();

    Comment::factory()->for($post)->create([
        'body' => 'Hidden comment',
        'status' => CommentStatus::Hidden,
    ]);

    Livewire::test(CommentsSection::class, ['postId' => $post->id])
        ->assertSee('No comments yet')
        ->assertSee('border-rg-border', false)
        ->assertDontSee('Hidden comment');
});

it('has comments loading state markup', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(CommentsSection::class, ['postId' => $post->id])
        ->assertSee('data-testid="comments-loading"', false)
        ->assertSee('wire:loading', false);
});

it('renders newest top level comments first', function () {
    $post = Post::factory()->published()->create();

    Comment::factory()->for($post)->create([
        'body' => 'Older comment',
        'status' => CommentStatus::Visible,
        'created_at' => now()->subHour(),
    ]);

    Comment::factory()->for($post)->create([
        'body' => 'Newer comment',
        'status' => CommentStatus::Visible,
        'created_at' => now(),
    ]);

    $html = Livewire::test(CommentsSection::class, ['postId' => $post->id])
        ->html();

    expect($html)
        ->toContain('Newer comment')
        ->toContain('Older comment');
    $newerPosition = strpos($html, 'Newer comment');
    $olderPosition = strpos($html, 'Older comment');

    expect($newerPosition)->not->toBeFalse()
        ->and($olderPosition)->not->toBeFalse()
        ->and($newerPosition)->toBeLessThan($olderPosition);
});

it('sorts comments by newest for guests', function () {
    $post = Post::factory()->published()->create();

    Comment::factory()->for($post)->create([
        'body' => 'Older comment',
        'status' => CommentStatus::Visible,
        'created_at' => now()->subHour(),
    ]);

    Comment::factory()->for($post)->create([
        'body' => 'Newer comment',
        'status' => CommentStatus::Visible,
        'created_at' => now(),
    ]);

    $html = Livewire::test(CommentsSection::class, ['postId' => $post->id])
        ->call('setCommentSort', 'newest')
        ->assertSet('commentSort', 'newest')
        ->html();

    $newerPosition = strpos($html, 'Newer comment');
    $olderPosition = strpos($html, 'Older comment');

    expect($newerPosition)->not->toBeFalse()
        ->and($olderPosition)->not->toBeFalse()
        ->and($newerPosition)->toBeLessThan($olderPosition);
});

it('sorts comments by top score for guests', function () {
    $post = Post::factory()->published()->create();

    Comment::factory()->for($post)->create([
        'body' => 'Low score comment',
        'status' => CommentStatus::Visible,
        'upvotes_count' => 1,
        'downvotes_count' => 0,
        'created_at' => now(),
    ]);

    Comment::factory()->for($post)->create([
        'body' => 'High score comment',
        'status' => CommentStatus::Visible,
        'upvotes_count' => 8,
        'downvotes_count' => 1,
        'created_at' => now()->subHour(),
    ]);

    $html = Livewire::test(CommentsSection::class, ['postId' => $post->id])
        ->call('setCommentSort', 'top')
        ->assertSet('commentSort', 'top')
        ->html();

    $highPosition = strpos($html, 'High score comment');
    $lowPosition = strpos($html, 'Low score comment');

    expect($highPosition)->not->toBeFalse()
        ->and($lowPosition)->not->toBeFalse()
        ->and($highPosition)->toBeLessThan($lowPosition);
});

it('sorts comments by hot engagement for guests', function () {
    $post = Post::factory()->published()->create();

    Comment::factory()->for($post)->create([
        'body' => 'Quiet comment',
        'status' => CommentStatus::Visible,
        'upvotes_count' => 1,
        'downvotes_count' => 0,
        'created_at' => now(),
    ]);

    Comment::factory()->for($post)->create([
        'body' => 'Active comment',
        'status' => CommentStatus::Visible,
        'upvotes_count' => 3,
        'downvotes_count' => 2,
        'created_at' => now()->subHour(),
    ]);

    $html = Livewire::test(CommentsSection::class, ['postId' => $post->id])
        ->call('setCommentSort', 'hot')
        ->assertSet('commentSort', 'hot')
        ->html();

    $activePosition = strpos($html, 'Active comment');
    $quietPosition = strpos($html, 'Quiet comment');

    expect($activePosition)->not->toBeFalse()
        ->and($quietPosition)->not->toBeFalse()
        ->and($activePosition)->toBeLessThan($quietPosition);
});

it('can add a reply to a top level comment', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();
    $comment = Comment::factory()->for($post)->create([
        'body' => 'Parent comment',
        'status' => CommentStatus::Visible,
    ]);

    Livewire::actingAs($user)
        ->test(CommentsSection::class, ['postId' => $post->id])
        ->call('startReply', $comment->id)
        ->assertSet('replyingTo', $comment->id)
        ->set('replyBody', 'Reply body')
        ->call('submitReply')
        ->assertSet('replyingTo', null)
        ->assertSee('Reply body')
        ->assertSee('data-testid="comment-replies"', false);

    $this->assertDatabaseHas('comments', [
        'post_id' => $post->id,
        'parent_id' => $comment->id,
        'user_id' => $user->id,
        'body' => 'Reply body',
    ]);
});

it('does not let guests submit replies through the component action', function () {
    $post = Post::factory()->published()->create();
    $comment = Comment::factory()->for($post)->create([
        'body' => 'Parent comment',
        'status' => CommentStatus::Visible,
    ]);

    Livewire::test(CommentsSection::class, ['postId' => $post->id])
        ->set('replyingTo', $comment->id)
        ->set('replyBody', 'Guest reply')
        ->call('submitReply')
        ->assertHasErrors('replyBody')
        ->assertSee('You must be signed in to reply.');

    $this->assertDatabaseMissing('comments', [
        'parent_id' => $comment->id,
        'body' => 'Guest reply',
    ]);
});

it('renders reply form with the compact comment composer styling', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();
    $comment = Comment::factory()->for($post)->create([
        'body' => 'Parent comment',
        'status' => CommentStatus::Visible,
    ]);

    Livewire::actingAs($user)
        ->test(CommentsSection::class, ['postId' => $post->id])
        ->call('startReply', $comment->id)
        ->assertSee('data-testid="reply-form"', false)
        ->assertSee('placeholder="Write a reply..."', false)
        ->assertSee('aria-label="Write a reply..."', false)
        ->assertSee('rounded-[10px]', false)
        ->assertSee('border-rg-border2', false)
        ->assertSee('bg-rg-card2', false)
        ->assertSee('rg-comment-input', false)
        ->assertSee('bg-rg-card', false);
});

it('shows view more comments for longer threads', function () {
    $post = Post::factory()->published()->create();

    Comment::factory()
        ->count(6)
        ->for($post)
        ->create(['status' => CommentStatus::Visible]);

    Livewire::test(CommentsSection::class, ['postId' => $post->id])
        ->assertSee('data-testid="view-more-comments"', false)
        ->call('loadMore')
        ->assertDontSee('data-testid="view-more-comments"', false);
});

it('stops offering more comments once it shows as many as it ever lists', function () {
    $post = Post::factory()->published()->create();
    Comment::factory()->count(CommentListQuery::MOST_ROOTS + 1)->for($post)->create(['status' => CommentStatus::Visible]);

    Livewire::test(CommentsSection::class, ['postId' => $post->id, 'visibleCount' => CommentListQuery::MOST_ROOTS])
        ->assertDontSee('data-testid="view-more-comments"', false);
});

it('does not 500 when a guest invokes deleteComment', function () {
    $owner = User::factory()->create();
    $post = Post::factory()->published()->create();

    $comment = Comment::factory()->for($owner)->for($post)->create([
        'body' => 'Survives guest delete attempt',
        'status' => CommentStatus::Visible,
    ]);

    Livewire::test(CommentsSection::class, ['postId' => $post->id])
        ->call('deleteComment', $comment->id)
        ->assertOk();

    $this->assertNotSoftDeleted('comments', ['id' => $comment->id]);
});

it('does not 500 when a guest invokes hideComment', function () {
    $post = Post::factory()->published()->create();

    $comment = Comment::factory()->for($post)->create([
        'body' => 'Survives guest hide attempt',
        'status' => CommentStatus::Visible,
    ]);

    Livewire::test(CommentsSection::class, ['postId' => $post->id])
        ->call('hideComment', $comment->id)
        ->assertOk();

    expect($comment->fresh()->status)->toBe(CommentStatus::Visible);
});

it('targets refreshComments in the loading state', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(CommentsSection::class, ['postId' => $post->id])
        ->assertSee('wire:target="deleteComment,hideComment,refreshComments,submitReply,loadMore"', false);
});

it('does not let a client point the thread at another post', function () {
    $published = Post::factory()->published()->create();
    $hidden = Post::factory()->hidden()->create();

    Livewire::test(CommentsSection::class, ['postId' => $published->id])
        ->set('postId', $hidden->id);
})->throws(CannotUpdateLockedPropertyException::class);

it('does not let a client ask for more of the thread than load more gives', function () {
    Livewire::test(CommentsSection::class, ['postId' => Post::factory()->published()->create()->id])
        ->set('visibleCount', 100000);
})->throws(CannotUpdateLockedPropertyException::class);

it('reads the viewer\'s comment votes for the whole thread at once', function () {
    $viewer = User::factory()->create();

    /** @return array{votes: int, comments: int, shown: int} */
    $queriesOn = function (int $comments) use ($viewer): array {
        // A thread of its own each time, each root with a reply, so replies
        // are read with the thread too; the viewer voted on one root and its reply.
        $post = Post::factory()->published()->create();
        $roots = Comment::factory()->count($comments)->for($post)->create(['status' => CommentStatus::Visible]);
        $replies = $roots->map(fn (Comment $root): Comment => Comment::factory()->for($post)->create(['status' => CommentStatus::Visible, 'parent_id' => $root->id]));
        foreach ([$roots->first(), $replies->first()] as $voted) {
            CommentVote::factory()->create(['user_id' => $viewer->id, 'comment_id' => $voted->id, 'type' => VoteType::Up]);
        }
        $queries = ['votes' => 0, 'comments' => 0];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $sql = str_replace(['"', '`'], '', $query->sql);

            match (true) {
                str_contains($sql, 'from comment_votes') => $queries['votes']++,
                str_contains($sql, 'from comments') => $queries['comments']++,
                default => null,
            };
        });

        // Shown in full the way a reader gets there: the count is locked. A
        // voting button is drawn once, by the response that first shows its
        // comment; later ones keep it as it is. In the long thread the voted,
        // oldest root first appears with Load more.
        $thread = Livewire::actingAs($viewer)->test(CommentsSection::class, ['postId' => $post->id]);
        $shown = preg_match_all('/aria-pressed="true"\s+data-state="active"/', $thread->html());

        foreach (range(1, 2) as $more) {
            $shown += preg_match_all('/aria-pressed="true"\s+data-state="active"/', $thread->call('loadMore')->html());
        }

        return [...$queries, 'shown' => $shown];
    };

    $two = $queriesOn(2);

    // Both votes drawn, read in one go per render, however long the thread.
    expect($two['shown'])->toBe(2)
        ->and($two['votes'])->toBeGreaterThan(0)
        ->and($queriesOn(8))->toBe($two);
});

it('closes the reply form under a comment hidden or deleted meanwhile', function () {
    $post = Post::factory()->published()->create();
    $comment = Comment::factory()->for($post)->create();
    $section = Livewire::actingAs(User::factory()->moderator()->create())
        ->test(CommentsSection::class, ['postId' => $post->id])
        ->call('startReply', $comment->id)
        ->assertSee('data-testid="reply-form"', false);

    $section->call('hideComment', $comment->id)
        ->assertSet('replyingTo', null)
        ->assertDontSee('data-testid="reply-form"', false);

    // Hidden or deleted in another tab or by someone else: told by the event.
    $other = Comment::factory()->for($post)->create();
    $section->call('startReply', $other->id)
        ->dispatch('comment-deleted', postId: $post->id, commentId: $other->id)
        ->assertSet('replyingTo', null);
});
