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

        // Every image the page names, not only the first: a page often gives
        // an http og:image and its https twin as og:image:secure_url, and the
        // import takes only https — the first alone would leave it no image.
        $imageCandidates = array_values(array_unique(array_map(
            fn (string $candidate): string => $this->resolveUrl($candidate, $pageUrl),
            array_filter([
                $this->extractMeta($xpath, 'og:image', 'property'),
                $this->extractMeta($xpath, 'og:image:secure_url', 'property'),
                $this->extractMeta($xpath, 'twitter:image', 'name'),
            ]),
        )));

        return new OpenGraphMetadata(
            title: $title,
            description: $description,
            imageUrl: $imageCandidates[0] ?? null,
            imageCandidates: $imageCandidates,
        );
    }

    private function extractMeta(DOMXPath $xpath, string $value, string $attr): ?string
    {
        $nodes = $xpath->query("//meta[@{$attr}='{$value}']");

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $node = $nodes->item(0);

        if (! $node instanceof DOMElement) {
            return null;
        }

        // Trimmed: whitespace around a URL would otherwise be resolved as part
        // of it, against the page, into an address that is not the image.
        $content = trim($node->getAttribute('content'));

        return $content !== '' ? $content : null;
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
