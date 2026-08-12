<?php

namespace App\Support\Seo;

use App\Data\Seo\OpenGraphImageData;
use App\Models\Post;
use App\Support\Media\PostImagePresenter;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Support\Str;

final class PostOpenGraph
{
    public function __construct(
        private readonly ProjectSettingsManager $settings,
        private readonly PostImagePresenter $imagePresenter,
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

    /**
     * Fallback chain (see PostImagePresenter::openGraph()): a dedicated
     * open_graph variant (exact 1200x630, JPEG) -> post_detail_1920 ->
     * master -> the static placeholder image. A private or missing post
     * image never reaches even the master branch — openGraph() returns null
     * before any variant lookup, so this falls straight to the placeholder,
     * same as "no image at all". See docs/architecture/media.md.
     */
    public function image(Post $post): OpenGraphImageData
    {
        $resolved = $this->imagePresenter->openGraph($post);

        if ($resolved !== null) {
            return new OpenGraphImageData(
                url: $this->absoluteUrl($resolved->url),
                mimeType: $resolved->mimeType,
                width: $resolved->width,
                height: $resolved->height,
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
}
