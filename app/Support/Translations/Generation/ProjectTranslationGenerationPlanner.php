<?php

namespace App\Support\Translations\Generation;

use App\Support\TranslationEngine\Data\TranslationBatchRequest;
use App\Support\TranslationEngine\Exceptions\TranslationConfigurationException;
use App\Support\TranslationEngine\TranslationBatchChunker;
use App\Support\TranslationEngine\TranslationProviderRouter;

/**
 * Cuts everything a Generate missing asks for into chunks of one provider
 * request each, for one queued job each.
 *
 * The cut is the engine's: the configured provider, chosen by the router, and
 * its limits(), applied by TranslationBatchChunker. Nothing here knows a
 * number of items or characters, so a chunk fits whichever provider is
 * configured — and a job carrying one makes at most one provider call, which
 * is what keeps it well inside the worker's timeout.
 *
 * The whole request may be larger than one call to TranslationService
 * accepts: that limit is per call, and every chunk is a call of its own.
 *
 * It is the one place outside the engine that reads a provider's name and
 * limits, and it only reads them: nothing is ever sent from here. Sending is
 * TranslationService's alone.
 */
final class ProjectTranslationGenerationPlanner
{
    public function __construct(
        private readonly TranslationProviderRouter $router,
        private readonly TranslationBatchChunker $chunker,
    ) {}

    /**
     * @throws TranslationConfigurationException when no provider may take the request — before anything is stored or queued
     */
    public function plan(TranslationBatchRequest $request): ProjectTranslationGenerationPlan
    {
        $provider = $this->router->providerFor($request);
        $limits = $provider->limits();
        $chunks = $this->chunker->chunk($request, $limits);

        return new ProjectTranslationGenerationPlan(
            $request->targetLocale,
            $request->dataClassification->value,
            $request->glossary,
            $provider->name(),
            $limits->maxItems,
            $limits->maxProviderVisibleChars,
            $chunks->chunks,
            $chunks->oversized,
        );
    }

    /**
     * The provider a request would go to now, by name and limits — so a
     * queued job can tell that its chunk, planned for one provider request,
     * still fits the one it would be sent to.
     *
     * @return array{provider: string, maxItems: int, maxProviderVisibleChars: int}
     *
     * @throws TranslationConfigurationException
     */
    public function current(TranslationBatchRequest $request): array
    {
        $provider = $this->router->providerFor($request);
        $limits = $provider->limits();

        return ['provider' => $provider->name(), 'maxItems' => $limits->maxItems, 'maxProviderVisibleChars' => $limits->maxProviderVisibleChars];
    }
}
