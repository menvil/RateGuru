<?php

use Illuminate\Support\Facades\Schema;

it('creates categories table with taxonomy columns', function () {
    expect(Schema::hasTable('categories'))->toBeTrue()
        ->and(Schema::hasColumns('categories', [
            'id',
            'name',
            'name_translations',
            'slug',
            'sort_order',
            'is_active',
            'created_at',
            'updated_at',
        ]))->toBeTrue();
});

it('stores one optional category foreign key on posts', function () {
    expect(Schema::hasColumn('posts', 'category_id'))->toBeTrue();
});
