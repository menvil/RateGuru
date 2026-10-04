<?php

use Illuminate\Support\Facades\Schema;

it('posts table exists', function () {
    expect(Schema::hasTable('posts'))->toBeTrue();
});

it('creates posts table with required columns', function () {
    expect(Schema::hasTable('posts'))->toBeTrue();
    expect(Schema::hasColumns('posts', [
        'id',
        'user_id',
        'title',
        'description',
        'image_asset_id',
        'source_url',
        'status',
        'upvotes_count',
        'downvotes_count',
        'comments_count',
        'reports_count',
        'hot_score',
        'published_at',
        'created_at',
        'updated_at',
        'deleted_at',
        'category_id',
    ]))->toBeTrue();

    expect(Schema::hasColumn('posts', 'category_option_id'))->toBeFalse();
});

it('does not have the legacy image columns', function () {
    expect(Schema::hasColumn('posts', 'image_path'))->toBeFalse();
    expect(Schema::hasColumn('posts', 'image_url'))->toBeFalse();
    expect(Schema::hasColumn('posts', 'thumbnail_url'))->toBeFalse();
    expect(Schema::hasColumn('posts', 'og_image_path'))->toBeFalse();
});
