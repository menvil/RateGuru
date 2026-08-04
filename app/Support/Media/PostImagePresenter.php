<?php

namespace App\Support\Media;

use App\Models\Post;
use App\Services\Media\MediaLocation;
use App\Services\Media\MediaUrlResolver;

/**
 * The only thing that knows a post's image comes from imageAsset — callers
 * never touch disk/path/Storage themselves. Reads the asset's own
 * disk/path/visibility and hands the resolver plain data — the resolver
 * itself never touches the imageAsset relation.
 */
final class PostImagePresenter
{
    public function __construct(
        private readonly MediaUrlResolver $resolver,
    ) {}

    public function url(Post $post): ?string
    {
        $asset = $post->imageAsset;

        if ($asset === null) {
            return null;
        }

        return $this->resolver->publicUrlOrNull(new MediaLocation($asset->disk, $asset->path), $asset->visibility);
    }
}
