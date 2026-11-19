<?php

use App\Models\TrafficSegment;
use App\Models\TrafficSource;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A paid source's segments, alike on every engine: their conditions kept as
 * stored, a deleted one only marked, read by source and by the
 * administrators who made and last changed them through indexes of their own;
 * neither their source nor those administrators can be deleted under them;
 * and down() takes the table off again.
 */

function trafficSegmentsMigration(): object
{
    return require database_path('migrations/2026_11_18_101000_create_traffic_segments_table.php');
}

it('keeps a segment’s conditions as stored, a deleted one only marked', function () {
    $segment = TrafficSegment::factory()->create(['rules' => [['parameter' => 'site', 'operator' => 'out', 'values' => ['1182']]]])->fresh();

    expect($segment->rules)->toBe([['parameter' => 'site', 'operator' => 'out', 'values' => ['1182']]])
        ->and($segment->updated_by_user_id)->toBeNull()
        ->and($segment->deleted_at)->toBeNull();

    $segment->delete();

    expect(TrafficSegment::query()->count())->toBe(0)
        ->and(TrafficSegment::query()->withTrashed()->whereKey($segment->id)->value('name'))->toBe($segment->name);
});

it('reads segments by source and by the administrators who made and changed them', function () {
    $indexes = collect(Schema::getIndexes('traffic_segments'));

    expect($indexes->firstWhere('name', 'traffic_segments_source_index')['columns'])->toBe(['source_id', 'deleted_at'])
        ->and($indexes->firstWhere('name', 'traffic_segments_created_by_index')['columns'])->toBe(['created_by_user_id'])
        ->and($indexes->firstWhere('name', 'traffic_segments_updated_by_index')['columns'])->toBe(['updated_by_user_id']);
});

it('keeps the source and the administrators of a segment from being deleted under it', function () {
    $editor = User::factory()->admin()->create();
    $segment = TrafficSegment::factory()->create(['updated_by_user_id' => $editor->id]);

    expect(fn () => DB::transaction(fn () => DB::table('traffic_sources')->where('id', $segment->source_id)->delete()))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('users')->where('id', $segment->created_by_user_id)->delete()))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('users')->where('id', $editor->id)->delete()))->toThrow(QueryException::class);

    expect(TrafficSource::query()->whereKey($segment->source_id)->exists())->toBeTrue();
});

it('undoes what it did and does it again', function () {
    TrafficSegment::factory()->create();

    trafficSegmentsMigration()->down();

    expect(Schema::hasTable('traffic_segments'))->toBeFalse();

    trafficSegmentsMigration()->up();

    expect(Schema::hasTable('traffic_segments'))->toBeTrue()
        ->and(TrafficSegment::query()->withTrashed()->count())->toBe(0);
});
