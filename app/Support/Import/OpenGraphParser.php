<?php

namespace App\Support\Import;

use DOMDocument;
use DOMXPath;

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

        $imageUrl = $this->extractMeta($xpath, 'og:image', 'property')
            ?? $this->extractMeta($xpath, 'og:image:secure_url', 'property')
            ?? $this->extractMeta($xpath, 'twitter:image', 'name');

        if ($imageUrl !== null) {
            $imageUrl = $this->resolveUrl($imageUrl, $pageUrl);
        }

        return new OpenGraphMetadata(
            title: $title,
            description: $description,
            imageUrl: $imageUrl,
        );
    }

    private function extractMeta(DOMXPath $xpath, string $value, string $attr): ?string
    {
        $nodes = $xpath->query("//meta[@{$attr}='{$value}']");

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $content = $nodes->item(0)?->getAttribute('content');

        return ($content !== null && $content !== '') ? $content : null;
    }

    private function extractTitle(DOMXPath $xpath): ?string
    {
        $nodes = $xpath->query('//title');

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $text = trim($nodes->item(0)?->textContent ?? '');

        return $text !== '' ? $text : null;
    }

    private function resolveUrl(string $url, string $pageUrl): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $parsed = parse_url($pageUrl);
        $base = ($parsed['scheme'] ?? 'https').'://'.($parsed['host'] ?? '');

        if (str_starts_with($url, '/')) {
            return $base.$url;
        }

        $path = rtrim(dirname($parsed['path'] ?? ''), '/');

        return $base.$path.'/'.$url;
    }
}
