<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates rating groups table with required columns', function () {
    expect(Schema::hasTable('rating_groups'))->toBeTrue();
    expect(Schema::hasColumns('rating_groups', [
        'id',
        'key',
        'label',
        'description',
        'min_options',
        'max_options',
        'is_active',
        'sort_order',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

it('uses rating group defaults', function () {
    $id = DB::table('rating_groups')->insertGetId([
        'key' => 'source',
        'label' => 'Source',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('rating_groups')->find($id))
        ->min_options->toBe(2)
        ->max_options->toBe(10)
        ->is_active->toBe(1)
        ->sort_order->toBe(0);
});

it('requires unique rating group keys', function () {
    DB::table('rating_groups')->insert([
        'key' => 'source',
        'label' => 'Source',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('rating_groups')->insert([
        'key' => 'source',
        'label' => 'Another Source',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects rating groups whose minimum options exceed maximum options', function () {
    expect(fn () => DB::table('rating_groups')->insert([
        'key' => 'invalid-range',
        'label' => 'Invalid range',
        'min_options' => 9,
        'max_options' => 2,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
