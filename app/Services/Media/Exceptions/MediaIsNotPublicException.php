<?php

namespace App\Services\Media\Exceptions;

use App\Models\MediaAsset;
use App\Models\MediaVariant;
use RuntimeException;

final class MediaIsNotPublicException extends RuntimeException
{
    public static function forMedia(MediaAsset|MediaVariant $media): self
    {
        $identity = $media instanceof MediaAsset
            ? "media_assets.{$media->id}"
            : "media_variants.{$media->id}";

        return new self("Cannot resolve a public URL for private media [{$identity}].");
    }
}
