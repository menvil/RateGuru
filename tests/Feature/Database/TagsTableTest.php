<?php

use Illuminate\Support\Facades\Schema;

it('creates tags table with required columns', function () {
    expect(Schema::hasTable('tags'))->toBeTrue();
    expect(Schema::hasColumns('tags', [
        'id',
        'name',
        'slug',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});
