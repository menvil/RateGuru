<?php

use Illuminate\Support\Facades\Schema;

it('creates origin_votes table with required columns', function () {
    expect(Schema::hasTable('origin_votes'))->toBeTrue();
    expect(Schema::hasColumns('origin_votes', [
        'id',
        'post_id',
        'user_id',
        'origin',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});
