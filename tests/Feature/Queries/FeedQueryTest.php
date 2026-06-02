<?php

use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use App\Queries\Feed\FeedQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

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
        'title' => 'Homemade Carbonara',
        'description' => 'Dinner',
    ]);

    Post::factory()->published()->create([
        'title' => 'Chocolate Cake',
        'description' => 'Dessert',
    ]);

    Post::factory()->hidden()->create([
        'title' => 'Hidden Carbonara',
    ]);

    $posts = app(FeedQuery::class)->get(search: 'carbonara');

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

it('paginates feed posts', function () {
    Post::factory()->published()->count(25)->create();
    Post::factory()->pending()->count(5)->create();

    $paginator = app(FeedQuery::class)->paginate(perPage: 10);

    expect($paginator)->toBeInstanceOf(LengthAwarePaginator::class);
    expect($paginator->items())->toHaveCount(10);
    expect($paginator->total())->toBe(25);
});

it('clamps perPage to minimum of 1 when 0 is given', function () {
    Post::factory()->published()->count(25)->create();

    $paginator = app(FeedQuery::class)->paginate(perPage: 0);

    expect($paginator->perPage())->toBe(1);
    expect($paginator->total())->toBe(25);
});

it('clamps perPage to maximum of 50 when 100 is given', function () {
    Post::factory()->published()->count(25)->create();

    $paginator = app(FeedQuery::class)->paginate(perPage: 100);

    expect($paginator->perPage())->toBe(50);
    expect($paginator->total())->toBe(25);
});
