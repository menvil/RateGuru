<?php

namespace App\Support\Import;

use DOMDocument;
use DOMElement;
use DOMXPath;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Throwable;

class OpenGraphParser
{
    /** As many images of a page as the import tries, in order of preference. */
    public const MOST_IMAGE_CANDIDATES = 10;

    public function parse(string $html, string $pageUrl): OpenGraphMetadata
    {
        $doc = new DOMDocument;

        @$doc->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR);

        $xpath = new DOMXPath($doc);

        $title = $this->extractMeta($xpath, 'og:title', 'property')
            ?? $this->extractMeta($xpath, 'twitter:title', 'name')
            ?? $this->extractTitle($xpath);

        $description = $this->extractMeta($xpath, 'og:description', 'property')
            ?? $this->extractMeta($xpath, 'twitter:description', 'name')
            ?? $this->extractMeta($xpath, 'description', 'name');

        // The images the page names, not only the first: a page often gives
        // an http og:image and its https twin as og:image:secure_url, or a
        // second og:image in Open Graph's array form, and the import takes only
        // https — the first alone would leave it no image. In order of
        // preference: the og:image values, then the secure ones, then Twitter's,
        // each kind in the order the page gives them.
        // Up to the first ten (MOST_IMAGE_CANDIDATES): each is checked by
        // resolving its host, and a page naming thousands would hold the import
        // on as many lookups.
        $imageCandidates = array_slice(array_values(array_unique(array_map(
            fn (string $candidate): string => $this->resolveUrl($candidate, $pageUrl),
            [
                ...$this->extractEveryMeta($xpath, 'og:image', 'property'),
                ...$this->extractEveryMeta($xpath, 'og:image:secure_url', 'property'),
                ...$this->extractEveryMeta($xpath, 'twitter:image', 'name'),
            ],
        ))), 0, self::MOST_IMAGE_CANDIDATES);

        return new OpenGraphMetadata(
            title: $title,
            description: $description,
            imageUrl: $imageCandidates[0] ?? null,
            imageCandidates: $imageCandidates,
        );
    }

    private function extractMeta(DOMXPath $xpath, string $value, string $attr): ?string
    {
        return $this->extractEveryMeta($xpath, $value, $attr)[0] ?? null;
    }

    /** @return list<string> every non-empty value of this meta tag, in the order the page gives them */
    private function extractEveryMeta(DOMXPath $xpath, string $value, string $attr): array
    {
        $nodes = $xpath->query("//meta[@{$attr}='{$value}']");
        $values = [];

        foreach ($nodes === false ? [] : $nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            // Trimmed: whitespace around a URL would otherwise be resolved as part
            // of it, against the page, into an address that is not the image.
            $content = trim($node->getAttribute('content'));

            if ($content !== '') {
                $values[] = $content;
            }
        }

        return $values;
    }

    private function extractTitle(DOMXPath $xpath): ?string
    {
        $nodes = $xpath->query('//title');

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $text = trim($nodes->item(0)->textContent);

        return $text !== '' ? $text : null;
    }

    /**
     * RFC 3986 reference resolution (not a hand-rolled dirname()-based
     * approximation) — correctly handles every relative form (../, ./,
     * bare query strings, scheme-relative //, absolute paths) the same way
     * a browser or SafeImportHttpClient's own redirect handling would.
     * og:image is only ever a candidate string here; UrlImportValidator is
     * what actually enforces safety on it, at the point it's fetched.
     */
    private function resolveUrl(string $url, string $pageUrl): string
    {
        try {
            $resolved = UriResolver::resolve(new Uri($pageUrl), new Uri($url));
        } catch (Throwable) {
            return $url;
        }

        return (string) $resolved->withFragment('');
    }
}
