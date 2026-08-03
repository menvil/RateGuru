<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Services\Media\Exceptions\MediaIsNotPublicException;

/**
 * Public URL resolution only — never stores files, reads content, creates
 * variants, or touches the database beyond reading the given model's own
 * disk/path/visibility.
 */
interface MediaUrlResolver
{
    /**
     * @throws MediaIsNotPublicException if the media is not public
     */
    public function publicUrl(MediaAsset|MediaVariant $media): string;

    public function publicUrlOrNull(MediaAsset|MediaVariant|null $media): ?string;
}
