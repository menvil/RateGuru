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
        'image_path',
        'image_url',
        'thumbnail_url',
        'source_url',
        'status',
        'origin_truth',
        'cuisine_truth',
        'upvotes_count',
        'downvotes_count',
        'homemade_votes_count',
        'restaurant_votes_count',
        'comments_count',
        'reports_count',
        'hot_score',
        'published_at',
        'created_at',
        'updated_at',
        'deleted_at',
    ]))->toBeTrue();
});
