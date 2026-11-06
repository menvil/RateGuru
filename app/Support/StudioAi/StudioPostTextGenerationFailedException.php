<?php

namespace App\Support\StudioAi;

use App\Enums\StudioPostTextGenerationError;
use RuntimeException;

/**
 * Why a batch of post texts was not written: a fixed reason, and the call's
 * metadata when a request was made. Nothing the provider sent, and no
 * exception of the HTTP client — whose trace holds the request — is kept.
 */
final class StudioPostTextGenerationFailedException extends RuntimeException
{
    public function __construct(
        public readonly StudioPostTextGenerationError $error,
        public readonly ?StudioPostTextGenerationCall $call = null,
    ) {
        parent::__construct("Post text generation failed: {$error->value}.");
    }
}
