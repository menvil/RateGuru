<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The foreign keys pages read through, and every delete of the row they point
 * at checks, each lead an index — PostgreSQL makes none for a foreign key.
 */

it('indexes the foreign keys that reads go through', function (string $table, array $columns) {
    // MySQL and MariaDB index each foreign key themselves, on its own column,
    // so the migration makes none there: an index led by that column is what
    // they have.
    $selfIndexing = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    $indexed = collect(Schema::getIndexes($table))
        ->contains(fn (array $index): bool => $selfIndexing ? $index['columns'][0] === $columns[0] : $index['columns'] === $columns);

    expect($indexed)->toBeTrue();
})->with([
    'a post\'s comments' => ['comments', ['post_id', 'parent_id']],
    'a comment\'s replies' => ['comments', ['parent_id']],
    'a category\'s posts' => ['posts', ['category_id']],
    'the posts with a picture' => ['posts', ['image_asset_id']],
    'the accounts with a picture' => ['users', ['avatar_asset_id']],
    'a tag\'s posts' => ['post_tag', ['tag_id']],
    'the days an account did something' => ['user_activity_days', ['user_id', 'day']],
    'the posts a visit opened' => ['traffic_visit_posts', ['visit_id', 'post_id']],
    'a source’s splits of a game’s traffic' => ['game_traffic_periods', ['source_id']],
    'a campaign’s splits of a game’s traffic' => ['game_traffic_periods', ['campaign_id']],
    'a source’s segments' => ['traffic_segments', ['source_id', 'deleted_at']],
    'the segments an administrator made' => ['traffic_segments', ['created_by_user_id']],
    'the segments an administrator changed last' => ['traffic_segments', ['updated_by_user_id']],
    'the tracking links to a game' => ['tracking_links', ['game_id']],
    'the tracking links an administrator made' => ['tracking_links', ['created_by_user_id']],
    'the tracking links an administrator changed last' => ['tracking_links', ['updated_by_user_id']],
    'where a block stands on each campaign’s list' => ['traffic_block_listings', ['block_id', 'campaign_id']],
    'the blocks on a campaign’s list' => ['traffic_block_listings', ['campaign_id']],
]);

it('drops the indexes it made on the way down, and makes them again on the way up', function () {
    $migration = require database_path('migrations/2026_11_07_130000_index_the_foreign_keys_that_reads_go_through.php');
    $made = [
        'comments_post_id_parent_id_index', 'comments_parent_id_index', 'posts_category_id_index',
        'posts_image_asset_id_index', 'users_avatar_asset_id_index', 'post_tag_tag_id_index',
    ];
    $present = fn (): array => array_values(array_intersect($made, collect(['comments', 'posts', 'users', 'post_tag'])
        ->flatMap(fn (string $table): array => array_column(Schema::getIndexes($table), 'name'))
        ->all()));

    $migration->down();
    expect($present())->toBe([])
        ->and(Schema::hasTable('comments'))->toBeTrue();

    $migration->up();
    expect($present())->toEqualCanonicalizing($made);
})->skip(
    fn (): bool => in_array(DB::getDriverName(), ['mysql', 'mariadb'], true),
    'MySQL and MariaDB index each foreign key when they make it, so the migration makes and drops nothing there.',
);
