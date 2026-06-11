<?php

namespace App\Actions\Import;

use App\Enums\ImportProvider;
use App\Exceptions\Import\ImportFetchException;
use App\Exceptions\Import\UrlImportDisabledException;
use App\Support\Import\Adapters\DirectImageImportAdapter;
use App\Support\Import\Adapters\OpenGraphImportAdapter;
use App\Support\Import\ImportPreview;
use App\Support\Import\ImportProviderDetector;
use App\Support\Import\UrlImportValidator;
use App\Support\Observability\DomainLogger;
use App\Support\Observability\SlowActionLogger;
use App\Support\Settings\ProjectSettingsManager;

class ImportFromUrlAction
{
    public function __construct(
        private readonly UrlImportValidator $validator,
        private readonly ImportProviderDetector $detector,
        private readonly DirectImageImportAdapter $directImageAdapter,
        private readonly OpenGraphImportAdapter $openGraphAdapter,
        private readonly ProjectSettingsManager $settings,
        private readonly DomainLogger $logger,
        private readonly SlowActionLogger $slowLogger,
    ) {}

    public function handle(string $url): ImportPreview
    {
        if (! $this->settings->featureEnabled('allow_url_imports')) {
            throw new UrlImportDisabledException;
        }

        $parsed = parse_url($url);
        $host = $parsed['host'] ?? 'unknown';

        $this->logger->info('url_import.preview.started', ['source_host' => $host]);

        $this->validator->validate($url);

        $provider = $this->detector->detect($url);

        if ($provider === 'direct_image') {
            $preview = $this->directImageAdapter->preview($url);
            $this->logger->info('url_import.preview.succeeded', ['source_host' => $host, 'provider' => $provider]);

            return $preview;
        }

        // For open_graph and all social providers (best-effort via OG)
        try {
            $preview = $this->slowLogger->measure('url_import.fetch', function () use ($url) {
                return $this->openGraphAdapter->preview($url);
            }, thresholdMs: (int) config('observability.slow_actions.external_fetch_threshold_ms', 1000));

            $this->logger->info('url_import.preview.succeeded', ['source_host' => $host, 'provider' => $provider]);

            // Tag with the detected social provider instead of generic open_graph
            if ($provider !== 'open_graph') {
                return new ImportPreview(
                    provider: ImportProvider::from($provider),
                    sourceUrl: $preview->sourceUrl,
                    title: $preview->title,
                    description: $preview->description,
                    imageUrl: $preview->imageUrl,
                    warnings: $preview->warnings,
                    unsupportedReason: $preview->unsupportedReason,
                );
            }

            return $preview;
        } catch (ImportFetchException $e) {
            $this->logger->warning('url_import.preview.failed', [
                'source_host' => $host,
                'provider' => $provider,
                'error_class' => get_class($e),
            ]);

            return new ImportPreview(
                provider: ImportProvider::from($provider),
                sourceUrl: $url,
                unsupportedReason: __('import.unsupported_reason_download_and_upload'),
            );
        }
    }
}
