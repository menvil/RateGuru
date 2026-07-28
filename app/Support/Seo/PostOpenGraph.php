<?php

namespace App\Support\Seo;

use App\Data\Seo\OpenGraphImageData;
use App\Models\Post;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Support\Str;

final class PostOpenGraph
{
    public function __construct(
        private readonly ProjectSettingsManager $settings,
    ) {}

    public function title(Post $post): string
    {
        return trim((string) $post->title);
    }

    public function description(Post $post): string
    {
        $description = trim(strip_tags((string) $post->description));
        $description = trim((string) preg_replace('/\s+/', ' ', $description));

        if ($description === '') {
            return __('sharing.post_fallback_description', [
                'siteName' => $this->siteName(),
            ]);
        }

        return Str::limit($description, 160, '');
    }

    public function siteName(): string
    {
        return $this->settings->current()->siteName();
    }

    public function locale(): string
    {
        return match (app()->getLocale()) {
            'ru' => 'ru_RU',
            'bg' => 'bg_BG',
            default => 'en_US',
        };
    }

    public function image(Post $post): OpenGraphImageData
    {
        $ogImagePath = trim((string) $post->og_image_path);

        if ($ogImagePath !== '') {
            return new OpenGraphImageData(
                url: $this->absoluteAsset('/storage/'.$ogImagePath),
                mimeType: (string) config('share.open_graph.mime_type', 'image/jpeg'),
                width: (int) config('share.open_graph.width', 1200),
                height: (int) config('share.open_graph.height', 630),
                alt: $this->title($post),
            );
        }

        $imageUrl = trim((string) $post->public_image_url);

        if ($imageUrl !== '') {
            return new OpenGraphImageData(
                url: $this->absoluteUrl($imageUrl),
                mimeType: $this->mimeTypeForUrl($imageUrl),
                width: null,
                height: null,
                alt: $this->title($post),
            );
        }

        return new OpenGraphImageData(
            url: $this->absoluteAsset((string) config(
                'share.open_graph.fallback_path',
                'images/og/rateguru-post-placeholder.png',
            )),
            mimeType: (string) config('share.open_graph.fallback_mime_type', 'image/png'),
            width: (int) config('share.open_graph.width', 1200),
            height: (int) config('share.open_graph.height', 630),
            alt: $this->title($post),
        );
    }

    private function absoluteUrl(string $url): string
    {
        if (Str::startsWith($url, ['http://', 'https://'])) {
            return $url;
        }

        if (Str::startsWith($url, '//')) {
            $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

            return $scheme.':'.$url;
        }

        return $this->absoluteAsset($url);
    }

    private function absoluteAsset(string $path): string
    {
        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }

    private function mimeTypeForUrl(string $url): ?string
    {
        $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => null,
        };
    }
}
