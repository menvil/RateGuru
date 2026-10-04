<?php

use App\Enums\MediaVariantName;
use App\Models\MediaAsset;
use App\Services\Media\MediaVariantPathGenerator;

it('nests a deterministic variant path under the master path with its own extension stripped', function () {
    $asset = MediaAsset::factory()->postImage()->make([
        'path' => 'posts/2026/08/abc-123.jpg',
    ]);

    $path = (new MediaVariantPathGenerator)->generate($asset, MediaVariantName::PostFeed640, 'jpg');

    expect($path)->toBe('posts/2026/08/abc-123/post_feed_640.jpg');
});

it('produces the same path across repeated calls, minting no new identifier', function () {
    $asset = MediaAsset::factory()->avatar()->make([
        'path' => 'avatars/7/def-456.png',
    ]);

    $generator = new MediaVariantPathGenerator;

    $first = $generator->generate($asset, MediaVariantName::Avatar128, 'png');
    $second = $generator->generate($asset, MediaVariantName::Avatar128, 'png');

    expect($first)->toBe($second)
        ->and($first)->toBe('avatars/7/def-456/avatar_128.png');
});

it('produces distinct paths for distinct variant names on the same asset', function () {
    $asset = MediaAsset::factory()->postImage()->make([
        'path' => 'posts/2026/08/abc-123.jpg',
    ]);

    $generator = new MediaVariantPathGenerator;

    $feed640 = $generator->generate($asset, MediaVariantName::PostFeed640, 'jpg');
    $detail1920 = $generator->generate($asset, MediaVariantName::PostDetail1920, 'jpg');

    expect($feed640)->not->toBe($detail1920);
});
