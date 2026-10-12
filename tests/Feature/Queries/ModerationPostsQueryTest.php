<?php

use App\Actions\Moderation\RejectPostAction;
use App\Actions\Posts\DeletePostAction;
use App\Enums\PostStatus;
use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use App\Queries\Posts\ModerationPostsQuery;

/**
 * The posts moderation lists, a tab at a time: what each tab holds, the
 * search and the category narrowing it, and an order that keeps every post
 * in its place from one page to the next.
 */
function moderationPage(string $tab = 'all', string $search = '', ?int $category = null, string $sort = 'oldest', int $page = 1): array
{
    return app(ModerationPostsQuery::class)->paginate($tab, $search, $category, $sort, $page)->pluck('id')->all();
}

it('lists live posts and the well-formed author-deleted ones, each tab its own', function () {
    $author = User::factory()->create();
    $pending = Post::factory()->pending()->for($author)->create();
    $published = Post::factory()->published()->for($author)->create();
    $hidden = Post::factory()->published()->for($author)->create(['status' => PostStatus::Hidden]);
    $reported = Post::factory()->published()->for($author)->create(['reports_count' => 2]);
    $flagged = Post::factory()->published()->for($author)->create(['needs_review' => true]);
    $deleted = Post::factory()->published()->for($author)->create();
    app(DeletePostAction::class)->handle($author, $deleted);
    // A legacy soft-deleted row that kept its status is listed nowhere.
    $legacy = Post::factory()->published()->for($author)->create();
    Post::query()->whereKey($legacy->id)->update(['deleted_at' => now()]);

    expect(moderationPage('pending'))->toBe([$pending->id])
        ->and(moderationPage('published'))->toBe([$published->id, $reported->id, $flagged->id])
        ->and(moderationPage('hidden'))->toBe([$hidden->id])
        ->and(moderationPage('reported'))->toBe([$reported->id, $flagged->id])
        ->and(moderationPage('deleted'))->toBe([$deleted->id])
        ->and(moderationPage())->not->toContain($legacy->id)->toHaveCount(6)
        ->and(app(ModerationPostsQuery::class)->counts())->toBe(['all' => 6, 'pending' => 1, 'published' => 3, 'hidden' => 1, 'reported' => 2, 'deleted' => 1]);
});

it('searches the title, the author and the id, taking what is typed literally', function () {
    $luna = User::factory()->create(['username' => 'luna_the_husky']);
    $first = Post::factory()->published()->for($luna)->create(['title' => 'A howl at dawn']);
    $second = Post::factory()->published()->create(['title' => '100% Goofball']);
    $third = Post::factory()->published()->create(['title' => 'Unrelated']);

    expect(moderationPage(search: 'HOWL'))->toBe([$first->id])
        ->and(moderationPage(search: 'luna_the'))->toBe([$first->id])
        ->and(moderationPage(search: '100%'))->toBe([$second->id])
        // An underscore is a character, not a wildcard.
        ->and(moderationPage(search: 'a_howl'))->toBe([])
        ->and(moderationPage(search: '#'.$third->id))->toBe([$third->id])
        ->and(moderationPage(search: (string) $third->id))->toContain($third->id);
});

it('narrows a list to a category', function () {
    $dogs = Category::factory()->create();
    $dog = Post::factory()->published()->create(['category_id' => $dogs->id]);
    Post::factory()->published()->create();

    expect(moderationPage(category: $dogs->id))->toBe([$dog->id]);
});

it('keeps posts that share a time in their place across pages, in every order', function (string $sort, bool $descending) {
    $at = now()->subHour()->startOfSecond();
    $posts = Post::factory()->count(ModerationPostsQuery::PER_PAGE + 5)->pending()->create(['created_at' => $at, 'reports_count' => 1]);
    $ids = $posts->pluck('id')->sort()->values()->all();
    $expected = $descending ? array_reverse($ids) : $ids;

    $first = moderationPage('pending', sort: $sort);
    $second = moderationPage('pending', sort: $sort, page: 2);

    expect($first)->toHaveCount(ModerationPostsQuery::PER_PAGE)
        ->and(array_intersect($first, $second))->toBe([])
        ->and([...$first, ...$second])->toBe($expected)
        ->and(app(ModerationPostsQuery::class)->pageIds('pending', '', null, $sort, 2))->toBe($second);
})->with([
    'oldest first' => ['oldest', false],
    'newest first' => ['newest', true],
    'most reported' => ['reports', false],
]);

it('orders the most reported first, and the longest waiting first among equals', function () {
    $older = Post::factory()->published()->create(['reports_count' => 1, 'created_at' => now()->subDay()]);
    $most = Post::factory()->published()->create(['reports_count' => 4]);
    $newer = Post::factory()->published()->create(['reports_count' => 1]);

    expect(moderationPage(sort: 'reports'))->toBe([$most->id, $older->id, $newer->id]);
});

it('says why a rejected post was rejected, and when the longest waiting post came in', function () {
    $moderator = User::factory()->moderator()->create();
    $rejected = Post::factory()->pending()->create();
    app(RejectPostAction::class)->handle($moderator, $rejected, 'Wrong category');
    $waiting = Post::factory()->pending()->create(['created_at' => now()->subHours(3)->startOfSecond()]);
    Post::factory()->pending()->create(['created_at' => now()->subHour()]);

    $page = app(ModerationPostsQuery::class)->paginate('all', '', null, 'oldest', 1);

    expect($page->firstWhere('id', $rejected->id)->rejection_reason)->toBe('Wrong category')
        ->and($page->firstWhere('id', $waiting->id)->rejection_reason)->toBeNull()
        ->and(app(ModerationPostsQuery::class)->oldestPendingAt()?->equalTo($waiting->created_at))->toBeTrue();
});
