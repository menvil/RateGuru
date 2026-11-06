<?php

namespace App\Support\Translations;

use DateTimeImmutable;

/**
 * A machine translation of one unit into one language, offered to an
 * administrator and stored nowhere.
 *
 * It becomes project content only if an administrator saves it, through the
 * same save as any other draft (UpdateProjectTranslationAction). Until then it
 * is a value handed to the browser and nothing more.
 */
final readonly class ProjectTranslationSuggestion
{
    public function __construct(
        public string $unitId,
        public string $locale,
        public string $text,
        public string $provider,
        public string $model,
        public DateTimeImmutable $generatedAt,
    ) {}
}
