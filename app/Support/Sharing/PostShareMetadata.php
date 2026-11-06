<?php

namespace App\Support\Sharing;

use App\Models\Post;
use App\Support\Media\PostImagePresenter;
use App\Support\Seo\PostOpenGraph;
use App\Support\Urls\PostUrl;

final class PostShareMetadata
{
    public function __construct(
        private readonly PostUrl $postUrl,
        private readonly PostOpenGraph $openGraph,
        private readonly PostImagePresenter $imagePresenter,
    ) {}

    public function forPost(Post $post): ShareMetadata
    {
        $image = $this->openGraph->image($post);
        $title = $this->openGraph->title($post);

        return new ShareMetadata(
            title: $title,
            shareText: $title,
            description: $this->openGraph->description($post),
            url: $this->postUrl->canonical($post),
            imageUrl: $image->url,
            siteName: $this->openGraph->siteName(),
            pictureUrl: $this->imagePresenter->shareUrl($post),
        );
    }
}
