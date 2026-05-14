<?php

use Illuminate\Support\Facades\Schema;

it('creates post_tag pivot table', function () {
    expect(Schema::hasTable('post_tag'))->toBeTrue();
    expect(Schema::hasColumns('post_tag', [
        'post_id',
        'tag_id',
    ]))->toBeTrue();
});
