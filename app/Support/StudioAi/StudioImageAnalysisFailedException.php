<?php

namespace App\Support\StudioAi;

use App\Enums\StudioImageAnalysisError;
use RuntimeException;

/**
 * Why an analysis gave no description: a fixed reason, and the call's
 * metadata when a request was made. Nothing the provider sent, and no
 * exception of the HTTP client — whose trace holds the request — is kept.
 */
final class StudioImageAnalysisFailedException extends RuntimeException
{
    public function __construct(
        public readonly StudioImageAnalysisError $error,
        public readonly ?StudioImageAnalysisCall $call = null,
    ) {
        parent::__construct("Photo analysis failed: {$error->value}.");
    }
}
