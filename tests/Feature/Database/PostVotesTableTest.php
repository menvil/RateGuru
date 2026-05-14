<?php

use Illuminate\Support\Facades\Schema;

it('creates post_votes table with required columns', function () {
    expect(Schema::hasTable('post_votes'))->toBeTrue();
    expect(Schema::hasColumns('post_votes', [
        'id',
        'post_id',
        'user_id',
        'type',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});
