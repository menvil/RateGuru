<?php

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Queries\Reports\ModerationReportsQuery;

/**
 * The reports moderation lists, a tab at a time: what each holds, the kind of
 * target and the search narrowing it, the open reports on the same target,
 * stepping from one report to the next, and an order that keeps every report
 * in its place from one page to the next.
 */
function moderationReportsPage(string $tab = 'open', string $search = '', ?string $type = null, int $page = 1): array
{
    return app(ModerationReportsQuery::class)->paginate($tab, $search, $type, $page)->pluck('id')->all();
}

it('lists open reports oldest first and closed ones newest first, each tab its own', function () {
    $post = Post::factory()->published()->create();
    $older = reportOn($post, ['created_at' => now()->subHours(2)]);
    $newer = reportOn($post, ['created_at' => now()->subHour()]);
    $resolved = reportOn($post, ['status' => ReportStatus::Resolved, 'created_at' => now()->subHours(3)]);
    $ignored = reportOn($post, ['status' => ReportStatus::Ignored, 'created_at' => now()->subMinutes(5)]);

    expect(moderationReportsPage())->toBe([$older->id, $newer->id])
        ->and(moderationReportsPage('resolved'))->toBe([$resolved->id])
        ->and(moderationReportsPage('ignored'))->toBe([$ignored->id])
        ->and(moderationReportsPage('all'))->toBe([$ignored->id, $newer->id, $older->id, $resolved->id])
        ->and(app(ModerationReportsQuery::class)->counts())->toBe(['open' => 2, 'resolved' => 1, 'ignored' => 1, 'all' => 4]);
});

it('counts the open reports on each report\'s target, the targets they point to and the longest wait', function () {
    $post = Post::factory()->published()->create();
    $comment = Comment::factory()->create();
    $first = reportOn($post, ['created_at' => now()->subHours(5)->startOfSecond()]);
    reportOn($post);
    reportOn($post, ['status' => ReportStatus::Resolved]);
    reportOn($comment);

    $query = app(ModerationReportsQuery::class);

    expect((int) $query->paginate('open', '', null, 1)->firstWhere('id', $first->id)->open_on_target)->toBe(2)
        ->and($query->openTargets())->toBe(2)
        ->and($query->oldestOpenAt()?->equalTo($first->created_at))->toBeTrue();
});

it('narrows a list to a kind of target, and searches the content, the reporter, the reason, the message and the id', function () {
    $post = Post::factory()->published()->create(['title' => 'Free kittens, DM for price']);
    $comment = Comment::factory()->create(['body' => 'Visit pet-deals24.top']);
    $person = User::factory()->create(['username' => 'spam_pawz']);
    $onPost = reportOn($post, ['reason' => ReportReason::Spam, 'reporter_id' => User::factory()->create(['username' => 'georgi.d'])->id]);
    $onComment = reportOn($comment, ['reason' => ReportReason::WrongCategory, 'message' => 'Same link under five posts']);
    $onPerson = reportOn($person, ['reason' => ReportReason::Offensive]);

    expect(moderationReportsPage(type: 'post'))->toBe([$onPost->id])
        ->and(moderationReportsPage(type: 'comment'))->toBe([$onComment->id])
        ->and(moderationReportsPage(type: 'user'))->toBe([$onPerson->id])
        ->and(moderationReportsPage(search: 'KITTENS'))->toBe([$onPost->id])
        ->and(moderationReportsPage(search: 'deals24'))->toBe([$onComment->id])
        ->and(moderationReportsPage(search: 'spam_paw'))->toBe([$onPerson->id])
        ->and(moderationReportsPage(search: 'georgi'))->toBe([$onPost->id])
        ->and(moderationReportsPage(search: 'wrong category'))->toBe([$onComment->id])
        ->and(moderationReportsPage(search: 'five posts'))->toBe([$onComment->id])
        ->and(moderationReportsPage(search: '#'.$onPerson->id))->toBe([$onPerson->id]);
});

it('keeps a report on a post its author deleted pointing at that post', function () {
    $post = Post::factory()->published()->create();
    $report = reportOn($post);
    $post->delete();

    expect(app(ModerationReportsQuery::class)->find($report->id)?->target?->is($post))->toBeTrue();
});

it('steps to the report before or after another, from where a closed one stood', function () {
    $post = Post::factory()->published()->create();
    $at = now()->subHour()->startOfSecond();
    [$a, $b, $c] = collect(range(1, 3))->map(fn () => reportOn($post, ['created_at' => $at]))->all();
    $query = app(ModerationReportsQuery::class);

    expect($query->adjacent('open', '', null, $a, 1))->toBe($b->id)
        ->and($query->adjacent('open', '', null, $b, -1))->toBe($a->id)
        ->and($query->adjacent('open', '', null, $c, 1))->toBeNull()
        ->and($query->first('open', '', null))->toBe($a->id);

    $b->update(['status' => ReportStatus::Resolved]);

    expect($query->adjacent('open', '', null, $b->fresh(), 1))->toBe($c->id)
        // The closed lists run newest first, so “next” is the older one.
        ->and($query->adjacent('all', '', null, $c, 1))->toBe($b->id);
});

it('keeps reports that came in together in their place across pages, open or not', function (string $tab, bool $descending) {
    $post = Post::factory()->published()->create();
    $reports = collect(range(1, ModerationReportsQuery::PER_PAGE + 5))->map(fn () => reportOn($post, ['created_at' => now()->subHour()->startOfSecond()]));
    $ids = $reports->pluck('id')->sort()->values()->all();

    $first = moderationReportsPage($tab);
    $second = moderationReportsPage($tab, page: 2);

    expect(array_intersect($first, $second))->toBe([])
        ->and([...$first, ...$second])->toBe($descending ? array_reverse($ids) : $ids);
})->with([
    'open, oldest first' => ['open', false],
    'all, newest first' => ['all', true],
]);

it('counts the reports on a person\'s posts and comments', function () {
    $author = User::factory()->create();
    reportOn(Post::factory()->published()->for($author)->create());
    reportOn(Comment::factory()->for($author)->create());
    reportOn(Post::factory()->published()->create());

    expect(app(ModerationReportsQuery::class)->reportsOnContentOf($author))->toBe(2);
});
