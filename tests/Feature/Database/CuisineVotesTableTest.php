<?php

use Illuminate\Support\Facades\Schema;

it('creates cuisine_votes table with required columns', function () {
    expect(Schema::hasTable('cuisine_votes'))->toBeTrue();
    expect(Schema::hasColumns('cuisine_votes', [
        'id',
        'post_id',
        'user_id',
        'cuisine',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});
