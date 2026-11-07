<?php

use App\Enums\MediaVariantName;
use App\Models\MediaVariant;

/*
 * A factory-made variant is the file the media pipeline would have written
 * for its name: the display variants WebP, the Open Graph card a JPEG, each
 * at a size its specification allows.
 */

it('makes each variant as the registry has it made', function (MediaVariantName $name, string $mimeType, string $extension, int $width, int $height) {
    $variant = MediaVariant::factory()->named($name)->make();

    expect($variant->mime_type)->toBe($mimeType)
        ->and($variant->extension)->toBe($extension)
        ->and($variant->path)->toEndWith('.'.$extension)
        ->and([$variant->width, $variant->height])->toBe([$width, $height]);
})->with([
    'a feed picture, by default too' => [MediaVariantName::PostFeed640, 'image/webp', 'webp', 640, 480],
    'the detail picture' => [MediaVariantName::PostDetail1920, 'image/webp', 'webp', 1920, 1440],
    'the Open Graph card' => [MediaVariantName::OpenGraph, 'image/jpeg', 'jpg', 1200, 630],
    'an avatar' => [MediaVariantName::Avatar128, 'image/webp', 'webp', 128, 128],
]);

it('makes the feed picture when no name is asked for', function () {
    $variant = MediaVariant::factory()->make();

    expect($variant->name)->toBe(MediaVariantName::PostFeed640)
        ->and($variant->mime_type)->toBe('image/webp');
});
