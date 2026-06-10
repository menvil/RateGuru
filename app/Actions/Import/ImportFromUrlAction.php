<?php

namespace App\Actions\Import;

use App\Exceptions\Import\ImportFetchException;
use App\Exceptions\Import\UrlImportDisabledException;
use App\Support\Import\Adapters\DirectImageImportAdapter;
use App\Support\Import\Adapters\OpenGraphImportAdapter;
use App\Support\Import\ImportPreview;
use App\Support\Import\ImportProviderDetector;
use App\Support\Import\UrlImportValidator;
use App\Support\Settings\ProjectSettingsManager;

class ImportFromUrlAction
{
    public function __construct(
        private readonly UrlImportValidator $validator,
        private readonly ImportProviderDetector $detector,
        private readonly DirectImageImportAdapter $directImageAdapter,
        private readonly OpenGraphImportAdapter $openGraphAdapter,
        private readonly ProjectSettingsManager $settings,
    ) {}

    public function handle(string $url): ImportPreview
    {
        if (! $this->settings->featureEnabled('allow_url_imports')) {
            throw new UrlImportDisabledException;
        }

        $this->validator->validate($url);

        $provider = $this->detector->detect($url);

        if ($provider === 'direct_image') {
            return $this->directImageAdapter->preview($url);
        }

        // For open_graph and all social providers (best-effort via OG)
        try {
            $preview = $this->openGraphAdapter->preview($url);

            // Tag with the detected social provider instead of generic open_graph
            if ($provider !== 'open_graph') {
                return new ImportPreview(
                    provider: $provider,
                    sourceUrl: $preview->sourceUrl,
                    title: $preview->title,
                    description: $preview->description,
                    imageUrl: $preview->imageUrl,
                    warnings: $preview->warnings,
                );
            }

            return $preview;
        } catch (ImportFetchException $e) {
            return new ImportPreview(
                provider: $provider,
                sourceUrl: $url,
                unsupportedReason: 'This URL cannot be imported automatically. Download the image and upload it manually.',
            );
        }
    }
}
