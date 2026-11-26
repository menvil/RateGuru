<?php

namespace App\Support\Import\Adapters;

use App\Enums\ImportProvider;
use App\Exceptions\Import\ImportFetchException;
use App\Exceptions\Import\UnsafeImportUrlException;
use App\Support\Import\ImportPreview;
use App\Support\Import\SafeImportHttpClient;
use App\Support\Import\UrlImportValidator;

/**
 * The picture behind a link to a post on X (or Twitter).
 *
 * The post page itself is a poor source: whether it carries og:image at all
 * depends on the headers of the request and on who asks, and a post without
 * a picture offers its author's avatar there instead. The JSON the embed
 * widget renders a post from names the post's own media and nothing else,
 * so it is read first. When it cannot be had, the post page's Open Graph
 * tags are still tried, as for any other page.
 */
class XPostImportAdapter
{
    private const SYNDICATION_ENDPOINT = 'https://cdn.syndication.twimg.com/tweet-result';

    public function __construct(
        private readonly SafeImportHttpClient $client,
        private readonly UrlImportValidator $validator,
        private readonly OpenGraphImportAdapter $openGraph,
    ) {}

    public function preview(string $url): ImportPreview
    {
        $postId = $this->postId($url);

        if ($postId === null) {
            return $this->pagePreview($url);
        }

        try {
            $response = $this->client->get(self::SYNDICATION_ENDPOINT.'?'.http_build_query([
                'id' => $postId,
                'lang' => 'en',
                'token' => $this->syndicationToken($postId),
            ]));
        } catch (ImportFetchException|UnsafeImportUrlException) {
            return $this->pagePreview($url);
        }

        $post = json_decode($response->body, true);

        if (! is_array($post) || ! isset($post['user']) || ! is_array($post['user'])) {
            return $this->pagePreview($url);
        }

        return new ImportPreview(
            provider: ImportProvider::X,
            sourceUrl: $url,
            title: $this->title($post['user']),
            description: $this->text($post),
            imageUrl: $this->imageUrl($post),
            videoUrl: $this->videoUrl($post),
        );
    }

    /**
     * The page's own Open Graph tags, except that an avatar is not taken for
     * a picture: X offers one in og:image for a profile, and for a post that
     * has no picture of its own.
     */
    private function pagePreview(string $url): ImportPreview
    {
        $preview = $this->openGraph->preview($url);

        if (! str_starts_with((string) $preview->imageUrl, 'https://pbs.twimg.com/profile_images/')) {
            return $preview;
        }

        return new ImportPreview(
            provider: $preview->provider,
            sourceUrl: $preview->sourceUrl,
            title: $preview->title,
            description: $preview->description,
            warnings: $preview->warnings,
            unsupportedReason: $preview->unsupportedReason,
        );
    }

    /**
     * The numeric post id from any of the forms a post link takes:
     * /{user}/status/{id}, /i/status/{id}, /i/web/status/{id}, with or
     * without a trailing /photo/1 or query string.
     */
    private function postId(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return preg_match('#/status(?:es)?/(\d{1,25})(?:/|$)#', $path, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * The widget derives this from the post id as
     * ((id / 1e15) * PI).toString(36) with every "0" and the point removed.
     * The endpoint accepts any non-empty token today; deriving it the same
     * way keeps working if it starts checking.
     */
    private function syndicationToken(string $postId): string
    {
        $value = ((float) $postId / 1e15) * M_PI;
        $integer = (int) floor($value);
        $fraction = $value - $integer;
        $digits = base_convert((string) $integer, 10, 36).'.';

        for ($i = 0; $i < 12 && $fraction > 0; $i++) {
            $fraction *= 36;
            $digit = (int) floor($fraction);
            $digits .= base_convert((string) $digit, 10, 36);
            $fraction -= $digit;
        }

        return (string) preg_replace('/0+|\./', '', $digits);
    }

    /** @param array<string, mixed> $user */
    private function title(array $user): ?string
    {
        $name = trim((string) ($user['name'] ?? ''));
        $handle = trim((string) ($user['screen_name'] ?? ''));

        return match (true) {
            $name !== '' && $handle !== '' => "{$name} (@{$handle}) on X",
            $handle !== '' => "@{$handle} on X",
            default => null,
        };
    }

    /**
     * The post's text without the links X appends to it for its own media,
     * which point back at the post rather than saying anything.
     *
     * @param  array<string, mixed>  $post
     */
    private function text(array $post): ?string
    {
        $text = (string) ($post['text'] ?? '');

        $ranges = collect($post['mediaDetails'] ?? [])
            ->map(fn ($media): mixed => is_array($media) ? ($media['indices'] ?? null) : null)
            ->filter(fn ($indices): bool => is_array($indices) && count($indices) === 2)
            ->sortByDesc(fn (array $indices): int => (int) $indices[0]);

        foreach ($ranges as [$start, $end]) {
            $text = mb_substr($text, 0, (int) $start).mb_substr($text, (int) $end);
        }

        $text = trim($text);

        return $text !== '' ? $text : null;
    }

    /**
     * The post's first picture, at X's "large" size (at most 2048 pixels on
     * a side). A video or GIF contributes its poster frame. A post without
     * media has no picture, which is what it is given.
     *
     * @param  array<string, mixed>  $post
     */
    private function imageUrl(array $post): ?string
    {
        $media = collect($post['mediaDetails'] ?? [])->filter(fn ($item): bool => is_array($item));
        $chosen = $media->firstWhere('type', 'photo') ?? $media->first();
        $url = is_array($chosen) ? ($chosen['media_url_https'] ?? null) : null;

        if (! is_string($url) || $url === '') {
            return null;
        }

        if (preg_match('#^https://pbs\.twimg\.com/media/([A-Za-z0-9_-]+)\.(jpg|jpeg|png|webp)$#', $url, $matches) === 1) {
            $url = "https://pbs.twimg.com/media/{$matches[1]}?format={$matches[2]}&name=large";
        }

        try {
            $this->validator->validate($url);
        } catch (UnsafeImportUrlException) {
            return null;
        }

        return $url;
    }

    /**
     * The video or animated GIF a post leads with — its first media item —
     * as the MP4 X serves for it: the highest bitrate no larger than 1080 on
     * its short side (the size a master is kept at), else the smallest. A
     * post that leads with a photo has none.
     *
     * @param  array<string, mixed>  $post
     */
    private function videoUrl(array $post): ?string
    {
        $first = collect($post['mediaDetails'] ?? [])->first(fn ($item): bool => is_array($item));

        if (! is_array($first) || ! in_array($first['type'] ?? null, ['video', 'animated_gif'], true)) {
            return null;
        }

        $variants = collect(is_array($first['video_info']['variants'] ?? null) ? $first['video_info']['variants'] : [])
            ->filter(fn ($variant): bool => is_array($variant)
                && ($variant['content_type'] ?? null) === 'video/mp4'
                && is_string($variant['url'] ?? null)
                && str_starts_with($variant['url'], 'https://'))
            ->map(function (array $variant): array {
                $shortSide = preg_match('#/(\d+)x(\d+)/#', $variant['url'], $size) === 1 ? min((int) $size[1], (int) $size[2]) : null;

                return ['url' => $variant['url'], 'bitrate' => (int) ($variant['bitrate'] ?? 0), 'short' => $shortSide];
            });

        $chosen = $variants->filter(fn (array $variant): bool => $variant['short'] === null || $variant['short'] <= 1080)->sortByDesc('bitrate')->first()
            ?? $variants->sortBy('bitrate')->first();

        if ($chosen === null) {
            return null;
        }

        try {
            $this->validator->validate($chosen['url']);
        } catch (UnsafeImportUrlException) {
            return null;
        }

        return $chosen['url'];
    }
}
