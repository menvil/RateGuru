<?php

namespace App\Support\TranslationEngine\Exceptions;

use App\Support\TranslationEngine\Data\TranslationProviderCall;
use App\Support\TranslationEngine\Enums\TranslationErrorCode;
use RuntimeException;

/**
 * One provider call that produced nothing usable: the provider could not be
 * reached, refused, failed, or answered with something other than the
 * structured output the request required.
 *
 * It fails the items of that one call and nothing else. TranslationService
 * catches it per call, so a failure in the middle of a batch never takes the
 * translations of the calls before or after it with it.
 *
 * The message is the code's own; the call metadata says what can be said
 * safely about the call (provider, model, HTTP status, the provider's request
 * id, latency). A provider's response body is never attached, and neither is
 * the HTTP client's exception — its trace would carry the request headers.
 */
final class TranslationProviderException extends RuntimeException
{
    public function __construct(
        public readonly TranslationErrorCode $errorCode,
        public readonly TranslationProviderCall $call,
    ) {
        parent::__construct($errorCode->message());
    }
}
