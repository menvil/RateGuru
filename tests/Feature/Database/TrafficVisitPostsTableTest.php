<?php

use App\Models\Post;
use App\Models\TrafficVisit;
use App\Models\TrafficVisitPost;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A visit keeps the posts it opened and shared, one row a post, and the new
 * votes, comments and posts a member made on it, alike on every engine: the
 * rows go with their visit, hold their post by id only, are read by post and
 * by age through indexes of their own, and down() takes all of it off again.
 */

function visitPostsMigration(): object
{
    return require database_path('migrations/2026_11_18_100600_add_post_views_and_actions_to_traffic_visits.php');
}

it('keeps one row a visit and post, gone with its visit, its post held by id only', function () {
    $visit = TrafficVisit::factory()->create();
    $post = Post::factory()->published()->create();
    TrafficVisitPost::query()->forceCreate(['visit_id' => $visit->id, 'post_id' => $post->id, 'first_at' => now()]);
    $row = TrafficVisitPost::query()->sole();

    expect($row)->views->toBe(0)->voted->toBeFalse()->commented->toBeFalse()->shared_at->toBeNull()
        ->and(fn () => DB::transaction(fn () => TrafficVisitPost::query()->forceCreate(['visit_id' => $visit->id, 'post_id' => $post->id, 'first_at' => now()])))->toThrow(QueryException::class);

    // A post deleted for good leaves the row of a visit that opened it.
    $post->forceDelete();
    expect(TrafficVisitPost::query()->count())->toBe(1);

    $visit->delete();
    expect(TrafficVisitPost::query()->exists())->toBeFalse();
});

it('counts a visit’s new votes, comments and posts from nothing, and reads the rows by post and by age', function () {
    $visit = TrafficVisit::factory()->create()->fresh();
    $indexes = collect(Schema::getIndexes('traffic_visit_posts'));

    expect($visit->only(['votes', 'comments', 'posts_created']))->toBe(['votes' => 0, 'comments' => 0, 'posts_created' => 0])
        ->and($indexes->firstWhere('primary', true)['columns'])->toBe(['visit_id', 'post_id'])
        ->and($indexes->firstWhere('name', 'traffic_visit_posts_post_index')['columns'])->toBe(['post_id', 'visit_id'])
        ->and($indexes->firstWhere('name', 'traffic_visit_posts_first_index')['columns'])->toBe(['first_at']);
});

it('undoes what it did and does it again, the visits staying', function () {
    $visit = TrafficVisit::factory()->create(['votes' => 3]);

    visitPostsMigration()->down();

    expect(Schema::hasTable('traffic_visit_posts'))->toBeFalse()
        ->and(collect(['votes', 'comments', 'posts_created'])->filter(fn (string $column): bool => Schema::hasColumn('traffic_visits', $column))->all())->toBe([])
        ->and(TrafficVisit::query()->whereKey($visit->id)->exists())->toBeTrue();

    visitPostsMigration()->up();

    expect(Schema::hasTable('traffic_visit_posts'))->toBeTrue()
        ->and(TrafficVisit::query()->whereKey($visit->id)->value('votes'))->toBe(0);
});
