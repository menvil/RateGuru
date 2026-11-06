<?php

namespace App\Support\TranslationEngine\Data;

use App\Support\TranslationEngine\Exceptions\TranslationConfigurationException;

/**
 * The most one request to a provider may carry. Both limits hold at once: a
 * request closes at whichever it reaches first.
 *
 * Characters are counted over what the provider is actually sent — the
 * instructions and the JSON payload, keys, constraints, glossary and
 * structure included — not over the source texts alone.
 */
final readonly class TranslationProviderLimits
{
    public function __construct(
        public int $maxItems,
        public int $maxProviderVisibleChars,
    ) {
        if ($maxItems < 1) {
            throw TranslationConfigurationException::invalidLimit('max_items');
        }

        if ($maxProviderVisibleChars < 1) {
            throw TranslationConfigurationException::invalidLimit('max_payload_chars');
        }
    }
}
