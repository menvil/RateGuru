<?php

namespace App\Support\StudioAi;

use App\Enums\StudioPostTextTone;

/**
 * Writes a batch of post text suggestions for Content Studio from a photo's
 * analysis — never the photo itself — in a tone, and in the voice of the
 * post's author when it has one: titles and descriptions, as a validated
 * StudioPostTextSuggestions, and the call that wrote them.
 *
 * One implementation, OpenAiStudioPostTextGenerator. The contract is here so
 * the queue job and the editor ask the same question — is it configured? —
 * and so tests can stand in for the provider.
 */
interface StudioPostTextGenerator
{
    /**
     * Whether writing texts is switched on and has everything a request
     * needs. Asked before anything is queued; never sends anything.
     */
    public function isConfigured(): bool;

    /** How many titles, and how many descriptions, one batch holds. */
    public function batchSize(): int;

    /**
     * One request, at most, and never a retry.
     *
     * @throws StudioPostTextGenerationFailedException with the reason, and the call's metadata when one was made
     */
    public function generate(StudioImageDescription $description, StudioPostTextTone $tone, ?StudioPostTextAuthor $author = null): StudioPostTextGenerationResult;
}
