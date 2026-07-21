<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('uses MariaDB-safe names for post author answer indexes', function () {
    $indexes = collect(Schema::getIndexes('post_author_answers'));

    expect($indexes->pluck('name'))
        ->toContain('paa_group_option_post_idx')
        ->and($indexes->pluck('name')->max(fn (string $name): int => strlen($name)))
        ->toBeLessThanOrEqual(64);
});
