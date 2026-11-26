<?php

namespace App\Services\Media;

use App\Enums\MediaType;

/**
 * An accepted video or animated GIF, stored as it arrived on the private
 * disk, with what ffprobe found in it. The asset made from it waits in
 * Processing until ProcessVideoAssetJob has made what pages play.
 */
final readonly class StoredVideo
{
    public function __construct(
        public StoredMedia $media,
        public VideoProbeResult $probe,
        public MediaType $mediaType,
    ) {}
}
