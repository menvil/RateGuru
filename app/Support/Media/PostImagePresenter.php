<?php

namespace App\Support\Media;

use App\Models\Post;
use App\Services\Media\MediaUrlResolver;

/**
 * The only thing that knows a post's image comes from imageAsset — callers
 * never touch disk/path/Storage themselves.
 */
final class PostImagePresenter
{
    public function __construct(
        private readonly MediaUrlResolver $resolver,
    ) {}

    public function url(Post $post): ?string
    {
        return $this->resolver->publicUrlOrNull($post->imageAsset);
    }
}
