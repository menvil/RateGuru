<?php

use Illuminate\Support\Facades\Schema;

it('has rating group translation columns', function () {
    expect(Schema::hasColumns('rating_groups', [
        'label_translations',
        'description_translations',
    ]))->toBeTrue();
});
