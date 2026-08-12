<?php

namespace App\Services\Media;

use App\Enums\MediaVariantName;
use App\Models\MediaAsset;

/**
 * Derives a deterministic variant path purely from an already-stored
 * asset's own immutable path — never mints a new UUID, so retries and
 * regenerations always land on the same key.
 */
final class MediaVariantPathGenerator
{
    public function generate(MediaAsset $asset, MediaVariantName $name, string $extension): string
    {
        $directory = preg_replace('/\.[^.\/]+$/', '', $asset->path);

        return "{$directory}/{$name->value}.{$extension}";
    }
}
