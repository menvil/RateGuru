<?php

namespace App\Support\Import\Adapters;

use App\Enums\ImportProvider;
use App\Exceptions\Import\UnsafeImportUrlException;
use App\Support\Import\ImportPreview;
use App\Support\Import\OpenGraphParser;
use App\Support\Import\SafeImportHttpClient;
use App\Support\Import\UrlImportValidator;

class OpenGraphImportAdapter
{
    public function __construct(
        private readonly SafeImportHttpClient $client,
        private readonly OpenGraphParser $parser,
        private readonly UrlImportValidator $validator,
    ) {}

    public function preview(string $url): ImportPreview
    {
        $response = $this->client->get($url);

        $metadata = $this->parser->parse($response->body, $response->finalUrl);

        // Its own page's picture only. A page whose tags say they describe the
        // site's home page while the link is to a page within it — an app
        // that loads its post in the browser, a post shown to a signed-in
        // visitor alone — gives the site's own picture, a logo, never the
        // post's. (A page that answers an error is refused by the client.)
        if (self::describesHomeInstead($metadata->canonicalUrl, $response->finalUrl)) {
            return new ImportPreview(
                provider: ImportProvider::OpenGraph,
                sourceUrl: $url,
                title: $metadata->title,
                description: $metadata->description,
                unsupportedReason: 'The page did not show its own picture: it may be gone, or shown only to a signed-in visitor.',
            );
        }

        $imageUrl = null;

        // The first the import may fetch; an unsafe one is passed over
        // silently, and with none the preview shows without an image. Every
        // candidate's host is looked up, a lookup takes no timeout from the
        // HTTP client, and a page can name ten hosts slow to fail: so they
        // share one budget, and past it the preview goes without an image.
        $deadline = now()->addSeconds((int) config('import.timeout_seconds'));

        foreach ($metadata->imageCandidates as $candidate) {
            if (now()->greaterThan($deadline)) {
                break;
            }

            try {
                $this->validator->validate($candidate);
                $imageUrl = $candidate;

                break;
            } catch (UnsafeImportUrlException) {
                continue;
            }
        }

        return new ImportPreview(
            provider: ImportProvider::OpenGraph,
            sourceUrl: $url,
            title: $metadata->title,
            description: $metadata->description,
            imageUrl: $imageUrl,
        );
    }

    /** Whether og:url names the site's home page while the page fetched is one within it. */
    private static function describesHomeInstead(?string $canonical, string $page): bool
    {
        if ($canonical === null) {
            return false;
        }

        $path = fn (string $url): string => rtrim((string) parse_url($url, PHP_URL_PATH), '/');

        return strtolower((string) parse_url($canonical, PHP_URL_HOST)) === strtolower((string) parse_url($page, PHP_URL_HOST))
            && $path($canonical) === '' && $path($page) !== '';
    }
}
