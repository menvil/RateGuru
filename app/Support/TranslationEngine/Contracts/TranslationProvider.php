<?php

namespace App\Support\TranslationEngine\Contracts;

use App\Support\TranslationEngine\Data\TranslationBatchRequest;
use App\Support\TranslationEngine\Data\TranslationProviderLimits;
use App\Support\TranslationEngine\Data\TranslationProviderResponse;
use App\Support\TranslationEngine\Exceptions\TranslationConfigurationException;
use App\Support\TranslationEngine\Exceptions\TranslationProviderException;

/**
 * Something that translates a batch: a hosted model API, or later a model
 * served on our own hardware.
 *
 * Consumers never see one. They call TranslationService, which picks the
 * provider through TranslationProviderRouter from translation.providers — so a
 * provider is added by registering it there, without touching a consumer or
 * the router.
 *
 * A provider is built per batch, with its registry name. It reads its own
 * settings from translation.providers.{name} and refuses to be built when one
 * it needs is missing, by throwing TranslationConfigurationException; it never
 * receives a setting's value as an argument, so no stack trace can carry a key.
 *
 * There is only a batch method. A request of one item is a batch of one.
 */
interface TranslationProvider
{
    /** The provider's registry name — `openai` — as recorded on each call. */
    public function name(): string;

    /** What one request to this provider may carry; TranslationService cuts batches to fit. */
    public function limits(): TranslationProviderLimits;

    /**
     * Sends one request, already within limits(), and returns what came back.
     *
     * Returns the model's translations without judging them beyond their
     * shape. Throws TranslationProviderException, carrying the call's
     * metadata, when the request produced nothing usable; that fails the
     * items of this request only.
     *
     * @throws TranslationProviderException
     * @throws TranslationConfigurationException
     */
    public function translateBatch(TranslationBatchRequest $request): TranslationProviderResponse;
}
