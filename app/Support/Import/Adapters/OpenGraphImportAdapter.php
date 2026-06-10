<?php

namespace App\Support\Import\Adapters;

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

        $metadata = $this->parser->parse($response->body(), $url);

        $warnings = [];
        $imageUrl = null;

        if ($metadata->imageUrl !== null) {
            try {
                $this->validator->validate($metadata->imageUrl);
                $imageUrl = $metadata->imageUrl;
            } catch (UnsafeImportUrlException) {
                $warnings[] = 'The image URL from this page is not safe to fetch.';
            }
        } else {
            $warnings[] = 'No image was found on this page.';
        }

        return new ImportPreview(
            provider: 'open_graph',
            sourceUrl: $url,
            title: $metadata->title,
            description: $metadata->description,
            imageUrl: $imageUrl,
            warnings: $warnings,
        );
    }
}
