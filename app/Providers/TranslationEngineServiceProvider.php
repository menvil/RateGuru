<?php

namespace App\Providers;

use App\Support\TranslationEngine\TranslationBatchChunker;
use App\Support\TranslationEngine\TranslationPromptBuilder;
use App\Support\TranslationEngine\TranslationProviderRouter;
use App\Support\TranslationEngine\TranslationService;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the translation engine.
 *
 * A consumer type-hints TranslationService and nothing else; it never resolves
 * a provider. The engine's parts are stateless and read configuration when a
 * batch is translated, not when they are built — so resolving them, booting
 * the application and caching its configuration all work without an API key.
 *
 * Providers are deliberately not registered here: the router builds the
 * configured one per batch from translation.providers.
 */
final class TranslationEngineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TranslationPromptBuilder::class);
        $this->app->singleton(TranslationBatchChunker::class);
        $this->app->singleton(TranslationProviderRouter::class);
        $this->app->singleton(TranslationService::class);
    }
}
