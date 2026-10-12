<?php

use Illuminate\Support\Facades\Schema;

it('has rating option translation columns', function () {
    expect(Schema::hasColumns('rating_options', [
        'label_translations',
        'description_translations',
    ]))->toBeTrue();
});
