<?php

namespace App\Support\StudioAi;

/**
 * Describes one photo for Content Studio: what it shows, as a validated
 * StudioImageDescription, and the call that said so.
 *
 * One implementation, OpenAiStudioImageAnalyzer. The contract is here so the
 * queue job and the editor ask the same question — is analysis configured? —
 * and so tests can stand in for the provider.
 */
interface StudioImageAnalyzer
{
    /**
     * Whether analysis is switched on and has everything a request needs.
     * Asked before anything is queued; never sends anything.
     */
    public function isConfigured(): bool;

    /**
     * One request, at most, and never a retry.
     *
     * @throws StudioImageAnalysisFailedException with the reason, and the call's metadata when one was made
     */
    public function analyze(StudioAnalysisImage $image): StudioImageAnalysisResult;
}
