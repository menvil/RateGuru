<?php

namespace App\Actions\Import;

/**
 * What a link given to the upload form comes to: a picture to fetch now, or
 * a video to download once the post exists — never both — and the post's
 * source when the link was a post on a site.
 */
final readonly class ResolvedMediaLink
{
    public function __construct(
        public ?string $imageUrl,
        public ?string $videoUrl,
        public ?string $source,
    ) {}
}
