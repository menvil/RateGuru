<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The foreign keys pages read through, and every delete of the row they point
 * at checks, each lead an index — PostgreSQL makes none for a foreign key.
 */

it('indexes the foreign keys that reads go through', function (string $table, array $columns) {
    $indexed = collect(Schema::getIndexes($table))
        ->contains(fn (array $index): bool => $index['columns'] === $columns);

    expect($indexed)->toBeTrue();
})->with([
    'a post\'s comments' => ['comments', ['post_id', 'parent_id']],
    'a comment\'s replies' => ['comments', ['parent_id']],
    'a category\'s posts' => ['posts', ['category_id']],
    'the posts with a picture' => ['posts', ['image_asset_id']],
    'the accounts with a picture' => ['users', ['avatar_asset_id']],
    'a tag\'s posts' => ['post_tag', ['tag_id']],
])->skip(
    fn (): bool => in_array(DB::getDriverName(), ['mysql', 'mariadb'], true),
    'MySQL and MariaDB index each foreign key when they make it, so the migration leaves them as they are.',
);
