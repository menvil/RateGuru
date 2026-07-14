<?php

use App\Models\Tag;

it('has a Tag model using tags table', function () {
    expect((new Tag)->getTable())->toBe('tags');
});
