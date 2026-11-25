<?php

use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use App\Queries\Feed\FeedLanguageOrder;
use App\Queries\Feed\FeedQuery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

it('returns only published posts', function () {
    $published = Post::factory()->published()->create();
    Post::factory()->pending()->create();
    Post::factory()->hidden()->create();
    Post::factory()->rejected()->create();

    $posts = app(FeedQuery::class)->get();

    expect($posts->pluck('id')->all())->toBe([$published->id]);
});

it('loads authors for feed posts', function () {
    $author = User::factory()->create();

    Post::factory()
        ->for($author, 'user')
        ->published()
        ->create();

    $posts = app(FeedQuery::class)->paginate();

    $first = $posts->items()[0];

    expect($first->relationLoaded('user'))->toBeTrue();
});

it('loads tags for feed posts', function () {
    $tag = Tag::factory()->create();

    $post = Post::factory()->published()->create();
    $post->tags()->attach($tag);

    $posts = app(FeedQuery::class)->paginate();

    $first = $posts->items()[0];

    expect($first->relationLoaded('tags'))->toBeTrue();
});

it('sorts published posts by newest', function () {
    $old = Post::factory()->published()->create([
        'published_at' => now()->subDay(),
    ]);

    $new = Post::factory()->published()->create([
        'published_at' => now(),
    ]);

    $posts = app(FeedQuery::class)->get(sort: 'newest');

    expect($posts->pluck('id')->all())->toBe([$new->id, $old->id]);
});

it('sorts published posts by top score', function () {
    // $high is created first (older) so newest sort would put $low first — top sort must override this
    $high = Post::factory()->published()->create([
        'upvotes_count' => 10,
        'downvotes_count' => 2,
        'published_at' => now()->subDay(),
    ]);

    $low = Post::factory()->published()->create([
        'upvotes_count' => 3,
        'downvotes_count' => 1,
        'published_at' => now(),
    ]);

    $posts = app(FeedQuery::class)->get(sort: 'top');

    expect($posts->pluck('id')->all())->toBe([$high->id, $low->id]);
});

it('sorts published posts by hot score', function () {
    // $hot is older — newest sort would return $cold first, hot sort must override
    $hot = Post::factory()->published()->create([
        'hot_score' => 10,
        'published_at' => now()->subDay(),
    ]);

    $cold = Post::factory()->published()->create([
        'hot_score' => 1,
        'published_at' => now(),
    ]);

    $posts = app(FeedQuery::class)->get(sort: 'hot');

    expect($posts->pluck('id')->all())->toBe([$hot->id, $cold->id]);
});

it('filters published posts by tag slug', function () {
    $pasta = Tag::factory()->create(['slug' => 'pasta']);
    $dessert = Tag::factory()->create(['slug' => 'dessert']);

    $matching = Post::factory()->published()->create();
    $matching->tags()->attach($pasta);

    $other = Post::factory()->published()->create();
    $other->tags()->attach($dessert);

    $hiddenMatching = Post::factory()->hidden()->create();
    $hiddenMatching->tags()->attach($pasta);

    $posts = app(FeedQuery::class)->get(tag: 'pasta');

    expect($posts->pluck('id')->all())->toBe([$matching->id]);
});

it('searches published posts by title', function () {
    $matching = Post::factory()->published()->create([
        'title' => 'Sample Post',
        'description' => 'Dinner',
    ]);

    Post::factory()->published()->create([
        'title' => 'Chocolate Cake',
        'description' => 'Dessert',
    ]);

    Post::factory()->hidden()->create([
        'title' => 'Hidden Carbonara',
    ]);

    $posts = app(FeedQuery::class)->get(search: 'sample');

    expect($posts->pluck('id')->all())->toBe([$matching->id]);
});

it('searches published posts by description', function () {
    $matching = Post::factory()->published()->create([
        'title' => 'Dinner',
        'description' => 'Fresh basil and tomato sauce',
    ]);

    Post::factory()->published()->create([
        'title' => 'Breakfast',
        'description' => 'Eggs and toast',
    ]);

    Post::factory()->hidden()->create([
        'title' => 'Hidden',
        'description' => 'Fresh basil hidden post',
    ]);

    $posts = app(FeedQuery::class)->get(search: 'basil');

    expect($posts->pluck('id')->all())->toBe([$matching->id]);
});

it('treats like wildcards as literal feed search characters', function (string $search, string $matchingTitle, string $otherTitle) {
    $matching = Post::factory()->published()->create(['title' => $matchingTitle]);
    Post::factory()->published()->create(['title' => $otherTitle]);

    $posts = app(FeedQuery::class)->get(search: $search);

    expect($posts->pluck('id')->all())->toBe([$matching->id]);
})->with([
    'percent' => ['%', 'Save 100%', 'Save 1000'],
    'underscore' => ['_', 'Dish_A', 'DishXA'],
]);

it('uses post id as the final deterministic feed order', function (string $sort) {
    $timestamp = now()->startOfSecond();
    $posts = Post::factory()->published()->count(3)->create([
        'published_at' => $timestamp,
        'created_at' => $timestamp,
        'upvotes_count' => 5,
        'downvotes_count' => 1,
        'hot_score' => 10,
    ]);

    $result = app(FeedQuery::class)->get(sort: $sort);

    expect($result->pluck('id')->all())->toBe($posts->pluck('id')->reverse()->values()->all());
})->with(['newest', 'top', 'hot']);

it('paginates feed posts', function () {
    Post::factory()->published()->count(25)->create();
    Post::factory()->pending()->count(5)->create();

    $paginator = app(FeedQuery::class)->paginate(perPage: 10);

    expect($paginator)->toBeInstanceOf(LengthAwarePaginator::class);
    expect($paginator->items())->toHaveCount(10);
    expect($paginator->total())->toBe(25);
});

it('keeps equal-ranked feed records stable across pages', function () {
    $timestamp = now()->startOfSecond();
    $posts = Post::factory()->published()->count(7)->create([
        'published_at' => $timestamp,
        'created_at' => $timestamp,
        'upvotes_count' => 5,
        'downvotes_count' => 1,
        'hot_score' => 10,
    ]);

    Paginator::currentPageResolver(static fn (): int => 1);
    $first = app(FeedQuery::class)->paginate(sort: 'top', perPage: 3)->pluck('id');
    Paginator::currentPageResolver(static fn (): int => 2);
    $second = app(FeedQuery::class)->paginate(sort: 'top', perPage: 3)->pluck('id');
    Paginator::currentPageResolver(static fn (): int => 3);
    $third = app(FeedQuery::class)->paginate(sort: 'top', perPage: 3)->pluck('id');
    Paginator::currentPageResolver(static fn (): int => 1);

    expect($first->intersect($second))->toBeEmpty()
        ->and($first->merge($second)->merge($third)->all())
        ->toBe($posts->pluck('id')->reverse()->values()->all());
});

/*
 * The viewer's language first: fresh posts in it and the viewer's own posts, then fresh English ones, then the
 * rest — each group in the sort's own order.
 */

function feedPost(?string $language, int $hoursAgo, array $attributes = []): Post
{
    return Post::factory()->published()->create([
        'language' => $language,
        'published_at' => now()->subHours($hoursAgo),
        'created_at' => now()->subHours($hoursAgo),
        ...$attributes,
    ]);
}

function feedOrder(string $sort, FeedLanguageOrder $languages): array
{
    return app(FeedQuery::class)->query(sort: $sort, languages: $languages)->pluck('id')->all();
}

it('puts fresh posts in the viewer\'s language first, fresh English next, the rest after, newest first in each', function () {
    $oldRussian = feedPost('ru', 24 * 9);
    $english = feedPost('en', 1);
    $german = feedPost('de', 2);
    $russian = feedPost('ru', 30);
    $newerRussian = feedPost('ru', 5);
    $oldEnglish = feedPost('en', 24 * 10);
    $unknown = feedPost(null, 3);

    $order = feedOrder('newest', new FeedLanguageOrder('ru', null, CarbonImmutable::now()->subDays(7)));

    expect($order)->toBe([$newerRussian->id, $russian->id, $english->id, $german->id, $unknown->id, $oldRussian->id, $oldEnglish->id]);
});

it('puts the viewer\'s own post first whatever language it was read as', function () {
    $viewer = User::factory()->create();
    $russian = feedPost('ru', 2);
    $own = feedPost('de', 5, ['user_id' => $viewer->id]);

    expect(feedOrder('newest', new FeedLanguageOrder('ru', $viewer->id, CarbonImmutable::now()->subDays(7))))->toBe([$russian->id, $own->id]);
});

it('ranks Top by language over all time, by votes within each group', function () {
    $oldRussian = feedPost('ru', 24 * 60, ['upvotes_count' => 2]);
    $popularEnglish = feedPost('en', 1, ['upvotes_count' => 90]);
    $russian = feedPost('ru', 3, ['upvotes_count' => 5]);
    $german = feedPost('de', 3, ['upvotes_count' => 200]);

    expect(feedOrder('top', new FeedLanguageOrder('ru', null, null)))
        ->toBe([$russian->id, $oldRussian->id, $popularEnglish->id, $german->id]);
});

it('lifts a post in Hot only from the floor up', function () {
    $weakRussian = feedPost('ru', 2, ['hot_score' => 0.1]);
    $strongRussian = feedPost('ru', 2, ['hot_score' => 0.9]);
    $english = feedPost('en', 2, ['hot_score' => 0.5]);

    expect(feedOrder('hot', new FeedLanguageOrder('ru', null, CarbonImmutable::now()->subDays(7), hotFloor: 0.3)))
        ->toBe([$strongRussian->id, $english->id, $weakRussian->id]);
});

it('gives an English viewer English first, the rest after', function () {
    $german = feedPost('de', 1);
    $english = feedPost('en', 5);

    expect(feedOrder('newest', new FeedLanguageOrder('en', null, CarbonImmutable::now()->subDays(7))))->toBe([$english->id, $german->id]);
});

it('reads the hot score a post has to reach at a percentile of the last days\' posts', function () {
    foreach ([0.1, 0.2, 0.3, 0.4, 0.5] as $score) {
        feedPost('en', 3, ['hot_score' => $score]);
    }
    feedPost('en', 24 * 5, ['hot_score' => 9.0]);

    expect(app(FeedQuery::class)->hotScoreAt(50, CarbonImmutable::now()->subDays(2)))->toBe(0.3)
        ->and(app(FeedQuery::class)->hotScoreAt(0, CarbonImmutable::now()->subDays(2)))->toBe(0.1)
        ->and(app(FeedQuery::class)->hotScoreAt(50, CarbonImmutable::now()->addDay()))->toBeNull();
});
