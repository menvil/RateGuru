<?php

namespace App\Support\StudioAi;

use App\Enums\StudioDiscussionGenerationError;
use RuntimeException;

/**
 * Why a discussion was not written: a fixed reason, and the call's metadata
 * when a request was made. Nothing the provider sent, and no exception of the
 * HTTP client — whose trace holds the request — is kept.
 */
final class StudioDiscussionGenerationFailedException extends RuntimeException
{
    public function __construct(
        public readonly StudioDiscussionGenerationError $error,
        public readonly ?StudioDiscussionGenerationCall $call = null,
    ) {
        parent::__construct("Discussion generation failed: {$error->value}.");
    }
}
