<?php

namespace App\Support\Seo;

use App\Models\Post;
use Illuminate\Support\Str;

final class PostOpenGraph
{
    public function title(Post $post): string
    {
        return trim((string) $post->title).' · RateGuru';
    }

    public function image(Post $post): string
    {
        $imageUrl = trim((string) $post->image_url);

        if ($imageUrl !== '') {
            if (Str::startsWith($imageUrl, ['http://', 'https://'])) {
                return $imageUrl;
            }

            return $this->absoluteAsset($imageUrl);
        }

        return $this->absoluteAsset('images/og/rateguru-post-placeholder.svg');
    }

    private function absoluteAsset(string $path): string
    {
        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }
}
