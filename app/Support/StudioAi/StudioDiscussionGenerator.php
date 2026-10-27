<?php

namespace App\Support\StudioAi;

/**
 * Writes a discussion for Content Studio — a whole one, or one part of a
 * plan's discussion again — from the post's final texts, its photo's stored
 * analysis and the characters of the Personas taking part: the answer as one
 * structured object, and the call that wrote it.
 *
 * One implementation, OpenAiStudioDiscussionGenerator. The contract is here so
 * the queue job and the actions ask the same question — is it configured? —
 * and so tests can stand in for the provider.
 */
interface StudioDiscussionGenerator
{
    /**
     * Whether writing discussions is switched on and has everything a request
     * needs. Asked before anything is queued; never sends anything.
     */
    public function isConfigured(): bool;

    /** The most comments and replies one generation writes. */
    public function maxNodes(): int;

    /** The most Personas taking part in one generation. */
    public function maxPersonas(): int;

    /**
     * One request, at most, and never a retry.
     *
     * @throws StudioDiscussionGenerationFailedException with the reason, and the call's metadata when one was made
     */
    public function generate(StudioDiscussionPrompt $prompt): StudioDiscussionGenerationResult;
}
