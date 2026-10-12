<?php

use App\Actions\Moderation\FinalizeCommentRemovalAction;
use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Queries\Comments\ModerationCommentsQuery;

/**
 * The comments moderation lists, a tab at a time: what each tab holds, the
 * search narrowing it, and an order that keeps every comment in its place
 * from one page to the next.
 */
function moderationCommentsPage(string $tab = 'all', string $search = '', int $page = 1): array
{
    return app(ModerationCommentsQuery::class)->paginate($tab, $search, $page)->pluck('id')->all();
}

it('lists every comment, the deleted ones included, each tab its own', function () {
    $visible = Comment::factory()->create(['created_at' => now()->subMinutes(5)]);
    $reported = Comment::factory()->create(['reports_count' => 2, 'created_at' => now()->subMinutes(4)]);
    $hidden = Comment::factory()->create(['status' => CommentStatus::Hidden, 'created_at' => now()->subMinutes(3)]);
    $finalized = Comment::factory()->create(['status' => CommentStatus::Hidden, 'reports_count' => 1, 'created_at' => now()->subMinutes(2)]);
    app(FinalizeCommentRemovalAction::class)->handle(User::factory()->admin()->create(), $finalized, 'Final.');
    $deleted = Comment::factory()->create(['created_at' => now()->subMinute()]);
    // Deleted by its author: the comment is soft-deleted.
    $deleted->delete();

    expect(moderationCommentsPage())->toBe([$deleted->id, $finalized->id, $hidden->id, $reported->id, $visible->id])
        ->and(moderationCommentsPage('visible'))->toBe([$reported->id, $visible->id])
        ->and(moderationCommentsPage('hidden'))->toBe([$finalized->id, $hidden->id])
        ->and(moderationCommentsPage('deleted'))->toBe([$deleted->id])
        ->and(moderationCommentsPage('reported'))->toBe([$reported->id])
        ->and(app(ModerationCommentsQuery::class)->counts())->toBe(['all' => 5, 'visible' => 2, 'hidden' => 2, 'deleted' => 1, 'reported' => 1]);
});

it('searches the text, the author, the post and the id, taking what is typed literally', function () {
    $post = Post::factory()->published()->create(['title' => 'Rex at the vet']);
    $first = Comment::factory()->for($post)->for(User::factory()->create(['username' => 'frau.keks']))->create(['body' => 'Get well soon, Rex']);
    $second = Comment::factory()->create(['body' => 'A 100% cute photo']);
    $third = Comment::factory()->create(['body' => 'Unrelated']);

    expect(moderationCommentsPage(search: 'WELL SOON'))->toBe([$first->id])
        ->and(moderationCommentsPage(search: 'frau.k'))->toBe([$first->id])
        ->and(moderationCommentsPage(search: 'the vet'))->toBe([$first->id])
        ->and(moderationCommentsPage(search: '100%'))->toBe([$second->id])
        ->and(moderationCommentsPage(search: 'get_well'))->toBe([])
        ->and(moderationCommentsPage(search: '#'.$third->id))->toBe([$third->id]);
});

it('keeps comments that came in at the same moment in their place across pages', function () {
    $comments = Comment::factory()->count(ModerationCommentsQuery::PER_PAGE + 5)->create(['created_at' => now()->subHour()->startOfSecond()]);
    $expected = $comments->pluck('id')->sortDesc()->values()->all();

    $first = moderationCommentsPage();
    $second = moderationCommentsPage(page: 2);

    expect($first)->toHaveCount(ModerationCommentsQuery::PER_PAGE)
        ->and(array_intersect($first, $second))->toBe([])
        ->and([...$first, ...$second])->toBe($expected);
});

it('finds a comment its author deleted, with the post it was on', function () {
    $comment = Comment::factory()->create();
    $comment->delete();

    $found = app(ModerationCommentsQuery::class)->find($comment->id);

    expect($found?->trashed())->toBeTrue()
        ->and($found?->post)->not->toBeNull();
});
