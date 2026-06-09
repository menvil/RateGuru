<?php

namespace App\Support\Sharing;

use App\Models\Post;
use App\Support\Seo\PostOpenGraph;
use App\Support\Urls\PostUrl;

final class PostShareMetadata
{
    public function __construct(
        private readonly PostUrl $postUrl,
        private readonly PostOpenGraph $openGraph,
    ) {}

    public function forPost(Post $post): ShareMetadata
    {
        $imageUrl = $this->resolveImageUrl($post);

        return new ShareMetadata(
            title: $this->openGraph->title($post),
            description: $this->openGraph->description($post),
            url: $this->postUrl->canonical($post),
            imageUrl: $imageUrl,
            siteName: config('app.name', 'RateGuru'),
        );
    }

    private function resolveImageUrl(Post $post): ?string
    {
        $url = trim((string) $post->public_image_url);

        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
    }
}
