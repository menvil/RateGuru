<?php

use Illuminate\Support\Facades\Schema;

it('creates comments table with required columns', function () {
    expect(Schema::hasTable('comments'))->toBeTrue();
    expect(Schema::hasColumns('comments', [
        'id',
        'post_id',
        'user_id',
        'body',
        'status',
        'reports_count',
        'created_at',
        'updated_at',
        'deleted_at',
    ]))->toBeTrue();
});
