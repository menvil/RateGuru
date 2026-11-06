<?php

namespace App\Support\Translations\Generation;

use App\Support\TranslationEngine\Data\TranslationBatchRequest;
use App\Support\TranslationEngine\Data\TranslationItem;

/**
 * How one Generate missing is carried out: the provider it was planned for,
 * that provider's limits at the time, and the chunks — each one provider
 * request, each one queued job. Items too large for any request are set apart
 * and never sent.
 */
final readonly class ProjectTranslationGenerationPlan
{
    /**
     * @param  array<string, string>  $glossary
     * @param  list<TranslationBatchRequest>  $chunks  each within the provider's limits as planned
     * @param  list<TranslationItem>  $oversized
     */
    public function __construct(
        public string $targetLocale,
        public string $classification,
        public array $glossary,
        public string $provider,
        public int $maxItems,
        public int $maxProviderVisibleChars,
        public array $chunks,
        public array $oversized,
    ) {}
}
