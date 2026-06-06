<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates rating options table with required columns', function () {
    expect(Schema::hasTable('rating_options'))->toBeTrue();
    expect(Schema::hasColumns('rating_options', [
        'id',
        'rating_group_id',
        'key',
        'label',
        'description',
        'is_active',
        'sort_order',
        'archived_at',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

it('uses rating option defaults', function () {
    $groupId = createRatingGroup();

    $optionId = DB::table('rating_options')->insertGetId([
        'rating_group_id' => $groupId,
        'key' => 'source_a',
        'label' => 'Source A',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('rating_options')->find($optionId))
        ->is_active->toBe(1)
        ->sort_order->toBe(0)
        ->archived_at->toBeNull();
});

it('requires unique rating option keys within a group', function () {
    $groupId = createRatingGroup();
    $otherGroupId = createRatingGroup('category');

    insertRatingOption($groupId, 'option_a');
    insertRatingOption($otherGroupId, 'option_a');

    expect(fn () => insertRatingOption($groupId, 'option_a'))
        ->toThrow(QueryException::class);
});

it('deletes rating options when their group is deleted', function () {
    $groupId = createRatingGroup();
    insertRatingOption($groupId, 'option_a');

    DB::table('rating_groups')->where('id', $groupId)->delete();

    expect(DB::table('rating_options')->where('rating_group_id', $groupId)->count())
        ->toBe(0);
});

function createRatingGroup(string $key = 'source'): int
{
    return DB::table('rating_groups')->insertGetId([
        'key' => $key,
        'label' => ucfirst($key),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function insertRatingOption(int $groupId, string $key): void
{
    DB::table('rating_options')->insert([
        'rating_group_id' => $groupId,
        'key' => $key,
        'label' => ucfirst(str_replace('_', ' ', $key)),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
