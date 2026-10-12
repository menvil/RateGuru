<?php

namespace App\Support\Import\Adapters;

use App\Exceptions\Import\ImportFetchException;
use App\Exceptions\Import\UnsafeImportUrlException;
use App\Support\Import\ImportPreview;
use App\Support\Import\SafeImportHttpClient;
use App\Support\Import\UrlImportValidator;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * The picture behind a link to an Instagram post.
 *
 * The post page's Open Graph tags give its caption and author, but their
 * og:image is a small square crop of the picture (351x350 is typical). The
 * embed page Instagram serves for the same post carries the picture itself,
 * in every size it keeps, so the largest of those replaces the crop. Should
 * the embed page be unavailable or change shape, the crop is still better
 * than nothing and stays.
 */
class InstagramPostImportAdapter
{
    public function __construct(
        private readonly SafeImportHttpClient $client,
        private readonly UrlImportValidator $validator,
        private readonly OpenGraphImportAdapter $openGraph,
    ) {}

    public function preview(string $url): ImportPreview
    {
        $preview = $this->openGraph->preview($url);
        $shortcode = $this->shortcode($url);

        if ($shortcode === null) {
            return $preview;
        }

        $imageUrl = $this->embeddedImageUrl($shortcode);

        if ($imageUrl === null) {
            return $preview;
        }

        return new ImportPreview(
            provider: $preview->provider,
            sourceUrl: $preview->sourceUrl,
            title: $preview->title,
            description: $preview->description,
            imageUrl: $imageUrl,
            warnings: $preview->warnings,
            unsupportedReason: $preview->unsupportedReason,
        );
    }

    /**
     * The post's shortcode from /p/{code}, /reel/{code}, /reels/{code} or
     * /tv/{code}, with or without the author's name in front.
     */
    private function shortcode(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return preg_match('#^/(?:[A-Za-z0-9_.]+/)?(?:p|reels?|tv)/([A-Za-z0-9_-]{5,64})(?:/|$)#', $path, $matches) === 1
            ? $matches[1]
            : null;
    }

    private function embeddedImageUrl(string $shortcode): ?string
    {
        try {
            $response = $this->client->get("https://www.instagram.com/p/{$shortcode}/embed/captioned/");
        } catch (ImportFetchException|UnsafeImportUrlException) {
            return null;
        }

        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8"?>'.$response->body, LIBXML_NOERROR);

        $images = (new DOMXPath($document))
            ->query("//img[contains(concat(' ', normalize-space(@class), ' '), ' EmbeddedMediaImage ')]");
        $image = $images === false ? null : $images->item(0);

        if (! $image instanceof DOMElement) {
            return null;
        }

        $url = $this->largestCandidate($image->getAttribute('srcset')) ?? $image->getAttribute('src');

        if ($url === '') {
            return null;
        }

        try {
            $this->validator->validate($url);
        } catch (UnsafeImportUrlException) {
            return null;
        }

        return $url;
    }

    /**
     * The widest entry of a srcset ("url 1080w, url 640w, ..."). The URLs
     * carry no commas of their own, but they do carry everything else, so
     * entries are told apart by where the next URL starts.
     */
    private function largestCandidate(string $srcset): ?string
    {
        $best = null;
        $bestWidth = 0;

        foreach (preg_split('/,\s*(?=https?:\/\/)/', trim($srcset)) ?: [] as $candidate) {
            if (preg_match('/^(\S+)\s+(\d+)w$/', trim($candidate), $matches) !== 1) {
                continue;
            }

            if ((int) $matches[2] > $bestWidth) {
                $bestWidth = (int) $matches[2];
                $best = $matches[1];
            }
        }

        return $best;
    }
}
