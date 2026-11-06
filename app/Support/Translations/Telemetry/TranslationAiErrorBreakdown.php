<?php

namespace App\Support\Translations\Telemetry;

/**
 * What went wrong over one filtered period, by error code, most frequent
 * first — provider calls and items kept apart.
 *
 * $providerErrors counts failed calls by the call's code: the provider timed
 * out, refused the credentials, rate limited or answered unusably.
 * $itemErrors counts failed items by the item's code: those of a successful
 * call that failed on their own (constraint_violation), and those that failed
 * because their call did, under the call's code.
 */
final readonly class TranslationAiErrorBreakdown
{
    /**
     * @param  array<string, int>  $providerErrors  error code => failed calls
     * @param  array<string, int>  $itemErrors  error code => failed items
     */
    public function __construct(
        public array $providerErrors,
        public array $itemErrors,
    ) {}
}
